<?php
/**
 * MCP discovery surface — the WP analogues of the dashboard's
 * Drupal-side `/api/dc-import/oauth-credentials` and the JSON:API
 * taxonomy reads. Splits out of rest.php to keep that file focused on
 * the import/export pipeline.
 *
 * All three endpoints are read-only and require nothing more than a
 * valid X-Decoupled-Token (same gate as /auth-credentials).
 *
 * Endpoints:
 *
 *   GET  /wp-json/dc/v1/oauth-credentials
 *        → { credentials: {...}, env_file: "...", graphql_url: ... }
 *        Returns the values the Astro starter needs in its .env file.
 *        Despite the name, no actual OAuth is involved on WP — it's
 *        a long-lived space token, kept "oauth-credentials" for
 *        backend-agnostic naming with Drupal.
 *
 *   GET  /wp-json/dc/v1/taxonomies
 *        → { data: [{ id, label, description, hierarchical }] }
 *        Lists every public taxonomy registered on the site.
 *
 *   GET  /wp-json/dc/v1/taxonomies/<tax>/terms?per_page=&page=
 *        → { data: [{ id, slug, name, parent, count }], meta }
 *        Lists terms in one taxonomy.
 */

namespace Dc\Core\McpDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

const NAMESPACE_V1 = 'dc/v1';

add_action('rest_api_init', __NAMESPACE__ . '\\register_routes');

function register_routes(): void
{
    register_rest_route(NAMESPACE_V1, '/oauth-credentials', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_oauth_credentials',
        'permission_callback' => __NAMESPACE__ . '\\require_space_token',
    ]);

    register_rest_route(NAMESPACE_V1, '/taxonomies', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_list_taxonomies',
        'permission_callback' => __NAMESPACE__ . '\\require_space_token',
    ]);

    register_rest_route(NAMESPACE_V1, '/taxonomies/(?P<tax>[a-z0-9_-]+)/terms', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_list_terms',
        'permission_callback' => __NAMESPACE__ . '\\require_space_token',
        'args'                => [
            'tax' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
            'per_page' => [
                'default'           => 50,
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
}

/**
 * Same gate as admin-login / frontend-connect / auth-credentials.
 */
function require_space_token(\WP_REST_Request $request): bool|\WP_Error
{
    $persisted = (string) get_option('dc_space_auth_token', '');
    if ($persisted === '') {
        return new \WP_Error(
            'dc_no_space_token',
            'Tenant has no dc_space_auth_token. The provisioner must set it at install time.',
            ['status' => 503]
        );
    }
    $presented = (string) $request->get_header('x_decoupled_token');
    if ($presented === '' || !hash_equals($persisted, $presented)) {
        return new \WP_Error(
            'dc_invalid_space_token',
            'Missing or invalid X-Decoupled-Token.',
            ['status' => 401]
        );
    }
    return true;
}

/**
 * GET /wp-json/dc/v1/oauth-credentials
 *
 * Returns the env values the Astro starter needs to talk to this
 * tenant. The "credentials" object is intentionally flat so the
 * dashboard can JSON-encode it straight into Netlify env vars.
 */
function handle_oauth_credentials(\WP_REST_Request $request): \WP_REST_Response
{
    $site_url    = (string) home_url();
    $space_token = (string) get_option('dc_space_auth_token', '');

    $credentials = [
        'wp_base_url'      => $site_url,
        'graphql_url'      => $site_url . '/graphql',
        'space_auth_token' => $space_token,
    ];

    // Same key naming the dashboard's frontend-env-update route already
    // pushes to Netlify; keeping the env_file shape in sync means a
    // user copy-pasting it locally gets the same vars as the Netlify
    // deploy.
    $env_file = implode("\n", [
        '# WordPress backend',
        "WP_BASE_URL={$site_url}",
        "PUBLIC_WP_BASE_URL={$site_url}",
        "NEXT_PUBLIC_WORDPRESS_BASE_URL={$site_url}",
        "WORDPRESS_SPACE_AUTH_TOKEN={$space_token}",
        '',
    ]);

    return new \WP_REST_Response([
        'success'     => true,
        'credentials' => $credentials,
        'env_file'    => $env_file,
        'graphql_url' => $credentials['graphql_url'],
    ], 200);
}

/**
 * GET /wp-json/dc/v1/taxonomies
 *
 * Returns every public taxonomy on the site. Mirrors the Drupal-side
 * MCP `list_taxonomies` shape so the dashboard can return one contract
 * regardless of backend.
 */
function handle_list_taxonomies(\WP_REST_Request $request): \WP_REST_Response
{
    $taxes = get_taxonomies(['public' => true], 'objects');
    $data  = [];

    foreach ($taxes as $tax) {
        $data[] = [
            'id'           => (string) $tax->name,
            'label'        => (string) $tax->label,
            'description'  => (string) ($tax->description ?? ''),
            'hierarchical' => (bool) $tax->hierarchical,
        ];
    }

    return new \WP_REST_Response([
        'data' => $data,
        'meta' => ['totalItems' => count($data)],
    ], 200);
}

/**
 * GET /wp-json/dc/v1/taxonomies/<tax>/terms
 *
 * Returns terms for a single taxonomy, paginated. 404 if the taxonomy
 * is unknown so callers get a clean signal instead of an empty list.
 */
function handle_list_terms(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
{
    $tax = (string) $request->get_param('tax');
    if (!taxonomy_exists($tax)) {
        return new \WP_Error(
            'dc_unknown_taxonomy',
            "Taxonomy '{$tax}' does not exist on this site.",
            ['status' => 404]
        );
    }

    $per_page = (int) $request->get_param('per_page');
    $page     = max(1, (int) $request->get_param('page'));
    $offset   = ($page - 1) * $per_page;

    $terms = get_terms([
        'taxonomy'   => $tax,
        'hide_empty' => false,
        'number'     => $per_page,
        'offset'     => $offset,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms)) {
        return $terms;
    }

    $total = (int) wp_count_terms(['taxonomy' => $tax, 'hide_empty' => false]);

    $data = [];
    foreach ($terms as $term) {
        $data[] = [
            'id'    => (int) $term->term_id,
            'slug'  => (string) $term->slug,
            'name'  => (string) $term->name,
            'parent'=> (int) $term->parent,
            'count' => (int) $term->count,
        ];
    }

    return new \WP_REST_Response([
        'data' => $data,
        'meta' => [
            'totalItems' => $total,
            'page'       => $page,
            'perPage'    => $per_page,
        ],
    ], 200);
}
