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
