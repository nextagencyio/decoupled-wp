<?php
/**
 * Content-model loader, normalizer, and validator.
 */

namespace Dc\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return the active Decoupled content model.
 *
 * For now the built-in model is the source of truth. The filter is the
 * extension point that later lets an option, JSON file, or signed
 * decoupled.io payload provide project-specific data.
 *
 * @return array<string, mixed>
 */
function model(bool $refresh = false): array
{
    static $model = null;

    if ($refresh) {
        $model = null;
    }

    if (is_array($model)) {
        return $model;
    }

    $candidate = stored_model();
    if (!is_array($candidate)) {
        $candidate = default_model();
    }

    $candidate = apply_filters('dc_core_content_model', $candidate);
    if (!is_array($candidate)) {
        $candidate = default_model();
    }

    $candidate = normalize_model($candidate);
    $errors = validate_model($candidate);

    if ($errors !== []) {
        set_validation_errors($errors);
        $candidate = normalize_model(default_model());
    }

    $model = $candidate;
    return $model;
}

/**
 * Return the option-backed model when one has been saved.
 *
 * @return array<string, mixed>|null
 */
function stored_model(): ?array
{
    $stored = get_option('dc_content_model_active', null);
    return is_array($stored) ? $stored : null;
}

/**
 * Save a validated model as the active option-backed model.
 *
 * @param array<string, mixed> $candidate
 * @return array<int, string> Validation errors. Empty means saved.
 */
function save_model(array $candidate): array
{
    $candidate = normalize_model($candidate);
    $errors = validate_model($candidate);

    if ($errors !== []) {
        return $errors;
    }

    update_option('dc_content_model_active', $candidate, false);
    wp_cache_delete('alloptions', 'options');
    model(true);
    flush_rewrite_rules();

    return [];
}

function reset_model(): void
{
    delete_option('dc_content_model_active');
    model(true);
    flush_rewrite_rules();
}

/**
 * @return array<string, array<string, mixed>>
 */
function post_types(): array
{
    $model = model();
    return is_array($model['postTypes'] ?? null) ? $model['postTypes'] : [];
}

/**
 * @return array<string, array<string, mixed>>
 */
function taxonomies(): array
{
    $model = model();
    return is_array($model['taxonomies'] ?? null) ? $model['taxonomies'] : [];
}

/**
 * @return array<string, mixed>
 */
function routes(): array
{
    $model = model();
    return is_array($model['routes'] ?? null) ? $model['routes'] : [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function field_groups(): array
{
    $model = model();
    return is_array($model['fieldGroups'] ?? null) ? $model['fieldGroups'] : [];
}

/**
 * @return array<string, mixed>
 */
function graphql(): array
{
    $model = model();
    return is_array($model['graphql'] ?? null) ? $model['graphql'] : [];
}

/**
 * @param array<string, mixed> $model
 * @return array<string, mixed>
 */
function normalize_model(array $model): array
{
    $model['version'] = (int) ($model['version'] ?? 1);
    $model['postTypes'] = is_array($model['postTypes'] ?? null) ? $model['postTypes'] : [];
    $model['taxonomies'] = is_array($model['taxonomies'] ?? null) ? $model['taxonomies'] : [];
    $model['fieldGroups'] = is_array($model['fieldGroups'] ?? null) ? $model['fieldGroups'] : [];
    $model['graphql'] = is_array($model['graphql'] ?? null) ? $model['graphql'] : [];
    $model['routes'] = is_array($model['routes'] ?? null) ? $model['routes'] : [];

    foreach ($model['postTypes'] as $key => $args) {
        if (!is_array($args)) {
            $model['postTypes'][$key] = [];
            $args = [];
        }

        $model['postTypes'][$key] = array_merge([
            'public'          => true,
            'show_in_rest'    => false,
            'show_in_graphql' => true,
            'supports'        => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
            'map_meta_cap'    => true,
        ], $args);
    }

    foreach ($model['taxonomies'] as $key => $taxonomy) {
        if (!is_array($taxonomy)) {
            $model['taxonomies'][$key] = ['object_type' => [], 'args' => []];
            continue;
        }

        $taxonomy['object_type'] = is_array($taxonomy['object_type'] ?? null) ? $taxonomy['object_type'] : [];
        $taxonomy['args'] = is_array($taxonomy['args'] ?? null) ? $taxonomy['args'] : [];
        $taxonomy['args'] = array_merge([
            'public'          => true,
            'hierarchical'    => false,
            'show_in_rest'    => false,
            'show_in_graphql' => true,
        ], $taxonomy['args']);

        $model['taxonomies'][$key] = $taxonomy;
    }

    $model['routes'] = array_merge([
        'headlessPostTypes' => [],
        'templates'         => [],
        'listRoutes'        => [],
        'revalidateAlways'  => ['/'],
    ], $model['routes']);

    $model['graphql'] = array_merge([
        'sharedPostTypes' => [],
        'fields'          => [],
        'postTypeFields'  => [],
    ], $model['graphql']);

    return $model;
}

/**
 * @param array<string, mixed> $model
 * @return array<int, string>
 */
function validate_model(array $model): array
{
    $errors = [];

    if (($model['version'] ?? null) !== 1) {
        $errors[] = 'Only content model version 1 is supported.';
    }

    foreach (array_keys($model['postTypes'] ?? []) as $post_type) {
        if (!is_valid_wp_key((string) $post_type)) {
            $errors[] = "Invalid post type key: {$post_type}";
        }
    }

    foreach (($model['postTypes'] ?? []) as $post_type => $args) {
        foreach (['graphql_single_name', 'graphql_plural_name'] as $graphql_key) {
            if (isset($args[$graphql_key]) && !is_valid_graphql_name((string) $args[$graphql_key])) {
                $errors[] = "Invalid {$graphql_key} for post type {$post_type}.";
            }
        }
    }

    foreach (($model['taxonomies'] ?? []) as $taxonomy => $config) {
        if (!is_valid_wp_key((string) $taxonomy)) {
            $errors[] = "Invalid taxonomy key: {$taxonomy}";
        }

        $args = is_array($config['args'] ?? null) ? $config['args'] : [];
        foreach (['graphql_single_name', 'graphql_plural_name'] as $graphql_key) {
            if (isset($args[$graphql_key]) && !is_valid_graphql_name((string) $args[$graphql_key])) {
                $errors[] = "Invalid {$graphql_key} for taxonomy {$taxonomy}.";
            }
        }
    }

    foreach (($model['fieldGroups'] ?? []) as $index => $group) {
        if (!is_array($group)) {
            $errors[] = "Field group {$index} must be an object.";
            continue;
        }

        $post_type = (string) ($group['postType'] ?? '');
        if ($post_type === '' || !is_valid_wp_key($post_type)) {
            $errors[] = "Field group {$index} has an invalid postType.";
        }

        $tabs = is_array($group['tabs'] ?? null) ? $group['tabs'] : [];
        foreach ($tabs as $tab_index => $tab) {
            if (!is_array($tab)) {
                $errors[] = "Field group {$index} tab {$tab_index} must be an object.";
                continue;
            }

            $fields = is_array($tab['fields'] ?? null) ? $tab['fields'] : [];
            foreach ($fields as $field_index => $field) {
                if (!is_array($field)) {
                    $errors[] = "Field group {$index} field {$field_index} must be an object.";
                    continue;
                }

                $type = (string) ($field['type'] ?? '');
                if (!in_array($type, allowed_field_types(), true)) {
                    $errors[] = "Unsupported field type '{$type}' in field group {$index}.";
                }

                $key = (string) ($field['key'] ?? '');
                if ($key === '' || !is_valid_meta_key($key)) {
                    $errors[] = "Invalid field key '{$key}' in field group {$index}.";
                }

                if ($type === 'preset') {
                    $preset = (string) ($field['preset'] ?? '');
                    if (!in_array($preset, allowed_field_presets(), true)) {
                        $errors[] = "Unsupported field preset '{$preset}' in field group {$index}.";
                    }
                }

                if ($type === 'complex') {
                    $sub_fields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
                    if ($sub_fields === []) {
                        $errors[] = "Complex field '{$key}' in field group {$index} needs sub-fields.";
                    }
                    foreach ($sub_fields as $sub) {
                        if (!is_array($sub)) {
                            $errors[] = "Complex field '{$key}' has a malformed sub-field.";
                            continue;
                        }
                        $sub_type = (string) ($sub['type'] ?? '');
                        // Complex rows can't nest complex/preset/association.
                        if (!in_array($sub_type, ['text', 'textarea', 'rich_text', 'image', 'checkbox', 'select', 'date', 'time', 'number', 'radio', 'color'], true)) {
                            $errors[] = "Unsupported sub-field type '{$sub_type}' in complex field '{$key}'.";
                        }
                        $sub_key = (string) ($sub['key'] ?? '');
                        if ($sub_key === '' || !is_valid_meta_key($sub_key)) {
                            $errors[] = "Invalid sub-field key '{$sub_key}' in complex field '{$key}'.";
                        }
                    }
                }

                // Options-bearing fields must declare options.
                if (in_array($type, ['select', 'radio', 'multiselect'], true)) {
                    if (!is_array($field['options'] ?? null) || $field['options'] === []) {
                        $errors[] = "Field '{$key}' of type '{$type}' needs an options map.";
                    }
                }

                // Association fields must declare a valid relation target.
                if ($type === 'association') {
                    $relate = (string) ($field['relate'] ?? 'post');
                    if (!in_array($relate, ['post', 'term', 'user'], true)) {
                        $errors[] = "Association field '{$key}' has invalid relate '{$relate}'.";
                    }
                    if ($relate === 'post' && empty($field['postTypes'])) {
                        $errors[] = "Association field '{$key}' (relate=post) needs postTypes.";
                    }
                    if ($relate === 'term' && empty($field['taxonomies'])) {
                        $errors[] = "Association field '{$key}' (relate=term) needs taxonomies.";
                    }
                }
            }
        }
    }

    $graphql = is_array($model['graphql'] ?? null) ? $model['graphql'] : [];
    $graphql_fields = is_array($graphql['fields'] ?? null) ? $graphql['fields'] : [];
    foreach ($graphql_fields as $field_name => $field) {
        $errors = array_merge($errors, validate_graphql_field((string) $field_name, $field));
    }

    // Per-CPT GraphQL fields — same rules, keyed by GraphQL type name.
    $by_type = is_array($graphql['postTypeFields'] ?? null) ? $graphql['postTypeFields'] : [];
    foreach ($by_type as $graphql_type => $fields) {
        if (!is_valid_graphql_name((string) $graphql_type)) {
            $errors[] = "Invalid GraphQL type name in postTypeFields: {$graphql_type}.";
        }
        if (!is_array($fields)) {
            $errors[] = "postTypeFields[{$graphql_type}] must be an object.";
            continue;
        }
        foreach ($fields as $field_name => $field) {
            $errors = array_merge($errors, validate_graphql_field((string) $field_name, $field));
        }
    }

    $routes = is_array($model['routes'] ?? null) ? $model['routes'] : [];
    foreach (($routes['templates'] ?? []) as $post_type => $template) {
        if (!is_string($template) || !str_starts_with($template, '/')) {
            $errors[] = "Route template for {$post_type} must start with '/'.";
            continue;
        }

        foreach (route_tokens($template) as $token) {
            if (!in_array($token, allowed_route_tokens(), true)) {
                $errors[] = "Unsupported route token {{$token}} for {$post_type}.";
            }
        }
    }

    return $errors;
}

/**
 * Validate one GraphQL field config (shared or per-CPT). Returns any
 * error strings; empty array means valid.
 *
 * @param mixed $field
 * @return array<int, string>
 */
function validate_graphql_field(string $field_name, $field): array
{
    $errors = [];

    if (!is_valid_graphql_name($field_name)) {
        $errors[] = "Invalid GraphQL field name: {$field_name}.";
    }

    if (!is_array($field)) {
        $errors[] = "GraphQL field {$field_name} must be an object.";
        return $errors;
    }

    $resolver = (string) ($field['resolver'] ?? '');
    if (!in_array($resolver, allowed_graphql_resolvers(), true)) {
        $errors[] = "Unsupported GraphQL resolver '{$resolver}' for {$field_name}.";
    }

    // Meta-key resolvers must name a valid meta key to read.
    $meta_key_resolvers = [
        'meta', 'metaComplexJson', 'metaInt', 'metaBool', 'metaList',
        'metaIntList', 'metaStringList', 'rawMeta', 'relatedPosts', 'variantMap',
    ];
    if (in_array($resolver, $meta_key_resolvers, true)) {
        $meta_key = (string) ($field['key'] ?? '');
        if ($meta_key === '' || !is_valid_meta_key($meta_key)) {
            $errors[] = "GraphQL field '{$field_name}' resolver '{$resolver}' needs a valid meta key.";
        }
    }

    // Sub-key resolvers (pluck one subfield from each complex row).
    if (in_array($resolver, ['metaIntList', 'metaStringList'], true)) {
        $sub = (string) ($field['subKey'] ?? '');
        if ($sub === '' || !is_valid_meta_key($sub)) {
            $errors[] = "GraphQL field '{$field_name}' resolver '{$resolver}' needs a valid subKey.";
        }
    }

    // The terms resolver names a taxonomy instead of a meta key.
    if ($resolver === 'terms') {
        $taxonomy = (string) ($field['taxonomy'] ?? '');
        if ($taxonomy === '' || !is_valid_wp_key($taxonomy)) {
            $errors[] = "GraphQL field '{$field_name}' resolver 'terms' needs a valid taxonomy.";
        }
    }

    // variantMap needs a variants map; each slot spec is a subKey string
    // or an object with a valid `as`.
    if ($resolver === 'variantMap') {
        $variants = is_array($field['variants'] ?? null) ? $field['variants'] : [];
        if ($variants === []) {
            $errors[] = "GraphQL field '{$field_name}' resolver 'variantMap' needs a variants map.";
        }
        $allowed_slots = ['kind', 'html', 'text', 'heading', 'value', 'buttonLabel', 'buttonUrl', 'code', 'caption', 'images', 'items', 'data'];
        $allowed_as = ['scalar', 'html', 'stringList', 'imageList'];
        foreach ($variants as $kind => $slots) {
            if (!is_array($slots)) {
                $errors[] = "variantMap '{$field_name}' variant '{$kind}' must be an object.";
                continue;
            }
            foreach ($slots as $slot => $spec) {
                if (!in_array((string) $slot, $allowed_slots, true)) {
                    $errors[] = "variantMap '{$field_name}' uses unknown slot '{$slot}' (allowed: " . implode(', ', $allowed_slots) . ").";
                }
                if (is_array($spec) && !in_array((string) ($spec['as'] ?? 'scalar'), $allowed_as, true)) {
                    $errors[] = "variantMap '{$field_name}' slot '{$slot}' has invalid 'as' value.";
                }
            }
        }
    }

    return $errors;
}

function is_valid_wp_key(string $key): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key);
}

function is_valid_meta_key(string $key): bool
{
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,190}$/', $key);
}

function is_valid_graphql_name(string $name): bool
{
    return (bool) preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/', $name);
}

/**
 * @return array<int, string>
 */
function allowed_route_tokens(): array
{
    return ['id', 'slug', 'uri', 'post_type', 'year', 'month', 'day'];
}

/**
 * @return array<int, string>
 */
function allowed_field_types(): array
{
    return [
        'text', 'textarea', 'rich_text', 'image', 'file', 'checkbox', 'select',
        'date', 'time', 'date_time', 'number', 'radio', 'multiselect', 'color',
        'complex', 'association', 'preset',
    ];
}

/**
 * @return array<int, string>
 */
function allowed_field_presets(): array
{
    return ['introParagraphs', 'components', 'galleryImages'];
}

/**
 * @return array<int, string>
 */
function allowed_graphql_resolvers(): array
{
    return [
        'bodyHtml', 'heroImage', 'introParagraphs', 'components', 'galleryImages',
        'metaDescription', 'catalogSlug',
        // Generic resolvers — expose any whitelisted meta key as a typed
        // field. `meta` = scalar passthrough; `metaComplexJson` = a
        // Carbon complex value flattened to a JSON-encoded [String];
        // metaInt/metaBool/metaList = typed scalars + set lists;
        // `terms` = a taxonomy's terms ([DcTerm]); `relatedPosts` =
        // an association value ([DcRef]).
        'meta', 'metaComplexJson', 'metaInt', 'metaBool', 'metaList',
        'metaIntList', 'metaStringList', 'rawMeta',
        'terms', 'relatedPosts',
        // The kind-discriminated complex normalizer (body blocks /
        // components / page sections) — config declares the discriminator
        // variants + per-kind slot map; output is [DcBlock].
        'variantMap',
    ];
}

/**
 * @return array<int, string>
 */
function route_tokens(string $template): array
{
    preg_match_all('/\{([a-z_]+)\}/', $template, $matches);
    return $matches[1] ?? [];
}

/**
 * @param array<int, string> $errors
 */
function set_validation_errors(array $errors): void
{
    $GLOBALS['dc_core_content_model_errors'] = $errors;
}

/**
 * @return array<int, string>
 */
function validation_errors(): array
{
    return $GLOBALS['dc_core_content_model_errors'] ?? [];
}
