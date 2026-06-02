<?php
/**
 * Frontend Connect — the WP analogue of Drupal's
 * DcConfigController::triggerConnect().
 *
 * When a user provisions a WP space + paired Astro frontend (the
 * "Decoupled Components (Astro WP)" template), the Netlify site is
 * created with placeholder env vars. The actual wiring happens when
 * something (the wp-admin dc-frontend page, or a future MCP tool)
 * POSTs to /wp-json/dc/v1/frontend-connect:
 *
 *   1. Import the bundled seed content (seed/launchpad.json) so
 *      the Astro app has something to render.
 *   2. Activate dc-puck + point its editor at the frontend URL.
 *   3. Mark this tenant's `dc_frontend_status` wp_option as 'active'.
 *   4. POST to the dashboard's /api/spaces/frontend-env-update so
 *      Netlify gets the real WORDPRESS_* env vars + a redeploy fires.
 *
 * Auth: X-Decoupled-Token header containing the space's auth token
 * (same pattern as /wp-json/dc/v1/auth-credentials). The endpoint
 * holds the keys to the kingdom — content import + admin-level config
 * changes — so we want the same bounded blast radius as the JWT mint.
 */

namespace Dc\Core\FrontendConnect;

use Dc\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', __NAMESPACE__ . '\\register_routes');

const OPTION_KEY = 'dc_frontend_status';
const DEFAULT_SEED = 'launchpad';
// The dashboard endpoint we call back to wire real env vars on Netlify.
// Override via DC_DASHBOARD_URL env (dev: http://host.docker.internal:3333).
const DEFAULT_DASHBOARD = 'https://dashboard.decoupled.io';

function register_routes(): void
{
    register_rest_route('dc/v1', '/frontend-connect', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\handle_connect',
        'permission_callback' => __NAMESPACE__ . '\\require_space_token',
    ]);

    register_rest_route('dc/v1', '/frontend-status', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\handle_status',
        // Reads the same option the admin page renders from; same
        // gate as connect itself (operators only, no anon snooping).
        'permission_callback' => __NAMESPACE__ . '\\require_space_token',
    ]);
}

/**
 * Permission gate: X-Decoupled-Token must match the persisted
 * dc_space_auth_token wp_option. Mirrors handle_auth_credentials's
 * pattern from rest.php.
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
 * POST /wp-json/dc/v1/frontend-connect
 *
 * Body (all optional):
 *   {
 *     "frontendUrl": "https://my-site.netlify.app",  // overrides any push from dashboard
 *     "seed":        "launchpad",                    // bundled seed name; falls back to DEFAULT_SEED
 *     "skipContent": false                           // true to skip the seed import step
 *   }
 *
 * Returns a per-step result map mirroring Drupal's triggerConnect
 * shape so dashboard/MCP consumers can treat the two backends
 * uniformly. Each step is independently try/catch'd — a single
 * failure doesn't abort the others.
 */
function handle_connect(\WP_REST_Request $request): \WP_REST_Response
{
    $body         = $request->get_json_params();
    $body         = is_array($body) ? $body : [];
    $frontend_url = isset($body['frontendUrl']) ? trim((string) $body['frontendUrl']) : '';
    $seed_name    = isset($body['seed']) ? sanitize_key((string) $body['seed']) : DEFAULT_SEED;
    $skip_content = !empty($body['skipContent']);

    // Resolve frontend URL: explicit body wins, then a previously-stored
    // value (the dashboard may push it during turnkey provisioning), then
    // bail. We can't connect without knowing where the frontend lives.
    $stored = get_option(OPTION_KEY, []);
    $stored = is_array($stored) ? $stored : [];
    if ($frontend_url === '') {
        $frontend_url = (string) ($stored['url'] ?? '');
    }
    if ($frontend_url === '') {
        return new \WP_REST_Response([
            'success' => false,
            'error'   => 'No frontend URL provided (body.frontendUrl) and none previously stored.',
        ], 400);
    }

    // Idempotency: if already connected to the same URL, no-op.
    if (
        ($stored['status'] ?? '') === 'active'
        && rtrim((string) ($stored['url'] ?? ''), '/') === rtrim($frontend_url, '/')
    ) {
        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Already connected to ' . $frontend_url,
            'results' => $stored,
        ], 200);
    }

    $results = [
        'content_imported'   => null,
        'puck_configured'    => null,
        'netlify_updated'    => null,
    ];

    // Step 1 — seed content import (skippable).
    if ($skip_content) {
        $results['content_imported'] = 'skipped';
    } else {
        try {
            $seed_path = DC_CORE_DIR . 'seed/' . $seed_name . '.json';
            if (!is_file($seed_path)) {
                throw new \RuntimeException("No bundled seed at $seed_path");
            }
            $envelope = json_decode((string) file_get_contents($seed_path), true);
            if (!is_array($envelope)) {
                throw new \RuntimeException('Seed JSON failed to parse: ' . json_last_error_msg());
            }
            $result = Content\import_envelope($envelope);
            if (!$result['ok']) {
                throw new \RuntimeException('import_envelope: ' . implode('; ', $result['errors'] ?? []));
            }
            $results['content_imported'] = true;
            $results['import_summary']   = $result['summary'] ?? [];
        } catch (\Throwable $e) {
            $results['content_imported'] = false;
            $results['import_error']     = $e->getMessage();
        }
    }

    // Step 2 — point dc-puck at the frontend so the in-editor preview
    // iframe loads the Astro site. Idempotent (just sets options).
    try {
        update_option('dc_puck_editor_url', $frontend_url, false);
        // dc-puck's enable flag (read by includes/admin.php). Default
        // option name; if you've renamed it in dc-puck, update here.
        update_option('dc_puck_enabled', true, false);
        $results['puck_configured'] = true;
    } catch (\Throwable $e) {
        $results['puck_configured'] = false;
        $results['puck_error']      = $e->getMessage();
    }

    // Step 3 — call back to the dashboard so Netlify env vars get the
    // real values + a redeploy is triggered. The dashboard endpoint
    // expects a space-token-authenticated POST and resolves the
    // Netlify site_id from the spaces row. No JWT needed here: the
    // dashboard already trusts the space token for this purpose
    // (matches the Drupal flow exactly).
    try {
        $space_token  = (string) get_option('dc_space_auth_token', '');
        $dashboard    = $_ENV['DC_DASHBOARD_URL']
            ?? getenv('DC_DASHBOARD_URL')
            ?: DEFAULT_DASHBOARD;
        $site_url     = home_url();
        $callback_url = rtrim((string) $dashboard, '/') . '/api/spaces/frontend-env-update';

        $response = wp_remote_post($callback_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode([
                'spaceToken' => $space_token,
                // backend hint so the dashboard knows to emit
                // WORDPRESS_* env vars rather than DRUPAL_*. The
                // dashboard route also infers from spaces.template if
                // missing, but explicit > implicit.
                'backend'    => 'wordpress',
                'siteUrl'    => $site_url,
            ]),
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('callback failed: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("callback returned HTTP $code: " . wp_remote_retrieve_body($response));
        }
        $results['netlify_updated'] = true;
        $decoded                    = json_decode((string) wp_remote_retrieve_body($response), true);
        if (is_array($decoded) && isset($decoded['deployId'])) {
            $results['netlify_deploy_id'] = (string) $decoded['deployId'];
        }
    } catch (\Throwable $e) {
        $results['netlify_updated'] = false;
        $results['netlify_error']   = $e->getMessage();
    }

    // Persist the new status so subsequent visits see "active" and
    // /frontend-status returns it.
    $new_status = [
        'status'             => 'active',
        'url'                => $frontend_url,
        'content_imported'   => (bool) $results['content_imported'],
        'puck_configured'    => (bool) $results['puck_configured'],
        'netlify_updated'    => (bool) $results['netlify_updated'],
        'updated_at'         => gmdate('c'),
    ];
    update_option(OPTION_KEY, $new_status, false);

    return new \WP_REST_Response([
        'success' => true,
        'status'  => $new_status,
        'results' => $results,
    ], 200);
}

/**
 * GET /wp-json/dc/v1/frontend-status
 *
 * Returns the currently-stored frontend status. Used by the
 * wp-admin dc-frontend page to decide whether to auto-trigger
 * connect or just show "you're already connected to <url>".
 */
function handle_status(\WP_REST_Request $request): \WP_REST_Response
{
    $stored = get_option(OPTION_KEY, []);
    if (!is_array($stored)) {
        $stored = [];
    }
    return new \WP_REST_Response([
        'status' => $stored['status'] ?? 'none',
        'data'   => $stored,
    ], 200);
}

/**
 * Internal helper for the wp-admin page (or future MCP tool) to
 * pre-seed the frontend URL without firing the full connect.
 * Stores the URL as the tenant's known frontend; subsequent
 * connect calls without a body URL fall back to this one.
 *
 * Called by setup-frontend.php (the admin page) when the page
 * reads the URL from a query parameter the dashboard appended
 * (?dc_frontend_url=...) during turnkey hand-off.
 */
function set_frontend_url(string $url): void
{
    $url = esc_url_raw($url);
    if ($url === '') {
        return;
    }
    $stored        = get_option(OPTION_KEY, []);
    $stored        = is_array($stored) ? $stored : [];
    $stored['url'] = $url;
    if (!isset($stored['status'])) {
        $stored['status'] = 'pending';
    }
    update_option(OPTION_KEY, $stored, false);
}

/**
 * Canonical "what's the public Astro frontend URL?" lookup, used by
 * preview-links, dashboard, compliance-dashboard, etc.
 *
 * Resolution order (most specific first):
 *   1. dc_frontend_status['url']    set by frontend-connect (real Netlify URL)
 *   2. DC_FRONTEND_URL constant     wp-config define (provisioner placeholder
 *                                   or a project-specific override)
 *   3. DC_FRONTEND_URL env var      runtime env
 *   4. http://localhost:4321        DDEV-friendly dev fallback
 *
 * Without #1 the View / Preview / Puck editor URLs all collapse to
 * the placeholder DC_FRONTEND_URL, which the provisioner sets to the
 * TENANT's own URL — so View links round-trip through WP and look
 * broken. Once the user runs frontend-connect, the stored option
 * takes precedence and View links point at the real Netlify host.
 */
function resolve_frontend_url(): string
{
    $stored = get_option(OPTION_KEY, []);
    if (is_array($stored) && !empty($stored['url'])) {
        return untrailingslashit((string) $stored['url']);
    }
    if (defined('DC_FRONTEND_URL')) {
        return untrailingslashit((string) DC_FRONTEND_URL);
    }
    $env = getenv('DC_FRONTEND_URL');
    return untrailingslashit($env ?: 'http://localhost:4321');
}
