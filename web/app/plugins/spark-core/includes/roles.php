<?php
/**
 * Custom editorial role for the decoupled-WordPress starter.
 *
 *   - content_editor  Can create / edit / publish the example
 *                     `spark_resource` CPT, default pages, and the
 *                     blog. Cannot manage users, themes, or plugins.
 *
 * Editors keep the familiar WordPress admin — the decoupled setup only
 * changes where the public site renders, not how content is authored.
 * Capabilities follow least-privilege: editors can do their jobs and
 * nothing more. Platform work stays behind the standard `administrator`
 * role used by the agency + the client's site administrator.
 *
 * Generalize per project: add a `caps_for_type()` block for each
 * custom CPT the engagement registers, and merge it into `$caps`.
 */

namespace Spark\Core\Roles;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', __NAMESPACE__ . '\\register_all');

/**
 * Create (or reconcile) the content_editor role and mirror its custom
 * CPT caps onto the administrator. Idempotent — safe to run on every
 * `init` and from the activation hook.
 */
function register_all(): void
{
    $custom_post_type_caps = custom_post_type_caps();

    // Default page caps — editors maintain institutional pages but do
    // not delete them (pages are structural; admin-only delete).
    $caps_page = [
        'edit_pages'           => true,
        'edit_published_pages' => true,
        'edit_others_pages'    => true,
        'publish_pages'        => true,
        'delete_pages'         => false,
    ];

    // Default blog-post caps.
    $caps_post = [
        'edit_posts'             => true,
        'edit_published_posts'   => true,
        'edit_others_posts'      => true,
        'publish_posts'          => true,
        'delete_posts'           => true,
        'delete_published_posts' => true,
    ];

    $caps = array_merge(
        ['read' => true, 'upload_files' => true],
        $custom_post_type_caps,
        $caps_page,
        $caps_post
    );

    if (!get_role('content_editor')) {
        add_role('content_editor', __('Content Editor', 'spark-core'), $caps);
    } else {
        // Role already exists — reconcile its caps so plugin updates
        // that add/remove a cap take effect without a re-install.
        $role = get_role('content_editor');
        foreach ($caps as $cap => $grant) {
            if ($grant) {
                $role->add_cap($cap);
            } else {
                $role->remove_cap($cap);
            }
        }
    }

    // Mirror the custom-CPT caps onto the administrator so site admins
    // can do everything editors can, plus the platform work. (Default
    // page/post caps the administrator already has.)
    $admin = get_role('administrator');
    if ($admin) {
        foreach ($custom_post_type_caps as $cap => $grant) {
            if ($grant) {
                $admin->add_cap($cap);
            }
        }
    }
}

/**
 * Build caps for every configured custom post type that declares a
 * `capability_type` pair.
 *
 * @return array<string, bool>
 */
function custom_post_type_caps(): array
{
    $caps = [];

    foreach (Config\post_types() as $args) {
        $capability_type = $args['capability_type'] ?? null;
        if (!is_array($capability_type) || count($capability_type) < 2) {
            continue;
        }

        $caps = array_merge(
            $caps,
            caps_for_type((string) $capability_type[0], (string) $capability_type[1])
        );
    }

    return $caps;
}

/**
 * Build the standard cap set for a CPT registered with
 * `capability_type => [singular, plural]` and `map_meta_cap => true`.
 *
 * @param string $singular  e.g. 'spark_resource'
 * @param string $plural    e.g. 'spark_resources'
 * @return array<string, bool>
 */
function caps_for_type(string $singular, string $plural): array
{
    return [
        "edit_{$singular}"           => true,
        "read_{$singular}"           => true,
        "delete_{$singular}"         => true,
        "edit_{$plural}"             => true,
        "edit_others_{$plural}"      => true,
        "publish_{$plural}"          => true,
        "read_private_{$plural}"     => true,
        "delete_{$plural}"           => true,
        "delete_private_{$plural}"   => true,
        "delete_published_{$plural}" => true,
        "delete_others_{$plural}"    => true,
        "edit_private_{$plural}"     => true,
        "edit_published_{$plural}"   => true,
    ];
}
