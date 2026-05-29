<?php
/**
 * Plugin Name:       Spark Puck
 * Plugin URI:        https://github.com/nextagencyio/decoupled-wp
 * Description:       Puck visual-editor bridge for the decoupled-WordPress starter. Exposes a REST contract (load / save / mapping / token) that translates between Puck's JSON component tree and the spark-core content model's Carbon Fields components — the WP analogue of Drupal's dc_puck. Depends on spark-core.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires at least: 6.6
 * Author:            Simple Spark
 * License:           MIT
 * Text Domain:       spark-puck
 *
 * Read-write companion to spark-core. spark-core owns the content model
 * + the Carbon Fields component storage; spark-puck reuses that storage
 * and presents it to a headless Puck editor as a JSON component tree,
 * and writes edits back.
 *
 * The contract mirrors dc_puck so the paired Astro frontend's Puck
 * integration is portable between the Drupal and WordPress products:
 *
 *   GET  /wp-json/spark-puck/v1/load/{id}        post  → Puck JSON
 *   POST /wp-json/spark-puck/v1/save/{id}        Puck JSON → post
 *   GET  /wp-json/spark-puck/v1/mapping          component↔prop mapping
 *   POST /wp-json/spark-puck/v1/configure        set mapping
 *   GET  /wp-json/spark-puck/v1/token/{id}       mint an edit token
 *   POST /wp-json/spark-puck/v1/validate-token   verify a token
 */

namespace Spark\Puck;

if (!defined('ABSPATH')) {
    exit;
}

define('SPARK_PUCK_VERSION', '0.1.0');
define('SPARK_PUCK_DIR', plugin_dir_path(__FILE__));

/**
 * spark-puck depends on spark-core for the model + Carbon storage. If
 * spark-core isn't active, surface a notice and bail rather than fatal.
 */
add_action('admin_init', function (): void {
    if (!function_exists('carbon_get_post_meta') || !function_exists('Spark\\Core\\Config\\model')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Spark Puck requires Spark Core (and Carbon Fields) to be active.', 'spark-puck')
                . '</p></div>';
        });
    }
});

require_once SPARK_PUCK_DIR . 'includes/mapping.php';
require_once SPARK_PUCK_DIR . 'includes/transform.php';
require_once SPARK_PUCK_DIR . 'includes/token.php';
require_once SPARK_PUCK_DIR . 'includes/rest.php';

add_action('rest_api_init', __NAMESPACE__ . '\\Rest\\register_routes');
