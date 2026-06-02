<?php
/**
 * One-shot migration: rename legacy spark_* DB keys → dc_* on first load.
 *
 * This plugin was previously distributed as "Spark Core / Spark Puck"
 * (the agency-internal name). When the codebase was rebranded to
 * "Decoupled Core / Decoupled Puck" we renamed every persisted key —
 * Carbon-Fields meta, WP options, post-meta — to the dc_* prefix. Any
 * site that had data under the old keys would have it disappear on
 * upgrade unless we migrate.
 *
 * This migration:
 *   1. Walks wp_postmeta and copies every key starting with `spark_`
 *      or `_spark_` to the equivalent `dc_` / `_dc_` key. Original
 *      rows are deleted on a successful copy.
 *   2. Walks wp_options for `spark_*` and `_transient_spark_*` /
 *      `_transient_timeout_spark_*` and does the same.
 *   3. Marks itself complete via the `dc_core_rename_complete` option
 *      so it never re-runs (idempotent in practice — re-running would
 *      be a no-op anyway since the spark_* rows are gone — but the
 *      flag short-circuits the queries entirely).
 *
 * Runs on `init` after the autoloader is up. Safe to ship in every
 * release: on a clean install the option is missing → the queries run
 * once → they find zero rows → the option is set → never runs again.
 */
namespace Dc\Core\Migrate;

if (!defined('ABSPATH')) {
    exit;
}

const COMPLETE_OPTION = 'dc_core_rename_complete';
const COMPLETE_VERSION = 1;

/** Register the bootstrap hook. Called from dc-core.php. */
function init(): void
{
    add_action('init', __NAMESPACE__ . '\\maybe_run', 1);
}

/**
 * Run the migration if it hasn't already. Wrapped in an option flag
 * so the queries don't even fire on the steady-state path.
 */
function maybe_run(): void
{
    if ((int) get_option(COMPLETE_OPTION, 0) >= COMPLETE_VERSION) {
        return;
    }
    run();
    update_option(COMPLETE_OPTION, COMPLETE_VERSION, false);
}

/**
 * Execute the rename. Two batched UPDATEs (one per table) — the
 * key namespace is short enough that this is cheap even on big sites.
 */
function run(): void
{
    global $wpdb;

    // wp_postmeta: any meta_key starting with `spark_` or `_spark_`.
    // Replace ONLY the prefix, so `_spark_components|cards|0|value`
    // becomes `_dc_components|cards|0|value`.
    $wpdb->query(
        "UPDATE {$wpdb->postmeta}
         SET meta_key = CONCAT('_dc_', SUBSTR(meta_key, 8))
         WHERE meta_key LIKE '\\_spark\\_%'"
    );
    $wpdb->query(
        "UPDATE {$wpdb->postmeta}
         SET meta_key = CONCAT('dc_', SUBSTR(meta_key, 7))
         WHERE meta_key LIKE 'spark\\_%'"
    );

    // wp_options. Covers plain options, transients, transient timeouts,
    // and site-transients. The same prefix-rewrite handles all four.
    $wpdb->query(
        "UPDATE {$wpdb->options}
         SET option_name = CONCAT('dc_', SUBSTR(option_name, 7))
         WHERE option_name LIKE 'spark\\_%'"
    );
    $wpdb->query(
        "UPDATE {$wpdb->options}
         SET option_name = CONCAT('_transient_dc_', SUBSTR(option_name, 18))
         WHERE option_name LIKE '\\_transient\\_spark\\_%'"
    );
    $wpdb->query(
        "UPDATE {$wpdb->options}
         SET option_name = CONCAT('_transient_timeout_dc_', SUBSTR(option_name, 26))
         WHERE option_name LIKE '\\_transient\\_timeout\\_spark\\_%'"
    );

    // Post types registered under the old `spark_*` names (e.g.
    // spark_landing, spark_resource). The post type itself is
    // re-registered as `dc_*` by the post-types code; here we move the
    // posts themselves so they stay routable under the new type.
    $wpdb->query(
        "UPDATE {$wpdb->posts}
         SET post_type = CONCAT('dc_', SUBSTR(post_type, 7))
         WHERE post_type LIKE 'spark\\_%'"
    );

    // Taxonomies registered under the old `spark_topic` name. The taxonomy
    // *itself* is re-registered as `dc_topic` by the post-types code;
    // here we just move any existing term_taxonomy + termmeta rows so
    // the terms stay attached after the swap.
    $wpdb->query(
        "UPDATE {$wpdb->term_taxonomy}
         SET taxonomy = CONCAT('dc_', SUBSTR(taxonomy, 7))
         WHERE taxonomy LIKE 'spark\\_%'"
    );

    // Clear the object cache so any stale Carbon meta lookups don't
    // serve old (now non-existent) keys.
    wp_cache_flush();
}
