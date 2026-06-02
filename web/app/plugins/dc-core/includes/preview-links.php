<?php
/**
 * Headless preview + permalink rewrites.
 *
 * The public site is the decoupled Astro frontend, so the admin's
 * "View" / "Preview" buttons should open the Astro page for a record,
 * not WordPress's own theme.
 *
 *   - "View" / permalink   → the public Astro URL for the record
 *   - "Preview" (drafts)   → Astro's /api/preview route, which pulls
 *                            the draft over WPGraphQL and renders it
 *
 * This starter assumes a SLUG-BASED routing convention on the frontend:
 * a `page` lives at `/<slug>/` and a `dc_resource` at
 * `/resources/<slug>/`. Projects with fixed per-section Astro routes
 * (e.g. /about/mission/ rather than /mission/) should replace
 * `frontend_path_for()` with an explicit slug→path map.
 *
 * Config:
 *   - DC_FRONTEND_URL          frontend origin (define or env var)
 *   - WORDPRESS_PREVIEW_SECRET    shared secret the Astro /api/preview
 *                                 route verifies
 */

namespace Dc\Core\PreviewLinks;

use Dc\Core\Routing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend origin. Delegates to FrontendConnect\resolve_frontend_url
 * so the connect-time stored URL (real Netlify host) wins over the
 * provisioner-placeholder DC_FRONTEND_URL constant. Without this
 * indirection, View links on a connected tenant would route back to
 * the WP host instead of the public Astro frontend.
 */
function frontend_url(): string
{
    return \Dc\Core\FrontendConnect\resolve_frontend_url();
}

/**
 * Public Astro URL for a record, or null if the record's post type is
 * not headless-routed (then the WP permalink is left untouched).
 */
function frontend_url_for(\WP_Post $post): ?string
{
    $base = frontend_url();
    $path = Routing\frontend_path_for_post($post);
    return $path ? $base . $path : null;
}

// ─── "View" / permalink rewrite ──────────────────────────────

add_filter('post_type_link', __NAMESPACE__ . '\\filter_permalink', 10, 2);
add_filter('post_link', __NAMESPACE__ . '\\filter_permalink', 10, 2);
add_filter('page_link', __NAMESPACE__ . '\\filter_page_permalink', 10, 2);

/**
 * Rewrite a CPT / post permalink to the Astro frontend.
 */
function filter_permalink($url, $post)
{
    if (!$post instanceof \WP_Post || !Routing\is_headless_type($post->post_type)) {
        return $url;
    }
    return frontend_url_for($post) ?? $url;
}

/**
 * `page_link` passes a post ID rather than a WP_Post object — resolve
 * it before rewriting.
 */
function filter_page_permalink($url, $post_id)
{
    $post = get_post($post_id);
    if (!$post || !Routing\is_headless_type($post->post_type)) {
        return $url;
    }
    return frontend_url_for($post) ?? $url;
}

// ─── "Preview" rewrite (drafts) ──────────────────────────────
//
// The Preview button links to the Astro /api/preview endpoint with a
// shared secret + post ID. Astro verifies the secret, then fetches and
// renders the draft over WPGraphQL. The shared secret comes from the
// WORDPRESS_PREVIEW_SECRET constant (wp-config.php) or env var.

/**
 * Shared preview secret, with a define → env var → dev fallback chain.
 * Override the dev fallback before going to production.
 */
function preview_secret(): string
{
    if (defined('WORDPRESS_PREVIEW_SECRET')) {
        return WORDPRESS_PREVIEW_SECRET;
    }
    return getenv('WORDPRESS_PREVIEW_SECRET') ?: 'dc_preview_dev_secret';
}

add_filter('preview_post_link', __NAMESPACE__ . '\\rewrite_preview_link', 10, 2);

/**
 * Rewrite the draft "Preview" link to the Astro /api/preview route.
 */
function rewrite_preview_link(string $url, \WP_Post $post): string
{
    if (!Routing\is_headless_type($post->post_type)) {
        return $url;
    }
    return sprintf(
        '%s/api/preview/?secret=%s&id=%d',
        frontend_url(),
        rawurlencode(preview_secret()),
        (int) $post->ID,
    );
}
