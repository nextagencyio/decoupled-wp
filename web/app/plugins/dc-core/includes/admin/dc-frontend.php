<?php
/**
 * wp-admin "Decoupled" landing page — the WP analogue of Drupal's
 * /dc-config page.
 *
 * Promoted from Tools submenu to a TOP-LEVEL admin menu item (mirrors
 * Drupal's prominent placement) and registered as the default
 * landing page for admins on login (via the `login_redirect` filter).
 *
 * Three visual states:
 *   - is_active   — frontend wired and connected (success hero)
 *   - is_pending  — URL stored but connect not yet fired (auto-trigger JS)
 *   - is_unwired  — no frontend URL at all (warning + manual form)
 *
 * Both states render the same settings-card grid (Design Studio,
 * Content Model, Compliance Dashboard, Brand) and the same sidebar
 * quick-start checklist with done/pending indicators per pipeline step.
 */

namespace Dc\Core\Admin\DcFrontend;

use Dc\Core\FrontendConnect;

if (!defined('ABSPATH')) {
    exit;
}

const SLUG = 'dc-frontend';
const CAP  = 'manage_options';

add_action('admin_menu', __NAMESPACE__ . '\\register_page');
add_action('admin_post_dc_frontend_save_url', __NAMESPACE__ . '\\handle_save_url');
add_action('admin_init', __NAMESPACE__ . '\\maybe_capture_url_from_query');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets');
add_filter('login_redirect', __NAMESPACE__ . '\\redirect_admins_to_landing', 10, 3);

/**
 * Register the top-level menu page. dashicons-admin-site-alt3 is the
 * standard "site" globe icon — appropriate for the "go to the public
 * site" concept the page revolves around.
 */
function register_page(): void
{
    add_menu_page(
        __('Decoupled', 'dc-core'),
        __('Decoupled', 'dc-core'),
        CAP,
        SLUG,
        __NAMESPACE__ . '\\render_page',
        'dashicons-admin-site-alt3',
        2 // Just under "Dashboard" (which is position 2 itself; WP will resolve the collision by appending).
    );
}

/**
 * Capture ?dc_frontend_url=<url> on every admin_init so the dashboard
 * can deep-link operators here with the Netlify URL pre-seeded (the
 * "Configure Frontend" menu item in SpacesGrid.tsx appends it).
 */
function maybe_capture_url_from_query(): void
{
    if (!current_user_can(CAP)) {
        return;
    }
    $url = isset($_GET['dc_frontend_url']) ? wp_unslash((string) $_GET['dc_frontend_url']) : '';
    if ($url === '') {
        return;
    }
    FrontendConnect\set_frontend_url($url);
}

/**
 * Stylesheet load gate. The page-hook check keeps the CSS off every
 * other admin page (load-styles.php caches per-page, so an
 * unconditionally-enqueued sheet bloats every screen).
 */
function enqueue_assets(string $hook): void
{
    // toplevel_page_{slug} is the hook name for top-level menu pages.
    if ($hook !== 'toplevel_page_' . SLUG) {
        return;
    }
    wp_enqueue_style(
        'dc-frontend-landing',
        plugins_url('assets/css/dc-frontend.css', DC_CORE_DIR . 'dc-core.php'),
        [],
        DC_CORE_VERSION
    );
}

/**
 * Send admins (manage_options capability) to /admin.php?page=dc-frontend
 * after login instead of the stock Dashboard. Operators of a freshly-
 * provisioned space land on the "connect your frontend" panel — same
 * UX as Drupal's /dc-config redirect.
 *
 * Non-admins (editor, author, etc.) skip the override and go through
 * WP's normal redirect_to chain.
 */
function redirect_admins_to_landing($redirect_to, $requested_redirect_to, $user)
{
    if (!($user instanceof \WP_User) || !user_can($user, CAP)) {
        return $redirect_to;
    }
    // Honor explicit redirect_to from the URL — operator landing on
    // an edit screen URL while logged out should still go there after
    // sign-in, not be hijacked to our landing.
    if (
        is_string($requested_redirect_to)
        && $requested_redirect_to !== ''
        && $requested_redirect_to !== admin_url()
    ) {
        return $redirect_to;
    }
    return admin_url('admin.php?page=' . SLUG);
}

function render_page(): void
{
    if (!current_user_can(CAP)) {
        wp_die(esc_html__('You do not have permission to view the Decoupled page.', 'dc-core'));
    }

    $status = get_option(FrontendConnect\OPTION_KEY, []);
    $status = is_array($status) ? $status : [];

    $state         = (string) ($status['status'] ?? 'none');
    $frontend_url  = (string) ($status['url'] ?? '');
    $updated_at    = (string) ($status['updated_at'] ?? '');
    $is_active     = $state === 'active' && $frontend_url !== '';
    $is_pending    = $state === 'pending' && $frontend_url !== '';
    $is_unwired    = !$is_active && !$is_pending;
    $auto_connect  = $is_pending;
    $space_token   = (string) get_option('dc_space_auth_token', '');

    // Per-pipeline-step flags drive the sidebar checklist. From
    // dc_frontend_status: content_imported, puck_configured, netlify_updated.
    $content_done  = !empty($status['content_imported']);
    $puck_done     = !empty($status['puck_configured']);
    $netlify_done  = !empty($status['netlify_updated']);
    ?>
    <div class="wrap dc-frontend">
        <?php render_hero($is_active, $is_pending, $is_unwired, $frontend_url, $updated_at); ?>

        <div class="dcf-layout">
            <div class="dcf-content">
                <?php render_settings_cards(); ?>
                <?php render_url_form($frontend_url, $is_active); ?>
                <?php render_advanced($status); ?>
            </div>
            <aside class="dcf-sidebar">
                <?php render_checklist($content_done, $puck_done, $netlify_done, $is_active); ?>
            </aside>
        </div>
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
                if (!res.ok) return res.text().then(function (t) {
                    throw new Error('HTTP ' + res.status + ': ' + t.slice(0, 200));
                });
                return res.json();
            })
            .then(function () {
                if (cfg.reloadAfter) {
                    var u = new URL(window.location.href);
                    u.searchParams.delete('dc_frontend_url');
                    window.location.replace(u.toString());
                }
            })
            .catch(function (err) {
                if (window.console) { console.error('dc-frontend-connect:', err); }
            });
        })();
        </script>
    <?php endif; ?>
    <?php
}

function render_hero(bool $is_active, bool $is_pending, bool $is_unwired, string $frontend_url, string $updated_at): void
{
    ?>
    <h1><?php esc_html_e('Decoupled', 'dc-core'); ?></h1>
    <p class="dcf-subtitle">
        <?php esc_html_e('WordPress is the editorial backend. Your public site is the Astro frontend that this page connects to.', 'dc-core'); ?>
    </p>

    <?php if ($is_active) : ?>
        <div class="dcf-status-card is-active">
            <h2>
                <span aria-hidden="true">✓</span>
                <?php esc_html_e('Your site is live', 'dc-core'); ?>
            </h2>
            <a class="dcf-status-url" href="<?php echo esc_url($frontend_url); ?>" target="_blank" rel="noopener">
                <?php echo esc_html($frontend_url); ?>
            </a>
            <?php if ($updated_at !== '') : ?>
                <p class="dcf-status-meta">
                    <?php
                    /* translators: %s ISO timestamp of last connect */
                    printf(esc_html__('Last connected %s', 'dc-core'), esc_html($updated_at));
                    ?>
                </p>
            <?php endif; ?>
        </div>
    <?php elseif ($is_pending) : ?>
        <div class="dcf-status-card is-pending">
            <h2>
                <span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>
                <?php esc_html_e('Setting up your site…', 'dc-core'); ?>
            </h2>
            <a class="dcf-status-url" href="<?php echo esc_url($frontend_url); ?>" target="_blank" rel="noopener">
                <?php echo esc_html($frontend_url); ?>
            </a>
            <p class="dcf-status-meta">
                <?php esc_html_e('Importing seed content, configuring the editor, and triggering the first build.', 'dc-core'); ?>
            </p>
        </div>
    <?php else : ?>
        <div class="dcf-status-card is-unwired">
            <h2>
                <span aria-hidden="true">⚠</span>
                <?php esc_html_e('No frontend wired yet', 'dc-core'); ?>
            </h2>
            <p class="dcf-status-meta">
                <?php esc_html_e('Provision an Astro frontend from the dashboard, or paste a frontend URL below.', 'dc-core'); ?>
            </p>
        </div>
    <?php endif; ?>
    <?php
}

/**
 * Settings cards mirror Drupal's /dc-config "Settings" grid. Each
 * card links to a wp-admin page that owns the relevant config.
 */
function render_settings_cards(): void
{
    // Only link to pages that actually exist in dc-core / dc-puck today.
    // brand-admin.php hooks into login + admin styles (no settings page);
    // dc-puck/includes/admin.php is just the per-post "Open Design
    // Studio" button (no global settings page). Adding cards for those
    // would 404 — better to point at the post-list screens where the
    // editor button lives and skip Brand entirely until it has its own
    // settings page.
    $cards = [
        [
            'href'  => admin_url('tools.php?page=dc-content-model'),
            'icon'  => 'box',
            'tint'  => '#fff7ed',
            'color' => '#f97316',
            'title' => __('Content Model', 'dc-core'),
            'desc'  => __('Post types, taxonomies, and Carbon Fields field groups', 'dc-core'),
        ],
        [
            // Landing-page edit screen is where the "Open Design Studio"
            // button lives (dc-puck/includes/admin.php injects it).
            'href'  => admin_url('edit.php?post_type=dc_landing'),
            'icon'  => 'palette',
            'tint'  => '#eff6ff',
            'color' => '#3a6ea5',
            'title' => __('Design Studio', 'dc-core'),
            'desc'  => __('Edit landing pages with the Puck visual editor', 'dc-core'),
        ],
        [
            'href'  => admin_url('admin.php?page=dc-compliance'),
            'icon'  => 'shield',
            'tint'  => '#ecfdf5',
            'color' => '#10b981',
            'title' => __('Compliance', 'dc-core'),
            'desc'  => __('Audit results — accessibility, performance, SEO', 'dc-core'),
        ],
        [
            'href'  => admin_url('edit.php?post_type=page'),
            'icon'  => 'sparkles',
            'tint'  => '#fdf2f8',
            'color' => '#ec4899',
            'title' => __('Pages', 'dc-core'),
            'desc'  => __('Manage your standard WordPress pages', 'dc-core'),
        ],
    ];

    ?>
    <div class="dcf-cards">
        <?php foreach ($cards as $c) : ?>
            <a class="dcf-card" href="<?php echo esc_url($c['href']); ?>">
                <span class="dcf-card-icon" style="background:<?php echo esc_attr($c['tint']); ?>;color:<?php echo esc_attr($c['color']); ?>;">
                    <?php echo render_icon($c['icon']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped (icon SVGs are static literals below) ?>
                </span>
                <span>
                    <span class="dcf-card-title"><?php echo esc_html($c['title']); ?></span>
                    <span class="dcf-card-desc"><?php echo esc_html($c['desc']); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Static SVG library for the settings cards. Inlined to avoid a
 * separate sprite fetch on a page that loads <= 10 times in a
 * tenant's lifetime.
 */
function render_icon(string $name): string
{
    $icons = [
        'box'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
        'palette'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.438-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
        'shield'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>',
        'sparkles' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function render_url_form(string $frontend_url, bool $is_active): void
{
    // When already active, collapse the form inside <details> so it's
    // there for re-wiring but doesn't compete with the success hero.
    $wrap_open  = $is_active ? '<details class="dcf-url-form"><summary>' . esc_html__('Re-wire frontend', 'dc-core') . '</summary>' : '<div class="dcf-url-form">';
    $wrap_close = $is_active ? '</details>' : '</div>';
    ?>
    <?php echo $wrap_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped (static markup) ?>
        <?php if (!$is_active) : ?>
            <h3><?php esc_html_e('Frontend URL', 'dc-core'); ?></h3>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('dc_frontend_save_url'); ?>
            <input type="hidden" name="action" value="dc_frontend_save_url">
            <input
                type="url"
                name="dc_frontend_url"
                class="regular-text"
                placeholder="https://my-site.netlify.app"
                value="<?php echo esc_attr($frontend_url); ?>"
            />
            <p>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save and Connect', 'dc-core'); ?>
                </button>
            </p>
            <p class="description">
                <?php esc_html_e('Saving imports the launchpad seed content, points the Puck editor at this URL, and asks the dashboard to update the Netlify env vars + redeploy.', 'dc-core'); ?>
            </p>
        </form>
    <?php echo $wrap_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
}

function render_advanced(array $status): void
{
    if ($status === []) {
        return;
    }
    ?>
    <details class="dcf-advanced">
        <summary><?php esc_html_e('Advanced — raw status', 'dc-core'); ?></summary>
        <div>
            <pre class="dcf-status-json"><?php echo esc_html(wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
        </div>
    </details>
    <?php
}

function render_checklist(bool $content_done, bool $puck_done, bool $netlify_done, bool $is_active): void
{
    $items = [
        [
            'title'   => __('Content imported', 'dc-core'),
            'desc'    => __('LaunchPad seed posts populated', 'dc-core'),
            'done'    => $content_done,
            'pending' => __('Importing…', 'dc-core'),
        ],
        [
            'title'   => __('Design Studio configured', 'dc-core'),
            'desc'    => __('Puck editor pointed at frontend', 'dc-core'),
            'done'    => $puck_done,
            'pending' => __('Configuring…', 'dc-core'),
        ],
        [
            'title'   => __('Frontend deployed', 'dc-core'),
            'desc'    => __('Astro + Netlify wired to WordPress', 'dc-core'),
            'done'    => $netlify_done,
            'pending' => __('Deploying…', 'dc-core'),
        ],
        [
            'title'   => __('Start creating', 'dc-core'),
            'desc'    => __('Build pages with drag-and-drop', 'dc-core'),
            'done'    => false, // Always "next step" — never auto-checks.
            'pending' => '',
        ],
    ];

    ?>
    <div class="dcf-sidebar-card">
        <h3>
            <span aria-hidden="true">📋</span>
            <?php esc_html_e('Quick start', 'dc-core'); ?>
        </h3>
        <div class="dcf-checklist">
            <?php foreach ($items as $i => $item) : ?>
                <div class="dcf-checklist-item">
                    <span class="dcf-step-num<?php echo $item['done'] ? ' is-done' : ''; ?>">
                        <?php echo $item['done'] ? '✓' : esc_html((string) ($i + 1)); ?>
                    </span>
                    <div>
                        <div class="dcf-step-title">
                            <?php
                            // Make "Start creating" a link to /edit.php once active.
                            if ($i === 3 && $is_active) {
                                printf(
                                    '<a href="%s" style="color:inherit;text-decoration:none;">%s</a>',
                                    esc_url(admin_url('edit.php?post_type=dc_landing')),
                                    esc_html($item['title'])
                                );
                            } else {
                                echo esc_html($item['title']);
                            }
                            ?>
                        </div>
                        <div class="dcf-step-desc">
                            <?php echo esc_html($item['done'] || $i === 3 ? $item['desc'] : ($item['pending'] ?: $item['desc'])); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function handle_save_url(): void
{
    if (!current_user_can(CAP)) {
        wp_die(esc_html__('You do not have permission to update the frontend URL.', 'dc-core'));
    }
    check_admin_referer('dc_frontend_save_url');
    $url = isset($_POST['dc_frontend_url']) ? wp_unslash((string) $_POST['dc_frontend_url']) : '';
    $url = esc_url_raw($url);
    if ($url === '') {
        wp_safe_redirect(admin_url('admin.php?page=' . SLUG . '&error=empty'));
        exit;
    }
    FrontendConnect\set_frontend_url($url);
    wp_safe_redirect(admin_url('admin.php?page=' . SLUG));
    exit;
}
