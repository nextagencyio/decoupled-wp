<?php
/**
 * Carbon Fields — structured fields for the example content types.
 *
 * The content-model rule (see includes/fields-body-block.php):
 * ordinary body prose is the standard WordPress editor
 * (`post_content`). Carbon Fields adds only STRUCTURED EXTRAS — a
 * hero image, intro paragraphs, an optional complex-components
 * field, a gallery. Carbon Fields is never used for plain headings
 * and paragraphs.
 *
 * Two containers are registered as worked examples:
 *
 *   1. Default `page` — institutional pages get a hero image and
 *      intro paragraphs. The page body itself is the WYSIWYG editor.
 *   2. `dc_resource` — the example CPT additionally gets the
 *      optional complex-components field (Rich text / Gallery / CTA
 *      / Embed) and a standalone gallery — showing how a content
 *      type that needs components mixed into prose is wired.
 *
 * Each field becomes a structured GraphQL field via
 * includes/graphql-extensions.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Dc\Core\Config;

add_action('carbon_fields_register_fields', function () {
    if (!class_exists('Carbon_Fields\\Container')) {
        return;
    }

    foreach (Config\field_groups() as $group) {
        dc_register_configured_field_group($group);
    }
});

/**
 * Register one configured Carbon Fields post-meta container.
 *
 * @param array<string, mixed> $group
 */
function dc_register_configured_field_group(array $group): void
{
    $post_type = (string) ($group['postType'] ?? '');
    if ($post_type === '') {
        return;
    }

    $container = Container::make(
        'post_meta',
        __((string) ($group['label'] ?? 'Content details'), 'dc-core')
    )->where('post_type', '=', $post_type);

    // Track tab labels so we never register two tabs with the same name on
    // one container. Carbon Fields throws an admin notice ("Tab name
    // duplication for <label>") when that happens — which occurs whenever a
    // model supplies two tabs without an explicit `label` (both fall back to
    // the 'Content' default below) or two tabs that share a label. Dedupe by
    // auto-suffixing collisions ("Content", "Content 2", …).
    $used_tab_labels = [];

    $tabs = is_array($group['tabs'] ?? null) ? $group['tabs'] : [];
    foreach ($tabs as $tab) {
        if (!is_array($tab)) {
            continue;
        }

        $fields = [];
        $field_configs = is_array($tab['fields'] ?? null) ? $tab['fields'] : [];
        foreach ($field_configs as $field_config) {
            if (!is_array($field_config)) {
                continue;
            }

            $field = dc_make_configured_field($field_config);
            if ($field) {
                $fields[] = $field;
            }
        }

        if ($fields !== []) {
            $label = (string) ($tab['label'] ?? 'Content');
            $base = $label;
            $suffix = 2;
            while (isset($used_tab_labels[$label])) {
                $label = $base . ' ' . $suffix;
                $suffix++;
            }
            $used_tab_labels[$label] = true;

            $container->add_tab(__($label, 'dc-core'), $fields);
        }
    }
}

/**
 * @param array<string, mixed> $config
 * @return mixed Carbon Fields field instance, or null when unsupported.
 */
function dc_make_configured_field(array $config)
{
    $type = (string) ($config['type'] ?? '');
    $key = (string) ($config['key'] ?? '');
    $label = __((string) ($config['label'] ?? dc_humanize_field_key($key)), 'dc-core');

    if ($key === '') {
        return null;
    }

    if ($type === 'preset') {
        $field = dc_make_preset_field((string) ($config['preset'] ?? ''), $key);
        return $field ? dc_apply_field_options($field, $config) : null;
    }

    if ($type === 'complex') {
        $field = dc_make_complex_field($key, $label, $config);
        return $field ? dc_apply_field_options($field, $config) : null;
    }

    if ($type === 'association') {
        $field = dc_make_association_field($key, $label, $config);
        return $field ? dc_apply_field_options($field, $config) : null;
    }

    // Map our config type names to Carbon Fields field types. `number`
    // is Carbon's `text` with set_attribute('type','number'); `multiselect`
    // is Carbon's `set` (multi-checkbox) field.
    $carbon_type = match ($type) {
        'text', 'textarea', 'rich_text', 'image', 'checkbox', 'select', 'date', 'time', 'date_time', 'file', 'radio', 'color' => $type,
        'number' => 'text',
        'multiselect' => 'set',
        default => null,
    };

    if (!$carbon_type) {
        return null;
    }

    $field = Field::make($carbon_type, $key, $label);

    // Options-bearing fields: select, radio, multiselect (set).
    if (in_array($carbon_type, ['select', 'radio', 'set'], true) && is_array($config['options'] ?? null)) {
        $field->add_options($config['options']);
    }

    if ($type === 'number') {
        $field->set_attribute('type', 'number');
        if (isset($config['min'])) {
            $field->set_attribute('min', (string) $config['min']);
        }
        if (isset($config['max'])) {
            $field->set_attribute('max', (string) $config['max']);
        }
        if (isset($config['step'])) {
            $field->set_attribute('step', (string) $config['step']);
        }
    }

    return dc_apply_field_options($field, $config);
}

/**
 * Build a generic Complex (repeater) field from config. Each repeater
 * row is composed of simple sub-fields (text / textarea / image / etc.)
 * declared under `fields`. Used for label+url link lists and similar.
 *
 * @param array<string, mixed> $config
 * @return mixed Carbon Fields complex field, or null when malformed.
 */
function dc_make_complex_field(string $key, string $label, array $config)
{
    $sub_configs = is_array($config['fields'] ?? null) ? $config['fields'] : [];
    if ($sub_configs === []) {
        return null;
    }

    $sub_fields = [];
    foreach ($sub_configs as $sub) {
        if (!is_array($sub)) {
            continue;
        }
        $sub_type = (string) ($sub['type'] ?? 'text');
        $carbon_sub = match ($sub_type) {
            'text', 'textarea', 'rich_text', 'image', 'checkbox', 'select', 'date', 'time' => $sub_type,
            default => null,
        };
        $sub_key = (string) ($sub['key'] ?? '');
        if (!$carbon_sub || $sub_key === '') {
            continue;
        }
        $sub_label = __((string) ($sub['label'] ?? dc_humanize_field_key($sub_key)), 'dc-core');
        $sub_field = Field::make($carbon_sub, $sub_key, $sub_label);
        if ($carbon_sub === 'select' && is_array($sub['options'] ?? null)) {
            $sub_field->add_options($sub['options']);
        }
        $sub_fields[] = dc_apply_field_options($sub_field, $sub);
    }

    if ($sub_fields === []) {
        return null;
    }

    $field = Field::make('complex', $key, $label)->add_fields($sub_fields);

    $layout = (string) ($config['layout'] ?? 'tabbed-horizontal');
    if (in_array($layout, ['grid', 'tabbed-horizontal', 'tabbed-vertical'], true)) {
        $field->set_layout($layout);
    }

    return $field;
}

/**
 * @return mixed Carbon Fields field instance, or null when unsupported.
 */
function dc_make_preset_field(string $preset, string $key)
{
    return match ($preset) {
        'introParagraphs' => dc_make_intro_field($key),
        'components'      => dc_make_components_field($key),
        'galleryImages'   => dc_make_gallery_field($key),
        default           => null,
    };
}

/**
 * Build an Association field — relates this post to other posts (or
 * terms / users). Config:
 *   relate:    'post' | 'term' | 'user'   (default 'post')
 *   postTypes: ['ysaqmd_program', ...]    (for post relations)
 *   taxonomies:['audience', ...]          (for term relations)
 *   max:       int                        (0 = unlimited)
 *
 * @param array<string, mixed> $config
 * @return mixed Carbon Fields association field, or null when malformed.
 */
function dc_make_association_field(string $key, string $label, array $config)
{
    $field = Field::make('association', $key, $label);

    $relate = (string) ($config['relate'] ?? 'post');
    $types = [];

    if ($relate === 'term') {
        foreach ((array) ($config['taxonomies'] ?? []) as $tax) {
            $types[] = ['type' => 'term', 'taxonomy' => (string) $tax];
        }
    } elseif ($relate === 'user') {
        $types[] = ['type' => 'user'];
    } else {
        foreach ((array) ($config['postTypes'] ?? ['post']) as $pt) {
            $types[] = ['type' => 'post', 'post_type' => (string) $pt];
        }
    }

    if ($types !== [] && method_exists($field, 'set_types')) {
        $field->set_types($types);
    }
    if (isset($config['max']) && method_exists($field, 'set_max')) {
        $field->set_max((int) $config['max']);
    }

    return $field;
}

/**
 * @param mixed $field Carbon Fields field instance.
 * @param array<string, mixed> $config
 * @return mixed
 */
function dc_apply_field_options($field, array $config)
{
    if (isset($config['value_type']) && method_exists($field, 'set_value_type')) {
        $field->set_value_type((string) $config['value_type']);
    }

    if (isset($config['help_text']) && method_exists($field, 'set_help_text')) {
        $field->set_help_text(__((string) $config['help_text'], 'dc-core'));
    }

    if (isset($config['width']) && method_exists($field, 'set_width')) {
        $field->set_width((int) $config['width']);
    }

    if (isset($config['rows']) && method_exists($field, 'set_rows')) {
        $field->set_rows((int) $config['rows']);
    }

    if (isset($config['storage_format']) && method_exists($field, 'set_storage_format')) {
        $field->set_storage_format((string) $config['storage_format']);
    }

    if (!empty($config['required']) && method_exists($field, 'set_required')) {
        $field->set_required(true);
    }

    if (isset($config['default']) && method_exists($field, 'set_default_value')) {
        $field->set_default_value($config['default']);
    }

    return $field;
}

function dc_humanize_field_key(string $key): string
{
    $label = preg_replace('/^dc_/', '', $key);
    $label = str_replace('_', ' ', (string) $label);
    return ucwords($label);
}
