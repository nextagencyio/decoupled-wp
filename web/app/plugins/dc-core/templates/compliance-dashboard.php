<?php
/**
 * Compliance Dashboard template.
 *
 * Rendered by includes/compliance-dashboard.php::render_page().
 *
 * @var array  $reports     Keyed array of summarized scanner reports.
 * @var string $base        Audit-source origin (where CI publishes JSON).
 * @var string $refresh_url Nonced URL that flushes the transient cache.
 */

if (!defined('ABSPATH')) {
    exit;
}

$column_labels = [
    'violations'   => 'Violations',
    'score'        => 'Score',
    'errors'       => 'Errors',
    'warnings'     => 'Warnings',
    'mobileScore'  => 'Mobile',
    'desktopScore' => 'Desktop',
    'lcp'          => 'LCP (ms)',
    'cls'          => 'CLS',
];

$status_label = static fn(string $s): string => match ($s) {
    'pass'  => 'PASS',
    'fail'  => 'FAIL',
    'warn'  => 'WARN',
    default => 'PENDING',
};
?>
<div class="wrap dc-dashboard">
    <header class="dc-dashboard__header">
        <div>
            <p class="dc-dashboard__eyebrow">Compliance dashboard</p>
            <h1 class="dc-dashboard__title">Daily site audit</h1>
            <p class="dc-dashboard__lede">
                Live results from the CI audit suite that runs against
                <a href="<?php echo esc_url($base_label); ?>" target="_blank" rel="noopener"><?php echo esc_html(preg_replace('#^https?://#', '', untrailingslashit($base_label))); ?></a>.
                Accessibility, SEO, and performance are scanned on every deploy and once daily.
            </p>
        </div>
        <a href="<?php echo esc_url($refresh_url); ?>" class="button button-secondary">Refresh now</a>
    </header>

    <section class="dc-dashboard__cards" aria-label="Headline scores">
        <?php foreach ($reports as $report): ?>
            <article class="dc-card dc-card--<?php echo esc_attr($report['status']); ?>">
                <header class="dc-card__head">
                    <p class="dc-card__category"><?php echo esc_html($report['title']); ?></p>
                    <span class="dc-card__badge dc-card__badge--<?php echo esc_attr($report['status']); ?>">
                        <?php echo esc_html($status_label($report['status'])); ?>
                    </span>
                </header>
                <p class="dc-card__headline">
                    <span class="dc-card__value"><?php echo esc_html((string) $report['headline']['value']); ?></span>
                    <span class="dc-card__unit"><?php echo esc_html($report['headline']['label']); ?></span>
                </p>
                <p class="dc-card__standard"><?php echo esc_html($report['standard']); ?></p>
                <p class="dc-card__timestamp">
                    <?php if (!empty($report['runAt'])): ?>
                        Last run: <?php echo esc_html(
                            wp_date('Y-m-d H:i T', strtotime($report['runAt']))
                        ); ?>
                    <?php else: ?>
                        Awaiting first scheduled run.
                    <?php endif; ?>
                </p>
                <?php if (!empty($report['metrics'])): ?>
                    <dl class="dc-card__metrics">
                        <?php foreach ($report['metrics'] as $m): ?>
                            <div>
                                <dt><?php echo esc_html($m['label']); ?></dt>
                                <dd><?php echo esc_html((string) $m['value']); ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <?php foreach ($reports as $report): ?>
        <?php if (empty($report['pages'])) {
            continue;
        } ?>
        <section class="dc-dashboard__table-block">
            <h2 class="dc-dashboard__table-title">
                <?php echo esc_html($report['title']); ?> — per-page results
            </h2>
            <div class="dc-table-wrap">
                <table class="dc-table widefat striped">
                    <thead>
                        <tr>
                            <th scope="col">Page</th>
                            <th scope="col">URL</th>
                            <?php foreach ($report['columns'] as $col): ?>
                                <th scope="col" class="dc-num">
                                    <?php echo esc_html($column_labels[$col] ?? $col); ?>
                                </th>
                            <?php endforeach; ?>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['pages'] as $p): ?>
                            <tr>
                                <td><?php echo esc_html($p['label']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(untrailingslashit($base) . $p['url']); ?>"
                                       target="_blank" rel="noopener">
                                        <code><?php echo esc_html($p['url']); ?></code>
                                    </a>
                                </td>
                                <?php foreach ($report['columns'] as $col): ?>
                                    <td class="dc-num">
                                        <?php
                                        $value = $p[$col] ?? null;
                                        echo $value === null || $value === ''
                                            ? '—'
                                            : esc_html((string) $value);
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <span class="dc-pill dc-pill--<?php echo esc_attr($p['status']); ?>">
                                        <?php echo esc_html(strtoupper($p['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <footer class="dc-dashboard__footer">
        <p>
            Data is cached for 60 seconds. Use <strong>Refresh now</strong> to flush after a fresh CI run.
            Audits run automatically on every deploy and on a daily schedule.
        </p>
    </footer>
</div>
