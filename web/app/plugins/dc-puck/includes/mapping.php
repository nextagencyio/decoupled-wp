<?php
/**
 * Puck ↔ component mapping.
 *
 * Builds (and stores) the map between Puck component types and the
 * dc-core content-model component types. The WP analogue of
 * dc_puck's component_map. dc_puck auto-detects from Drupal paragraph
 * bundles; we auto-detect from the model's component set
 * (richtext / gallery / cta / embed today), which is fixed by
 * dc_make_components_field() but described here as data so a future
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

namespace Dc\Puck\Mapping;

if (!defined('ABSPATH')) {
    exit;
}

const OPTION_KEY    = 'dc_puck_mapping';
const SECTIONS_FIELD = 'dc_components';

/**
 * The Carbon Fields complex field that holds the ordered page sections.
 * Reuses dc-core's components field (the page-builder store).
 */
function sections_field(): string
{
    return (string) (get_option('dc_puck_sections_field') ?: SECTIONS_FIELD);
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
 * dc_make_components_field(); described here as data so load/save and
 * a future model-driven set share one source of truth.
 *
 * @return array<string, array<string, mixed>>
 */
function build_default_mapping(): array
{
    // PuckType => kind (Carbon _type). The transform is generic — it
    // normalizes props via dc_normalize_row and back — so per-field
    // config is no longer required; this map is the discriminator
    // (PuckType name ↔ Carbon component kind) plus a human label. The
    // PuckType names must match the Astro renderer's switch + Puck
    // component config exactly (the shared contract).
    $kinds = [
        'RichText'       => 'richtext',
        'Gallery'        => 'gallery',
        'Cta'            => 'cta',
        'Embed'          => 'embed',
        'Hero'           => 'hero',
        'TextBlock'      => 'textblock',
        'CardGroup'      => 'cardgroup',
        'SideBySide'     => 'sidebyside',
        'Accordion'      => 'accordion',
        // PuckType is "Testimonials" (the editor config / Astro renderer
        // name); the Carbon kind is "quote". Names must match the Puck
        // component config key exactly or the editor shows
        // "No configuration for …".
        'Testimonials'   => 'quote',
        'Pricing'        => 'pricing',
        'LogoCollection' => 'logocollection',
        'Stats'          => 'stats',
        'Newsletter'     => 'newsletter',
    ];
    $map = [];
    foreach ($kinds as $puck_type => $kind) {
        $map[$puck_type] = [
            'component_type' => $kind,
            'label'          => $puck_type,
        ];
    }
    return $map;
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
