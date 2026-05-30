<?php
/**
 * Compliance Dashboard — wp-admin page.
 *
 * Pulls audit JSON written by the project's CI (accessibility, SEO,
 * performance scanners) from the deployed Astro frontend at
 * `<DC_AUDIT_URL>/audits/<scanner>/latest.json`, distills it into
 * headline tiles + per-page tables, and renders inside wp-admin under
 * its own "Compliance" menu item.
 *
 * Why in WordPress: content editors live in wp-admin. Surfacing the
 * audit results there means an editor publishing a page sees the same
 * accessibility / SEO / performance scorecard the engineering team
 * sees, without leaving the CMS.
 *
 * The audit pipeline this expects:
 *   1. CI workflows (a11y / seo / performance) run audit scripts
 *      against the deployed frontend.
 *   2. Each writes its result to `audits/<scanner>/latest.json` in the
 *      frontend's public/ directory.
 *   3. The frontend deploy serves that JSON as a static file.
 *   4. This dashboard fetches and renders it.
 *
 * If a project has no audit pipeline yet, the dashboard degrades
 * gracefully — every tile shows "PENDING / awaiting first run".
 *
 * Fetch caching: results are cached in a transient for 60 seconds —
 * short enough that a fresh CI run shows up almost immediately, long
 * enough that quick clicks around wp-admin don't hammer the origin.
 */

namespace Dc\Core\ComplianceDashboard;

if (!defined('ABSPATH')) {
    exit;
}

const CACHE_TTL = 60;
const CAP       = 'edit_posts';
const MENU_SLUG = 'dc-compliance';
const SCANNERS  = ['a11y', 'seo', 'performance'];

/**
 * Where audit JSON lives. Defaults to the WordPress home URL; override
 * with DC_AUDIT_URL (define or env var) to point at the deployed
 * Astro frontend, which is where CI publishes the audit JSON.
 */
function audit_base(): string
{
    $base = defined('DC_AUDIT_URL')
        ? DC_AUDIT_URL
        : (getenv('DC_AUDIT_URL') ?: default_audit_url());
    return rtrim($base, '/') . '/audits';
}

/**
 * Fallback audit origin when DC_AUDIT_URL is not configured — the
 * frontend URL if set, otherwise the WordPress home URL.
 */
function default_audit_url(): string
{
    if (defined('DC_FRONTEND_URL')) {
        return DC_FRONTEND_URL;
    }
    return getenv('DC_FRONTEND_URL') ?: home_url('/');
}

add_action('admin_menu', __NAMESPACE__ . '\\register_menu');

/**
 * Add the "Compliance" top-level menu item.
 */
function register_menu(): void
{
    add_menu_page(
        __('Compliance Dashboard', 'dc-core'),
        __('Compliance', 'dc-core'),
        CAP,
        MENU_SLUG,
        __NAMESPACE__ . '\\render_page',
        'dashicons-chart-line',
        58 // Just below Comments (25); above Appearance (60).
    );
}

add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets');

/**
 * Enqueue the dashboard stylesheet — only on the dashboard's own page.
 */
function enqueue_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_' . MENU_SLUG) {
        return;
    }
    wp_enqueue_style(
        'dc-compliance-dashboard',
        DC_CORE_URL . 'assets/css/compliance-dashboard.css',
        [],
        DC_CORE_VERSION
    );
}

/**
 * Fetch a scanner's latest.json, with a transient cache. Returns null
 * on network / JSON failure (caller renders a "pending" tile).
 */
function fetch_scanner(string $scanner): ?array
{
    $cache_key = 'dc_compliance_' . $scanner;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }
    if ($cached === 'NULL') {
        // Negative-cached: don't refetch a known-failing endpoint for
        // a minute.
        return null;
    }

    $url = audit_base() . '/' . $scanner . '/latest.json';
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        set_transient($cache_key, 'NULL', CACHE_TTL);
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        set_transient($cache_key, 'NULL', CACHE_TTL);
        return null;
    }

    set_transient($cache_key, $data, CACHE_TTL);
    return $data;
}

/**
 * Reshape an accessibility latest.json into the structure the template
 * iterates. Empty data → a "pending" placeholder.
 */
function summarize_a11y(?array $data): array
{
    if (!$data) {
        return empty_report('Accessibility', 'WCAG 2.2 AA via axe-core');
    }
    $summary = $data['summary'] ?? [];
    return [
        'title'    => 'Accessibility',
        'standard' => $data['standard'] ?? 'WCAG 2.2 AA',
        'runAt'    => $data['runAt'] ?? null,
        'baseUrl'  => $data['baseUrl'] ?? null,
        'status'   => !empty($summary['pass']) ? 'pass' : 'fail',
        'headline' => [
            'value' => ($summary['pagesPassing'] ?? 0) . ' / ' . ($summary['pagesAudited'] ?? 0),
            'label' => 'pages passing',
        ],
        'metrics' => [
            ['label' => 'Total violations', 'value' => $summary['totalViolations'] ?? 0],
            ['label' => 'Pages audited',    'value' => $summary['pagesAudited'] ?? 0],
            ['label' => 'Pages passing',    'value' => $summary['pagesPassing'] ?? 0],
        ],
        'columns' => ['violations'],
        'pages'   => array_map(static function (array $p): array {
            $violations = $p['violationCount']
                ?? (is_array($p['violations'] ?? null) ? count($p['violations']) : 0);
            return [
                'label'      => $p['label'] ?? $p['url'] ?? '—',
                'url'        => $p['url'] ?? '',
                'violations' => $violations,
                'status'     => $violations === 0 ? 'pass' : 'fail',
            ];
        }, $data['pages'] ?? []),
    ];
}

/**
 * Reshape SEO latest.json. Counts error vs warn issues per page.
 */
function summarize_seo(?array $data): array
{
    if (!$data) {
        return empty_report('SEO', 'Lighthouse SEO + structural checks');
    }
    $summary = $data['summary'] ?? [];
    $pages   = $data['pages']   ?? [];

    $avgScore = null;
    $scores   = array_filter(array_map(
        static fn(array $p) => $p['lighthouseScore'] ?? null,
        $pages
    ), static fn($v) => is_numeric($v));
    if ($scores) {
        $avgScore = (int) round(array_sum($scores) / count($scores));
    }

    return [
        'title'    => 'SEO',
        'standard' => $data['standard'] ?? 'Lighthouse SEO',
        'runAt'    => $data['runAt'] ?? null,
        'baseUrl'  => $data['baseUrl'] ?? null,
        'status'   => infer_seo_status($pages, $data),
        'headline' => [
            'value' => $summary['avgScore'] ?? ($avgScore ?? '—'),
            'label' => 'avg Lighthouse SEO score',
        ],
        'metrics' => [
            ['label' => 'Avg score',  'value' => $summary['avgScore'] ?? ($avgScore ?? '—')],
            ['label' => 'Pages',      'value' => $summary['pagesAudited'] ?? count($pages)],
            ['label' => 'Sitemap',    'value' => !empty($data['site']['sitemap']['ok']) ? 'ok' : 'fail'],
            ['label' => 'Robots.txt', 'value' => !empty($data['site']['robots']['ok'])  ? 'ok' : 'fail'],
        ],
        'columns' => ['score', 'errors', 'warnings'],
        'pages'   => array_map(static function (array $p): array {
            $errors   = 0;
            $warnings = 0;
            foreach ($p['issues'] ?? [] as $issue) {
                $sev = $issue['severity'] ?? '';
                if ($sev === 'error') {
                    $errors++;
                } elseif ($sev === 'warn') {
                    $warnings++;
                }
            }
            $status = $errors > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'pass');
            return [
                'label'    => $p['label'] ?? $p['url'] ?? '—',
                'url'      => $p['url'] ?? '',
                'score'    => $p['lighthouseScore'] ?? null,
                'errors'   => $errors,
                'warnings' => $warnings,
                'status'   => $status,
            ];
        }, $pages),
    ];
}

/**
 * Derive an overall SEO status: explicit summary.pass if present, else
 * fail when any page carries an error-severity issue.
 */
function infer_seo_status(array $pages, array $data): string
{
    if (isset($data['summary']['pass'])) {
        return !empty($data['summary']['pass']) ? 'pass' : 'fail';
    }
    foreach ($pages as $p) {
        foreach ($p['issues'] ?? [] as $issue) {
            if (($issue['severity'] ?? '') === 'error') {
                return 'fail';
            }
        }
    }
    return 'pass';
}

/**
 * Reshape performance latest.json (Lighthouse + Core Web Vitals).
 * Status is fail when any page misses one of: mobile <80, LCP >3500ms,
 * CLS >0.1.
 */
function summarize_performance(?array $data): array
{
    if (!$data || empty($data['pages'])) {
        return empty_report('Performance', 'Lighthouse + Core Web Vitals');
    }
    $summary = $data['summary'] ?? [];
    return [
        'title'    => 'Performance',
        'standard' => $data['standard'] ?? 'Lighthouse + CWV',
        'runAt'    => $data['runAt'] ?? null,
        'baseUrl'  => $data['baseUrl'] ?? null,
        'status'   => !empty($summary['pass']) ? 'pass' : 'fail',
        'headline' => [
            'value' => $summary['avgMobileScore'] ?? '—',
            'label' => 'avg mobile Lighthouse score',
        ],
        'metrics' => [
            ['label' => 'Avg mobile',  'value' => $summary['avgMobileScore']  ?? '—'],
            ['label' => 'Avg desktop', 'value' => $summary['avgDesktopScore'] ?? '—'],
            ['label' => 'Pages',       'value' => $summary['pagesAudited']    ?? 0],
        ],
        'columns' => ['mobileScore', 'desktopScore', 'lcp', 'cls'],
        'pages'   => array_map(static function (array $p): array {
            $m     = $p['mobile']['metrics'] ?? [];
            $score = $p['mobile']['score']   ?? null;
            $lcp   = $m['lcp'] ?? null;
            $cls   = $m['cls'] ?? null;
            $status = 'pass';
            if ($score !== null && $score < 80) {
                $status = 'fail';
            } elseif ($lcp !== null && $lcp > 3500) {
                $status = 'fail';
            } elseif ($cls !== null && $cls > 0.1) {
                $status = 'fail';
            } elseif ($score !== null && $score < 90) {
                $status = 'warn';
            }
            return [
                'label'        => $p['label'] ?? $p['url'] ?? '—',
                'url'          => $p['url'] ?? '',
                'mobileScore'  => $score,
                'desktopScore' => $p['desktop']['score'] ?? null,
                'lcp'          => $lcp,
                'cls'          => $cls,
                'status'       => $status,
            ];
        }, $data['pages'] ?? []),
    ];
}

/**
 * A placeholder report for a scanner that has no data yet.
 */
function empty_report(string $title, string $standard): array
{
    return [
        'title'    => $title,
        'standard' => $standard,
        'runAt'    => null,
        'baseUrl'  => null,
        'status'   => 'pending',
        'headline' => ['value' => '—', 'label' => 'awaiting first run'],
        'metrics'  => [],
        'columns'  => [],
        'pages'    => [],
    ];
}

add_action('admin_init', __NAMESPACE__ . '\\maybe_refresh');

/**
 * Cache-bust handler. The page's "Refresh now" link carries `?refresh=1`
 * + a nonce; this flushes the three transients then redirects back so
 * the next load pulls fresh JSON.
 */
function maybe_refresh(): void
{
    if (empty($_GET['refresh']) || !current_user_can(CAP)) {
        return;
    }
    check_admin_referer('dc_compliance_refresh');
    foreach (SCANNERS as $s) {
        delete_transient('dc_compliance_' . $s);
    }
    wp_safe_redirect(admin_url('admin.php?page=' . MENU_SLUG));
    exit;
}

/**
 * Render the Compliance Dashboard page.
 */
function render_page(): void
{
    if (!current_user_can(CAP)) {
        wp_die(esc_html__('Insufficient permissions.', 'dc-core'));
    }

    $reports = [
        'a11y'        => summarize_a11y(fetch_scanner('a11y')),
        'seo'         => summarize_seo(fetch_scanner('seo')),
        'performance' => summarize_performance(fetch_scanner('performance')),
    ];

    // The audit source — where CI publishes the audit JSON.
    $base = defined('DC_AUDIT_URL')
        ? DC_AUDIT_URL
        : (getenv('DC_AUDIT_URL') ?: default_audit_url());

    // The label shown in the lede. Defaults to the fetch URL, but a
    // pilot can fetch JSON from a local/internal origin while DISPLAYING
    // the intended production URL via DC_AUDIT_LABEL — so the
    // dashboard reads cleanly without exposing a docker/internal host.
    $base_label = defined('DC_AUDIT_LABEL')
        ? DC_AUDIT_LABEL
        : (getenv('DC_AUDIT_LABEL') ?: $base);

    $refresh_url = wp_nonce_url(
        admin_url('admin.php?page=' . MENU_SLUG . '&refresh=1'),
        'dc_compliance_refresh'
    );

    include DC_CORE_DIR . 'templates/compliance-dashboard.php';
}
