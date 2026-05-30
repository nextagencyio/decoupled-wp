<?php
/**
 * Spark Core — route wp_mail() through the Resend HTTP API.
 *
 * On Fly tenants there's no MTA inside the machine, so PHP's mail()
 * silently fails. We short-circuit wp_mail() via the `pre_wp_mail`
 * filter (WP 5.7+) and POST to https://api.resend.com/emails instead.
 *
 * Gated on two env vars set as Fly secrets by the provisioner:
 *   RESEND_API_KEY  — the bearer token. Without it the filter no-ops
 *                     and WP falls back to its default mail() path
 *                     (which fails, but that's the operator's signal
 *                     to set the secret).
 *   MAIL_FROM       — the From address. Has to be on a Resend-verified
 *                     domain; defaults to noreply@decoupled.io to match
 *                     the Drupal-side convention.
 */
namespace Spark\Core\Mail;

if (!defined('ABSPATH')) {
    exit;
}

const RESEND_ENDPOINT = 'https://api.resend.com/emails';

/**
 * Whether the Resend integration is active. Centralized so the
 * pre_wp_mail filter is a one-liner check and other code (e.g. an
 * admin notice) can render the same gate.
 */
function is_enabled(): bool
{
    $key = (string) (getenv('RESEND_API_KEY') ?: '');
    return $key !== '';
}

/**
 * Resolve the From address. Caller-supplied (via wp_mail_from filter
 * or message-level headers) wins; otherwise MAIL_FROM env; otherwise
 * the WordPress default (wordpress@<host>).
 */
function from_address(string $proposed = ''): string
{
    if ($proposed !== '') {
        return $proposed;
    }
    $env = (string) (getenv('MAIL_FROM') ?: '');
    return $env !== '' ? $env : 'wordpress@' . (string) parse_url((string) home_url(), PHP_URL_HOST);
}

/**
 * Resolve the From name. Defaults to the site name; wp_mail_from_name
 * filter still applies (we don't override it here).
 */
function from_name(): string
{
    $name = (string) apply_filters('wp_mail_from_name', '');
    return $name !== '' ? $name : (string) get_bloginfo('name');
}

/**
 * pre_wp_mail hook. Return non-null to short-circuit wp_mail's default
 * PHPMailer path. Return null to fall through (mail() default behavior).
 *
 * @param null|bool $short_circuit   Always null on incoming call.
 * @param array<string,mixed> $atts  wp_mail args: to, subject, message,
 *                                   headers, attachments.
 * @return null|bool                  null → fall through; bool → handled.
 */
function pre_send($short_circuit, array $atts)
{
    if (!is_enabled()) {
        return null;
    }

    // wp_mail's `to` can be a string, comma-separated, or an array.
    $to = $atts['to'] ?? '';
    $to_list = is_array($to) ? $to : array_map('trim', explode(',', (string) $to));
    $to_list = array_values(array_filter($to_list, fn($a) => $a !== ''));
    if ($to_list === []) {
        // Nothing to send to — let WP's default path handle the error path.
        return null;
    }

    $subject = (string) ($atts['subject'] ?? '');
    $body    = (string) ($atts['message'] ?? '');

    // Headers can be string or array. Parse a few we care about:
    //   From: / Reply-To: / Content-Type:
    $hdr_lines = (array) ($atts['headers'] ?? []);
    if (count($hdr_lines) === 1 && is_string($hdr_lines[0]) && str_contains($hdr_lines[0], "\n")) {
        $hdr_lines = preg_split('/\r\n|\r|\n/', $hdr_lines[0]) ?: [];
    }
    $from = '';
    $reply_to = '';
    $is_html = false;
    foreach ($hdr_lines as $line) {
        if (!is_string($line) || !str_contains($line, ':')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $k_lc = strtolower($k);
        if ($k_lc === 'from') {
            $from = $v;
        } elseif ($k_lc === 'reply-to') {
            $reply_to = $v;
        } elseif ($k_lc === 'content-type' && stripos($v, 'text/html') !== false) {
            $is_html = true;
        }
    }

    // wp_mail_from / wp_mail_from_name filters still apply to the
    // default From — let them run, then fall back to our env default.
    $from_email = $from !== ''
        ? $from
        : from_address((string) apply_filters('wp_mail_from', ''));
    $from_email = trim($from_email, "<> \t");
    $from_name  = from_name();

    $payload = [
        'from'    => $from_name !== '' ? sprintf('%s <%s>', $from_name, $from_email) : $from_email,
        'to'      => $to_list,
        'subject' => $subject,
        $is_html ? 'html' : 'text' => $body,
    ];
    if ($reply_to !== '') {
        $payload['reply_to'] = trim($reply_to, "<> \t");
    }

    $res = wp_remote_post(RESEND_ENDPOINT, [
        'method'  => 'POST',
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) getenv('RESEND_API_KEY'),
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($payload),
    ]);

    if (is_wp_error($res)) {
        error_log('[spark_mail] Resend transport error: ' . $res->get_error_message());
        // Fire wp_mail_failed for parity with the default path.
        do_action('wp_mail_failed', new \WP_Error('spark_mail_resend_failed', $res->get_error_message(), $atts));
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        $body_text = (string) wp_remote_retrieve_body($res);
        error_log("[spark_mail] Resend HTTP {$code}: " . substr($body_text, 0, 500));
        do_action('wp_mail_failed', new \WP_Error('spark_mail_resend_http', "Resend HTTP {$code}: {$body_text}", $atts));
        return false;
    }

    return true;
}

// Register the pre_wp_mail hook. Priority 10 is fine — we want to win
// against any plugin that hasn't claimed an explicit priority.
add_filter('pre_wp_mail', __NAMESPACE__ . '\\pre_send', 10, 2);

// Also pin the wp_mail_from default to MAIL_FROM so any plugin that
// builds its own headers and doesn't go through pre_wp_mail still
// gets the right From.
add_filter('wp_mail_from', function (string $from): string {
    return from_address($from);
}, 5);
