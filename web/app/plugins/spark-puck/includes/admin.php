<?php
/**
 * "Design Studio" launch button — the WP analogue of dc_puck's
 * Design Studio node tab.
 *
 * On the edit screen of any Puck-enabled post type (one whose model
 * declares a `components` field), show a button that mints a fresh,
 * short-lived edit token via the spark-puck REST route and redirects to
 * the headless Puck editor at {editor_url}/editor/{id}?token={token}.
 *
 * The button href is a placeholder; a tiny inline script fetches the
 * token on click (so the token is always fresh and never baked into a
 * cached page) — exactly how dc_puck does it.
 */

namespace Spark\Puck\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend base URL that hosts the Puck editor. Prefer an explicit
 * option, then the SPARK_FRONTEND_URL env the dev stack already sets,
 * then a sensible local default.
 */
function editor_base_url(): string
{
    $opt = (string) get_option('spark_puck_editor_url', '');
    if ($opt !== '') {
        return rtrim($opt, '/');
    }
    $env = (string) (getenv('SPARK_FRONTEND_URL') ?: '');
    if ($env !== '') {
        return rtrim($env, '/');
    }
    return 'http://localhost:4321';
}

/**
 * Post types that support the Puck editor: those whose model field
 * groups declare a `components` preset field.
 *
 * @return array<int, string>
 */
function enabled_post_types(): array
{
    static $types = null;
    if (is_array($types)) {
        return $types;
    }
    $types = [];

    if (!function_exists('Spark\\Core\\Config\\field_groups')) {
        return $types;
    }
    foreach (\Spark\Core\Config\field_groups() as $group) {
        if (!is_array($group)) {
            continue;
        }
        $post_type = (string) ($group['postType'] ?? '');
        if ($post_type === '') {
            continue;
        }
        foreach ((is_array($group['tabs'] ?? null) ? $group['tabs'] : []) as $tab) {
            foreach ((is_array($tab['fields'] ?? null) ? $tab['fields'] : []) as $field) {
                if (is_array($field)
                    && (string) ($field['type'] ?? '') === 'preset'
                    && (string) ($field['preset'] ?? '') === 'components'
                ) {
                    $types[] = $post_type;
                    break 2;
                }
            }
        }
    }
    $types = array_values(array_unique($types));
    return $types;
}

/**
 * True when the given post is editable with Puck by the current user.
 */
function can_design(int $post_id): bool
{
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post) {
        return false;
    }
    return in_array($post->post_type, enabled_post_types(), true)
        && current_user_can('edit_post', $post_id);
}

/**
 * Register the button surfaces + click handler.
 */
function init(): void
{
    // A prominent button at the top of the edit screen.
    add_action('edit_form_after_title', __NAMESPACE__ . '\\render_edit_screen_button');
    // A matching item in the admin bar while editing.
    add_action('admin_bar_menu', __NAMESPACE__ . '\\admin_bar_button', 100);
    // The click handler that mints a token then redirects.
    add_action('admin_footer', __NAMESPACE__ . '\\print_launch_script');
}

/**
 * The edit-screen button (shown just under the title).
 */
function render_edit_screen_button(\WP_Post $post): void
{
    if (!can_design((int) $post->ID)) {
        return;
    }
    printf(
        '<p style="margin:12px 0;"><a href="#" class="button button-primary button-hero spark-puck-launch" data-puck-id="%d">%s</a></p>',
        (int) $post->ID,
        esc_html__('Open Design Studio', 'spark-puck')
    );
}

/**
 * The admin-bar button (mirrors the edit-screen one).
 */
function admin_bar_button(\WP_Admin_Bar $bar): void
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post') {
        return;
    }
    $post_id = (int) (get_the_ID() ?: ($_GET['post'] ?? 0));
    if ($post_id <= 0 || !can_design($post_id)) {
        return;
    }
    $bar->add_node([
        // The post id rides in the node id (→ <li id="...-{id}">) so the
        // click handler can read it: the admin-bar API can't put a
        // data-attribute on the <a>, and esc_url strips a "#a:b" href.
        'id'    => 'spark-puck-launch-' . $post_id,
        'title' => esc_html__('Open Design Studio', 'spark-puck'),
        'href'  => '#',
        'meta'  => ['class' => 'spark-puck-launch'],
    ]);
}

/**
 * Inline JS: on click, fetch a fresh token from the REST route, then
 * redirect to the headless editor. Uses the nonce'd REST cookie auth so
 * only a logged-in editor can mint.
 */
function print_launch_script(): void
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post') {
        return;
    }
    $rest_root = esc_url_raw(rest_url('spark-puck/v1/token/'));
    $nonce     = wp_create_nonce('wp_rest');
    $base      = esc_js(editor_base_url());
    ?>
    <script>
    (function () {
      var REST = <?php echo wp_json_encode($rest_root); ?>;
      var NONCE = <?php echo wp_json_encode($nonce); ?>;
      var BASE = <?php echo wp_json_encode($base); ?>;
      document.addEventListener('click', function (e) {
        var el = e.target.closest('.spark-puck-launch');
        if (!el) return;
        e.preventDefault();
        // Edit-screen button carries data-puck-id; the admin-bar item
        // carries it in its <li id="wp-admin-bar-spark-puck-launch-{id}">.
        var id = el.getAttribute('data-puck-id');
        if (!id) {
          var host = el.closest('[id*="spark-puck-launch-"]') || el;
          var m = (host.id || '').match(/spark-puck-launch-(\d+)/);
          id = m ? m[1] : null;
        }
        if (!id) return;
        el.style.pointerEvents = 'none';
        el.style.opacity = '0.6';
        fetch(REST + id, { headers: { 'X-WP-Nonce': NONCE }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
          .then(function (data) {
            if (!data || !data.token) throw new Error('No token');
            window.location.href = BASE + '/editor/' + id + '?token=' + encodeURIComponent(data.token);
          })
          .catch(function () {
            el.style.pointerEvents = '';
            el.style.opacity = '';
            window.alert('Could not open the Design Studio. Make sure the frontend is running and you have permission to edit this page.');
          });
      });
    })();
    </script>
    <?php
}
