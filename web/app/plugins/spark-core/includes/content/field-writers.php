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
 * per component type. Matches spark_make_components_field():
 *   richtext → { html }
 *   gallery  → { images: [ { src, alt } ] }
 *   cta      → { heading, text, button_label, button_url }
 *   embed    → { embed_code, caption }
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
    $img_spec = ['value_type' => 'url'];
    $rows = [];

    foreach ($value as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = (string) ($row['type'] ?? '');
        switch ($type) {
            case 'richtext':
                $rows[] = ['_type' => 'richtext', 'html' => (string) ($row['html'] ?? $row['spark_richtext_body'] ?? '')];
                break;

            case 'gallery':
                $images = [];
                $src_list = is_array($row['images'] ?? null) ? $row['images'] : [];
                foreach ($src_list as $img) {
                    $alt = is_array($img) ? (string) ($img['alt'] ?? '') : '';
                    $src_val = is_array($img) ? ($img['src'] ?? $img['media'] ?? $img['url'] ?? null) : $img;
                    if (is_array($img) && isset($img['media'])) {
                        $src_val = ['media' => $img['media']];
                    }
                    $images[] = [
                        'src' => (string) (resolve_image_value($src_val, $media_table, $img_spec) ?? ''),
                        'alt' => $alt,
                    ];
                }
                $rows[] = ['_type' => 'gallery', 'images' => $images];
                break;

            case 'cta':
                $rows[] = [
                    '_type'        => 'cta',
                    'heading'      => (string) ($row['heading'] ?? ''),
                    'text'         => (string) ($row['text'] ?? ''),
                    'button_label' => (string) ($row['button_label'] ?? $row['spark_cta_label'] ?? ''),
                    'button_url'   => (string) ($row['button_url'] ?? $row['spark_cta_url'] ?? ''),
                ];
                break;

            case 'embed':
                $rows[] = [
                    '_type'      => 'embed',
                    'embed_code' => (string) ($row['embed_code'] ?? ''),
                    'caption'    => (string) ($row['caption'] ?? ''),
                ];
                break;
        }
    }
    return $rows;
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
