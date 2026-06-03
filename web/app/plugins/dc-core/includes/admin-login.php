<?php
/**
 * One-click login link — the WP analogue of Drupal's
 * `/api/generate-login` (user_pass_reset_url + auto-login route).
 *
 * Two-step flow:
 *
 *   1) Mint:  POST /wp-json/dc/v1/admin-login    (X-Decoupled-Token)
 *      Returns: { login_url, user, expires }
 *      where login_url points back at /wp-json/dc/v1/admin-login/redirect?key=...
 *
 *   2) Click: GET /wp-json/dc/v1/admin-login/redirect?key=<nonce>
 *      Validates the nonce (single-use, ~5 min TTL), calls
 *      wp_set_auth_cookie() for the admin user, and 302s to wp-admin.
 *
 * WordPress has no native one-time-login URL — `get_password_reset_key()`
 * gives a reset (two-step: click → set new password). This endpoint is
 * a true one-click login so MCP `get_login_link` can return the same
 * shape it returns for Drupal spaces. The nonce is stored as a
 * single-use transient hashed at rest; the plaintext lives only in the
 * URL emitted by step 1.
 */

namespace Dc\Core\AdminLogin;

if (!defined('ABSPATH')) {
    exit;
}

const NAMESPACE_V1     = 'dc/v1';
const TRANSIENT_PREFIX = 'dc_admin_login_';
const TTL_SECONDS      = 300; // 5 minutes; WP's pass-reset key is 24h, but for an auto-login URL emitted by MCP we want tighter blast radius.

add_action('rest_api_init', __NAMESPACE__ . '\\register_routes');

function register_routes(): void
{
    register_rest_route(NAMESPACE_V1, '/admin-login', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\handle_mint',
        'permission_callback' => __NAMESPACE__ . '\\require_space_token',
    ]);

    register_rest_route(NAMESPACE_V1, '/admin-login/redirect', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_redirect',
        'permission_callback' => '__return_true', // gated by the nonce in ?key=
    ]);
}

/**
 * Same gate as frontend-connect / auth-credentials: X-Decoupled-Token
 * header must match the persisted dc_space_auth_token wp_option.
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
 * POST /wp-json/dc/v1/admin-login
 *
 * Body (all optional):
 *   { "user_login": "<login>" }   defaults to the first admin user (user ID 1 if it exists & is admin, else the lowest-ID administrator).
 *
 * Returns: { login_url, user: {id, login, email}, expires }
 */
function handle_mint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
{
    $body  = $request->get_json_params() ?: [];
    $login = isset($body['user_login']) ? sanitize_user((string) $body['user_login']) : '';

    $user = $login !== '' ? get_user_by('login', $login) : resolve_default_admin();
    if (!$user || !user_can($user, 'manage_options')) {
        return new \WP_Error(
            'dc_no_admin_user',
            'No administrator user available to log in.',
            ['status' => 404]
        );
    }

    $nonce      = wp_generate_password(48, false, false);
    $nonce_hash = hash('sha256', $nonce);
    $expires    = time() + TTL_SECONDS;

    set_transient(
        TRANSIENT_PREFIX . $nonce_hash,
        [
            'user_id' => $user->ID,
            'expires' => $expires,
        ],
        TTL_SECONDS
    );

    $login_url = add_query_arg(
        ['key' => $nonce],
        rest_url(NAMESPACE_V1 . '/admin-login/redirect')
    );

    return new \WP_REST_Response([
        'success'   => true,
        'login_url' => $login_url,
        'user'      => [
            'id'    => (int) $user->ID,
            'login' => (string) $user->user_login,
            'email' => (string) $user->user_email,
        ],
        'expires'   => gmdate('c', $expires),
    ], 200);
}

/**
 * GET /wp-json/dc/v1/admin-login/redirect?key=<nonce>
 *
 * Validates the nonce, deletes it (single-use), sets the auth cookie,
 * and 302s into wp-admin. Never returns JSON — always redirects (to
 * /wp-admin on success, or to /wp/wp-login.php with an error notice on
 * failure) so the user lands somewhere usable.
 */
function handle_redirect(\WP_REST_Request $request): void
{
    $nonce = (string) $request->get_param('key');
    $login_url = wp_login_url();

    if ($nonce === '') {
        wp_safe_redirect(add_query_arg('dc_login_error', 'missing-key', $login_url));
        exit;
    }

    $nonce_hash = hash('sha256', $nonce);
    $payload    = get_transient(TRANSIENT_PREFIX . $nonce_hash);

    // Single-use: delete immediately so a replay can't re-log-in.
    delete_transient(TRANSIENT_PREFIX . $nonce_hash);

    if (!is_array($payload) || empty($payload['user_id'])) {
        wp_safe_redirect(add_query_arg('dc_login_error', 'invalid-or-expired', $login_url));
        exit;
    }

    if (!empty($payload['expires']) && time() > (int) $payload['expires']) {
        wp_safe_redirect(add_query_arg('dc_login_error', 'expired', $login_url));
        exit;
    }

    $user = get_user_by('id', (int) $payload['user_id']);
    if (!$user || !user_can($user, 'manage_options')) {
        wp_safe_redirect(add_query_arg('dc_login_error', 'user-gone', $login_url));
        exit;
    }

    wp_clear_auth_cookie();
    wp_set_current_user($user->ID, $user->user_login);
    wp_set_auth_cookie($user->ID, false, is_ssl());
    do_action('wp_login', $user->user_login, $user);

    wp_safe_redirect(admin_url());
    exit;
}

/**
 * Pick the admin user to log in when the caller didn't specify one.
 * Prefers user ID 1 if it's an admin (matches WP-CLI's default), else
 * the lowest-ID administrator.
 */
function resolve_default_admin(): ?\WP_User
{
    $u1 = get_user_by('id', 1);
    if ($u1 && user_can($u1, 'manage_options')) {
        return $u1;
    }
    $admins = get_users([
        'role'    => 'administrator',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => 1,
    ]);
    return $admins[0] ?? null;
}

/**
 * Surface the error reasons on the standard wp-login.php page so the
 * user sees something better than a blank/raw form when the magic
 * link is stale.
 */
add_filter('login_message', function (string $message): string {
    $code = isset($_GET['dc_login_error']) ? sanitize_key((string) $_GET['dc_login_error']) : '';
    if ($code === '') {
        return $message;
    }
    $map = [
        'missing-key'        => 'This login link is missing its key.',
        'invalid-or-expired' => 'This login link is invalid or has already been used.',
        'expired'            => 'This login link has expired. Generate a new one.',
        'user-gone'          => 'The user this link was issued for no longer has access.',
    ];
    $text = $map[$code] ?? 'Login link could not be used.';
    return $message . '<p class="message">' . esc_html($text) . '</p>';
});
