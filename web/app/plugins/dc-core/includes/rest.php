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
