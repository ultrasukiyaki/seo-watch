<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class TrendChart
{
    /** @param list<float|int> $values */
    public static function sparkline(array $values, bool $invert = false, int $width = 120, int $height = 34): string
    {
        $values = array_values(array_map('floatval', $values));
        if ($values === []) {
            return '<span class="muted">—</span>';
        }

        $padding = 3.0;
        $min = min($values);
        $max = max($values);
        $range = max(0.000001, $max - $min);
        $count = count($values);
        $points = [];

        foreach ($values as $i => $value) {
            $x = $count === 1 ? $width / 2 : $padding + ($i / ($count - 1)) * ($width - $padding * 2);
            $ratio = ($value - $min) / $range;
            if ($invert) {
                $ratio = 1 - $ratio;
            }
            $y = $height - $padding - $ratio * ($height - $padding * 2);
            $points[] = self::num($x) . ',' . self::num($y);
        }

        return sprintf(
            '<svg class="sparkline" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="推移"><polyline points="%s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            $width,
            $height,
            $width,
            $height,
            implode(' ', $points)
        );
    }

    /**
     * @param list<array<string,mixed>> $points
     */
    public static function metricChart(array $points, string $key, bool $invert = false, int $width = 420, int $height = 150): string
    {
        if ($points === []) {
            return '<div class="chart-empty">データなし</div>';
        }

        $values = array_map(static fn(array $point): float => (float)($point[$key] ?? 0), $points);
        if ($key === 'position') {
            $positive = array_values(array_filter($values, static fn(float $value): bool => $value > 0));
            if ($positive !== []) {
                $fallback = $positive[0];
                foreach ($values as $index => $value) {
                    if ($value > 0) {
                        $fallback = $value;
                    } else {
                        $values[$index] = $fallback;
                    }
                }
            }
        }
        $dates = array_map(static fn(array $point): string => (string)($point['date'] ?? ''), $points);
        $paddingLeft = 34.0;
        $paddingRight = 12.0;
        $paddingTop = 14.0;
        $paddingBottom = 28.0;
        $plotWidth = $width - $paddingLeft - $paddingRight;
        $plotHeight = $height - $paddingTop - $paddingBottom;
        $min = min($values);
        $max = max($values);
        if ($min === $max) {
            $min = max(0.0, $min - 1.0);
            $max += 1.0;
        }
        $range = $max - $min;
        $count = count($values);
        $line = [];
        $area = [];

        foreach ($values as $i => $value) {
            $x = $count === 1 ? $paddingLeft + $plotWidth / 2 : $paddingLeft + ($i / ($count - 1)) * $plotWidth;
            $ratio = ($value - $min) / $range;
            if ($invert) {
                $ratio = 1 - $ratio;
            }
            $y = $paddingTop + (1 - $ratio) * $plotHeight;
            $line[] = self::num($x) . ',' . self::num($y);
        }

        $area[] = self::num($paddingLeft) . ',' . self::num($paddingTop + $plotHeight);
        $area = array_merge($area, $line);
        $area[] = self::num($paddingLeft + $plotWidth) . ',' . self::num($paddingTop + $plotHeight);

        $midIndex = (int)floor(($count - 1) / 2);
        $firstDate = self::dateLabel($dates[0] ?? '');
        $midDate = self::dateLabel($dates[$midIndex] ?? '');
        $lastDate = self::dateLabel($dates[$count - 1] ?? '');

        $grid = '';
        for ($i = 0; $i <= 3; $i++) {
            $y = $paddingTop + ($plotHeight / 3) * $i;
            $grid .= '<line x1="' . self::num($paddingLeft) . '" y1="' . self::num($y) . '" x2="' . self::num($paddingLeft + $plotWidth) . '" y2="' . self::num($y) . '" class="chart-grid-line"/>';
        }

        $topLabel = $invert ? self::valueLabel($min, $key) : self::valueLabel($max, $key);
        $bottomLabel = $invert ? self::valueLabel($max, $key) : self::valueLabel($min, $key);

        return sprintf(
            '<svg class="metric-chart" viewBox="0 0 %d %d" role="img" aria-label="日別推移">%s<polygon points="%s" class="chart-area"/><polyline points="%s" class="chart-line"/><text x="2" y="%s" class="chart-axis-label">%s</text><text x="2" y="%s" class="chart-axis-label">%s</text><text x="%s" y="%d" text-anchor="start" class="chart-date-label">%s</text><text x="%s" y="%d" text-anchor="middle" class="chart-date-label">%s</text><text x="%s" y="%d" text-anchor="end" class="chart-date-label">%s</text></svg>',
            $width,
            $height,
            $grid,
            implode(' ', $area),
            implode(' ', $line),
            self::num($paddingTop + 9),
            htmlspecialchars($topLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            self::num($paddingTop + $plotHeight),
            htmlspecialchars($bottomLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            self::num($paddingLeft),
            $height - 6,
            htmlspecialchars($firstDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            self::num($paddingLeft + $plotWidth / 2),
            $height - 6,
            htmlspecialchars($midDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            self::num($paddingLeft + $plotWidth),
            $height - 6,
            htmlspecialchars($lastDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function dateLabel(string $date): string
    {
        if (preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $date, $matches)) {
            return ((int)$matches[1]) . '/' . ((int)$matches[2]);
        }
        return $date;
    }

    private static function valueLabel(float $value, string $key): string
    {
        return $key === 'position' ? number_format($value, 1) : number_format($value, $value < 10 ? 1 : 0);
    }
}
