<?php
/**
 * Admin + login branding scaffold.
 *
 * Keeps the WordPress editor experience visually consistent and lets a
 * project recolor the admin without forking PHP. The two stylesheets
 * (assets/css/admin-brand.css, assets/css/login-brand.css) drive their
 * palette entirely from CSS custom properties — a project recolors by
 * overriding `--spark-*` tokens, not by editing selectors.
 *
 * Logo / favicon: this starter ships NO brand images. Drop PNGs into
 * assets/brand/ and point the helpers below at them, or override the
 * `--spark-logo-url` / `--spark-favicon-url` custom properties. Until
 * then the stylesheets simply leave those slots empty — nothing breaks.
 *
 * Generalize per project: set the real login URL / header text / footer
 * text in the filter callbacks at the bottom.
 */

namespace Spark\Core\BrandAdmin;

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_assets');
add_action('login_enqueue_scripts', __NAMESPACE__ . '\\enqueue_login_assets');
add_filter('login_headerurl', __NAMESPACE__ . '\\login_header_url');
add_filter('login_headertext', __NAMESPACE__ . '\\login_header_text');
add_filter('login_body_class', __NAMESPACE__ . '\\login_body_class');
add_filter('admin_footer_text', __NAMESPACE__ . '\\admin_footer_text');

/**
 * Absolute URL to a file under the plugin's assets/ directory.
 */
function asset_url(string $path): string
{
    return SPARK_CORE_URL . 'assets/' . ltrim($path, '/');
}

/**
 * Enqueue the branded admin stylesheet.
 */
function enqueue_admin_assets(): void
{
    wp_enqueue_style(
        'spark-admin-brand',
        asset_url('css/admin-brand.css'),
        [],
        SPARK_CORE_VERSION,
    );
    wp_add_inline_style('spark-admin-brand', brand_asset_css());
}

/**
 * Enqueue the branded login stylesheet.
 */
function enqueue_login_assets(): void
{
    wp_enqueue_style(
        'spark-login-brand',
        asset_url('css/login-brand.css'),
        [],
        SPARK_CORE_VERSION,
    );
    wp_add_inline_style('spark-login-brand', brand_asset_css());
}

/**
 * Inject brand image URLs as CSS custom properties so the stylesheets
 * stay portable (no hard-coded plugin paths) — and so a project that
 * has no brand images yet simply gets empty `url()` slots.
 *
 * A project with brand assets should drop them into assets/brand/ and
 * the file_exists() guards below will wire them up automatically.
 */
/**
 * Read per-instance branding from the `spark_brand` option (data, not a
 * plugin file) so the admin + login screens match the project's brand
 * without forking PHP or dropping client assets into this shared plugin.
 *
 * Shape (all optional):
 *   { "logoUrl": "...", "faviconUrl": "...",
 *     "primary": "#1a3a5c", "accent": "#e2671a", "ink": "#0e1f31" }
 *
 * `logoUrl` falls back to a bundled assets/brand/logo.png (legacy), then
 * to none. Colours map onto the login/admin stylesheet's --spark-* tokens.
 *
 * @return array<string, string>
 */
function brand_config(): array
{
    $opt = get_option('spark_brand', []);
    return is_array($opt) ? $opt : [];
}

function brand_logo_url(): string
{
    $brand = brand_config();
    if (!empty($brand['logoUrl'])) {
        return (string) $brand['logoUrl'];
    }
    $logo_file = SPARK_CORE_DIR . 'assets/brand/logo.png';
    return file_exists($logo_file) ? (string) asset_url('brand/logo.png') : '';
}

function brand_asset_css(): string
{
    $brand        = brand_config();
    $favicon_file = SPARK_CORE_DIR . 'assets/brand/favicon-32x32.png';

    $logo_url    = brand_logo_url();
    $favicon_url = !empty($brand['faviconUrl'])
        ? (string) $brand['faviconUrl']
        : (file_exists($favicon_file) ? (string) asset_url('brand/favicon-32x32.png') : '');

    $vars = [
        '--spark-logo-url:' . ($logo_url ? 'url("' . esc_url($logo_url) . '")' : 'none'),
        '--spark-favicon-url:' . ($favicon_url ? 'url("' . esc_url($favicon_url) . '")' : 'none'),
    ];

    // Map brand colours onto the stylesheet tokens. Only valid hex/rgb
    // values are emitted (the value is quoted into a CSS declaration, so
    // restrict to a safe charset).
    $colour_map = [
        'primary' => '--spark-brand-500',
        'accent'  => '--spark-accent-500',
        'ink'     => '--spark-ink-900',
    ];
    foreach ($colour_map as $key => $token) {
        $val = (string) ($brand[$key] ?? '');
        if ($val !== '' && preg_match('/^#?[0-9a-zA-Z(),.\s%]+$/', $val)) {
            $vars[] = $token . ':' . $val;
        }
    }

    return ':root{' . implode(';', $vars) . ';}';
}

/**
 * Where the login-screen logo links to. Set this to the project's
 * public site URL.
 */
function login_header_url(): string
{
    return home_url('/');
}

/**
 * Accessible text for the login-screen logo.
 */
function login_header_text(): string
{
    return get_bloginfo('name') ?: __('Spark CMS', 'spark-core');
}

/**
 * Add `has-logo` to the login <body> when a real brand image exists
 * at assets/brand/logo.png.
 *
 * Without a brand image, login-brand.css renders a text wordmark on
 * a light plate. When a real logo.png is dropped into assets/brand/,
 * this class flips login-brand.css to show the image instead — in a
 * compact, roughly square plate sized to the logo (not the wide
 * wordmark plate).
 *
 * @param array<int, string> $classes
 * @return array<int, string>
 */
function login_body_class(array $classes): array
{
    if (brand_logo_url() !== '') {
        $classes[] = 'has-logo';
    }
    return $classes;
}

/**
 * wp-admin footer credit line.
 */
function admin_footer_text(): string
{
    return __('Decoupled WordPress · Spark Core', 'spark-core');
}
