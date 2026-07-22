<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class OpportunityScorer
{
    public function score(array $row): array
    {
        $impressions = (float)$row['current_impressions'];
        $clicks = (float)$row['current_clicks'];
        $position = (float)$row['current_position'];
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;
        $previousPosition = isset($row['previous_position']) ? (float)$row['previous_position'] : 0.0;
        $previousClicks = (float)($row['previous_clicks'] ?? 0);

        $expectedCtr = match (true) {
            $position <= 3 => 0.12,
            $position <= 5 => 0.08,
            $position <= 10 => 0.04,
            $position <= 20 => 0.015,
            default => 0.005,
        };

        $ctrGapClicks = max(0.0, $impressions * ($expectedCtr - $ctr));
        $strikingDistance = ($position >= 4 && $position <= 20)
            ? $impressions * ((21 - $position) / 20) * 0.08
            : 0.0;
        $positionTrend = ($previousPosition > 0 && $previousPosition > $position)
            ? min(20.0, ($previousPosition - $position) * 2)
            : 0.0;
        $clickTrend = max(0.0, $clicks - $previousClicks) * 0.5;
        $score = round($ctrGapClicks + $strikingDistance + $positionTrend + $clickTrend, 2);

        $reasons = [];
        if ($position >= 4 && $position <= 10) {
            $reasons[] = '1ページ目の上位目前';
        } elseif ($position > 10 && $position <= 20) {
            $reasons[] = '2ページ目から1ページ目を狙える';
        }
        if ($ctr + 0.001 < $expectedCtr && $impressions >= 20) {
            $reasons[] = '表示回数に対してCTRが低め';
        }
        if ($previousPosition > 0 && $previousPosition - $position >= 1.0) {
            $reasons[] = '順位が上昇中';
        }
        if ($previousPosition > 0 && $position - $previousPosition >= 2.0) {
            $reasons[] = '順位下落の点検が必要';
        }
        if (!$reasons) {
            $reasons[] = '検索需要を継続監視';
        }

        return $row + [
            'current_ctr' => $ctr,
            'expected_ctr' => $expectedCtr,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }
}
