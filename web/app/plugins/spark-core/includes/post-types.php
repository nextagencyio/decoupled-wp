<?php
/**
 * Custom post types for the decoupled-WordPress content model.
 *
 * Ships ONE generic example CPT — `spark_resource` — to demonstrate the
 * pattern an engagement should follow. A "resource" is a deliberately
 * vague stand-in: a guide, a service, a program, a news item — whatever
 * the project's content model actually needs. Rename it, clone it, or
 * delete it; it exists to show the wiring, not to be the final model.
 *
 * The default `page` post type handles institutional pages (Home,
 * About, Contact). Page BODY content is the standard WordPress
 * editor (`post_content`); Carbon Fields adds only structured extras
 * — a hero image, intro paragraphs, optional complex components.
 * See includes/fields-page.php. The default `post` type keeps the
 * classic WP blog workflow.
 *
 * Every CPT registers `show_in_graphql => true` so WPGraphQL exposes it
 * to the Astro frontend automatically — that is the contract the
 * headless frontend depends on.
 */

namespace Spark\Core\PostTypes;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', __NAMESPACE__ . '\\register_all');

// Page body content is the standard WordPress editor (`post_content`)
// — editors write prose the way they already know. Carbon Fields
// (includes/fields-page.php) adds only structured extras: a hero
// image, intro paragraphs, and an optional complex-components field.
// The `page` editor box stays; do NOT remove editor support.

/**
 * Register every CPT this plugin owns. Called on `init` and from the
 * activation hook so permalinks resolve immediately after activation.
 */
function register_all(): void
{
    foreach (Config\post_types() as $post_type => $args) {
        register_post_type($post_type, $args);
    }
}

/**
 * Generic "Resource" CPT — the example content type.
 *
 * Uses a custom `capability_type` so the content_editor role (see
 * includes/roles.php) can be scoped to it independently of default
 * pages and posts. `map_meta_cap => true` makes WordPress derive the
 * per-object meta caps from that type.
 */
function register_resource(): void
{
    $post_types = Config\post_types();
    if (isset($post_types['spark_resource'])) {
        register_post_type('spark_resource', $post_types['spark_resource']);
    }
}
