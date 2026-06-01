<?php
/**
 * Content importer — orchestrates a full content load.
 *
 * Validate-then-write (fail loud on structural errors). Pipeline:
 *   1. validate envelope against the active model
 *   2. upsert terms          → terms exist for attachment
 *   3. sideload media        → ref => attachment_id
 *   4. upsert posts (pass 1) → ref => post_id  (create/find by slug)
 *   5. posts (pass 2)        → content, terms, parent, fields
 *
 * Idempotent: posts keyed by (postType, slug); re-running updates in
 * place. Media keyed by source-URL hash. Terms by (taxonomy, slug).
 *
 * The WP analogue of Drupal's DrupalContentImporter::importFile().
 */

namespace Dc\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

const POST_REF_META = '_dc_import_ref';

/**
 * Import a parsed envelope. Returns a result with counts + warnings.
 *
 * @param array<string, mixed> $envelope
 * @return array{ok:bool, errors:array<int,string>, warnings:array<int,string>, summary:array<string,mixed>}
 */
function import_envelope(array $envelope): array
{
    $errors = validate_envelope($envelope);
    if ($errors !== []) {
        return [
            'ok'       => false,
            'errors'   => $errors,
            'warnings' => [],
            'summary'  => [],
        ];
    }

    $warnings = [];
    $content = is_array($envelope['content'] ?? null) ? $envelope['content'] : [];
    $terms = is_array($content['terms'] ?? null) ? $content['terms'] : [];
    $media = is_array($content['media'] ?? null) ? $content['media'] : [];
    $posts = is_array($content['posts'] ?? null) ? $content['posts'] : [];

    // 2. terms
    $term_report = import_terms($terms, $warnings);

    // 3. media
    $media_table = import_media($media, $warnings);

    // 4. posts pass 1 — create/find, build ref table
    $post_table = [];
    $created = 0;
    $updated = 0;
    foreach ($posts as $post) {
        if (!is_array($post)) {
            continue;
        }
        [$post_id, $was_created] = upsert_post_shell($post);
        if ($post_id <= 0) {
            $warnings[] = "post '" . (string) ($post['title'] ?? '?') . "': could not create/find.";
            continue;
        }
        $ref = (string) ($post['ref'] ?? '');
        if ($ref !== '') {
            $post_table[$ref] = $post_id;
        }
        $was_created ? $created++ : $updated++;
    }

    // 5. posts pass 2 — content, parent, terms, fields (refs resolve now)
    $index = field_index();
    foreach ($posts as $post) {
        if (!is_array($post)) {
            continue;
        }
        $ref = (string) ($post['ref'] ?? '');
        $post_id = $ref !== '' ? (int) ($post_table[$ref] ?? 0) : find_post_id($post);
        if ($post_id <= 0) {
            continue;
        }

        apply_post_body($post_id, $post, $post_table);

        $post_terms = is_array($post['terms'] ?? null) ? $post['terms'] : [];
        if ($post_terms !== []) {
            attach_post_terms($post_id, $post_terms, $warnings);
        }

        $fields = is_array($post['fields'] ?? null) ? $post['fields'] : [];
        if ($fields !== []) {
            $specs = $index[(string) $post['postType']] ?? [];
            write_fields($post_id, $fields, $specs, $media_table, $post_table, $warnings);
        }
    }

    return [
        'ok'       => true,
        'errors'   => [],
        'warnings' => $warnings,
        'summary'  => [
            'terms'  => $term_report,
            'media'  => ['imported' => count($media_table)],
            'posts'  => ['created' => $created, 'updated' => $updated],
        ],
    ];
}

/**
 * Create or find a post by (postType, slug); set title up front so the
 * ref table resolves before bodies/fields are written. Returns
 * [post_id, was_created].
 *
 * @param array<string, mixed> $post
 * @return array{0:int,1:bool}
 */
function upsert_post_shell(array $post): array
{
    $existing = find_post_id($post);
    if ($existing > 0) {
        return [$existing, false];
    }

    $post_id = wp_insert_post([
        'post_type'   => (string) $post['postType'],
        'post_title'  => (string) ($post['title'] ?? ''),
        'post_name'   => (string) ($post['slug'] ?? ''),
        'post_status' => (string) ($post['status'] ?? 'publish'),
    ], true);

    if (is_wp_error($post_id)) {
        return [0, false];
    }

    $ref = (string) ($post['ref'] ?? '');
    if ($ref !== '') {
        update_post_meta((int) $post_id, POST_REF_META, $ref);
    }

    return [(int) $post_id, true];
}

/**
 * Find an existing post id by (postType, slug), else 0.
 * Slug match is exact; title dedup is case-insensitive as a fallback
 * (matches the Drupal importer's case-insensitive title identity).
 *
 * @param array<string, mixed> $post
 */
function find_post_id(array $post): int
{
    $post_type = (string) ($post['postType'] ?? '');
    $slug = (string) ($post['slug'] ?? '');
    $title = (string) ($post['title'] ?? '');

    if ($slug !== '') {
        $found = get_posts([
            'post_type'   => $post_type,
            'name'        => $slug,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        if (is_array($found) && $found) {
            return (int) $found[0];
        }
    }

    if ($title !== '') {
        $found = get_posts([
            'post_type'   => $post_type,
            'post_status' => 'any',
            'numberposts' => 50,
            'fields'      => 'ids',
        ]);
        foreach ((array) $found as $id) {
            if (strcasecmp(get_the_title((int) $id), $title) === 0) {
                return (int) $id;
            }
        }
    }

    return 0;
}

/**
 * Apply body content, excerpt, parent, menu order, status to a post.
 *
 * @param array<string, mixed> $post
 * @param array<string, int> $post_table
 */
function apply_post_body(int $post_id, array $post, array $post_table): void
{
    $update = ['ID' => $post_id];

    if (array_key_exists('content', $post)) {
        $update['post_content'] = (string) $post['content'];
    }
    if (array_key_exists('excerpt', $post)) {
        $update['post_excerpt'] = (string) $post['excerpt'];
    }
    if (array_key_exists('status', $post)) {
        $update['post_status'] = (string) $post['status'];
    }
    if (array_key_exists('menuOrder', $post)) {
        $update['menu_order'] = (int) $post['menuOrder'];
    }

    $parent = $post['parent'] ?? null;
    if (is_string($parent) && $parent !== '') {
        $parent_id = (int) ($post_table[$parent] ?? 0);
        if ($parent_id > 0) {
            $update['post_parent'] = $parent_id;
        }
    } elseif (is_int($parent)) {
        $update['post_parent'] = $parent;
    }

    if (count($update) > 1) {
        wp_update_post($update);
    }
}
