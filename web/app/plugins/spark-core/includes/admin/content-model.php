<?php
/**
 * Admin import/export screen for the active Spark content model.
 */

namespace Spark\Core\Admin\ContentModel;

use Spark\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', __NAMESPACE__ . '\\register_page');
add_action('admin_post_spark_save_content_model', __NAMESPACE__ . '\\handle_save');
add_action('admin_post_spark_reset_content_model', __NAMESPACE__ . '\\handle_reset');

function register_page(): void
{
    add_management_page(
        __('Spark Content Model', 'spark-core'),
        __('Spark Content Model', 'spark-core'),
        'manage_options',
        'spark-content-model',
        __NAMESPACE__ . '\\render_page'
    );
}

function render_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to manage the Spark content model.', 'spark-core'));
    }

    $model_json = wp_json_encode(Config\model(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $errors = get_transient('spark_content_model_admin_errors');
    $message = get_transient('spark_content_model_admin_message');
    delete_transient('spark_content_model_admin_errors');
    delete_transient('spark_content_model_admin_message');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Spark Content Model', 'spark-core'); ?></h1>

        <?php if (is_string($message) && $message !== '') : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <?php if (is_array($errors) && $errors !== []) : ?>
            <div class="notice notice-error">
                <p><?php echo esc_html__('The content model could not be saved:', 'spark-core'); ?></p>
                <ul>
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo esc_html((string) $error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('spark_save_content_model'); ?>
            <input type="hidden" name="action" value="spark_save_content_model">
            <textarea
                name="spark_content_model_json"
                rows="28"
                class="large-text code"
                spellcheck="false"
            ><?php echo esc_textarea((string) $model_json); ?></textarea>
            <p>
                <button type="submit" class="button button-primary">
                    <?php echo esc_html__('Validate and Save Model', 'spark-core'); ?>
                </button>
            </p>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('spark_reset_content_model'); ?>
            <input type="hidden" name="action" value="spark_reset_content_model">
            <p>
                <button type="submit" class="button">
                    <?php echo esc_html__('Reset to Built-In Model', 'spark-core'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

function handle_save(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to manage the Spark content model.', 'spark-core'));
    }

    check_admin_referer('spark_save_content_model');

    $raw = isset($_POST['spark_content_model_json'])
        ? wp_unslash((string) $_POST['spark_content_model_json'])
        : '';

    // Defense in depth: reject absurdly large payloads before decoding.
    // A real content model is a few tens of KB; 1 MB is a generous ceiling.
    if (strlen($raw) > 1048576) {
        set_transient('spark_content_model_admin_errors', ['Model JSON exceeds the 1 MB limit.'], 60);
        redirect_back();
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        set_transient('spark_content_model_admin_errors', [json_last_error_msg()], 60);
        redirect_back();
    }

    $errors = Config\save_model($decoded);
    if ($errors !== []) {
        set_transient('spark_content_model_admin_errors', $errors, 60);
        redirect_back();
    }

    set_transient('spark_content_model_admin_message', __('Content model saved. Rewrite rules were flushed.', 'spark-core'), 60);
    redirect_back();
}

function handle_reset(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to manage the Spark content model.', 'spark-core'));
    }

    check_admin_referer('spark_reset_content_model');
    Config\reset_model();
    set_transient('spark_content_model_admin_message', __('Content model reset to the built-in default. Rewrite rules were flushed.', 'spark-core'), 60);
    redirect_back();
}

function redirect_back(): void
{
    wp_safe_redirect(admin_url('tools.php?page=spark-content-model'));
    exit;
}
