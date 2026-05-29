<?php
/**
 * REST contract for the headless Puck editor.
 *
 * Mirrors dc_puck's routes under the spark-puck/v1 namespace:
 *   GET  load/{id}        post → Puck JSON
 *   POST save/{id}        Puck JSON → post
 *   GET  mapping          component↔prop mapping
 *   POST configure        set mapping / sections field
 *   GET  token/{id}       mint an edit token (editor only)
 *   POST validate-token   verify a token
 */

namespace Spark\Puck\Rest;

use Spark\Puck\Mapping;
use Spark\Puck\Token;
use Spark\Puck\Transform;

if (!defined('ABSPATH')) {
    exit;
}

const NS = 'spark-puck/v1';

function register_routes(): void
{
    register_rest_route(NS, '/load/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\load',
        'permission_callback' => __NAMESPACE__ . '\\can_edit',
        'args'                => ['id' => ['validate_callback' => 'is_numeric']],
    ]);

    register_rest_route(NS, '/save/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\save',
        'permission_callback' => __NAMESPACE__ . '\\can_edit',
        'args'                => ['id' => ['validate_callback' => 'is_numeric']],
    ]);

    register_rest_route(NS, '/mapping', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\get_mapping',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route(NS, '/configure', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\configure',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route(NS, '/token/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\mint_token',
        // Minting requires a real logged-in editor — never a token.
        'permission_callback' => function (\WP_REST_Request $r) {
            return current_user_can('edit_post', (int) $r['id']);
        },
        'args'                => ['id' => ['validate_callback' => 'is_numeric']],
    ]);

    register_rest_route(NS, '/validate-token', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\check_token',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Shared permission callback for load/save: editor OR valid token.
 */
function can_edit(\WP_REST_Request $request): bool
{
    return Token\authorize((int) $request['id'], $request);
}

function load(\WP_REST_Request $request): \WP_REST_Response
{
    $post_id = (int) $request['id'];
    if (!get_post($post_id)) {
        return new \WP_REST_Response(['error' => 'not_found'], 404);
    }
    return new \WP_REST_Response(Transform\load($post_id), 200);
}

function save(\WP_REST_Request $request): \WP_REST_Response
{
    $post_id = (int) $request['id'];
    if (!get_post($post_id)) {
        return new \WP_REST_Response(['error' => 'not_found'], 404);
    }
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return new \WP_REST_Response(['error' => 'invalid_body'], 400);
    }

    $warnings = [];
    Transform\save($post_id, $body, $warnings);

    return new \WP_REST_Response([
        'ok'       => true,
        'warnings' => $warnings,
        'data'     => Transform\load($post_id), // echo the canonical state back
    ], 200);
}

function get_mapping(): \WP_REST_Response
{
    return new \WP_REST_Response([
        'sectionsField' => Mapping\sections_field(),
        'mapping'       => Mapping\mapping(),
    ], 200);
}

function configure(\WP_REST_Request $request): \WP_REST_Response
{
    $body = $request->get_json_params();
    if (isset($body['mapping']) && is_array($body['mapping'])) {
        Mapping\set_mapping($body['mapping']);
    }
    if (isset($body['sectionsField']) && is_string($body['sectionsField'])) {
        update_option('spark_puck_sections_field', sanitize_key($body['sectionsField']));
    }
    return new \WP_REST_Response([
        'ok'            => true,
        'sectionsField' => Mapping\sections_field(),
        'mapping'       => Mapping\mapping(),
    ], 200);
}

function mint_token(\WP_REST_Request $request): \WP_REST_Response
{
    $post_id = (int) $request['id'];
    return new \WP_REST_Response([
        'token'   => Token\generate($post_id),
        'postId'  => $post_id,
        'expires' => time() + Token\TTL,
    ], 200);
}

function check_token(\WP_REST_Request $request): \WP_REST_Response
{
    $body = $request->get_json_params();
    $post_id = (int) ($body['postId'] ?? 0);
    $token = (string) ($body['token'] ?? '');
    return new \WP_REST_Response(['valid' => Token\validate($post_id, $token)], 200);
}
