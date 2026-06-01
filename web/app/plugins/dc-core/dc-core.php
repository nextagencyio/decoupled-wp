<?php
/**
 * Plugin Name:       Decoupled Core
 * Plugin URI:        https://github.com/nextagencyio/decoupled-wp
 * Description:       Content model + GraphQL extensions for the decoupled-WordPress starter kit. Registers an example custom post type, taxonomy, structured Carbon Fields field groups, a content_editor role, headless preview / redirect / revalidation wiring, admin branding, and a wp-admin Compliance Dashboard. The WP analogue of Drupal's dc_core profile.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires at least: 6.6
 * Author:            Decoupled.io
 * License:           MIT
 * Text Domain:       dc-core
 *
 * One client-owned plugin houses every CPT, taxonomy, field group,
 * role, and GraphQL extension the headless Astro frontend needs.
 * WordPress core + third-party plugins (Carbon Fields, WPGraphQL)
 * provide infrastructure; this plugin owns the project's content
 * model. Fork it per engagement: rename the example CPT/taxonomy,
 * add the field groups the project actually needs, and adjust the
 * GraphQL surface to match.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DC_CORE_VERSION', '0.1.0');
define('DC_CORE_DIR', plugin_dir_path(__FILE__));
define('DC_CORE_URL', plugin_dir_url(__FILE__));

require_once DC_CORE_DIR . 'includes/carbon-fields-bootstrap.php';
require_once DC_CORE_DIR . 'includes/config/defaults.php';
require_once DC_CORE_DIR . 'includes/config/loader.php';
// One-shot rename migration: copies legacy spark_* DB keys → dc_* on
// first load after the upgrade. No-op on a clean install. See the
// file header for the full list of keys this touches.
require_once DC_CORE_DIR . 'includes/bootstrap-migrate.php';
\Dc\Core\Migrate\init();
// Bundled-model loader: DC_CONTENT_MODEL=<name> loads a vetted
// models/<name>.json as the provisioning default (data, not PHP). An
// imported / admin-saved model still wins over this.
require_once DC_CORE_DIR . 'includes/config/bundled.php';
require_once DC_CORE_DIR . 'includes/config/routes.php';
require_once DC_CORE_DIR . 'includes/admin/content-model.php';
require_once DC_CORE_DIR . 'includes/post-types.php';
require_once DC_CORE_DIR . 'includes/taxonomies.php';
require_once DC_CORE_DIR . 'includes/roles.php';
require_once DC_CORE_DIR . 'includes/fields-body-block.php';
require_once DC_CORE_DIR . 'includes/fields-page.php';
require_once DC_CORE_DIR . 'includes/graphql-extensions.php';
require_once DC_CORE_DIR . 'includes/preview-links.php';
require_once DC_CORE_DIR . 'includes/headless-redirect.php';
require_once DC_CORE_DIR . 'includes/revalidate.php';
require_once DC_CORE_DIR . 'includes/mail.php';
require_once DC_CORE_DIR . 'includes/brand-admin.php';
require_once DC_CORE_DIR . 'includes/dashboard.php';
require_once DC_CORE_DIR . 'includes/compliance-dashboard.php';
// Content import — populate posts / terms / media / field values from a
// JSON envelope against the active model (the WP analogue of Drupal's
// dc_import). model-index introspects the model; the rest build on it.
require_once DC_CORE_DIR . 'includes/content/model-index.php';
require_once DC_CORE_DIR . 'includes/content/validator.php';
require_once DC_CORE_DIR . 'includes/content/terms.php';
require_once DC_CORE_DIR . 'includes/content/media.php';
require_once DC_CORE_DIR . 'includes/content/field-writers.php';
require_once DC_CORE_DIR . 'includes/content/importer.php';
require_once DC_CORE_DIR . 'includes/content/example.php';
require_once DC_CORE_DIR . 'includes/cli.php';
// REST namespace /wp-json/dc/v1/* — thin transport layer over the
// content/model helpers above. The MCP server and CLI hit these so
// they can speak the same shape of endpoint regardless of backend.
require_once DC_CORE_DIR . 'includes/rest.php';

/**
 * Activation hook — register the content model up front, then flush
 * permalinks so CPT / taxonomy slugs resolve immediately instead of
 * 404ing until the next manual flush.
 */
register_activation_hook(__FILE__, function () {
    \Dc\Core\PostTypes\register_all();
    \Dc\Core\Taxonomies\register_all();
    \Dc\Core\Roles\register_all();
    flush_rewrite_rules();
});

/**
 * Deactivation hook — flush rewrites so the CPT / taxonomy rules are
 * dropped cleanly and don't linger as dead routes.
 */
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
