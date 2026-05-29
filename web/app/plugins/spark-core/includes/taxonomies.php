<?php
/**
 * Taxonomies for the decoupled-WordPress content model.
 *
 * Ships ONE generic example taxonomy — `spark_topic` — attached to the
 * example `spark_resource` CPT and the default `post`. It demonstrates
 * the registration pattern (hierarchical, GraphQL-exposed); rename or
 * replace it with whatever taxonomy the project actually needs.
 *
 * Like the CPTs, every taxonomy registers `show_in_graphql => true` so
 * the Astro frontend can query terms and filter content by them.
 */

namespace Spark\Core\Taxonomies;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', __NAMESPACE__ . '\\register_all');

/**
 * Register every taxonomy this plugin owns. Called on `init` and from
 * the activation hook so term rewrite rules resolve immediately.
 */
function register_all(): void
{
    foreach (Config\taxonomies() as $taxonomy => $config) {
        $object_type = is_array($config['object_type'] ?? null) ? $config['object_type'] : [];
        $args = is_array($config['args'] ?? null) ? $config['args'] : [];
        register_taxonomy($taxonomy, $object_type, $args);
    }
}

/**
 * Generic "Topic" taxonomy — the example term set.
 *
 * Hierarchical (category-style) so editors can build a nested topic
 * tree. The Astro frontend uses topics to surface "related" content.
 */
function register_topic(): void
{
    $taxonomies = Config\taxonomies();
    if (!isset($taxonomies['spark_topic'])) {
        return;
    }

    $config = $taxonomies['spark_topic'];
    $object_type = is_array($config['object_type'] ?? null) ? $config['object_type'] : [];
    $args = is_array($config['args'] ?? null) ? $config['args'] : [];
    register_taxonomy('spark_topic', $object_type, $args);
}
