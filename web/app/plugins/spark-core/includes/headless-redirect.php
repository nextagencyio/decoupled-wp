<?php
/**
 * Headless-mode redirect.
 *
 * The WordPress install is the editorial backend only — the public site
 * is the Astro frontend at SPARK_FRONTEND_URL. Anyone who lands on the
 * WP frontend (the bare site URL, a permalink that wasn't already
 * rewritten, etc.) gets bounced to the matching Astro path so they
 * never see the default WP theme by accident.
 *
 * Excluded: /wp-admin, /wp-login.php, /wp-cron.php, /wp/* (Bedrock core
 * path), /wp-json/* (REST), /graphql (WPGraphQL — the frontend reads
 * from this), and /app/uploads/* (media the frontend hot-links).
 *
 * 302 (not 301) so a future SPARK_FRONTEND_URL change isn't cached
 * forever by upstream proxies.
 */

namespace Spark\Core\HeadlessRedirect;

if (!defined('ABSPATH')) {
    exit;
}

add_action('template_redirect', __NAMESPACE__ . '\\maybe_redirect', 1);

/**
 * If the current request is a public WP front-end hit, bounce it to the
 * matching path on the Astro frontend.
 */
function maybe_redirect(): void
{
    if (!should_redirect()) {
        return;
    }

    $frontend = frontend_url();
    if (!$frontend) {
        return;
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
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
    if (defined('SPARK_FRONTEND_URL')) {
        return SPARK_FRONTEND_URL;
    }
    return getenv('SPARK_FRONTEND_URL') ?: null;
}

/**
 * Whether the current request should be bounced to the Astro frontend.
 * False for any WP-internal path or capability.
 */
function should_redirect(): bool
{
    if (is_admin())                 return false;
    if (wp_doing_ajax())            return false;
    if (wp_doing_cron())            return false;
    if (defined('REST_REQUEST'))    return false;
    if (defined('GRAPHQL_REQUEST')) return false;

    $frontend = frontend_url();
    if (!$frontend) {
        return false;
    }

    // Infinite-loop guard: never redirect when the frontend URL
    // resolves to the host we're already serving.
    $current_host  = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $frontend_host = strtolower((string) parse_url($frontend, PHP_URL_HOST));
    if (!$frontend_host || $frontend_host === $current_host) {
        return false;
    }

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
    wp_safe_redirect($url, 302, 'Spark-Headless');
    remove_filter('allowed_redirect_hosts', $filter);
}
