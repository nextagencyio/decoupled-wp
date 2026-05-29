<?php
/**
 * Front-to-back-end controller shim (Bedrock-style).
 *
 * WordPress core lives in web/wp/ but the web server's docroot is
 * web/. This file is the docroot's index.php: it tells WordPress to
 * load (without theme), then hands off to core's wp-blog-header.php.
 *
 * Required for any request that isn't a real file/directory — the
 * homepage, pretty permalinks, and the WPGraphQL endpoint all route
 * through here.
 */

define('WP_USE_THEMES', false);

require __DIR__ . '/wp/wp-blog-header.php';
