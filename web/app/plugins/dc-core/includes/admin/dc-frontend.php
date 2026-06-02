<?php
/**
 * wp-admin "Decoupled Frontend" page — the WP analogue of Drupal's
 * /dc-config page.
 *
 * Lives under wp-admin > Tools > Decoupled Frontend. On first visit
 * with the dashboard-pushed query param ?dc_frontend_url=<url>, it
 * pre-seeds the frontend URL and immediately fires
 * /wp-json/dc/v1/frontend-connect via inline JS — same UX shape as
 * Drupal's auto-connect on /dc-config.
 *
 * For subsequent visits, the page renders a summary of the current
 * connection status (read from the dc_frontend_status wp_option) +
 * a button to manually re-trigger connect (useful if the user
 * wired the frontend to a different URL later).
 *
 * Page slug: dc-frontend
 * Capability: manage_options (matches the REST endpoint's gate)
 */

namespace Dc\Core\Admin\DcFrontend;

use Dc\Core\FrontendConnect;

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', __NAMESPACE__ . '\\register_page');
add_action('admin_post_dc_frontend_save_url', __NAMESPACE__ . '\\handle_save_url');

const SLUG = 'dc-frontend';

function register_page(): void
{
    add_management_page(
        __('Decoupled Frontend', 'dc-core'),
        __('Decoupled Frontend', 'dc-core'),
        'manage_options',
        SLUG,
        __NAMESPACE__ . '\\render_page'
    );
}

/**
 * Capture ?dc_frontend_url=<url> early — before the page renders —
 * so the URL is persisted to the dc_frontend_status option and the
 * inline JS can pick it up. Runs on every admin_init so the
 * dashboard can deep-link to either the wp-admin index OR the
 * dc-frontend page itself.
 */
add_action('admin_init', __NAMESPACE__ . '\\maybe_capture_url_from_query');

function maybe_capture_url_from_query(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $url = isset($_GET['dc_frontend_url']) ? wp_unslash((string) $_GET['dc_frontend_url']) : '';
    if ($url === '') {
        return;
    }
    FrontendConnect\set_frontend_url($url);
}

function render_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view the Decoupled Frontend page.', 'dc-core'));
    }

    $status = get_option(FrontendConnect\OPTION_KEY, []);
    $status = is_array($status) ? $status : [];

    $state         = (string) ($status['status'] ?? 'none');
    $frontend_url  = (string) ($status['url'] ?? '');
    $updated_at    = (string) ($status['updated_at'] ?? '');
    $auto_connect  = ($state === 'pending') && $frontend_url !== '';

    $space_token = (string) get_option('dc_space_auth_token', '');

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Decoupled Frontend', 'dc-core'); ?></h1>

        <?php if ($state === 'active' && $frontend_url !== '') : ?>
            <div class="notice notice-success">
                <p>
                    <?php
                    /* translators: %s: frontend URL */
                    printf(
                        esc_html__('Connected to %s', 'dc-core'),
                        '<code><a href="' . esc_url($frontend_url) . '" target="_blank" rel="noopener">' . esc_html($frontend_url) . '</a></code>'
                    );
                    ?>
                </p>
                <?php if ($updated_at !== '') : ?>
                    <p class="description">
                        <?php
                        /* translators: %s: ISO timestamp */
                        printf(esc_html__('Last updated %s', 'dc-core'), esc_html($updated_at));
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php elseif ($state === 'pending' && $frontend_url !== '') : ?>
            <div id="dc-frontend-connecting" class="notice notice-info">
                <p>
                    <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                    <?php
                    /* translators: %s: frontend URL */
                    printf(
                        esc_html__('Connecting to %s — importing seed content, configuring preview, and triggering the first Netlify build…', 'dc-core'),
                        '<code>' . esc_html($frontend_url) . '</code>'
                    );
                    ?>
                </p>
            </div>
        <?php else : ?>
            <div class="notice notice-warning">
                <p>
                    <?php esc_html_e('No frontend is wired to this tenant yet. Provision an Astro frontend from the dashboard, or paste a frontend URL below.', 'dc-core'); ?>
                </p>
            </div>
        <?php endif; ?>

        <h2><?php esc_html_e('Frontend URL', 'dc-core'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('dc_frontend_save_url'); ?>
            <input type="hidden" name="action" value="dc_frontend_save_url">
            <p>
                <input
                    type="url"
                    name="dc_frontend_url"
                    class="regular-text"
                    style="width: 100%; max-width: 600px;"
                    placeholder="https://my-site.netlify.app"
                    value="<?php echo esc_attr($frontend_url); ?>"
                />
            </p>
            <p>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save and Connect', 'dc-core'); ?>
                </button>
            </p>
            <p class="description">
                <?php esc_html_e('Saving will import the launchpad seed content, point the Puck editor at this URL, and ask the dashboard to update the Netlify env vars + redeploy.', 'dc-core'); ?>
            </p>
        </form>

        <?php if ($state === 'active' || ($state === 'pending' && $frontend_url !== '')) : ?>
            <hr>
            <h2><?php esc_html_e('Status', 'dc-core'); ?></h2>
            <pre style="background: #fff; padding: 12px; border: 1px solid #d8dcdd; border-radius: 4px; max-width: 700px; overflow: auto;"><?php echo esc_html(wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php endif; ?>
    </div>

    <?php if ($auto_connect && $space_token !== '') : ?>
        <script type="application/json" id="dc-frontend-bootstrap">
            <?php
            echo wp_json_encode([
                'restUrl'     => rest_url('dc/v1/frontend-connect'),
                'spaceToken'  => $space_token,
                'frontendUrl' => $frontend_url,
                'reloadAfter' => true,
            ]);
            ?>
        </script>
        <script>
        (function () {
            var cfg;
            try {
                cfg = JSON.parse(document.getElementById('dc-frontend-bootstrap').textContent);
            } catch (e) { return; }

            fetch(cfg.restUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Decoupled-Token': cfg.spaceToken,
                },
                body: JSON.stringify({ frontendUrl: cfg.frontendUrl }),
            })
            .then(function (res) {
                if (!res.ok) {
                    return res.text().then(function (t) {
                        throw new Error('HTTP ' + res.status + ': ' + t.slice(0, 200));
                    });
                }
                return res.json();
            })
            .then(function () {
                if (cfg.reloadAfter) {
                    // Strip the dc_frontend_url query so refreshes don't
                    // reset the option back to pending mid-typing.
                    var u = new URL(window.location.href);
                    u.searchParams.delete('dc_frontend_url');
                    window.location.replace(u.toString());
                }
            })
            .catch(function (err) {
                var notice = document.getElementById('dc-frontend-connecting');
                if (notice) {
                    notice.className = 'notice notice-error';
                    notice.innerHTML = '<p>Connect failed: ' + String(err.message || err) + '</p>';
                }
                if (window.console) { console.error('dc-frontend-connect:', err); }
            });
        })();
        </script>
    <?php endif; ?>
    <?php
}

/**
 * Form handler for the "Save and Connect" button. Persists the URL,
 * marks status pending, redirects back to the page. The auto-connect
 * JS then fires on the next render.
 */
function handle_save_url(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to update the frontend URL.', 'dc-core'));
    }
    check_admin_referer('dc_frontend_save_url');

    $url = isset($_POST['dc_frontend_url']) ? wp_unslash((string) $_POST['dc_frontend_url']) : '';
    $url = esc_url_raw($url);
    if ($url === '') {
        wp_safe_redirect(admin_url('tools.php?page=' . SLUG . '&error=empty'));
        exit;
    }

    FrontendConnect\set_frontend_url($url);
    wp_safe_redirect(admin_url('tools.php?page=' . SLUG));
    exit;
}
