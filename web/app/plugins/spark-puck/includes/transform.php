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
 * Reuses spark-core / media for image handling (idempotent sideload).
 */

namespace Spark\Puck\Transform;

use Spark\Puck\Mapping;

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
    $reverse = Mapping\reverse_map($map);
    $field = Mapping\sections_field();

    $rows = function_exists('carbon_get_post_meta')
        ? carbon_get_post_meta($post_id, $field)
        : [];
    $rows = is_array($rows) ? $rows : [];

    $content = [];
    foreach ($rows as $row) {
        $component = row_to_puck($row, $reverse);
        if ($component !== null) {
            $content[] = $component;
        }
    }

    return [
        'content' => $content,
        'root'    => ['props' => ['title' => get_the_title($post_id)]],
        'zones'   => new \stdClass(),
    ];
}

/**
 * One Carbon row → one Puck component { type, props }.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $reverse
 * @return array<string, mixed>|null
 */
function row_to_puck(array $row, array $reverse): ?array
{
    $kind = (string) ($row['_type'] ?? '');
    if (!isset($reverse[$kind])) {
        return null;
    }
    $puck_type = $reverse[$kind]['puck_type'];
    $fields = $reverse[$kind]['fields'];

    // Stable row id: reuse a persisted one or synthesize a deterministic
    // fallback. A persisted id (written on a prior save) keeps editor
    // identity stable across reorders.
    $row_id = (string) ($row[ROW_ID_SUB] ?? '');
    if ($row_id === '') {
        $row_id = $puck_type . '-' . substr(md5(wp_json_encode($row) ?: $kind), 0, 12);
    }

    $props = ['id' => $row_id, '_sparkRowId' => $row_id];

    foreach ($fields as $sub => $meta) {
        $sub = (string) $sub;
        $prop = $meta['prop'];
        $type = $meta['type'];
        $raw = $row[$sub] ?? null;

        if ($type === 'gallery') {
            $images = [];
            foreach ((is_array($raw) ? $raw : []) as $img) {
                $images[] = [
                    'src' => (string) ($img['src'] ?? ''),
                    'alt' => (string) ($img['alt'] ?? ''),
                ];
            }
            $props[$prop] = $images;
        } else {
            $props[$prop] = is_scalar($raw) ? (string) $raw : '';
        }
    }

    return ['type' => $puck_type, 'props' => $props];
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
        if (!isset($map[$puck_type])) {
            continue; // unknown component type — skip
        }
        $config = $map[$puck_type];
        $kind = (string) $config['component_type'];

        // Resolve / mint the row id (update vs create).
        $row_id = (string) ($props['_sparkRowId'] ?? $props['id'] ?? '');
        if ($row_id === '' || !isset($existing_by_id[$row_id])) {
            $row_id = $puck_type . '-' . wp_generate_password(12, false, false);
        }

        $row = ['_type' => $kind, ROW_ID_SUB => $row_id];

        foreach (($config['fields'] ?? []) as $prop => $fieldcfg) {
            $sub = (string) $fieldcfg['sub'];
            $type = (string) $fieldcfg['type'];
            $value = $props[$prop] ?? '';

            if ($type === 'gallery') {
                $images = [];
                foreach ((is_array($value) ? $value : []) as $img) {
                    if (!is_array($img)) {
                        continue;
                    }
                    $src = (string) ($img['src'] ?? '');
                    $src = maybe_sideload($src, $warnings);
                    if ($src !== '') {
                        $images[] = ['src' => $src, 'alt' => (string) ($img['alt'] ?? '')];
                    }
                }
                $row[$sub] = $images;
            } elseif ($type === 'image') {
                $row[$sub] = maybe_sideload((string) $value, $warnings);
            } else {
                $row[$sub] = is_scalar($value) ? (string) $value : '';
            }
        }

        $out[] = $row;
    }

    carbon_set_post_meta($post_id, $field, $out);
}

/**
 * Image props arrive as URLs. Sideload via spark-core's idempotent
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
    if (function_exists('Spark\\Core\\Content\\import_media')) {
        $table = \Spark\Core\Content\import_media(
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
