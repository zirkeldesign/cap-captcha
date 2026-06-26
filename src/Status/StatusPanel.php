<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

/**
 * Renders the Cap server status panel — headline stat cards plus the hourly
 * sparkline — from a StatsClient payload. Shared by the settings page and the
 * admin dashboard widget so both stay in lock-step.
 */
final class StatusPanel
{
    /**
     * Render the stat-card grid + sparkline for a decoded stats payload.
     *
     * @param  array<string, mixed>  $stats
     */
    public function render(array $stats): void
    {
        $name = (string) ($stats['key']['name'] ?? '');
        $siteKey = (string) ($stats['key']['siteKey'] ?? '');
        $config = is_array($stats['key']['config'] ?? null) ? $stats['key']['config'] : [];
        $difficulty = (int) ($config['difficulty'] ?? 0);
        $challengeCount = (int) ($config['challengeCount'] ?? 0);
        $saltSize = (int) ($config['saltSize'] ?? 0);

        $current = is_array($stats['stats'] ?? null) ? $stats['stats'] : [];
        $prev = is_array($stats['prevStats'] ?? null) ? $stats['prevStats'] : [];

        $challenges = (int) ($current['challenges'] ?? 0);
        $verified = (int) ($current['verified'] ?? 0);
        $failed = (int) ($current['failed'] ?? 0);
        $avgLatencyMs = (int) ($current['avgLatency'] ?? 0);

        $buckets = is_array($stats['chartData']['data'] ?? null) ? $stats['chartData']['data'] : [];

        ?>
        <div class="cap-captcha-status__grid">
            <div class="cap-captcha-status__card">
                <span class="cap-captcha-status__label"><?php echo esc_html__('Site', 'privacy-captcha-for-cap'); ?></span>
                <strong class="cap-captcha-status__value"><?php echo esc_html($name !== '' ? $name : $siteKey); ?></strong>
                <?php if ($name !== '') { ?>
                    <code class="cap-captcha-status__sub"><?php echo esc_html($siteKey); ?></code>
                <?php } ?>
            </div>
            <?php
            $this->card(
                __('Challenges', 'privacy-captcha-for-cap'),
                number_format_i18n($challenges),
                $challenges,
                (int) ($prev['challenges'] ?? 0),
            );
        $this->card(
            __('Verified', 'privacy-captcha-for-cap'),
            number_format_i18n($verified),
            $verified,
            (int) ($prev['verified'] ?? 0),
        );
        $this->card(
            __('Failed', 'privacy-captcha-for-cap'),
            number_format_i18n($failed),
            $failed,
            (int) ($prev['failed'] ?? 0),
        );
        $this->card(
            __('Avg. duration', 'privacy-captcha-for-cap'),
            /* translators: %s is the latency in seconds with one decimal, e.g. "3.4 s" */
            sprintf(esc_html__('%s s', 'privacy-captcha-for-cap'), number_format_i18n($avgLatencyMs / 1000, 1)),
            $avgLatencyMs,
            (int) ($prev['avgLatency'] ?? 0),
        );
        ?>
            <div class="cap-captcha-status__card">
                <span class="cap-captcha-status__label"><?php echo esc_html__('Difficulty', 'privacy-captcha-for-cap'); ?></span>
                <strong class="cap-captcha-status__value"><?php echo esc_html((string) $difficulty); ?></strong>
                <span class="cap-captcha-status__sub">
                    <?php echo esc_html(sprintf(
                        /* translators: 1: challenge count, 2: salt size in bytes */
                        __('%1$d challenges · %2$d-byte salt', 'privacy-captcha-for-cap'),
                        $challengeCount,
                        $saltSize
                    )); ?>
                </span>
            </div>
        </div>

        <?php if ($buckets !== []) {
            $this->sparkline($buckets);
        }
    }

    /**
     * Single headline stat card with optional trend % vs the previous period.
     * Colour follows direction: up = green, down = red. The arrow and colour
     * just reflect numeric change — they don't try to be clever about whether
     * "going up" is semantically good for the metric.
     */
    private function card(string $label, string $displayValue, int $current, int $previous): void
    {
        $trendHtml = '';
        if ($previous > 0) {
            $delta = (($current - $previous) / $previous) * 100;
            $up = $delta >= 0;
            $cls = $up ? 'cap-captcha-status__trend--up' : 'cap-captcha-status__trend--down';
            $arrow = $up ? '↗' : '↘';
            $trendHtml = sprintf(
                '<span class="cap-captcha-status__trend %s">%s %s%%</span>',
                esc_attr($cls),
                esc_html($arrow),
                esc_html(number_format_i18n(abs($delta), 0))
            );
        }

        printf(
            '<div class="cap-captcha-status__card"><span class="cap-captcha-status__label">%s</span><strong class="cap-captcha-status__value">%s</strong>%s</div>',
            esc_html($label),
            esc_html($displayValue),
            $trendHtml // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $buckets
     */
    private function sparkline(array $buckets): void
    {
        $max = 0;
        foreach ($buckets as $bucket) {
            $count = (int) ($bucket['verified'] ?? $bucket['challenges'] ?? $bucket['count'] ?? 0);
            if ($count > $max) {
                $max = $count;
            }
        }

        $count = count($buckets);
        $tickHours = [0, 6, 12, 18];

        echo '<div class="cap-captcha-status__sparkline" role="img" aria-label="'
            .esc_attr__('Hourly solves chart', 'privacy-captcha-for-cap')
            .'">';

        echo '<div class="cap-captcha-status__bars">';
        foreach ($buckets as $bucket) {
            $bucketCount = (int) ($bucket['verified'] ?? $bucket['challenges'] ?? $bucket['count'] ?? 0);
            $percent = $max > 0 ? max(4, (int) round($bucketCount / $max * 100)) : 4;
            $bucketTs = (int) ($bucket['bucket'] ?? 0);
            $title = sprintf(
                /* translators: 1: localized hour label, 2: verified-solve count */
                esc_attr__('%1$s — %2$d verified', 'privacy-captcha-for-cap'),
                wp_date('H:i', $bucketTs),
                $bucketCount
            );
            // $percent + $bucketCount are integers passed to %d; $title comes
            // pre-escaped via esc_attr__/sprintf above.
            echo wp_kses(
                sprintf(
                    '<span class="cap-captcha-status__bar" style="height:%d%%" data-count="%d" title="%s"></span>',
                    $percent,
                    $bucketCount,
                    $title
                ),
                ['span' => ['class' => [], 'style' => [], 'data-count' => [], 'title' => []]]
            );
        }
        echo '</div>';

        echo '<div class="cap-captcha-status__axis" aria-hidden="true">';
        foreach ($buckets as $bucket) {
            $bucketTs = (int) ($bucket['bucket'] ?? 0);
            $hour = (int) wp_date('G', $bucketTs);
            $label = $count <= 6 || in_array($hour, $tickHours, true)
                ? wp_date('H', $bucketTs)
                : '';
            printf('<span class="cap-captcha-status__tick">%s</span>', esc_html($label));
        }
        echo '</div>';

        echo '</div>';
    }
}
