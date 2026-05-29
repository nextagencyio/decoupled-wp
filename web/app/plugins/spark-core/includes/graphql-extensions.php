<?php
/**
 * WPGraphQL extensions — expose content on each type so the Astro
 * frontend can fetch everything in a single GraphQL query.
 *
 * Body content follows the WYSIWYG-first rule (see
 * fields-body-block.php): the page body is the standard WordPress
 * editor, exposed as `bodyHtml` (rendered `post_content`). Carbon
 * Fields adds only structured extras — `heroImage`,
 * `introParagraphs`, an optional `components` list, `galleryImages`
 * — resolved through the converters in fields-body-block.php.
 *
 * Pattern to follow per project: register fields on the GraphQL
 * type whose name matches the CPT's `graphql_single_name` (capitalised
 * by WPGraphQL — `resource` → `Resource`, `page` → `Page`).
 */

namespace Spark\Core\GraphQL;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

add_action('graphql_register_types', __NAMESPACE__ . '\\register_all');

/**
 * Register every GraphQL object type + field this plugin adds. Guarded
 * so it no-ops cleanly if WPGraphQL is not active.
 */
function register_all(): void
{
    if (!function_exists('register_graphql_field')) {
        return;
    }

    register_shared_types();
    foreach (configured_graphql_types() as $graphql_type) {
        register_shared_fields($graphql_type);
    }
    register_post_type_fields();
}

/**
 * Shared GraphQL object types.
 *
 * `SparkComponent` is one COMPLEX COMPONENT (gallery / CTA / embed /
 * a run of rich text) — NOT a per-paragraph block. Ordinary prose is
 * `bodyHtml`, not a component. The `kind` discriminator tells the
 * frontend which fields are meaningful; a flattened union keeps the
 * frontend query simple.
 */
function register_shared_types(): void
{
    register_graphql_object_type('SparkImage', [
        'description' => __('An image reference: src + alt (+ optional dimensions).', 'spark-core'),
        'fields' => [
            'src'    => ['type' => 'String'],
            'alt'    => ['type' => 'String'],
            'width'  => ['type' => 'String'],
            'height' => ['type' => 'String'],
        ],
    ]);

    register_graphql_object_type('SparkTerm', [
        'description' => __('A taxonomy term reference: slug + display name.', 'spark-core'),
        'fields' => [
            'slug' => ['type' => 'String'],
            'name' => ['type' => 'String'],
        ],
    ]);

    register_graphql_object_type('SparkRef', [
        'description' => __('A reference to a related post: id + slug + title.', 'spark-core'),
        'fields' => [
            'id'    => ['type' => 'String'],
            'slug'  => ['type' => 'String'],
            'title' => ['type' => 'String'],
        ],
    ]);

    register_graphql_object_type('SparkBlock', [
        'description' => __('A normalized content block from a kind-discriminated complex field (body block / component / page section). `kind` selects which slots are meaningful; `data` is the full row as JSON for anything the typed slots miss.', 'spark-core'),
        'fields' => [
            'kind'        => ['type' => 'String'],
            'html'        => ['type' => 'String'],
            'text'        => ['type' => 'String'],
            'heading'     => ['type' => 'String'],
            'value'       => ['type' => 'String'],
            'buttonLabel' => ['type' => 'String'],
            'buttonUrl'   => ['type' => 'String'],
            'code'        => ['type' => 'String'],
            'caption'     => ['type' => 'String'],
            'images'      => ['type' => ['list_of' => 'SparkImage']],
            'items'       => ['type' => ['list_of' => 'String']],
            'data'        => ['type' => 'String', 'description' => 'Full row as JSON.'],
        ],
    ]);

    register_graphql_object_type('SparkComponent', [
        'description' => __('One complex page component — a gallery, CTA, embed, or a run of rich text. Ordinary prose is bodyHtml, not a component.', 'spark-core'),
        'fields' => [
            'kind'        => ['type' => 'String', 'description' => 'richtext | gallery | cta | embed'],
            'html'        => ['type' => 'String', 'description' => 'Rich HTML (richtext components).'],
            'images'      => ['type' => ['list_of' => 'SparkImage'], 'description' => 'Images (gallery components).'],
            'heading'     => ['type' => 'String', 'description' => 'Heading (cta components).'],
            'text'        => ['type' => 'String', 'description' => 'Text (cta components).'],
            'buttonLabel' => ['type' => 'String', 'description' => 'Button label (cta components).'],
            'buttonUrl'   => ['type' => 'String', 'description' => 'Button URL (cta components).'],
            'code'        => ['type' => 'String', 'description' => 'Embed code or URL (embed components).'],
            'caption'     => ['type' => 'String', 'description' => 'Caption (embed components).'],
        ],
    ]);
}

/**
 * Resolver factory — return a plain Carbon Fields meta value for $key.
 */
function meta_resolver(string $key): callable
{
    return function ($post) use ($key) {
        return carbon_get_post_meta($post->databaseId, $key);
    };
}

/**
 * Resolve the page body — the standard WordPress editor content
 * (`post_content`), run through `the_content` filters so shortcodes,
 * embeds, and wpautop paragraphs render. This is the body; ordinary
 * prose is NOT a Carbon Fields structure.
 */
function body_html_resolver(): callable
{
    return function ($post): string {
        $raw = get_post_field('post_content', $post->databaseId);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }
        return apply_filters('the_content', $raw);
    };
}

/** Resolve the optional components Complex into the SparkComponent list. */
function components_resolver(string $key = 'spark_components'): callable
{
    return function ($post) use ($key) {
        $raw = carbon_get_post_meta($post->databaseId, $key);
        return spark_components_to_array($raw);
    };
}

/** Resolve a stored intro Complex into a flat list of strings. */
function intro_resolver(string $key = 'spark_intro_paragraphs'): callable
{
    return function ($post) use ($key) {
        $raw = carbon_get_post_meta($post->databaseId, $key);
        return spark_intro_to_strings($raw);
    };
}

/** Resolve a stored gallery Complex into a list of SparkImage. */
function gallery_resolver(string $key = 'spark_gallery_images'): callable
{
    return function ($post) use ($key) {
        $raw = carbon_get_post_meta($post->databaseId, $key);
        return spark_gallery_to_images($raw);
    };
}

/**
 * Resolve a hero image (url meta) + its alt into a single SparkImage,
 * or null when no hero image is set.
 */
function hero_resolver(string $src_key = 'spark_hero_image', string $alt_key = 'spark_hero_alt'): callable
{
    return function ($post) use ($src_key, $alt_key) {
        $src = (string) carbon_get_post_meta($post->databaseId, $src_key);
        if ($src === '') {
            return null;
        }
        return [
            'src' => $src,
            'alt' => (string) carbon_get_post_meta($post->databaseId, $alt_key),
        ];
    };
}

/**
 * Resolver for `metaDescription` — the SEO description the Astro
 * frontend puts in the page <meta name="description">. Falls back to
 * the post excerpt; null when empty.
 */
function meta_description_resolver(): callable
{
    return static function ($post): ?string {
        $excerpt = trim((string) ($post->excerpt ?? ''));
        if ($excerpt !== '') {
            return wp_strip_all_tags($excerpt);
        }
        return null;
    };
}

/**
 * Resolver for `catalogSlug` — an optional routing-slug override the
 * Astro frontend prefers over the raw WP slug (`catalogSlug ?? slug`).
 * Lets a project re-key a page's URL without changing the WP slug.
 * Stored in the `_spark_catalog_slug` meta; null when unset.
 */
function catalog_slug_resolver(): callable
{
    return static function ($post): ?string {
        $val = trim((string) get_post_meta($post->databaseId, '_spark_catalog_slug', true));
        return $val !== '' ? $val : null;
    };
}

/**
 * The shared, headless-frontend-facing fields registered on every
 * content type the Astro starter reads. Keep this list in sync with
 * the queries in astro-spark's src/lib/wp.ts.
 */
function register_shared_fields(string $graphql_type): void
{
    $graphql = Config\graphql();
    $fields = is_array($graphql['fields'] ?? null) ? $graphql['fields'] : [];

    foreach ($fields as $field_name => $field_config) {
        if (!is_array($field_config)) {
            continue;
        }

        $resolver = resolver_from_config($field_config);
        if (!$resolver) {
            continue;
        }

        register_graphql_field($graphql_type, (string) $field_name, [
            'type'        => $field_config['type'] ?? 'String',
            'description' => __((string) ($field_config['description'] ?? ''), 'spark-core'),
            'resolve'     => $resolver,
        ]);
    }
}

/**
 * @return array<int, string>
 */
function configured_graphql_types(): array
{
    $graphql = Config\graphql();
    $post_types = is_array($graphql['sharedPostTypes'] ?? null) ? $graphql['sharedPostTypes'] : [];
    $types = [];

    foreach ($post_types as $post_type) {
        $type = graphql_type_for_post_type((string) $post_type);
        if ($type) {
            $types[] = $type;
        }
    }

    return array_values(array_unique($types));
}

function graphql_type_for_post_type(string $post_type): ?string
{
    if ($post_type === 'page') {
        return 'Page';
    }
    if ($post_type === 'post') {
        return 'Post';
    }

    $post_types = Config\post_types();
    $single_name = $post_types[$post_type]['graphql_single_name'] ?? null;
    if (!is_string($single_name) || $single_name === '') {
        return null;
    }

    return ucfirst($single_name);
}

/**
 * @param array<string, mixed> $config
 */
function resolver_from_config(array $config): ?callable
{
    return match ((string) ($config['resolver'] ?? '')) {
        'heroImage'       => hero_resolver((string) ($config['srcKey'] ?? 'spark_hero_image'), (string) ($config['altKey'] ?? 'spark_hero_alt')),
        'introParagraphs' => intro_resolver((string) ($config['key'] ?? 'spark_intro_paragraphs')),
        'bodyHtml'        => body_html_resolver(),
        'components'      => components_resolver((string) ($config['key'] ?? 'spark_components')),
        'galleryImages'   => gallery_resolver((string) ($config['key'] ?? 'spark_gallery_images')),
        'metaDescription' => meta_description_resolver(),
        'catalogSlug'     => catalog_slug_resolver(),
        'meta'            => meta_resolver((string) ($config['key'] ?? '')),
        'metaComplexJson' => meta_complex_json_resolver((string) ($config['key'] ?? '')),
        'metaInt'         => meta_int_resolver((string) ($config['key'] ?? '')),
        'metaBool'        => meta_bool_resolver((string) ($config['key'] ?? ''), (string) ($config['truthy'] ?? '')),
        'metaList'        => meta_list_resolver((string) ($config['key'] ?? '')),
        'metaIntList'     => meta_int_list_resolver((string) ($config['key'] ?? ''), (string) ($config['subKey'] ?? '')),
        'metaStringList'  => meta_string_list_resolver((string) ($config['key'] ?? ''), (string) ($config['subKey'] ?? '')),
        'rawMeta'         => raw_meta_resolver((string) ($config['key'] ?? '')),
        'terms'           => terms_resolver((string) ($config['taxonomy'] ?? '')),
        'relatedPosts'    => related_posts_resolver((string) ($config['key'] ?? '')),
        'variantMap'      => variant_map_resolver($config),
        default           => null,
    };
}

/**
 * Resolve a raw (non-Carbon) post meta value as a scalar string, null
 * when empty. For underscore-prefixed keys Carbon doesn't own (e.g.
 * `_spark_catalog_slug`).
 */
function raw_meta_resolver(string $key): callable
{
    return function ($post) use ($key) {
        if ($key === '') return null;
        $v = trim((string) get_post_meta($post->databaseId, $key, true));
        return $v !== '' ? $v : null;
    };
}

/**
 * Pluck one subKey from each row of a Carbon complex, as a list of
 * strings. (e.g. `amenities` = each row's `label`.)
 */
function meta_string_list_resolver(string $key, string $subKey): callable
{
    return function ($post) use ($key, $subKey) {
        if ($key === '' || $subKey === '') return [];
        $rows = carbon_get_post_meta($post->databaseId, $key);
        if (!is_array($rows)) return [];
        return array_values(array_map(static fn($r) => (string) ($r[$subKey] ?? ''), $rows));
    };
}

/**
 * Pluck one subKey from each row of a Carbon complex, as a list of ints.
 * (e.g. gfparks `yardages` = each row's `yards` cast to int.)
 */
function meta_int_list_resolver(string $key, string $subKey): callable
{
    return function ($post) use ($key, $subKey) {
        if ($key === '' || $subKey === '') return [];
        $rows = carbon_get_post_meta($post->databaseId, $key);
        if (!is_array($rows)) return [];
        return array_values(array_map(static fn($r) => (int) ($r[$subKey] ?? 0), $rows));
    };
}

/** Resolve a meta value as an Int (number fields). Null when unset. */
function meta_int_resolver(string $key): callable
{
    return function ($post) use ($key) {
        if ($key === '') return null;
        $v = carbon_get_post_meta($post->databaseId, $key);
        return ($v === '' || $v === null) ? null : (int) $v;
    };
}

/**
 * Resolve a meta value as a Boolean (checkbox fields). When $truthy is
 * given (e.g. 'yes'), the value is true only when it equals that token —
 * matching Carbon checkboxes configured with set_option_value('yes').
 */
function meta_bool_resolver(string $key, string $truthy = ''): callable
{
    return function ($post) use ($key, $truthy) {
        if ($key === '') return false;
        $v = carbon_get_post_meta($post->databaseId, $key);
        return $truthy !== '' ? ((string) $v === $truthy) : (bool) $v;
    };
}

/** Resolve a `set` (multiselect) meta value as a list of strings. */
function meta_list_resolver(string $key): callable
{
    return function ($post) use ($key) {
        if ($key === '') return [];
        $v = carbon_get_post_meta($post->databaseId, $key);
        return is_array($v) ? array_map('strval', $v) : [];
    };
}

/**
 * Resolve a taxonomy's terms on a post into a list of SparkTerm
 * (slug + name). Lets config expose `audiences { slug name }` without
 * relying on WP's built-in taxonomy connections.
 */
function terms_resolver(string $taxonomy): callable
{
    return function ($post) use ($taxonomy) {
        if ($taxonomy === '') return [];
        $terms = get_the_terms($post->databaseId, $taxonomy);
        if (!is_array($terms)) return [];
        return array_map(static fn($t) => ['slug' => $t->slug, 'name' => $t->name], $terms);
    };
}

/**
 * Resolve a Carbon association value (post relations) into a list of
 * SparkRef (id + slug + title), so the frontend can render relations
 * without a second query.
 */
function related_posts_resolver(string $key): callable
{
    return function ($post) use ($key) {
        if ($key === '') return [];
        $items = carbon_get_post_meta($post->databaseId, $key);
        if (!is_array($items)) return [];
        $out = [];
        foreach ($items as $item) {
            // Carbon stores associations as ['type'=>'post','subtype'=>..,'id'=>N].
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : (int) $item;
            if ($id <= 0) continue;
            $out[] = [
                'id'    => (string) $id,
                'slug'  => (string) get_post_field('post_name', $id),
                'title' => (string) get_the_title($id),
            ];
        }
        return $out;
    };
}

/**
 * Resolve a Carbon complex (repeater) meta value into a list of
 * JSON-encoded strings — one per row. Mirrors how the bespoke YSAQMD
 * `relatedForms` field exposed label+url rows to the frontend.
 */
function meta_complex_json_resolver(string $key): callable
{
    return function ($post) use ($key) {
        if ($key === '') {
            return [];
        }
        $items = carbon_get_post_meta($post->databaseId, $key);
        if (!is_array($items)) {
            return [];
        }
        return array_map(static fn($i) => wp_json_encode($i), $items);
    };
}

/**
 * The kind-discriminated complex normalizer — the resolver every prior
 * engagement hand-wrote (body blocks, components, page sections).
 *
 * Carbon stores a complex field as rows, each with a `_type` discriminator
 * (the complex group name). This maps each row into a flat SparkBlock:
 *   - `kind`  = the row's `_type`
 *   - typed slots (html / text / images / heading / buttonLabel /
 *     buttonUrl / code / caption / value / items) filled per the config's
 *     per-variant field map
 *   - `data`  = the full row as a JSON string (escape hatch for anything
 *     the typed slots don't cover)
 *
 * Config shape (on the GraphQL field):
 *   resolver: 'variantMap'
 *   key:      'spark_components'
 *   variants: {
 *     richtext: { html: 'body' },
 *     cta:      { heading: 'title', text: 'body', buttonLabel: 'label', buttonUrl: 'url' },
 *     gallery:  { images: { from: 'images', as: 'imageList', src: 'image', alt: 'alt' } },
 *     stat:     { value: 'number', text: 'label' }      // field rename handled here
 *   }
 * A slot value is either a subKey string (scalar/html passthrough) or an
 * object {from, as} where `as` is 'imageList' | 'stringList' | 'html' | 'scalar'.
 *
 * @param array<string, mixed> $config
 */
function variant_map_resolver(array $config): callable
{
    $key = (string) ($config['key'] ?? '');
    $variants = is_array($config['variants'] ?? null) ? $config['variants'] : [];

    return function ($post) use ($key, $variants) {
        if ($key === '') return [];
        $rows = carbon_get_post_meta($post->databaseId, $key);
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $kind = (string) ($row['_type'] ?? '');
            $block = ['kind' => $kind, 'data' => (string) wp_json_encode($row)];

            $map = is_array($variants[$kind] ?? null) ? $variants[$kind] : [];
            foreach ($map as $slot => $spec) {
                $block[$slot] = variant_slot_value($row, $spec);
            }
            $out[] = $block;
        }
        return $out;
    };
}

/**
 * Resolve one SparkBlock slot from a complex row, per its config spec.
 * @param array<string, mixed> $row
 * @param mixed $spec  subKey string, or {from, as, src, alt}
 * @return mixed
 */
function variant_slot_value(array $row, $spec)
{
    if (is_string($spec)) {
        return (string) ($row[$spec] ?? '');
    }
    if (!is_array($spec)) {
        return null;
    }

    $from = (string) ($spec['from'] ?? '');
    $as = (string) ($spec['as'] ?? 'scalar');
    $val = $from !== '' ? ($row[$from] ?? null) : null;

    return match ($as) {
        'html', 'scalar' => (string) ($val ?? ''),
        'stringList' => is_array($val)
            ? array_values(array_map('strval', $val))
            : [],
        'imageList' => is_array($val)
            ? array_values(array_map(static fn($i) => [
                'src' => (string) ($i[(string) ($spec['src'] ?? 'image')] ?? ''),
                'alt' => (string) ($i[(string) ($spec['alt'] ?? 'alt')] ?? ''),
            ], $val))
            : [],
        default => (string) ($val ?? ''),
    };
}

/**
 * Register per-CPT GraphQL fields from `graphql.postTypeFields`.
 *
 * Unlike register_shared_fields (same fields on every shared type),
 * this maps a DIFFERENT field set onto each named GraphQL type — e.g.
 * Program gets lede/applyUrl, BoardMember gets representation/role.
 * The GraphQL type name is the key (already capitalised, e.g. "Program").
 */
function register_post_type_fields(): void
{
    $graphql = Config\graphql();
    $by_type = is_array($graphql['postTypeFields'] ?? null) ? $graphql['postTypeFields'] : [];

    foreach ($by_type as $graphql_type => $fields) {
        if (!is_array($fields)) {
            continue;
        }
        foreach ($fields as $field_name => $field_config) {
            if (!is_array($field_config)) {
                continue;
            }
            $resolver = resolver_from_config($field_config);
            if (!$resolver) {
                continue;
            }
            register_graphql_field((string) $graphql_type, (string) $field_name, [
                'type'        => $field_config['type'] ?? 'String',
                'description' => __((string) ($field_config['description'] ?? ''), 'spark-core'),
                'resolve'     => $resolver,
            ]);
        }
    }
}

/**
 * Fields on the example `spark_resource` CPT (GraphQL type `Resource`).
 */
function register_resource_fields(): void
{
    register_shared_fields('Resource');
}

/**
 * Fields on the default `page` post type (GraphQL type `Page`).
 */
function register_page_fields(): void
{
    register_shared_fields('Page');
}
