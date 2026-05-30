<?php
/**
 * Puck ↔ Carbon Fields component transform.
 *
 * load:  carbon_get_post_meta(sections) → Puck { content, root, zones }
 * save:  Puck content[] → carbon_set_post_meta(sections), diffed by
 *        a synthetic _sparkRowId so edits update in place, new rows are
 *        created, and removed rows are dropped — the row-identity
 *        analogue of dc_puck's _drupalUuid matching.
 *
 * Reuses dc-core / media for image handling (idempotent sideload).
 */

namespace Dc\Puck\Transform;

use Dc\Puck\Mapping;

if (!defined('ABSPATH')) {
    exit;
}

const ROW_ID_SUB = '_spark_row_id';

/**
 * Build the Puck data object for a post.
 *
 * @return array<string, mixed>
 */
function load(int $post_id): array
{
    $map = Mapping\mapping();
    $field = Mapping\sections_field();

    $rows = function_exists('carbon_get_post_meta')
        ? carbon_get_post_meta($post_id, $field)
        : [];
    $rows = is_array($rows) ? $rows : [];

    $content = [];
    foreach ($rows as $row) {
        $component = row_to_puck(is_array($row) ? $row : [], $map);
        if ($component !== null) {
            $content[] = $component;
        }
    }

    return [
        'content' => $content,
        'root'    => ['props' => ['title' => post_title_text($post_id)]],
        'zones'   => new \stdClass(),
    ];
}

/**
 * Plain-text post title for the editor. get_the_title() runs the
 * `the_title` filters, which HTML-encode characters like the en-dash
 * (– → &#8211;); the Puck title field is a plain text input, so decode
 * entities back to real characters.
 */
function post_title_text(int $post_id): string
{
    $post = get_post($post_id);
    $raw = $post instanceof \WP_Post ? $post->post_title : (string) get_the_title($post_id);
    return html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * One Carbon row → one Puck component { type, props }.
 *
 * Generic: the row's data is normalized to camelCase props via the same
 * dc-core helper the GraphQL `props` exposure uses (single source of
 * truth — load and read can't drift). Only the discriminator (`_type` →
 * PuckType) and the stable row id are handled specially.
 *
 * @param array<string, mixed> $row
 * @param array<string, array<string, mixed>> $map
 * @return array<string, mixed>|null
 */
function row_to_puck(array $row, array $map): ?array
{
    $kind = (string) ($row['_type'] ?? '');
    if ($kind === '') {
        return null;
    }
    // PuckType name from the mapping; fall back to PascalCase(kind).
    $puck_type = null;
    foreach ($map as $ptype => $config) {
        if ((string) ($config['component_type'] ?? '') === $kind) {
            $puck_type = $ptype;
            break;
        }
    }
    if ($puck_type === null) {
        $puck_type = str_replace(' ', '', ucwords(str_replace('_', ' ', $kind)));
    }

    // Stable row id: reuse the persisted one or synthesize a fallback.
    $row_id = (string) ($row[ROW_ID_SUB] ?? '');
    if ($row_id === '') {
        $row_id = $puck_type . '-' . substr(md5((string) wp_json_encode($row)), 0, 12);
    }

    // Normalize the row exactly as the GraphQL props exposure does.
    $props = function_exists('dc_normalize_row')
        ? dc_normalize_row($row)
        : [];
    // Stats' figures are stored as one-per-line "value | label |
    // description" text; parse into the `stats` array the contract expects.
    if ($kind === 'stats') {
        $props['stats'] = function_exists('dc_lines_to_stats')
            ? dc_lines_to_stats($props['figures'] ?? '')
            : [];
        unset($props['figures']);
    }
    // Pricing features are stored one-per-line; expose as string[].
    if ($kind === 'pricing' && isset($props['tiers']) && is_array($props['tiers'])) {
        foreach ($props['tiers'] as &$tier) {
            if (isset($tier['features']) && is_string($tier['features'])) {
                $tier['features'] = split_lines($tier['features']);
            }
        }
        unset($tier);
    }
    $props['id'] = $row_id;
    $props['_sparkRowId'] = $row_id;

    return ['type' => $puck_type, 'props' => $props];
}

/**
 * Split a one-per-line text value into a trimmed, non-empty list.
 *
 * @return array<int, string>
 */
function split_lines(string $text): array
{
    return array_values(array_filter(
        array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []),
        static fn($s) => $s !== ''
    ));
}

/**
 * camelCase (Puck prop) → snake_case (Carbon subkey).
 */
function snake_key(string $key): string
{
    return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key);
}

/**
 * Recursively convert a Puck props object → a Carbon row data array:
 * snake_case keys, recurse into nested arrays of objects, sideload any
 * value that looks like an image URL field. Drops Puck-only keys
 * (id, _sparkRowId, type).
 *
 * @param array<string, mixed> $props
 * @param array<int, string> $warnings
 * @return array<string, mixed>
 */
function puck_props_to_row(array $props, array &$warnings): array
{
    $out = [];
    foreach ($props as $key => $value) {
        if (in_array($key, ['id', '_sparkRowId', 'type'], true)) {
            continue;
        }
        $sub = snake_key((string) $key);

        if (is_array($value)) {
            // List of objects (nested repeatable) → recurse each row.
            if ($value !== [] && array_is_list($value) && is_array($value[0] ?? null)) {
                $out[$sub] = array_map(
                    fn($r) => is_array($r) ? puck_props_to_row($r, $warnings) : $r,
                    $value
                );
            } else {
                // A plain list (e.g. pricing features string[]) → store
                // one-per-line text for Carbon round-trip safety.
                $out[$sub] = implode("\n", array_map('strval', $value));
            }
        } elseif (is_string($value) && str_starts_with($value, 'http')
            && preg_match('/\.(jpe?g|png|gif|webp|svg|avif)(\?|$)/i', $value)) {
            $out[$sub] = maybe_sideload($value, $warnings);
        } else {
            $out[$sub] = is_scalar($value) ? (string) $value : $value;
        }
    }
    return $out;
}

/**
 * Save a Puck data object back to a post's sections field.
 *
 * @param array<string, mixed> $puck_data
 * @param array<int, string> $warnings
 */
function save(int $post_id, array $puck_data, array &$warnings): void
{
    if (!function_exists('carbon_set_post_meta')) {
        $warnings[] = 'Carbon Fields not loaded; nothing saved.';
        return;
    }

    $map = Mapping\mapping();
    $field = Mapping\sections_field();

    // Existing rows, indexed by their persisted row id, so unchanged
    // rows keep their id (and any data the editor didn't send back).
    $existing = carbon_get_post_meta($post_id, $field);
    $existing = is_array($existing) ? $existing : [];
    $existing_by_id = [];
    foreach ($existing as $row) {
        $rid = (string) ($row[ROW_ID_SUB] ?? '');
        if ($rid !== '') {
            $existing_by_id[$rid] = $row;
        }
    }

    $out = [];
    foreach (($puck_data['content'] ?? []) as $component) {
        if (!is_array($component)) {
            continue;
        }
        $puck_type = (string) ($component['type'] ?? '');
        $props = is_array($component['props'] ?? null) ? $component['props'] : [];

        // PuckType → kind (Carbon _type). Fall back to snake_case(type).
        $kind = isset($map[$puck_type]['component_type'])
            ? (string) $map[$puck_type]['component_type']
            : snake_key($puck_type);
        if ($kind === '') {
            continue;
        }

        // Resolve / mint the row id (update vs create).
        $row_id = (string) ($props['_sparkRowId'] ?? $props['id'] ?? '');
        if ($row_id === '' || !isset($existing_by_id[$row_id])) {
            $row_id = $puck_type . '-' . wp_generate_password(12, false, false);
        }

        // Stats' figures are stored as one-per-line "value | label |
        // description" text (Carbon can't persist this 6th nested complex
        // programmatically); encode the incoming `stats` array into the
        // `figures` textarea.
        if ($kind === 'stats') {
            $props['figures'] = function_exists('dc_stats_to_lines')
                ? dc_stats_to_lines($props['stats'] ?? [])
                : '';
            unset($props['stats']);
        }

        // Generic prop → Carbon row conversion (handles nested
        // repeatables + image sideload). Pricing features arrive as a
        // string[] and are stored one-per-line by puck_props_to_row.
        $row = puck_props_to_row($props, $warnings);
        $row['_type'] = $kind;
        $row[ROW_ID_SUB] = $row_id;

        $out[] = $row;
    }

    carbon_set_post_meta($post_id, $field, $out);
}

/**
 * Image props arrive as URLs. Sideload via dc-core's idempotent
 * media importer when available (hash-dedup), returning a stable URL.
 * Skips obviously-fake URLs (matches dc_puck's guard).
 *
 * @param array<int, string> $warnings
 */
function maybe_sideload(string $url, array &$warnings): string
{
    if ($url === '' || !str_starts_with($url, 'http')) {
        return '';
    }
    if (str_contains($url, 'example.com') || str_contains($url, 'placeholder')) {
        return '';
    }
    if (function_exists('Dc\Core\\Content\\import_media')) {
        $table = \Dc\Core\Content\import_media(
            [['ref' => 'puck', 'sourceUrl' => $url]],
            $warnings
        );
        $att = (int) ($table['puck'] ?? 0);
        if ($att > 0) {
            $src = wp_get_attachment_url($att);
            if (is_string($src) && $src !== '') {
                return $src;
            }
        }
    }
    // Fall back to the literal URL (value_type 'url' fields accept it).
    return $url;
}
