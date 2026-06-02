<?php
/**
 * Headless-mode redirect.
 *
 * The WordPress install is the editorial backend only — the public site
 * is the Astro frontend at DC_FRONTEND_URL. Anyone who lands on the
 * WP frontend (the bare site URL, a permalink that wasn't already
 * rewritten, etc.) gets bounced to the matching Astro path so they
 * never see the default WP theme by accident.
 *
 * Excluded: /wp-admin, /wp-login.php, /wp-cron.php, /wp/* (Bedrock core
 * path), /wp-json/* (REST), /graphql (WPGraphQL — the frontend reads
 * from this), and /app/uploads/* (media the frontend hot-links).
 *
 * 302 (not 301) so a future DC_FRONTEND_URL change isn't cached
 * forever by upstream proxies.
 *
 * Unwired-tenant fallback: when no real DC_FRONTEND_URL is configured
 * (unset, or set to the tenant's own URL as a placeholder by the
 * provisioner), public front-end hits would otherwise render as a
 * blank page — Bedrock's web/index.php sets WP_USE_THEMES=false on
 * the assumption that some external frontend handles the actual
 * rendering. To avoid that "blank page" footgun, an unwired tenant
 * redirects public hits to /wp/wp-login.php so the operator lands
 * somewhere useful instead of a WSOD.
 */

namespace Dc\Core\HeadlessRedirect;

if (!defined('ABSPATH')) {
    exit;
}

// Bedrock sets WP_USE_THEMES=false in web/index.php, which makes WP's
// template-loader.php skip the `template_redirect` action entirely
// (see wp-includes/template-loader.php: `if (wp_using_themes())`).
// So we hook the `wp` action — fires right after the main query is set
// up, before template-loader runs, and ALWAYS fires regardless of
// WP_USE_THEMES.
add_action('wp', __NAMESPACE__ . '\\maybe_redirect', 1);

/**
 * If the current request is a public WP front-end hit, bounce it to the
 * matching path on the Astro frontend — or, if no real frontend is
 * wired, to /wp/wp-login.php so the visitor doesn't see a blank page.
 */
function maybe_redirect(): void
{
    if (!is_public_frontend_hit()) {
        return;
    }

    $frontend = frontend_url();
    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

    // No real frontend configured (DC_FRONTEND_URL unset, empty, or
    // pointing at the tenant's own host as a provisioner placeholder).
    // Bedrock's WP_USE_THEMES=false would render this as an empty 200
    // (WSOD), so bounce to login instead.
    if (!$frontend || is_same_host($frontend)) {
        $login = wp_login_url();
        nocache_headers();
        wp_safe_redirect($login, 302, 'Dc-Headless-Unwired');
        exit;
    }

    $target = rtrim($frontend, '/') . $request_uri;
    nocache_headers();
    redirect_off_host($target);
    exit;
}

/**
 * Frontend origin from a define or env var. Returns null when neither
 * is set — in that case nothing is redirected (fail-safe).
 */
function frontend_url(): ?string
{
    if (defined('DC_FRONTEND_URL')) {
        return DC_FRONTEND_URL;
    }
    return getenv('DC_FRONTEND_URL') ?: null;
}

/**
 * Whether this request is a public front-end hit that needs handling
 * (either bounce to frontend, or bounce to login if no frontend wired).
 *
 * False for WP-internal paths (/wp-admin, /wp-login.php, /wp-json, etc.)
 * and for AJAX / REST / GraphQL / cron requests.
 */
function is_public_frontend_hit(): bool
{
    if (is_admin())                 return false;
    if (wp_doing_ajax())            return false;
    if (wp_doing_cron())            return false;
    if (defined('REST_REQUEST'))    return false;
    if (defined('GRAPHQL_REQUEST')) return false;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $allowed_prefixes = [
        '/wp/',
        '/wp-admin',
        '/wp-login.php',
        '/wp-cron.php',
        '/wp-json',
        '/graphql',
        '/app/uploads',
    ];
    foreach ($allowed_prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return false;
        }
    }

    return true;
}

/**
 * Whether the given URL points at the same host the request came in on.
 * Used to detect provisioner-placeholder DC_FRONTEND_URL values (which
 * point at the tenant itself to defeat the redirect's infinite-loop
 * guard while still leaving the constant defined).
 */
function is_same_host(string $url): bool
{
    $current_host  = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $frontend_host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $frontend_host !== '' && $frontend_host === $current_host;
}

/**
 * wp_safe_redirect() blocks off-host targets; the frontend runs on a
 * different hostname, so whitelist that host for the duration of this
 * one redirect.
 */
function redirect_off_host(string $url): void
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return;
    }

    $filter = function (array $hosts) use ($host): array {
        $hosts[] = $host;
        return $hosts;
    };
    add_filter('allowed_redirect_hosts', $filter);
    wp_safe_redirect($url, 302, 'Dc-Headless');
    remove_filter('allowed_redirect_hosts', $filter);
}
