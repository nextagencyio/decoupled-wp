<?php
/**
 * Puck ↔ component mapping.
 *
 * Builds (and stores) the map between Puck component types and the
 * spark-core content-model component types. The WP analogue of
 * dc_puck's component_map. dc_puck auto-detects from Drupal paragraph
 * bundles; we auto-detect from the model's component set
 * (richtext / gallery / cta / embed today), which is fixed by
 * spark_make_components_field() but described here as data so a future
 * model can extend it.
 *
 * Mapping shape:
 *   [ puckType => [
 *       'component_type' => <carbon _type>,
 *       'label'          => <human>,
 *       'fields'         => [ propName => [ 'sub' => <carbon subkey>, 'type' => <text|image|gallery|string> ] ],
 *     ] ]
 *
 * Puck type = PascalCase(component_type). Prop names mirror the Astro
 * Puck config; keep them stable — they are the shared contract.
 */

namespace Spark\Puck\Mapping;

if (!defined('ABSPATH')) {
    exit;
}

const OPTION_KEY    = 'spark_puck_mapping';
const SECTIONS_FIELD = 'spark_components';

/**
 * The Carbon Fields complex field that holds the ordered page sections.
 * Reuses spark-core's components field (the page-builder store).
 */
function sections_field(): string
{
    return (string) (get_option('spark_puck_sections_field') ?: SECTIONS_FIELD);
}

/**
 * Return the active mapping, defaulting (and persisting) from the model
 * when unset. Mirrors dc_puck::getMapping().
 *
 * @return array<string, array<string, mixed>>
 */
function mapping(): array
{
    $stored = get_option(OPTION_KEY, null);
    if (is_array($stored) && $stored !== []) {
        return $stored;
    }
    $default = build_default_mapping();
    update_option(OPTION_KEY, $default, false);
    return $default;
}

/**
 * @param array<string, array<string, mixed>> $map
 */
function set_mapping(array $map): void
{
    update_option(OPTION_KEY, $map, false);
}

/**
 * Auto-detect the default mapping. Today the component set is fixed by
 * spark_make_components_field(); described here as data so load/save and
 * a future model-driven set share one source of truth.
 *
 * @return array<string, array<string, mixed>>
 */
function build_default_mapping(): array
{
    return [
        'RichText' => [
            'component_type' => 'richtext',
            'label'          => 'Rich text',
            'fields'         => [
                'html' => ['sub' => 'html', 'type' => 'text'],
            ],
        ],
        'Gallery' => [
            'component_type' => 'gallery',
            'label'          => 'Image gallery',
            'fields'         => [
                // images is a nested complex of { src(image), alt(text) }.
                'images' => ['sub' => 'images', 'type' => 'gallery'],
            ],
        ],
        'Cta' => [
            'component_type' => 'cta',
            'label'          => 'Call to action',
            'fields'         => [
                'heading'     => ['sub' => 'heading', 'type' => 'string'],
                'text'        => ['sub' => 'text', 'type' => 'text'],
                'buttonLabel' => ['sub' => 'button_label', 'type' => 'string'],
                'buttonUrl'   => ['sub' => 'button_url', 'type' => 'string'],
            ],
        ],
        'Embed' => [
            'component_type' => 'embed',
            'label'          => 'Embed',
            'fields'         => [
                'embedCode' => ['sub' => 'embed_code', 'type' => 'text'],
                'caption'   => ['sub' => 'caption', 'type' => 'string'],
            ],
        ],
    ];
}

/**
 * Reverse map: carbon component_type => [ puckType, fields(by subkey) ].
 * Used by the load transform.
 *
 * @param array<string, array<string, mixed>> $map
 * @return array<string, array<string, mixed>>
 */
function reverse_map(array $map): array
{
    $reverse = [];
    foreach ($map as $puck_type => $config) {
        $by_sub = [];
        foreach (($config['fields'] ?? []) as $prop => $field) {
            $by_sub[(string) $field['sub']] = ['prop' => $prop, 'type' => $field['type']];
        }
        $reverse[(string) $config['component_type']] = [
            'puck_type' => $puck_type,
            'fields'    => $by_sub,
        ];
    }
    return $reverse;
}
