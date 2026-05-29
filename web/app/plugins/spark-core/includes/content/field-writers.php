<?php
/**
 * Field writers — turn envelope field values into Carbon Fields meta.
 *
 * Each writer matches the shape the corresponding Carbon Fields builder
 * in fields-body-block.php / fields-page.php produces, so imported
 * values round-trip through the editor UI and the GraphQL resolvers
 * unchanged. Complex/preset values use Carbon's complex-field storage
 * (rows of associative arrays; component rows carry a `_type`
 * discriminator).
 *
 * All writes go through carbon_set_post_meta() when Carbon Fields is
 * loaded; if it isn't (defensive — Carbon is a hard dependency), the
 * writer no-ops with a warning rather than fataling.
 */

namespace Spark\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write every field on a post according to its model spec.
 *
 * @param array<string, mixed> $fields    key => value from the envelope
 * @param array<string, array<string, mixed>> $specs  key => model spec
 * @param array<string, int> $media_table ref => attachment_id
 * @param array<string, int> $post_table  ref => post_id
 * @param array<int, string> $warnings
 */
function write_fields(
    int $post_id,
    array $fields,
    array $specs,
    array $media_table,
    array $post_table,
    array &$warnings
): void {
    if (!function_exists('carbon_set_post_meta')) {
        $warnings[] = "post {$post_id}: Carbon Fields not loaded; skipped field values.";
        return;
    }

    foreach ($fields as $key => $value) {
        $key = (string) $key;
        $spec = $specs[$key] ?? null;
        if ($spec === null) {
            continue; // validator already rejected unknown keys
        }
        $writer = (string) ($spec['writer'] ?? 'unsupported');

        switch ($writer) {
            case 'scalar':
            case 'bool':
            case 'select':
                carbon_set_post_meta($post_id, $key, normalize_scalar($writer, $value));
                break;

            case 'image':
            case 'file':
                $resolved = resolve_image_value($value, $media_table, $spec);
                if ($resolved !== null) {
                    carbon_set_post_meta($post_id, $key, $resolved);
                }
                break;

            case 'intro':
                carbon_set_post_meta($post_id, $key, intro_rows($value));
                break;

            case 'gallery':
                carbon_set_post_meta($post_id, $key, gallery_rows($value, $media_table, $spec));
                break;

            case 'components':
                carbon_set_post_meta($post_id, $key, component_rows($value, $media_table));
                break;

            case 'complex':
                carbon_set_post_meta($post_id, $key, complex_rows($value, $spec, $media_table));
                break;

            case 'association':
                carbon_set_post_meta($post_id, $key, association_value($value, $post_table));
                break;

            default:
                $warnings[] = "post {$post_id}: field '{$key}' has unsupported writer; skipped.";
        }
    }
}

/**
 * @param mixed $value
 * @return mixed
 */
function normalize_scalar(string $writer, $value)
{
    if ($writer === 'bool') {
        return (bool) $value ? 'yes' : '';
    }
    if (is_array($value)) {
        // multiselect/radio may arrive as arrays; Carbon set fields take arrays.
        return array_map('strval', $value);
    }
    return is_scalar($value) ? (string) $value : '';
}

/**
 * introParagraphs: complex rows of { text: <string> }.
 * Matches spark_make_intro_field().
 *
 * @param mixed $value
 * @return array<int, array<string, string>>
 */
function intro_rows($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $rows = [];
    foreach ($value as $paragraph) {
        $rows[] = ['text' => is_scalar($paragraph) ? (string) $paragraph : ''];
    }
    return $rows;
}

/**
 * galleryImages: complex rows of { image: <url>, alt: <string> }.
 * Matches spark_make_gallery_field().
 *
 * @param mixed $value
 * @param array<string, int> $media_table
 * @param array<string, mixed> $spec
 * @return array<int, array<string, string>>
 */
function gallery_rows($value, array $media_table, array $spec): array
{
    if (!is_array($value)) {
        return [];
    }
    // Gallery sub-image is value_type 'url'.
    $img_spec = ['value_type' => 'url'];
    $rows = [];
    foreach ($value as $entry) {
        $alt = '';
        $img = $entry;
        if (is_array($entry)) {
            $alt = (string) ($entry['alt'] ?? '');
            $img = $entry['image'] ?? $entry['media'] ?? $entry['url'] ?? null;
            // Re-wrap a ref so resolve_image_value handles it.
            if (is_string($img) && isset($entry['media'])) {
                $img = ['media' => $entry['media']];
            } elseif (isset($entry['media'])) {
                $img = ['media' => $entry['media']];
            }
        }
        $resolved = resolve_image_value($img, $media_table, $img_spec);
        $rows[] = ['image' => (string) ($resolved ?? ''), 'alt' => $alt];
    }
    return $rows;
}

/**
 * components: complex rows discriminated by `_type`, sub-fields keyed
 * per component type. Matches spark_make_components_field() — the full
 * 10-kind palette. Each envelope component is `{ type, ...props }`;
 * nested repeatables (cards, tiers, logos, testimonials, features,
 * accordion items, stats) arrive as inline arrays of objects.
 *
 * Image fields are stored as URL strings (value_type 'url'); a `media`
 * ref or `url`/`src` is resolved via resolve_image_value. Two storage
 * quirks match the Carbon field definitions:
 *   - stats `figures` is a JSON-string textarea (Carbon can't persist
 *     this 6th nested complex programmatically) → encode the incoming
 *     `stats` array as JSON.
 *   - pricing tier `features` is one-per-line text (Carbon can't persist
 *     3-level complex) → join the incoming string[] with newlines.
 *
 * @param mixed $value
 * @param array<string, int> $media_table
 * @return array<int, array<string, mixed>>
 */
function component_rows($value, array $media_table): array
{
    if (!is_array($value)) {
        return [];
    }
    $rows = [];

    foreach ($value as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = (string) ($row['type'] ?? '');
        $built = build_component_row($type, $row, $media_table);
        if ($built !== null) {
            $rows[] = $built;
        }
    }
    return $rows;
}

/**
 * Build a single Carbon component row from an envelope component.
 *
 * @param array<string, mixed> $row
 * @param array<string, int> $media_table
 * @return array<string, mixed>|null
 */
function build_component_row(string $type, array $row, array $media_table): ?array
{
    // Resolve a component image to a stored URL. A `{media: ref}` wrapper
    // resolves via the ref table; a raw URL is sideloaded into the media
    // library (idempotent, hash-deduped) so seeded images become real WP
    // attachments — matching Drupal's inline-image behavior — and fall
    // back to the literal URL only if the fetch fails.
    $img = function ($v) use ($media_table): string {
        $alt = is_array($v) ? (string) ($v['alt'] ?? '') : '';
        $coerced = coerce_image($v);
        if (is_string($coerced) && $coerced !== '') {
            return sideload_component_image($coerced, $alt);
        }
        return (string) (resolve_image_value($coerced, $media_table, ['value_type' => 'url']) ?? '');
    };

    switch ($type) {
        case 'richtext':
            return ['_type' => 'richtext', 'html' => (string) ($row['html'] ?? $row['spark_richtext_body'] ?? '')];

        case 'gallery':
            $images = [];
            foreach ((is_array($row['images'] ?? null) ? $row['images'] : []) as $g) {
                $alt = is_array($g) ? (string) ($g['alt'] ?? '') : '';
                $src = is_array($g) ? ($g['src'] ?? $g['media'] ?? $g['url'] ?? null) : $g;
                $images[] = ['src' => $img($src), 'alt' => $alt];
            }
            return ['_type' => 'gallery', 'images' => $images];

        case 'cta':
            return [
                '_type'        => 'cta',
                'heading'      => (string) ($row['heading'] ?? ''),
                'text'         => (string) ($row['text'] ?? ''),
                'button_label' => (string) ($row['button_label'] ?? $row['spark_cta_label'] ?? ''),
                'button_url'   => (string) ($row['button_url'] ?? $row['spark_cta_url'] ?? ''),
            ];

        case 'embed':
            return [
                '_type'      => 'embed',
                'embed_code' => (string) ($row['embed_code'] ?? ''),
                'caption'    => (string) ($row['caption'] ?? ''),
            ];

        case 'hero':
            return [
                '_type'              => 'hero',
                'eyebrow'            => (string) ($row['eyebrow'] ?? ''),
                'title'              => (string) ($row['title'] ?? ''),
                'subtitle'           => (string) ($row['subtitle'] ?? ''),
                'layout'             => (string) ($row['layout'] ?? 'centered'),
                'background_image'   => $img($row['background_image'] ?? null),
                'primary_cta_text'   => (string) ($row['primary_cta_text'] ?? ''),
                'primary_cta_url'    => (string) ($row['primary_cta_url'] ?? ''),
                'secondary_cta_text' => (string) ($row['secondary_cta_text'] ?? ''),
                'secondary_cta_url'  => (string) ($row['secondary_cta_url'] ?? ''),
            ];

        case 'textblock':
            return [
                '_type'     => 'textblock',
                'eyebrow'   => (string) ($row['eyebrow'] ?? ''),
                'title'     => (string) ($row['title'] ?? ''),
                'content'   => (string) ($row['content'] ?? ''),
                'alignment' => (string) ($row['alignment'] ?? 'left'),
                'cta_text'  => (string) ($row['cta_text'] ?? ''),
                'cta_url'   => (string) ($row['cta_url'] ?? ''),
            ];

        case 'cardgroup':
            $cards = [];
            foreach ((is_array($row['cards'] ?? null) ? $row['cards'] : []) as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $cards[] = [
                    'icon'        => (string) ($c['icon'] ?? ''),
                    'title'       => (string) ($c['title'] ?? ''),
                    'description' => (string) ($c['description'] ?? ''),
                    'link_text'   => (string) ($c['link_text'] ?? ''),
                    'link_url'    => (string) ($c['link_url'] ?? ''),
                ];
            }
            return [
                '_type'    => 'cardgroup',
                'eyebrow'  => (string) ($row['eyebrow'] ?? ''),
                'title'    => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'columns'  => (string) ($row['columns'] ?? '3'),
                'cards'    => $cards,
            ];

        case 'sidebyside':
            $features = [];
            foreach ((is_array($row['features'] ?? null) ? $row['features'] : []) as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $features[] = [
                    'icon'        => (string) ($f['icon'] ?? ''),
                    'title'       => (string) ($f['title'] ?? ''),
                    'description' => (string) ($f['description'] ?? ''),
                ];
            }
            return [
                '_type'          => 'sidebyside',
                'eyebrow'        => (string) ($row['eyebrow'] ?? ''),
                'title'          => (string) ($row['title'] ?? ''),
                'content'        => (string) ($row['content'] ?? ''),
                'image'          => $img($row['image'] ?? null),
                'image_position' => (string) ($row['image_position'] ?? 'right'),
                'features'       => $features,
                'cta_text'       => (string) ($row['cta_text'] ?? ''),
                'cta_url'        => (string) ($row['cta_url'] ?? ''),
            ];

        case 'accordion':
            $items = [];
            foreach ((is_array($row['items'] ?? null) ? $row['items'] : []) as $i) {
                if (!is_array($i)) {
                    continue;
                }
                $items[] = [
                    'question' => (string) ($i['question'] ?? ''),
                    'answer'   => (string) ($i['answer'] ?? ''),
                ];
            }
            return [
                '_type'    => 'accordion',
                'eyebrow'  => (string) ($row['eyebrow'] ?? ''),
                'title'    => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'items'    => $items,
            ];

        case 'quote':
            $testimonials = [];
            foreach ((is_array($row['testimonials'] ?? null) ? $row['testimonials'] : []) as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $testimonials[] = [
                    'quote'          => (string) ($t['quote'] ?? ''),
                    'author_name'    => (string) ($t['author_name'] ?? ''),
                    'author_title'   => (string) ($t['author_title'] ?? ''),
                    'author_company' => (string) ($t['author_company'] ?? ''),
                    'rating'         => (string) ($t['rating'] ?? ''),
                    'author_image'   => $img($t['author_image'] ?? null),
                ];
            }
            return [
                '_type'        => 'quote',
                'eyebrow'      => (string) ($row['eyebrow'] ?? ''),
                'title'        => (string) ($row['title'] ?? ''),
                'layout'       => (string) ($row['layout'] ?? 'single'),
                'testimonials' => $testimonials,
            ];

        case 'pricing':
            $tiers = [];
            foreach ((is_array($row['tiers'] ?? null) ? $row['tiers'] : []) as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $feat = is_array($t['features'] ?? null) ? $t['features'] : [];
                $tiers[] = [
                    'name'           => (string) ($t['name'] ?? ''),
                    'price'          => (string) ($t['price'] ?? ''),
                    'billing_period' => (string) ($t['billing_period'] ?? ''),
                    'description'    => (string) ($t['description'] ?? ''),
                    // One-per-line text; normalizer splits back to string[].
                    'features'       => implode("\n", array_map('strval', $feat)),
                    'is_featured'    => !empty($t['is_featured']) ? 'yes' : '',
                    'cta_text'       => (string) ($t['cta_text'] ?? ''),
                    'cta_url'        => (string) ($t['cta_url'] ?? ''),
                ];
            }
            return [
                '_type'    => 'pricing',
                'eyebrow'  => (string) ($row['eyebrow'] ?? ''),
                'title'    => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'tiers'    => $tiers,
            ];

        case 'logocollection':
            $logos = [];
            foreach ((is_array($row['logos'] ?? null) ? $row['logos'] : []) as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $logos[] = [
                    'name'  => (string) ($l['name'] ?? ''),
                    'image' => $img($l['image'] ?? null),
                    'url'   => (string) ($l['url'] ?? ''),
                ];
            }
            return [
                '_type'   => 'logocollection',
                'eyebrow' => (string) ($row['eyebrow'] ?? ''),
                'title'   => (string) ($row['title'] ?? ''),
                'logos'   => $logos,
            ];

        case 'stats':
            // Figures are stored as a JSON string (Carbon can't persist
            // this nested complex programmatically). Encode the incoming
            // stats array; the normalizer decodes it back to `stats`.
            $figures = [];
            foreach ((is_array($row['stats'] ?? null) ? $row['stats'] : []) as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $figures[] = [
                    'value'       => (string) ($s['value'] ?? ''),
                    'label'       => (string) ($s['label'] ?? ''),
                    'description' => (string) ($s['description'] ?? ''),
                ];
            }
            return [
                '_type'            => 'stats',
                'eyebrow'          => (string) ($row['eyebrow'] ?? ''),
                'title'            => (string) ($row['title'] ?? ''),
                'background_color' => (string) ($row['background_color'] ?? ''),
                'figures'          => (string) wp_json_encode($figures),
            ];

        case 'newsletter':
            return [
                '_type'            => 'newsletter',
                'eyebrow'          => (string) ($row['eyebrow'] ?? ''),
                'title'            => (string) ($row['title'] ?? ''),
                'subtitle'         => (string) ($row['subtitle'] ?? ''),
                'placeholder'      => (string) ($row['placeholder'] ?? ''),
                'button_text'      => (string) ($row['button_text'] ?? ''),
                'background_color' => (string) ($row['background_color'] ?? 'light'),
            ];
    }
    return null;
}

/**
 * Sideload a raw component-image URL into the media library and return
 * the stored attachment URL. Idempotent (hash-deduped via import_media),
 * so re-running the seed reuses the existing attachment. Skips obviously
 * fake URLs and falls back to the literal URL when the fetch fails — a
 * broken image never sinks the import.
 */
function sideload_component_image(string $url, string $alt = ''): string
{
    if ($url === '' || !str_starts_with($url, 'http')) {
        return $url;
    }
    if (str_contains($url, 'example.com') || str_contains($url, 'placeholder')) {
        return '';
    }

    $warnings = [];
    $table = import_media(
        [['ref' => 'component', 'sourceUrl' => $url, 'alt' => $alt]],
        $warnings
    );
    $att = (int) ($table['component'] ?? 0);
    if ($att > 0) {
        $src = wp_get_attachment_url($att);
        if (is_string($src) && $src !== '') {
            return $src;
        }
    }
    return $url; // fetch failed — keep the literal URL so rendering still works
}

/**
 * Coerce an envelope image value into the shape resolve_image_value
 * accepts: a `{media: ref}` wrapper, an `{url}`/`{src}` object, or a
 * plain URL string.
 *
 * @param mixed $v
 * @return mixed
 */
function coerce_image($v)
{
    if (is_array($v)) {
        if (isset($v['media'])) {
            return ['media' => $v['media']];
        }
        return $v['url'] ?? $v['src'] ?? null;
    }
    return $v;
}

/**
 * Generic complex field: pass each row's known sub-keys through.
 * Sub-field shape comes from the model spec's `fields` list.
 *
 * @param mixed $value
 * @param array<string, mixed> $spec
 * @param array<string, int> $media_table
 * @return array<int, array<string, mixed>>
 */
function complex_rows($value, array $spec, array $media_table): array
{
    if (!is_array($value)) {
        return [];
    }
    $sub_specs = [];
    foreach ((is_array($spec['fields'] ?? null) ? $spec['fields'] : []) as $sub) {
        if (is_array($sub) && isset($sub['key'])) {
            $sub_specs[(string) $sub['key']] = $sub;
        }
    }

    $rows = [];
    foreach ($value as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $k = (string) $k;
            $sub = $sub_specs[$k] ?? null;
            if ($sub !== null && (string) ($sub['type'] ?? '') === 'image') {
                $out[$k] = (string) (resolve_image_value($v, $media_table, $sub) ?? '');
            } else {
                $out[$k] = is_scalar($v) ? (string) $v : $v;
            }
        }
        $rows[] = $out;
    }
    return $rows;
}

/**
 * Association field value: Carbon stores associations as a list of
 * "type:subtype:id" strings. We resolve { post: <ref> } via the ref
 * table; { term: "tax/slug" } and { user: <id> } pass through.
 *
 * @param mixed $value
 * @param array<string, int> $post_table
 * @return array<int, string>
 */
function association_value($value, array $post_table): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $rel) {
        if (!is_array($rel)) {
            continue;
        }
        if (isset($rel['post'])) {
            $pid = (int) ($post_table[(string) $rel['post']] ?? 0);
            if ($pid > 0) {
                $ptype = get_post_type($pid) ?: 'post';
                $out[] = "post:{$ptype}:{$pid}";
            }
        } elseif (isset($rel['term'])) {
            // term value form "taxonomy/slug"
            [$tax, $slug] = array_pad(explode('/', (string) $rel['term'], 2), 2, '');
            $t = $tax && $slug ? get_term_by('slug', $slug, $tax) : null;
            if ($t instanceof \WP_Term) {
                $out[] = "term:{$tax}:{$t->term_id}";
            }
        } elseif (isset($rel['user'])) {
            $out[] = 'user:user:' . (int) $rel['user'];
        }
    }
    return $out;
}
