<?php
/**
 * Bootstrap Carbon Fields.
 *
 * Carbon Fields is installed via Composer at the repo root
 * (vendor/htmlburger/carbon-fields/) and loaded through the Composer
 * autoloader. Its JS/CSS assets are copied into the plugin at
 * web/app/plugins/dc-core/vendor/carbon-fields/build/ so they are
 * reachable from the web.
 *
 * Carbon Fields auto-detects its asset URL via
 * Carbon_Fields::directory_to_url(), which only succeeds if Carbon
 * Fields lives inside WP_PLUGIN_DIR, WP_CONTENT_DIR, or ABSPATH. Our
 * Composer install puts it at vendor/htmlburger/carbon-fields/ — above
 * all three — so detection returns an empty string and assets enqueue
 * from "/wp/build/classic/..." (404), leaving every meta box blank.
 *
 * Pre-defining Carbon_Fields\URL points the asset enqueue at the
 * in-plugin copy. It MUST run BEFORE Carbon_Fields::boot() because the
 * constant is consumed inside Carbon Fields' config.php, which boot()
 * triggers via the autoloader.
 */

namespace Dc\Core\CarbonFields;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('Carbon_Fields\\URL')) {
    define(
        'Carbon_Fields\\URL',
        plugins_url('vendor/carbon-fields', dirname(__DIR__) . '/dc-core.php')
    );
}

add_action('after_setup_theme', function () {
    if (class_exists('Carbon_Fields\\Carbon_Fields')) {
        \Carbon_Fields\Carbon_Fields::boot();
    }
});
