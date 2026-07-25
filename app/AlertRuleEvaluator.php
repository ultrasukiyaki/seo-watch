<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use InvalidArgumentException;

final class AlertRuleEvaluator
{
    public const RULE_KEYS = [
        'ranking_drop', 'ranking_gain', 'clicks_drop', 'clicks_gain',
        'impressions_drop', 'impressions_gain', 'ctr_drop',
        'low_ctr_opportunity', 'entered_rank_threshold', 'left_rank_threshold',
    ];

    public function evaluate(array $rule, array $metrics): ?array
    {
        $key = (string)($rule['rule_key'] ?? '');
        if (!in_array($key, self::RULE_KEYS, true)) {
            throw new InvalidArgumentException('不正な変動通知ルールです。');
        }
        $previousClicks = (float)($metrics['previous_clicks'] ?? 0);
        $currentClicks = (float)($metrics['current_clicks'] ?? 0);
        $previousImpressions = (float)($metrics['previous_impressions'] ?? 0);
        $currentImpressions = (float)($metrics['current_impressions'] ?? 0);
        $previousCtr = $previousImpressions > 0 ? $previousClicks / $previousImpressions : null;
        $currentCtr = $currentImpressions > 0 ? $currentClicks / $currentImpressions : null;
        $previousPosition = $previousImpressions > 0 ? (float)$metrics['previous_position_weight'] / $previousImpressions : null;
        $currentPosition = $currentImpressions > 0 ? (float)$metrics['current_position_weight'] / $currentImpressions : null;
        $minimumImpressions = (float)$rule['minimum_impressions'];
        $minimumClicks = (float)$rule['minimum_clicks'];
        $absolute = (float)$rule['absolute_change_threshold'];
        $relative = (float)$rule['relative_change_threshold'];
        $positionChange = (float)$rule['position_change_threshold'];
        $ctrPoints = (float)$rule['ctr_point_threshold'];
        $rank = isset($rule['rank_threshold']) ? (float)$rule['rank_threshold'] : null;
        $matched = false;
        $delta = null;
        $relativeDelta = null;

        switch ($key) {
            case 'ranking_drop':
            case 'ranking_gain':
                if ($previousPosition === null || $currentPosition === null || $currentImpressions < $minimumImpressions) {
                    break;
                }
                $delta = $currentPosition - $previousPosition;
                $matched = $key === 'ranking_drop' ? $delta >= $positionChange : -$delta >= $positionChange;
                break;
            case 'clicks_drop':
            case 'clicks_gain':
                $delta = $currentClicks - $previousClicks;
                $relativeDelta = $previousClicks > 0 ? $delta / $previousClicks : null;
                if ($key === 'clicks_drop') {
                    $matched = $previousClicks >= $minimumClicks && -$delta >= $absolute
                        && $relativeDelta !== null && -$relativeDelta >= $relative;
                } else {
                    $matched = $delta >= $absolute
                        && ($previousClicks == 0.0 ? $currentClicks >= max($minimumClicks, $absolute) : $relativeDelta >= $relative);
                }
                break;
            case 'impressions_drop':
            case 'impressions_gain':
                $delta = $currentImpressions - $previousImpressions;
                $relativeDelta = $previousImpressions > 0 ? $delta / $previousImpressions : null;
                if ($key === 'impressions_drop') {
                    $matched = $previousImpressions >= $minimumImpressions && -$delta >= $absolute
                        && $relativeDelta !== null && -$relativeDelta >= $relative;
                } else {
                    $matched = $delta >= $absolute
                        && ($previousImpressions == 0.0
                            ? $currentImpressions >= max($minimumImpressions, $absolute)
                            : $relativeDelta >= $relative);
                }
                break;
            case 'ctr_drop':
                if ($previousCtr === null || $currentCtr === null
                    || min($previousImpressions, $currentImpressions) < $minimumImpressions) {
                    break;
                }
                $delta = $currentCtr - $previousCtr;
                $relativeDelta = $previousCtr > 0 ? $delta / $previousCtr : null;
                $matched = -$delta >= $ctrPoints
                    && ($relative <= 0 || ($relativeDelta !== null && -$relativeDelta >= $relative));
                break;
            case 'low_ctr_opportunity':
                if ($currentCtr === null || $currentPosition === null) {
                    break;
                }
                $delta = $currentCtr;
                $matched = $currentImpressions >= $minimumImpressions
                    && $currentPosition >= (float)($rule['minimum_position'] ?? 1)
                    && $rank !== null && $currentPosition <= $rank
                    && $currentCtr < (float)($rule['maximum_ctr'] ?? 0);
                break;
            case 'entered_rank_threshold':
            case 'left_rank_threshold':
                if ($previousPosition === null || $currentPosition === null || $rank === null) {
                    break;
                }
                $delta = $currentPosition - $previousPosition;
                $matched = $key === 'entered_rank_threshold'
                    ? $previousPosition > $rank && $currentPosition <= $rank && $currentImpressions >= $minimumImpressions
                    : $previousPosition <= $rank && $currentPosition > $rank && $previousImpressions >= $minimumImpressions;
                break;
        }
        if (!$matched) {
            return null;
        }
        return [
            'previous_clicks' => $previousClicks,
            'current_clicks' => $currentClicks,
            'previous_impressions' => $previousImpressions,
            'current_impressions' => $currentImpressions,
            'previous_ctr' => $previousCtr,
            'current_ctr' => $currentCtr,
            'previous_position' => $previousPosition,
            'current_position' => $currentPosition,
            'absolute_delta' => $delta,
            'relative_delta' => $relativeDelta,
            'explanation' => $this->explanation($key, $delta, $relativeDelta),
        ];
    }

    private function explanation(string $key, ?float $delta, ?float $relative): string
    {
        $labels = [
            'ranking_drop' => '平均掲載順位が設定値以上悪化しました。',
            'ranking_gain' => '平均掲載順位が設定値以上改善しました。',
            'clicks_drop' => 'クリック数が設定した絶対数と割合の両方を超えて減少しました。',
            'clicks_gain' => 'クリック数が設定した基準を超えて増加しました。',
            'impressions_drop' => '表示回数が設定した絶対数と割合の両方を超えて減少しました。',
            'impressions_gain' => '表示回数が設定した基準を超えて増加しました。',
            'ctr_drop' => '期間合計から再計算したCTRが設定幅以上低下しました。',
            'low_ctr_opportunity' => '設定順位内で表示回数が多く、CTRが設定値未満です。',
            'entered_rank_threshold' => '前期間は順位閾値外、現期間は順位閾値内です。',
            'left_rank_threshold' => '前期間は順位閾値内、現期間は順位閾値外です。',
        ];
        $suffix = $delta === null ? '' : sprintf(' 変化量: %.6f%s', $delta, $relative === null ? '' : sprintf('（%.2f%%）', $relative * 100));
        return $labels[$key] . $suffix;
    }
}
