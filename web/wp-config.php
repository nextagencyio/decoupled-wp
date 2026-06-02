<?php
// wp-config-ddev.php not needed
/**
 * Bedrock-style wp-config.php for decoupled-wp-spark.
 *
 * - WordPress core lives at web/wp/, content at web/app/
 * - Environment loaded via vlucas/phpdotenv from the project root
 * - DDEV provides DB_HOST=db / DB_NAME=db / DB_USER=db / DB_PASSWORD=db
 *   out of the box; .env overrides for any other environment.
 *
 * Starter note: replace the salt placeholders with real values from
 * https://api.wordpress.org/secret-key/1.1/salt/ (or set them in .env).
 */

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
}

if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// DDEV-default DB credentials (override via .env in any other env)
define('DB_NAME', getenv('DB_NAME') ?: 'db');
define('DB_USER', getenv('DB_USER') ?: 'db');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'db');
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wp_';

// Trust the X-Forwarded-Proto header from upstream TLS terminators
// (Fly's edge proxy, Cloudflare, nginx, etc). Without this WP's
// is_ssl() returns false on the container — even though the visitor
// connected over HTTPS — and the FORCE_SSL_ADMIN check below kicks
// off an infinite redirect loop on /wp/wp-admin and /wp/wp-login.php
// because WP keeps redirecting to its own https URL and seeing http
// internally. Standard headless-Bedrock pattern; safe because we
// only honor the header when the upstream genuinely terminated TLS.
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// URL configuration. Prefer an explicit WP_HOME env (set per
// environment / by the Fly provisioner); otherwise derive from the
// request host so a fresh clone or any DDEV project name works without
// editing this file. CLI (no HTTP host) falls back to localhost.
if (!getenv('WP_HOME')) {
    $spark_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $spark_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    putenv('WP_HOME=' . $spark_scheme . '://' . $spark_host);
}
define('WP_HOME', getenv('WP_HOME'));
define('WP_SITEURL', getenv('WP_SITEURL') ?: WP_HOME . '/wp');

// Bedrock content directory
define('WP_CONTENT_DIR', __DIR__ . '/app');
define('WP_CONTENT_URL', WP_HOME . '/app');

// Composer manages all plugins/themes/core — lock down the admin so
// nothing is installed, updated, or deleted through the UI (and no
// in-admin file editor). DISALLOW_FILE_MODS removes the install/update/
// delete affordances entirely and implies DISALLOW_FILE_EDIT.
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);
define('DISALLOW_FILE_MODS', true);
define('DISALLOW_FILE_EDIT', true);

// Debug
define('WP_DEBUG', (bool) (getenv('WP_DEBUG') ?: false));
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', false);

// Salts — replace with real values from
// https://api.wordpress.org/secret-key/1.1/salt/ or load via .env.
define('AUTH_KEY',         getenv('AUTH_KEY')         ?: 'put your unique phrase here');
define('SECURE_AUTH_KEY',  getenv('SECURE_AUTH_KEY')  ?: 'put your unique phrase here');
define('LOGGED_IN_KEY',    getenv('LOGGED_IN_KEY')    ?: 'put your unique phrase here');
define('NONCE_KEY',        getenv('NONCE_KEY')        ?: 'put your unique phrase here');
define('AUTH_SALT',        getenv('AUTH_SALT')        ?: 'put your unique phrase here');
define('SECURE_AUTH_SALT', getenv('SECURE_AUTH_SALT') ?: 'put your unique phrase here');
define('LOGGED_IN_SALT',   getenv('LOGGED_IN_SALT')   ?: 'put your unique phrase here');
define('NONCE_SALT',       getenv('NONCE_SALT')       ?: 'put your unique phrase here');

// Required by wp-graphql-jwt-authentication (used by the headless
// preview flow — the Astro frontend logs in to fetch draft content).
define('GRAPHQL_JWT_AUTH_SECRET_KEY', getenv('GRAPHQL_JWT_AUTH_SECRET_KEY') ?: 'change-me-in-production');

// DC_FRONTEND_URL — the decoupled Astro frontend. The admin's
// "View" / "Preview" buttons rewrite to this host (see dc-core's
// preview-links.php), and headless-redirect.php sends public WP
// front-end hits here. DDEV sets it via .ddev/config.yaml's
// web_environment; production sets the real demo URL.
define('DC_FRONTEND_URL', getenv('DC_FRONTEND_URL') ?: 'http://localhost:4321');

// DC_AUDIT_URL — where the Compliance Dashboard reads audit JSON
// (audits/<scanner>/latest.json). That JSON is published only by
// deployed CI to the PRODUCTION frontend — a local dev frontend never
// has it, and the DDEV container can't reach the host's localhost. So
// this MUST point at the deployed demo URL. Set it per project via
// .ddev/config.yaml's web_environment (and the production env); the
// fallback below is intentionally a non-working placeholder so a
// missing config surfaces as an obvious "pending" dashboard.
define('DC_AUDIT_URL', getenv('DC_AUDIT_URL') ?: 'https://example.invalid');

// DC_CONTENT_MODEL — selects an optional project content model
// (e.g. "ysaqmd") over the starter default. Empty = starter default.
// Set via env (ddev .ddev/config web_environment) per project.
define('DC_CONTENT_MODEL', getenv('DC_CONTENT_MODEL') ?: 'ysaqmd');

// WORDPRESS_PREVIEW_SECRET — shared secret gating the Astro
// /api/preview endpoint. Must match the value in the frontend's env.
define('WORDPRESS_PREVIEW_SECRET', getenv('WORDPRESS_PREVIEW_SECRET') ?: 'dc_preview_dev_secret');

// DC_REVALIDATE_BYPASS_TOKEN — token sent with the on-save
// revalidation request so the frontend trusts it.
define('DC_REVALIDATE_BYPASS_TOKEN', getenv('DC_REVALIDATE_BYPASS_TOKEN') ?: 'dc-revalidate-dev-token');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp/');
}

require_once ABSPATH . 'wp-settings.php';
