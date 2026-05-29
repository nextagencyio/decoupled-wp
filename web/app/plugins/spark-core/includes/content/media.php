<?php
/**
 * Idempotent media sideload + ref table.
 *
 * Each media entry in the envelope has a file-local `ref`. We fetch the
 * `sourceUrl` into the WP media library once, tag the attachment with a
 * `_spark_import_source` hash, and on re-import reuse the existing
 * attachment instead of fetching again. Posts reference media by `ref`;
 * the importer resolves ref → attachment_id via the table this builds.
 *
 * Media failures are non-fatal: a broken source URL warns and leaves
 * that ref unresolved (the post imports without the image) rather than
 * aborting the whole content load.
 */

namespace Spark\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

const MEDIA_SOURCE_META = '_spark_import_source';

/**
 * Sideload every media entry, returning [ref => attachment_id] for the
 * ones that succeeded, plus collecting warnings by reference.
 *
 * @param array<int, array<string, mixed>> $media
 * @param array<int, string> $warnings
 * @return array<string, int>
 */
function import_media(array $media, array &$warnings): array
{
    $table = [];

    foreach ($media as $item) {
        if (!is_array($item)) {
            continue;
        }
        $ref = (string) ($item['ref'] ?? '');
        $url = (string) ($item['sourceUrl'] ?? '');
        if ($ref === '' || $url === '') {
            continue;
        }

        $existing = find_existing_attachment($url);
        if ($existing > 0) {
            $table[$ref] = $existing;
            apply_attachment_meta($existing, $item);
            continue;
        }

        $attachment_id = sideload($url, $item, $warnings);
        if ($attachment_id > 0) {
            $table[$ref] = $attachment_id;
        }
    }

    return $table;
}

/**
 * Look up an attachment previously imported from this source URL.
 */
function find_existing_attachment(string $url): int
{
    $hash = source_hash($url);
    $found = get_posts([
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => MEDIA_SOURCE_META,
        'meta_value'  => $hash,
    ]);
    return is_array($found) && $found ? (int) $found[0] : 0;
}

/**
 * Fetch a remote URL into the media library. Returns 0 on failure
 * (warned, non-fatal).
 *
 * @param array<string, mixed> $item
 * @param array<int, string> $warnings
 */
function sideload(string $url, array $item, array &$warnings): int
{
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url, 60);
    if (is_wp_error($tmp)) {
        $warnings[] = "media '{$item['ref']}': download failed ({$url}): " . $tmp->get_error_message();
        return 0;
    }

    $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'media');
    if ($filename === '' || strpos($filename, '.') === false) {
        $filename = 'media-' . substr(source_hash($url), 0, 8) . '.jpg';
    }

    $file_array = ['name' => $filename, 'tmp_name' => $tmp];
    $attachment_id = media_handle_sideload($file_array, 0, (string) ($item['title'] ?? ''));

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        $warnings[] = "media '{$item['ref']}': sideload failed: " . $attachment_id->get_error_message();
        return 0;
    }

    update_post_meta($attachment_id, MEDIA_SOURCE_META, source_hash($url));
    apply_attachment_meta((int) $attachment_id, $item);

    return (int) $attachment_id;
}

/**
 * Apply alt text + title to an attachment (idempotent).
 *
 * @param array<string, mixed> $item
 */
function apply_attachment_meta(int $attachment_id, array $item): void
{
    if (isset($item['alt'])) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $item['alt']));
    }
    if (isset($item['title']) && (string) $item['title'] !== '') {
        wp_update_post([
            'ID'         => $attachment_id,
            'post_title' => sanitize_text_field((string) $item['title']),
        ]);
    }
}

/**
 * Stable hash of a source URL, used as the dedup key.
 */
function source_hash(string $url): string
{
    return hash('sha256', $url);
}

/**
 * Resolve an image/file field value to a usable value for storage.
 *
 * Field value forms:
 *   { "media": "<ref>" }  → resolve via the ref table
 *   { "url": "https..." } → use the URL as-is
 *   "https://..."         → bare URL string
 *
 * Returns either an attachment ID (int) or a URL (string) depending on
 * the field's value_type (default 'url' for Spark image fields), or
 * null when unresolvable.
 *
 * @param mixed $value
 * @param array<string, int> $media_table
 * @param array<string, mixed> $spec
 * @return int|string|null
 */
function resolve_image_value($value, array $media_table, array $spec)
{
    $value_type = (string) ($spec['value_type'] ?? 'url');

    $attachment_id = 0;
    $url = '';

    if (is_string($value)) {
        $url = $value;
    } elseif (is_array($value)) {
        if (isset($value['media'])) {
            $attachment_id = (int) ($media_table[(string) $value['media']] ?? 0);
        }
        if (isset($value['url'])) {
            $url = (string) $value['url'];
        }
    }

    if ($value_type === 'id') {
        return $attachment_id > 0 ? $attachment_id : null;
    }

    // value_type 'url' (Spark default): prefer a resolved attachment's
    // URL, else the literal url.
    if ($attachment_id > 0) {
        $src = wp_get_attachment_url($attachment_id);
        return $src ?: ($url !== '' ? $url : null);
    }
    return $url !== '' ? $url : null;
}
