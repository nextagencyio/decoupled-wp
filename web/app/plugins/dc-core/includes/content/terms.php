<?php
/**
 * Idempotent taxonomy term upsert.
 *
 * Terms are created/found first so posts can attach them by slug.
 * Identity is (taxonomy, slug): an existing term is reused (and its
 * name/description updated), never duplicated. Parent terms are
 * resolved within the same envelope by slug.
 */

namespace Dc\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Upsert every term entry. Returns a report with created/updated counts.
 *
 * @param array<int, array<string, mixed>> $terms
 * @param array<int, string> $warnings
 * @return array{created:int, updated:int}
 */
function import_terms(array $terms, array &$warnings): array
{
    $created = 0;
    $updated = 0;

    // Two passes so a child can reference a parent declared later: first
    // ensure every (taxonomy, slug) exists, then set parents.
    $ids = []; // "taxonomy/slug" => term_id

    foreach ($terms as $term) {
        if (!is_array($term)) {
            continue;
        }
        $taxonomy = (string) ($term['taxonomy'] ?? '');
        $name = (string) ($term['name'] ?? '');
        $slug = (string) ($term['slug'] ?? '');
        if ($taxonomy === '' || ($name === '' && $slug === '')) {
            continue;
        }
        if ($slug === '') {
            $slug = sanitize_title($name);
        }
        if ($name === '') {
            $name = $slug;
        }

        if (!taxonomy_exists($taxonomy)) {
            $warnings[] = "term '{$slug}': taxonomy '{$taxonomy}' is not registered (model not applied?).";
            continue;
        }

        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof \WP_Term) {
            $term_id = (int) $existing->term_id;
            wp_update_term($term_id, $taxonomy, [
                'name'        => $name,
                'description' => (string) ($term['description'] ?? $existing->description),
            ]);
            $updated++;
        } else {
            $res = wp_insert_term($name, $taxonomy, [
                'slug'        => $slug,
                'description' => (string) ($term['description'] ?? ''),
            ]);
            if (is_wp_error($res)) {
                $warnings[] = "term '{$slug}': insert failed: " . $res->get_error_message();
                continue;
            }
            $term_id = (int) $res['term_id'];
            $created++;
        }

        $ids["{$taxonomy}/{$slug}"] = $term_id;
    }

    // Second pass: parents.
    foreach ($terms as $term) {
        if (!is_array($term)) {
            continue;
        }
        $taxonomy = (string) ($term['taxonomy'] ?? '');
        $slug = (string) ($term['slug'] ?? '') ?: sanitize_title((string) ($term['name'] ?? ''));
        $parent_slug = (string) ($term['parent'] ?? '');
        if ($taxonomy === '' || $slug === '' || $parent_slug === '') {
            continue;
        }
        $child = $ids["{$taxonomy}/{$slug}"] ?? 0;
        $parent = $ids["{$taxonomy}/{$parent_slug}"] ?? 0;
        if ($child > 0 && $parent > 0) {
            wp_update_term($child, $taxonomy, ['parent' => $parent]);
        }
    }

    return ['created' => $created, 'updated' => $updated];
}

/**
 * Attach a post's terms (replacing existing for each named taxonomy).
 *
 * @param array<string, array<int, string>> $post_terms taxonomy => [slugs]
 * @param array<int, string> $warnings
 */
function attach_post_terms(int $post_id, array $post_terms, array &$warnings): void
{
    foreach ($post_terms as $taxonomy => $slugs) {
        $taxonomy = (string) $taxonomy;
        if (!taxonomy_exists($taxonomy) || !is_array($slugs)) {
            continue;
        }
        $term_ids = [];
        foreach ($slugs as $slug) {
            $t = get_term_by('slug', (string) $slug, $taxonomy);
            if ($t instanceof \WP_Term) {
                $term_ids[] = (int) $t->term_id;
            } else {
                $warnings[] = "post {$post_id}: term '{$slug}' not found in '{$taxonomy}' (declare it in content.terms).";
            }
        }
        wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
    }
}
