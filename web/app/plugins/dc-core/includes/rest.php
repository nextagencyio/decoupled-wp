<?php
/**
 * REST namespace `/wp-json/dc/v1/*` — the WP analogue of Drupal's
 * dc_import REST surface. Wraps the existing dc-core functions
 * (Content\field_index, Config\post_types, Content\import_envelope,
 * etc.) so the dashboard's MCP server and CLI can hit the same shape
 * of endpoint regardless of backend.
 *
 * This file is intentionally thin: every endpoint resolves to a one-
 * or two-line call into an existing dc-core helper. The business
 * logic lives there; REST is just transport.
 *
 * Auth model:
 * - Read-only endpoints (content-types, content) are public — the
 *   model is the same data WPGraphQL exposes unauthenticated.
 * - Write endpoints (import-content, import-config) require a JWT
 *   bearer that wp-graphql-jwt-authentication validates. Callers get
 *   the JWT by POSTing the `login` mutation to /graphql with admin
 *   credentials, exactly the headless-preview flow already uses.
 */

namespace Dc\Core\Rest;

use Dc\Core\Config;
use Dc\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

const NAMESPACE_V1 = 'dc/v1';

add_action('rest_api_init', __NAMESPACE__ . '\\register_routes');

function register_routes(): void
{
    // POST /wp-json/dc/v1/auth-credentials
    // Mint a short-lived admin JWT for the caller, given a valid
    // space-scoped token (stored on the tenant at provision time as the
    // `dc_space_auth_token` wp_option, mirrors Drupal's
    // /api/dc-import/oauth-credentials in intent).
    //
    // SECURITY MODEL: The admin password never leaves the tenant. The
    // dashboard holds a per-tenant space token (the same one Drupal
    // tenants already use as spaceAuthToken) and exchanges it for a
    // JWT here. A Supabase breach exposes the space token — which only
    // buys an attacker MCP-equivalent access, not full WP admin —
    // bounded blast radius compared to storing the admin password
    // dashboard-side.
    register_rest_route(NAMESPACE_V1, '/auth-credentials', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\handle_auth_credentials',
        'permission_callback' => '__return_true',
    ]);

    // GET /wp-json/dc/v1/content-types
    // List all post types known to the active content model, including
    // core 'page'/'post', with a count of modeled fields each carries.
    register_rest_route(NAMESPACE_V1, '/content-types', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_list_content_types',
        'permission_callback' => '__return_true',
    ]);

    // GET /wp-json/dc/v1/content-types/<type>
    // Per-type field definitions (key, type, options, writer hint).
    register_rest_route(NAMESPACE_V1, '/content-types/(?P<type>[a-z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_describe_content_type',
        'permission_callback' => '__return_true',
        'args'                => [
            'type' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
    ]);

    // GET /wp-json/dc/v1/import-example
    // The canonical content envelope, ready for `import-content`. The
    // REST analogue of `wp dc content example`.
    register_rest_route(NAMESPACE_V1, '/import-example', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_import_example',
        'permission_callback' => '__return_true',
    ]);

    // GET /wp-json/dc/v1/content?type=<type>&per_page=<n>&page=<n>
    // List posts of a content type. Returns id, title, slug, status,
    // modified timestamp; field values come from /content/<id>.
    register_rest_route(NAMESPACE_V1, '/content', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_list_content',
        'permission_callback' => '__return_true',
        'args'                => [
            'type' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
            'per_page' => [
                'default'           => 20,
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);

    // GET /wp-json/dc/v1/content/<id>
    // One post, with every modeled field value read flat off post meta.
    // The shape mirrors what `import-content` writes IN, so a round-trip
    // export → re-import is at least structurally honest.
    register_rest_route(NAMESPACE_V1, '/content/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_get_content',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);

    // POST /wp-json/dc/v1/import-config
    // Validate + store a content model. JWT-protected.
    // Body: the model JSON itself (the same shape `wp dc model export`
    // prints). Returns the persisted model on success or a list of
    // validation errors on rejection.
    register_rest_route(NAMESPACE_V1, '/import-config', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\handle_import_config',
        'permission_callback' => __NAMESPACE__ . '\\require_admin',
    ]);

    // POST /wp-json/dc/v1/import-content
    // Validate + import a content envelope. JWT-protected.
    // Body: the envelope JSON (the same shape /import-example returns
    // and `wp dc content import` accepts on stdin). Returns import
    // counts + any warnings on success or validation errors on
    // rejection. Idempotent: posts keyed by (postType, slug); media by
    // source-URL hash; terms by (taxonomy, slug).
    register_rest_route(NAMESPACE_V1, '/import-content', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\handle_import_content',
        'permission_callback' => __NAMESPACE__ . '\\require_admin',
    ]);
}

/**
 * Permission callback for write endpoints. The JWT plugin already
 * resolves a valid `Authorization: Bearer <jwt>` into the current
 * user, so the only thing we check here is the capability —
 * standard WP machinery does the rest. Returns a `WP_Error` rather
 * than `false` so the REST framework emits a useful 401/403 with a
 * message instead of a generic "rest_forbidden".
 */
function require_admin(): bool|\WP_Error
{
    if (current_user_can('manage_options')) {
        return true;
    }
    return new \WP_Error(
        'dc_rest_forbidden',
        'Write endpoints require manage_options. Provide a JWT bearer for an admin user via Authorization: Bearer <token>.',
        ['status' => is_user_logged_in() ? 403 : 401]
    );
}

/**
 * POST /wp-json/dc/v1/auth-credentials
 *
 * Validate the caller's space token (X-Decoupled-Token header) against
 * the persisted `dc_space_auth_token` wp_option. On match, mint a JWT
 * for the admin user (ID 1, the WP-side analogue of Drupal's MCP Agent
 * consumer). The dashboard then uses that JWT as `Authorization:
 * Bearer <jwt>` against the rest of /wp-json/dc/v1/*.
 *
 * Returns a `WP_Error` (not just a bool) so the REST framework emits
 * a useful 401 with a message instead of a generic forbidden response.
 */
function handle_auth_credentials(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
{
    // Plugin must be active for us to mint a token.
    if (!class_exists('\\WPGraphQL\\JWT_Authentication\\Auth')) {
        return new \WP_Error(
            'dc_jwt_unavailable',
            'wp-graphql-jwt-authentication is not active; cannot mint a JWT.',
            ['status' => 503]
        );
    }

    $persisted = (string) get_option('dc_space_auth_token', '');
    if ($persisted === '') {
        return new \WP_Error(
            'dc_no_space_token',
            'This tenant has no dc_space_auth_token. The provisioner must set it at install time.',
            ['status' => 503]
        );
    }

    // The header is the canonical channel (mirrors Drupal's
    // X-Decoupled-Token). hash_equals to defeat timing attacks.
    $presented = (string) $request->get_header('x_decoupled_token');
    if ($presented === '' || !hash_equals($persisted, $presented)) {
        return new \WP_Error(
            'dc_invalid_space_token',
            'Missing or invalid X-Decoupled-Token.',
            ['status' => 401]
        );
    }

    // Mint a JWT for user_id 1 — the admin account the provisioner
    // creates. This mirrors Drupal's MCP Agent consumer (third_party:
    // FALSE, user_id: 1). cap_check=false because we're already
    // authorized by the space token; the cap check inside Auth::get_token
    // would otherwise need a currently-logged-in user to run against.
    $admin = get_user_by('ID', 1);
    if (!$admin instanceof \WP_User) {
        return new \WP_Error(
            'dc_no_admin_user',
            'No user with ID 1 — provisioner did not create the admin account.',
            ['status' => 500]
        );
    }

    $jwt = \WPGraphQL\JWT_Authentication\Auth::get_token($admin, false);
    if (is_wp_error($jwt) || !is_string($jwt) || $jwt === '') {
        return new \WP_Error(
            'dc_jwt_mint_failed',
            is_wp_error($jwt) ? $jwt->get_error_message() : 'Token mint returned an empty value.',
            ['status' => 500]
        );
    }

    return new \WP_REST_Response([
        'data' => [
            'authToken' => $jwt,
            'userId'    => $admin->ID,
            // expiresIn isn't returned by the plugin directly; the
            // default config is 5 minutes (300s) unless filtered.
            // Surface it as advisory only so clients can plan refreshes.
            'expiresInSeconds' => (int) apply_filters(
                'graphql_jwt_auth_expire',
                300
            ),
        ],
    ], 200);
}

/**
 * GET /wp-json/dc/v1/content-types
 */
function handle_list_content_types(): \WP_REST_Response
{
    $index = Content\field_index();
    $modeled = Config\post_types();

    $types = [];
    foreach ($index as $type => $fields) {
        $meta = is_array($modeled[$type] ?? null) ? $modeled[$type] : [];
        $types[] = [
            'id'         => $type,
            'label'      => (string) ($meta['labels']['singular_name'] ?? $meta['label'] ?? ucfirst($type)),
            'modeled'    => isset($modeled[$type]),
            'fieldCount' => is_array($fields) ? count($fields) : 0,
        ];
    }

    // Stable ordering so callers can diff responses across runs.
    usort($types, static fn($a, $b) => strcmp((string) $a['id'], (string) $b['id']));

    return new \WP_REST_Response([
        'data' => $types,
    ], 200);
}

/**
 * GET /wp-json/dc/v1/content-types/<type>
 */
function handle_describe_content_type(\WP_REST_Request $request): \WP_REST_Response
{
    $type = (string) $request->get_param('type');
    $index = Content\field_index();

    if (!isset($index[$type])) {
        return new \WP_REST_Response([
            'error' => sprintf("Unknown content type '%s'.", $type),
        ], 404);
    }

    $modeled = Config\post_types();
    $meta = is_array($modeled[$type] ?? null) ? $modeled[$type] : [];

    $fields = [];
    foreach ($index[$type] as $key => $spec) {
        if (!is_array($spec)) {
            continue;
        }
        $fields[] = [
            'key'       => (string) $key,
            'type'      => (string) ($spec['type'] ?? ''),
            'valueType' => (string) ($spec['value_type'] ?? ''),
            'writer'    => (string) ($spec['writer'] ?? ''),
            'options'   => is_array($spec['options'] ?? null) ? $spec['options'] : null,
        ];
    }

    return new \WP_REST_Response([
        'data' => [
            'id'      => $type,
            'label'   => (string) ($meta['labels']['singular_name'] ?? $meta['label'] ?? ucfirst($type)),
            'modeled' => isset($modeled[$type]),
            'fields'  => $fields,
        ],
    ], 200);
}

/**
 * GET /wp-json/dc/v1/import-example
 */
function handle_import_example(): \WP_REST_Response
{
    return new \WP_REST_Response([
        'data' => Content\example_envelope(),
    ], 200);
}

/**
 * GET /wp-json/dc/v1/content?type=<type>&per_page=<n>&page=<n>
 */
function handle_list_content(\WP_REST_Request $request): \WP_REST_Response
{
    $type = (string) $request->get_param('type');
    $index = Content\field_index();
    if (!isset($index[$type])) {
        return new \WP_REST_Response([
            'error' => sprintf("Unknown content type '%s'.", $type),
        ], 404);
    }

    $per_page = max(1, min(100, (int) $request->get_param('per_page')));
    $page     = max(1, (int) $request->get_param('page'));

    $query = new \WP_Query([
        'post_type'      => $type,
        'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'no_found_rows'  => false,
    ]);

    $items = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof \WP_Post) {
            continue;
        }
        $items[] = [
            'id'       => $post->ID,
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'title'    => html_entity_decode((string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug'     => $post->post_name,
            'modified' => mysql_to_rfc3339($post->post_modified_gmt),
        ];
    }

    return new \WP_REST_Response([
        'data' => $items,
        'meta' => [
            'page'       => $page,
            'perPage'    => $per_page,
            'totalItems' => (int) $query->found_posts,
            'totalPages' => (int) $query->max_num_pages,
        ],
    ], 200);
}

/**
 * GET /wp-json/dc/v1/content/<id>
 *
 * Reads every modeled field flat off Carbon Fields meta. The shape
 * mirrors what `import-content` accepts on the way IN, so the contract
 * is symmetric (a value read here can be re-posted to /import-content).
 */
function handle_get_content(\WP_REST_Request $request): \WP_REST_Response
{
    $id = (int) $request->get_param('id');
    $post = get_post($id);
    if (!$post instanceof \WP_Post) {
        return new \WP_REST_Response([
            'error' => sprintf('No post with id %d.', $id),
        ], 404);
    }

    $type = $post->post_type;
    $index = Content\field_index();
    $field_specs = is_array($index[$type] ?? null) ? $index[$type] : [];

    $fields = [];
    foreach ($field_specs as $key => $spec) {
        if (!function_exists('carbon_get_post_meta')) {
            break;
        }
        $value = carbon_get_post_meta($post->ID, $key);
        // Carbon hands back `''` for unset scalars and `[]` for unset
        // complex fields; normalize both to null so callers don't have to
        // disambiguate "intentionally blank" from "never set".
        if ($value === '' || $value === [] || $value === null) {
            $fields[(string) $key] = null;
            continue;
        }
        $fields[(string) $key] = $value;
    }

    return new \WP_REST_Response([
        'data' => [
            'id'       => $post->ID,
            'type'     => $type,
            'status'   => $post->post_status,
            'title'    => html_entity_decode((string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug'     => $post->post_name,
            'content'  => $post->post_content,
            'modified' => mysql_to_rfc3339($post->post_modified_gmt),
            'fields'   => $fields,
        ],
    ], 200);
}

/**
 * POST /wp-json/dc/v1/import-config
 *
 * Body: the model JSON (top-level keys: version, postTypes,
 * taxonomies, fieldGroups, routes, etc.). Delegates to Config\save_model
 * which validates + persists + flushes rewrites. Returns the persisted
 * model on success, validation errors with HTTP 400 on rejection.
 */
function handle_import_config(\WP_REST_Request $request): \WP_REST_Response
{
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return new \WP_REST_Response([
            'errors' => ['Request body must be a JSON object containing the model.'],
        ], 400);
    }

    // Guard against a malformed POST silently wiping the active model.
    // The validator treats an empty model as "valid empty" (that's how
    // `wp dc model reset` works internally), but on a REST POST a typo'd
    // body shouldn't destroy the model — require an explicit shape. To
    // actually wipe the model, callers POST a model with version + an
    // empty postTypes/taxonomies, or hit the wp-admin Reset button.
    if (!isset($body['version']) || !isset($body['postTypes'])) {
        return new \WP_REST_Response([
            'errors' => [
                'Model body must include at least `version` and `postTypes`. To clear the model, send {"version":1,"postTypes":{},"taxonomies":{}}.',
            ],
        ], 400);
    }

    $errors = Config\save_model($body);
    if ($errors !== []) {
        return new \WP_REST_Response([
            'errors' => $errors,
        ], 400);
    }

    return new \WP_REST_Response([
        'data' => Config\model(),
    ], 200);
}

/**
 * POST /wp-json/dc/v1/import-content
 *
 * Body: the content envelope ({version, content: {terms, media, posts}}),
 * the same shape /import-example returns. Delegates to
 * Content\import_envelope, which validates the envelope against the
 * active model, then upserts terms / sideloads media / upserts posts
 * in two passes. Idempotent. Returns counts + warnings on success,
 * validation errors with HTTP 400 on rejection.
 */
function handle_import_content(\WP_REST_Request $request): \WP_REST_Response
{
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return new \WP_REST_Response([
            'ok'     => false,
            'errors' => ['Request body must be a JSON object containing a content envelope.'],
        ], 400);
    }

    $result = Content\import_envelope($body);

    if (!$result['ok']) {
        return new \WP_REST_Response([
            'ok'     => false,
            'errors' => $result['errors'] ?? [],
        ], 400);
    }

    return new \WP_REST_Response([
        'ok'       => true,
        'warnings' => $result['warnings'] ?? [],
        'summary'  => $result['summary'] ?? [],
    ], 200);
}
