<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeImmutable;
use PDO;

final class EffectComparisonService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function compare(int $propertyId, string $normalizedUrl, string $revisionDate): array
    {
        $revision = new DateTimeImmutable($revisionDate);
        $ranges = [
            'before' => [$revision->modify('-28 days')->format('Y-m-d'), $revision->modify('-1 day')->format('Y-m-d')],
            'after' => [$revision->modify('+1 day')->format('Y-m-d'), $revision->modify('+28 days')->format('Y-m-d')],
        ];
        $result = [];
        foreach ($ranges as $name => [$start, $end]) {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(impressions),0) impressions,
                 CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE NULL END position,
                 COUNT(DISTINCT data_date) data_days
                 FROM search_performance WHERE property_id = :property AND normalized_page_hash = :hash
                 AND data_date BETWEEN :start AND :end'
            );
            $stmt->bindValue(':property', $propertyId, PDO::PARAM_INT);
            $stmt->bindValue(':hash', hash('sha256', $normalizedUrl, true), PDO::PARAM_LOB);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->execute();
            $row = $stmt->fetch() ?: [];
            $impressions = (float)($row['impressions'] ?? 0);
            $result[$name] = [
                'start' => $start, 'end' => $end, 'clicks' => (float)($row['clicks'] ?? 0),
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? (float)$row['clicks'] / $impressions : 0.0,
                'position' => isset($row['position']) ? (float)$row['position'] : null,
                'data_days' => (int)($row['data_days'] ?? 0),
            ];
        }
        $result['is_final'] = $result['after']['data_days'] >= 28;
        $result['remaining_days'] = max(0, 28 - $result['after']['data_days']);
        $result['notice'] = '修正前後の変化です。季節変動、検索需要、Googleアップデートなどの影響を含む可能性があります。';
        return $result;
    }
}
