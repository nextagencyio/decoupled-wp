<?php
/**
 * Model introspection for the content importer.
 *
 * The content importer writes VALUES into the fields the active model
 * defines. To do that safely it needs a flat lookup: for a given post
 * type, what fields exist, what type each is, and (for presets/complex)
 * what sub-shape each row takes. This file derives that lookup from the
 * same model the Carbon Fields containers are built from
 * (Spark\Core\Config\field_groups()), so the importer can never drift
 * from what the editor UI / GraphQL resolvers expect.
 *
 * Nothing here writes anything — it's pure introspection consumed by
 * validator.php and field-writers.php.
 */

namespace Spark\Core\Content;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build a map of postType => [fieldKey => spec] from the active model.
 *
 * Each spec is the raw field config from the model's fieldGroups
 * (type, key, preset, value_type, options, relate, postTypes, etc.),
 * plus a normalized 'writer' hint naming which field-writer strategy
 * handles it. Core types `page` and `post` always appear (possibly with
 * empty field maps) since they're valid import targets even with no
 * custom fields.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function field_index(): array
{
    static $index = null;
    if (is_array($index)) {
        return $index;
    }

    $index = [];

    // Seed core + every modeled post type so they're always valid
    // targets even when they carry no custom fields.
    $index['page'] = [];
    $index['post'] = [];
    foreach (array_keys(Config\post_types()) as $post_type) {
        $index[(string) $post_type] = $index[(string) $post_type] ?? [];
    }

    foreach (Config\field_groups() as $group) {
        if (!is_array($group)) {
            continue;
        }
        $post_type = (string) ($group['postType'] ?? '');
        if ($post_type === '') {
            continue;
        }
        $index[$post_type] = $index[$post_type] ?? [];

        $tabs = is_array($group['tabs'] ?? null) ? $group['tabs'] : [];
        foreach ($tabs as $tab) {
            $fields = is_array($tab['fields'] ?? null) ? $tab['fields'] : [];
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = (string) ($field['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $field['writer'] = writer_for($field);
                $index[$post_type][$key] = $field;
            }
        }
    }

    return $index;
}

/**
 * Refresh the cached index (after a model change in the same request).
 */
function reset_field_index(): void
{
    // The static in field_index() can't be reset directly; callers in
    // long-lived processes (rare for CLI) should re-require. For the
    // import flow the index is read once per command, so this is a
    // no-op placeholder kept for symmetry with Config\model(true).
}

/**
 * Return the field spec for one (postType, fieldKey), or null.
 *
 * @return array<string, mixed>|null
 */
function field_spec(string $post_type, string $key): ?array
{
    $index = field_index();
    return $index[$post_type][$key] ?? null;
}

/**
 * True when the post type is a valid import target (core or modeled).
 */
function is_importable_post_type(string $post_type): bool
{
    return array_key_exists($post_type, field_index());
}

/**
 * Decide which field-writer strategy handles a given model field.
 *
 * Returns one of:
 *   'scalar'          text/textarea/rich_text/number/color/date/time/...
 *   'bool'            checkbox
 *   'select'          select/radio/multiselect (validated against options)
 *   'image'           single image
 *   'file'            single file
 *   'intro'           preset introParagraphs
 *   'gallery'         preset galleryImages
 *   'components'      preset components
 *   'complex'         arbitrary complex field
 *   'association'     post/term/user relation
 *
 * @param array<string, mixed> $field
 */
function writer_for(array $field): string
{
    $type = (string) ($field['type'] ?? '');

    if ($type === 'preset') {
        return match ((string) ($field['preset'] ?? '')) {
            'introParagraphs' => 'intro',
            'galleryImages'   => 'gallery',
            'components'      => 'components',
            default           => 'unsupported',
        };
    }

    return match ($type) {
        'text', 'textarea', 'rich_text', 'number', 'color', 'date', 'time', 'date_time' => 'scalar',
        'checkbox'    => 'bool',
        'select', 'radio', 'multiselect' => 'select',
        'image'       => 'image',
        'file'        => 'file',
        'complex'     => 'complex',
        'association' => 'association',
        default       => 'unsupported',
    };
}
