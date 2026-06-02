<?php
/**
 * Protect the WP password-reset cookie from incidental fetches.
 *
 * WP core's wp-login.php has a default-case handler (line ~1487 in
 * 7.x) that ALWAYS clears the `wp-resetpass-{COOKIEHASH}` cookie
 * when it sees one on a plain /wp/wp-login.php GET (no action arg).
 * That's intentional cleanup — but it silently breaks the reset flow
 * if anything on the password-reset page triggers an incidental fetch
 * to /wp/wp-login.php in between the form render and the form POST.
 *
 * Observed in production with Chrome on macOS: while the user was
 * filling in the new-password form (at ?action=rp), Chrome made a
 * background fetch to /wp/wp-login.php with Sec-Fetch-Dest: image.
 * Source unclear (possibly a browser extension, password manager, or
 * a Chrome speculative-prefetch heuristic). WP processed it as a
 * normal page load, deleted the cookie, and when the user clicked
 * Save the form POST arrived with no cookie → "invalid reset link"
 * error → start over.
 *
 * Fix: bail out of further wp-login.php processing very early
 * (login_init priority -100) when the request is a non-document
 * image-style fetch. The cookie stays intact for the eventual
 * form POST.
 */

namespace Dc\Core\ProtectRpCookie;

if (!defined('ABSPATH')) {
    exit;
}

add_action('login_init', __NAMESPACE__ . '\\bail_on_image_fetch', -100);

/**
 * Exit early on incidental image-style fetches to /wp/wp-login.php
 * so WP's default-case cookie cleanup doesn't run.
 *
 * Triggers when ALL of:
 *   - Method is GET
 *   - Sec-Fetch-Dest is "image" (Chrome / Edge / Firefox modern UAs)
 *   - The wp-resetpass-{hash} cookie is present (only protect when
 *     we'd actually lose state — incidental fetches without the
 *     cookie present go through as normal)
 *
 * Returns a 204 No Content so the browser sees a clean response
 * (instead of accidentally caching an HTML page as an image).
 */
function bail_on_image_fetch(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        return;
    }
    $fetch_dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
    if ($fetch_dest !== 'image') {
        return;
    }
    $rp_cookie = 'wp-resetpass-' . COOKIEHASH;
    if (!isset($_COOKIE[$rp_cookie])) {
        return;
    }

    // No Set-Cookie header → existing rp cookie stays intact.
    status_header(204);
    nocache_headers();
    exit;
}
