<?php
/**
 * On-demand revalidation.
 *
 * When a post is saved / updated / deleted in WordPress, fire a POST to
 * the Astro frontend's revalidate endpoint with the path to invalidate.
 * A Vercel deployment honors the `x-prerender-revalidate` header with
 * the bypass token and re-renders the page on the next request; a
 * self-hosted Astro frontend can read the same header in its own
 * /api/revalidate handler.
 *
 * Fires on:
 *   - save_post (publish)
 *   - trashed_post
 *   - delete_post
 *
 * Skips:
 *   - Drafts (status != publish) — they only show via preview-links.php
 *   - Autosaves and revisions
 *
 * Config:
 *   - SPARK_FRONTEND_URL              frontend origin
 *   - SPARK_REVALIDATE_BYPASS_TOKEN   shared bypass token
 */

namespace Spark\Core\Revalidate;

use Spark\Core\Routing;

if (!defined('ABSPATH')) {
    exit;
}

add_action('save_post', __NAMESPACE__ . '\\on_save_post', 10, 3);
add_action('trashed_post', __NAMESPACE__ . '\\on_trash_or_delete');
add_action('delete_post', __NAMESPACE__ . '\\on_trash_or_delete');

/**
 * save_post handler — revalidate only for published, non-revision posts.
 */
function on_save_post(int $post_id, \WP_Post $post, bool $update): void
{
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if ($post->post_status !== 'publish') {
        return;
    }
    revalidate_for_post($post);
}

/**
 * trash / delete handler — revalidate so the frontend drops the page.
 */
function on_trash_or_delete(int $post_id): void
{
    $post = get_post($post_id);
    if (!$post) {
        return;
    }
    revalidate_for_post($post);
}

/**
 * Revalidate every frontend path affected by a change to $post.
 */
function revalidate_for_post(\WP_Post $post): void
{
    foreach (paths_for_post($post) as $path) {
        revalidate_path($path);
    }
}

/**
 * Frontend paths a change to $post should invalidate. The homepage is
 * almost always affected; the rest follow the slug-based routing
 * convention from includes/preview-links.php. Adjust per project.
 *
 * @return array<int, string>
 */
function paths_for_post(\WP_Post $post): array
{
    $paths = Routing\revalidate_always_paths();

    foreach (Routing\list_paths_for_post_type($post->post_type) as $path) {
        $paths[] = $path;
    }

    $detail_path = Routing\frontend_path_for_post($post);
    if ($detail_path) {
        $paths[] = $detail_path;
    }

    return array_unique($paths);
}

/**
 * Fire a non-blocking revalidation POST for one path.
 */
function revalidate_path(string $path): void
{
    $frontend = defined('SPARK_FRONTEND_URL')
        ? SPARK_FRONTEND_URL
        : (getenv('SPARK_FRONTEND_URL') ?: 'http://localhost:4321');
    $frontend = rtrim($frontend, '/');

    $token = defined('SPARK_REVALIDATE_BYPASS_TOKEN')
        ? SPARK_REVALIDATE_BYPASS_TOKEN
        : (getenv('SPARK_REVALIDATE_BYPASS_TOKEN') ?: '');

    $url = $frontend . $path;

    // Non-blocking: the save shouldn't wait on the frontend rebuild.
    wp_remote_post($url, [
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => [
            'x-prerender-revalidate' => $token,
            'Content-Type'           => 'application/json',
        ],
        'body'     => wp_json_encode(['source' => 'wp-revalidate']),
    ]);
}
