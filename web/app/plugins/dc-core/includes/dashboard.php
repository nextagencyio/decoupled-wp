<?php
/**
 * Custom wp-admin dashboard widget for the decoupled-WordPress starter.
 *
 *   - "Decoupled Status" — shows the live frontend URL + the stack, so
 *     editors always know where the public site is and that publishing
 *     triggers a frontend rebuild.
 *
 * Add project-specific widgets here (content-health counts, sync
 * status, etc.) as the engagement's content model grows.
 */

namespace Dc\Core\Dashboard;

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_dashboard_setup', __NAMESPACE__ . '\\register_widgets');

/**
 * Register the dashboard widgets this plugin contributes.
 */
function register_widgets(): void
{
    wp_add_dashboard_widget(
        'dc_decoupled_status',
        __('Decoupled Status', 'dc-core'),
        __NAMESPACE__ . '\\render_decoupled_status'
    );
}

/**
 * Frontend origin, with a define → env var → localhost fallback chain.
 */
function frontend_url(): string
{
    $url = defined('DC_FRONTEND_URL')
        ? DC_FRONTEND_URL
        : (getenv('DC_FRONTEND_URL') ?: 'http://localhost:4321');
    return untrailingslashit($url);
}

/**
 * Render the "Decoupled Status" widget body.
 */
function render_decoupled_status(): void
{
    $frontend = frontend_url();
    ?>
    <p>
        <strong><?php esc_html_e('Public site:', 'dc-core'); ?></strong>
        <a href="<?php echo esc_url($frontend); ?>" target="_blank" rel="noopener">
            <?php echo esc_html($frontend); ?>
        </a>
    </p>
    <p>
        <strong><?php esc_html_e('Stack:', 'dc-core'); ?></strong>
        <?php esc_html_e('WordPress (headless CMS) + WPGraphQL + Astro (public frontend)', 'dc-core'); ?>
    </p>
    <p>
        <?php esc_html_e('This WordPress install is the editorial backend. The public site renders from Astro. Publishing or updating a post fires an on-demand revalidation so the frontend rebuilds just the affected pages.', 'dc-core'); ?>
    </p>
    <?php
}
