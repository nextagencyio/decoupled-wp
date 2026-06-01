<?php
/**
 * Short-lived per-post edit tokens.
 *
 * The headless Puck editor never holds WP credentials — it holds a
 * token scoped to one post, minted by an authenticated editor and
 * validated on load/save. Mirrors dc_puck's TokenController.
 *
 * Backed by a WP transient with an hour TTL.
 */

namespace Dc\Puck\Token;

if (!defined('ABSPATH')) {
    exit;
}

const TTL = HOUR_IN_SECONDS;

function transient_key(int $post_id): string
{
    return "dc_puck_token_{$post_id}";
}

/**
 * Mint a token for a post. Caller must already be cap-checked.
 */
function generate(int $post_id): string
{
    $token = wp_generate_password(40, false, false);
    set_transient(transient_key($post_id), $token, TTL);
    return $token;
}

/**
 * Validate a token against a post.
 */
function validate(int $post_id, string $token): bool
{
    if ($token === '') {
        return false;
    }
    $stored = get_transient(transient_key($post_id));
    return is_string($stored) && hash_equals($stored, $token);
}

/**
 * A request is authorized for a post if EITHER:
 *  - the current user can edit it (logged-in editor in wp-admin), OR
 *  - it carries a valid edit token (headless frontend).
 */
function authorize(int $post_id, \WP_REST_Request $request): bool
{
    if (current_user_can('edit_post', $post_id)) {
        return true;
    }
    $token = (string) ($request->get_param('token') ?? $request->get_header('x-dc-puck-token') ?? '');
    return validate($post_id, $token);
}
