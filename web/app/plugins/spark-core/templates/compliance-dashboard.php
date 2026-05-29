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
<div class="wrap spark-dashboard">
    <header class="spark-dashboard__header">
        <div>
            <p class="spark-dashboard__eyebrow">Compliance dashboard</p>
            <h1 class="spark-dashboard__title">Daily site audit</h1>
            <p class="spark-dashboard__lede">
                Live results from the CI audit suite that runs against
                <a href="<?php echo esc_url($base_label); ?>" target="_blank" rel="noopener"><?php echo esc_html(preg_replace('#^https?://#', '', untrailingslashit($base_label))); ?></a>.
                Accessibility, SEO, and performance are scanned on every deploy and once daily.
            </p>
        </div>
        <a href="<?php echo esc_url($refresh_url); ?>" class="button button-secondary">Refresh now</a>
    </header>

    <section class="spark-dashboard__cards" aria-label="Headline scores">
        <?php foreach ($reports as $report): ?>
            <article class="spark-card spark-card--<?php echo esc_attr($report['status']); ?>">
                <header class="spark-card__head">
                    <p class="spark-card__category"><?php echo esc_html($report['title']); ?></p>
                    <span class="spark-card__badge spark-card__badge--<?php echo esc_attr($report['status']); ?>">
                        <?php echo esc_html($status_label($report['status'])); ?>
                    </span>
                </header>
                <p class="spark-card__headline">
                    <span class="spark-card__value"><?php echo esc_html((string) $report['headline']['value']); ?></span>
                    <span class="spark-card__unit"><?php echo esc_html($report['headline']['label']); ?></span>
                </p>
                <p class="spark-card__standard"><?php echo esc_html($report['standard']); ?></p>
                <p class="spark-card__timestamp">
                    <?php if (!empty($report['runAt'])): ?>
                        Last run: <?php echo esc_html(
                            wp_date('Y-m-d H:i T', strtotime($report['runAt']))
                        ); ?>
                    <?php else: ?>
                        Awaiting first scheduled run.
                    <?php endif; ?>
                </p>
                <?php if (!empty($report['metrics'])): ?>
                    <dl class="spark-card__metrics">
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
        <section class="spark-dashboard__table-block">
            <h2 class="spark-dashboard__table-title">
                <?php echo esc_html($report['title']); ?> — per-page results
            </h2>
            <div class="spark-table-wrap">
                <table class="spark-table widefat striped">
                    <thead>
                        <tr>
                            <th scope="col">Page</th>
                            <th scope="col">URL</th>
                            <?php foreach ($report['columns'] as $col): ?>
                                <th scope="col" class="spark-num">
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
                                    <td class="spark-num">
                                        <?php
                                        $value = $p[$col] ?? null;
                                        echo $value === null || $value === ''
                                            ? '—'
                                            : esc_html((string) $value);
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <span class="spark-pill spark-pill--<?php echo esc_attr($p['status']); ?>">
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

    <footer class="spark-dashboard__footer">
        <p>
            Data is cached for 60 seconds. Use <strong>Refresh now</strong> to flush after a fresh CI run.
            Audits run automatically on every deploy and on a daily schedule.
        </p>
    </footer>
</div>
