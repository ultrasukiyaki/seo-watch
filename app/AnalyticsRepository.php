<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeImmutable;
use PDO;

final class AnalyticsRepository
{
    public function __construct(private readonly PDO $pdo, private readonly OpportunityScorer $scorer)
    {
    }

    public function dateRange(int $propertyId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT MIN(data_date) min_date, MAX(data_date) max_date FROM search_performance WHERE property_id = :id');
        $stmt->execute(['id' => $propertyId]);
        $row = $stmt->fetch();
        if (!$row || !$row['max_date']) {
            return null;
        }
        return $row;
    }

    public function summary(int $propertyId, int $days = 28): array
    {
        $range = $this->periods($propertyId, $days);
        if (!$range) {
            return ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0, 'range' => null];
        }
        $stmt = $this->pdo->prepare(
            'SELECT SUM(clicks) clicks, SUM(impressions) impressions,
                    SUM(clicks) / NULLIF(SUM(impressions),0) ctr,
                    SUM(position * impressions) / NULLIF(SUM(impressions),0) position
             FROM search_performance
             WHERE property_id = :property_id AND data_date BETWEEN :start_date AND :end_date'
        );
        $stmt->execute([
            'property_id' => $propertyId,
            'start_date' => $range['current_start'],
            'end_date' => $range['current_end'],
        ]);
        $row = $stmt->fetch() ?: [];
        return [
            'clicks' => (float)($row['clicks'] ?? 0),
            'impressions' => (float)($row['impressions'] ?? 0),
            'ctr' => (float)($row['ctr'] ?? 0),
            'position' => (float)($row['position'] ?? 0),
            'range' => $range,
        ];
    }

    /**
     * 検索語単位で集約する。同じ検索語が複数ページ・アンカーへ出た場合も1件にまとめる。
     */
    public function opportunities(int $propertyId, int $days = 28, int $limit = 100, int $minImpressions = 10): array
    {
        return array_slice($this->buildOpportunities($propertyId, $days, $minImpressions), 0, max(1, $limit));
    }

    /**
     * 伸びしろ一覧をページングする。
     *
     * @return array{rows:list<array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int,has_previous:bool,has_next:bool,from:int,to:int}
     */
    public function opportunityPage(
        int $propertyId,
        int $days = 28,
        int $page = 1,
        int $perPage = 25,
        int $minImpressions = 10
    ): array {
        return Paginator::slice(
            $this->buildOpportunities($propertyId, $days, $minImpressions),
            $page,
            $perPage,
            [25, 50, 100]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildOpportunities(int $propertyId, int $days, int $minImpressions): array
    {
        $rows = $this->queryPageOpportunityRows($propertyId, $days);
        $queries = [];

        foreach ($rows as $row) {
            $query = (string)$row['query_text'];
            if (!isset($queries[$query])) {
                $queries[$query] = [
                    'query_text' => $query,
                    'current_clicks' => 0.0,
                    'current_impressions' => 0.0,
                    'current_position_weight' => 0.0,
                    'previous_clicks' => 0.0,
                    'previous_impressions' => 0.0,
                    'previous_position_weight' => 0.0,
                    'pages' => [],
                    'page_url' => '',
                    'primary_page_impressions' => -1.0,
                ];
            }

            $currentImpressions = (float)$row['current_impressions'];
            $previousImpressions = (float)$row['previous_impressions'];
            $pageUrl = (string)$row['page_url'];
            $queries[$query]['current_clicks'] += (float)$row['current_clicks'];
            $queries[$query]['current_impressions'] += $currentImpressions;
            $queries[$query]['current_position_weight'] += (float)$row['current_position'] * $currentImpressions;
            $queries[$query]['previous_clicks'] += (float)$row['previous_clicks'];
            $queries[$query]['previous_impressions'] += $previousImpressions;
            $queries[$query]['previous_position_weight'] += (float)$row['previous_position'] * $previousImpressions;
            $queries[$query]['pages'][$pageUrl] = true;

            if ($currentImpressions > $queries[$query]['primary_page_impressions']) {
                $queries[$query]['primary_page_impressions'] = $currentImpressions;
                $queries[$query]['page_url'] = $pageUrl;
            }
        }

        $scored = [];
        foreach ($queries as $item) {
            if ($item['current_impressions'] < $minImpressions) {
                continue;
            }
            $item['current_position'] = $item['current_impressions'] > 0
                ? $item['current_position_weight'] / $item['current_impressions']
                : 0.0;
            $item['previous_position'] = $item['previous_impressions'] > 0
                ? $item['previous_position_weight'] / $item['previous_impressions']
                : 0.0;
            $item['page_count'] = count($item['pages']);
            $item['page_urls'] = array_keys($item['pages']);
            unset(
                $item['current_position_weight'],
                $item['previous_position_weight'],
                $item['primary_page_impressions'],
                $item['pages']
            );

            $item = $this->scorer->score($item);
            if ($item['page_count'] > 1) {
                $item['reasons'][] = '複数ページで表示（カニバリ候補）';
            }
            $scored[] = $item;
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return $scored;
    }

    /**
     * 正規化URL単位で検索語のスコアを積み上げる。
     */
    public function pageOpportunities(int $propertyId, int $days = 28, int $limit = 20): array
    {
        $items = $this->queryPageOpportunityRows($propertyId, $days);
        $pages = [];

        foreach ($items as $item) {
            if ((float)$item['current_impressions'] < 10) {
                continue;
            }
            $url = (string)$item['page_url'];
            if (!isset($pages[$url])) {
                $pages[$url] = [
                    'page_url' => $url,
                    'score' => 0.0,
                    'impressions' => 0.0,
                    'clicks' => 0.0,
                    'position_weight' => 0.0,
                    'queries_by_score' => [],
                ];
            }
            $scored = $this->scorer->score($item);
            $impressions = (float)$item['current_impressions'];
            $pages[$url]['score'] += (float)$scored['score'];
            $pages[$url]['impressions'] += $impressions;
            $pages[$url]['clicks'] += (float)$item['current_clicks'];
            $pages[$url]['position_weight'] += (float)$item['current_position'] * $impressions;
            $query = (string)$item['query_text'];
            $pages[$url]['queries_by_score'][$query] = max(
                (float)($pages[$url]['queries_by_score'][$query] ?? 0),
                (float)$scored['score']
            );
        }

        foreach ($pages as &$page) {
            arsort($page['queries_by_score'], SORT_NUMERIC);
            $page['queries'] = array_slice(array_keys($page['queries_by_score']), 0, 3);
            $page['ctr'] = $page['impressions'] > 0 ? $page['clicks'] / $page['impressions'] : 0.0;
            $page['position'] = $page['impressions'] > 0 ? $page['position_weight'] / $page['impressions'] : 0.0;
            unset($page['queries_by_score'], $page['position_weight']);
        }
        unset($page);

        $pages = array_values($pages);
        usort($pages, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($pages, 0, $limit);
    }

    public function queryRows(int $propertyId, string $search = '', int $page = 1, int $perPage = 50): array
    {
        return $this->aggregateRows($propertyId, 'query', $search, $page, $perPage);
    }

    public function pageRows(int $propertyId, string $search = '', int $page = 1, int $perPage = 50): array
    {
        return $this->aggregateRows($propertyId, 'page', $search, $page, $perPage);
    }


    /**
     * 正規化URL単位の記事詳細分析。
     *
     * @return array<string,mixed>|null
     */
    public function pageDetail(int $propertyId, string $pageUrl, int $days = 28): ?array
    {
        $range = $this->periods($propertyId, $days);
        if (!$range) {
            return null;
        }

        $pageExpression = $this->canonicalPageExpression();
        $summarySql = "SELECT
                    SUM(CASE WHEN data_date BETWEEN :current_start AND :current_end THEN clicks ELSE 0 END) current_clicks,
                    SUM(CASE WHEN data_date BETWEEN :current_start2 AND :current_end2 THEN impressions ELSE 0 END) current_impressions,
                    SUM(CASE WHEN data_date BETWEEN :current_start3 AND :current_end3 THEN position * impressions ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN data_date BETWEEN :current_start4 AND :current_end4 THEN impressions ELSE 0 END),0) current_position,
                    SUM(CASE WHEN data_date BETWEEN :previous_start AND :previous_end THEN clicks ELSE 0 END) previous_clicks,
                    SUM(CASE WHEN data_date BETWEEN :previous_start2 AND :previous_end2 THEN impressions ELSE 0 END) previous_impressions,
                    SUM(CASE WHEN data_date BETWEEN :previous_start3 AND :previous_end3 THEN position * impressions ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN data_date BETWEEN :previous_start4 AND :previous_end4 THEN impressions ELSE 0 END),0) previous_position
                FROM search_performance
                WHERE property_id = :property_id
                  AND data_date BETWEEN :previous_start5 AND :current_end5
                  AND {$pageExpression} = :page_url";

        $params = ['property_id' => $propertyId, 'page_url' => $pageUrl];
        foreach (['current_start','current_start2','current_start3','current_start4'] as $key) {
            $params[$key] = $range['current_start'];
        }
        foreach (['current_end','current_end2','current_end3','current_end4','current_end5'] as $key) {
            $params[$key] = $range['current_end'];
        }
        foreach (['previous_start','previous_start2','previous_start3','previous_start4','previous_start5'] as $key) {
            $params[$key] = $range['previous_start'];
        }
        foreach (['previous_end','previous_end2','previous_end3','previous_end4'] as $key) {
            $params[$key] = $range['previous_end'];
        }

        $stmt = $this->pdo->prepare($summarySql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, $key === 'property_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $summary = $stmt->fetch() ?: [];
        $currentImpressions = (float)($summary['current_impressions'] ?? 0);
        $previousImpressions = (float)($summary['previous_impressions'] ?? 0);
        if ($currentImpressions <= 0 && $previousImpressions <= 0) {
            return null;
        }

        $currentClicks = (float)($summary['current_clicks'] ?? 0);
        $previousClicks = (float)($summary['previous_clicks'] ?? 0);
        $currentPosition = (float)($summary['current_position'] ?? 0);
        $previousPosition = (float)($summary['previous_position'] ?? 0);
        $currentCtr = $currentImpressions > 0 ? $currentClicks / $currentImpressions : 0.0;
        $previousCtr = $previousImpressions > 0 ? $previousClicks / $previousImpressions : 0.0;

        $queryRows = $this->pageQueryRows($propertyId, $pageUrl, $range);
        $trendMap = $this->pageQueryTrendMap($propertyId, $pageUrl, $range);

        $score = 0.0;
        foreach ($queryRows as &$query) {
            $query = $this->scorer->score($query);
            $query['click_change'] = (float)$query['current_clicks'] - (float)$query['previous_clicks'];
            $query['impression_change'] = (float)$query['current_impressions'] - (float)$query['previous_impressions'];
            $query['position_change'] = (float)$query['previous_position'] > 0
                ? (float)$query['previous_position'] - (float)$query['current_position']
                : 0.0;
            $query['trend'] = $trendMap[(string)$query['query_text']] ?? [];
            $score += (float)$query['score'];
        }
        unset($query);
        usort($queryRows, static function (array $a, array $b): int {
            $scoreCompare = (float)$b['score'] <=> (float)$a['score'];
            return $scoreCompare !== 0
                ? $scoreCompare
                : ((float)$b['current_impressions'] <=> (float)$a['current_impressions']);
        });

        return [
            'page_url' => $pageUrl,
            'days' => $days,
            'range' => $range,
            'current_clicks' => $currentClicks,
            'current_impressions' => $currentImpressions,
            'current_ctr' => $currentCtr,
            'current_position' => $currentPosition,
            'previous_clicks' => $previousClicks,
            'previous_impressions' => $previousImpressions,
            'previous_ctr' => $previousCtr,
            'previous_position' => $previousPosition,
            'click_change' => $currentClicks - $previousClicks,
            'impression_change' => $currentImpressions - $previousImpressions,
            'ctr_change' => $currentCtr - $previousCtr,
            'position_change' => $previousPosition > 0 ? $previousPosition - $currentPosition : 0.0,
            'score' => round($score, 2),
            'daily' => $this->pageDailyTrend($propertyId, $pageUrl, $range),
            'queries' => $queryRows,
            'query_count' => count($queryRows),
        ];
    }

    /**
     * @param array<string,string> $range
     * @return list<array<string,mixed>>
     */
    private function pageQueryRows(int $propertyId, string $pageUrl, array $range): array
    {
        $pageExpression = $this->canonicalPageExpression();
        $sql = "SELECT query_text,
                    SUM(CASE WHEN data_date BETWEEN :current_start AND :current_end THEN clicks ELSE 0 END) current_clicks,
                    SUM(CASE WHEN data_date BETWEEN :current_start2 AND :current_end2 THEN impressions ELSE 0 END) current_impressions,
                    SUM(CASE WHEN data_date BETWEEN :current_start3 AND :current_end3 THEN position * impressions ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN data_date BETWEEN :current_start4 AND :current_end4 THEN impressions ELSE 0 END),0) current_position,
                    SUM(CASE WHEN data_date BETWEEN :previous_start AND :previous_end THEN clicks ELSE 0 END) previous_clicks,
                    SUM(CASE WHEN data_date BETWEEN :previous_start2 AND :previous_end2 THEN impressions ELSE 0 END) previous_impressions,
                    SUM(CASE WHEN data_date BETWEEN :previous_start3 AND :previous_end3 THEN position * impressions ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN data_date BETWEEN :previous_start4 AND :previous_end4 THEN impressions ELSE 0 END),0) previous_position
                FROM search_performance
                WHERE property_id = :property_id
                  AND data_date BETWEEN :previous_start5 AND :current_end5
                  AND {$pageExpression} = :page_url
                GROUP BY query_hash, query_text
                HAVING current_impressions > 0 OR previous_impressions > 0
                ORDER BY current_impressions DESC
                LIMIT 1000";

        $params = ['property_id' => $propertyId, 'page_url' => $pageUrl];
        foreach (['current_start','current_start2','current_start3','current_start4'] as $key) {
            $params[$key] = $range['current_start'];
        }
        foreach (['current_end','current_end2','current_end3','current_end4','current_end5'] as $key) {
            $params[$key] = $range['current_end'];
        }
        foreach (['previous_start','previous_start2','previous_start3','previous_start4','previous_start5'] as $key) {
            $params[$key] = $range['previous_start'];
        }
        foreach (['previous_end','previous_end2','previous_end3','previous_end4'] as $key) {
            $params[$key] = $range['previous_end'];
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, $key === 'property_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,string> $range
     * @return list<array{date:string,clicks:float,impressions:float,position:float}>
     */
    private function pageDailyTrend(int $propertyId, string $pageUrl, array $range): array
    {
        $pageExpression = $this->canonicalPageExpression();
        $sql = "SELECT data_date,
                       SUM(clicks) clicks,
                       SUM(impressions) impressions,
                       SUM(position * impressions) / NULLIF(SUM(impressions),0) position
                FROM search_performance
                WHERE property_id = :property_id
                  AND data_date BETWEEN :start_date AND :end_date
                  AND {$pageExpression} = :page_url
                GROUP BY data_date
                ORDER BY data_date";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'property_id' => $propertyId,
            'start_date' => $range['current_start'],
            'end_date' => $range['current_end'],
            'page_url' => $pageUrl,
        ]);
        $byDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDate[(string)$row['data_date']] = [
                'date' => (string)$row['data_date'],
                'clicks' => (float)$row['clicks'],
                'impressions' => (float)$row['impressions'],
                'position' => (float)$row['position'],
            ];
        }

        $result = [];
        $date = new DateTimeImmutable($range['current_start']);
        $end = new DateTimeImmutable($range['current_end']);
        while ($date <= $end) {
            $key = $date->format('Y-m-d');
            $result[] = $byDate[$key] ?? ['date' => $key, 'clicks' => 0.0, 'impressions' => 0.0, 'position' => 0.0];
            $date = $date->modify('+1 day');
        }
        return $result;
    }

    /**
     * @param array<string,string> $range
     * @return array<string,list<array<string,mixed>>>
     */
    private function pageQueryTrendMap(int $propertyId, string $pageUrl, array $range): array
    {
        $pageExpression = $this->canonicalPageExpression();
        $sql = "SELECT data_date, query_text,
                       SUM(clicks) clicks,
                       SUM(impressions) impressions,
                       SUM(position * impressions) / NULLIF(SUM(impressions),0) position
                FROM search_performance
                WHERE property_id = :property_id
                  AND data_date BETWEEN :start_date AND :end_date
                  AND {$pageExpression} = :page_url
                GROUP BY data_date, query_hash, query_text
                ORDER BY data_date";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'property_id' => $propertyId,
            'start_date' => $range['current_start'],
            'end_date' => $range['current_end'],
            'page_url' => $pageUrl,
        ]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $query = (string)$row['query_text'];
            $map[$query][(string)$row['data_date']] = [
                'date' => (string)$row['data_date'],
                'clicks' => (float)$row['clicks'],
                'impressions' => (float)$row['impressions'],
                'position' => (float)$row['position'],
            ];
        }

        $start = new DateTimeImmutable($range['current_start']);
        $end = new DateTimeImmutable($range['current_end']);
        foreach ($map as $query => $rows) {
            $filled = [];
            $date = $start;
            while ($date <= $end) {
                $key = $date->format('Y-m-d');
                $filled[] = $rows[$key] ?? ['date' => $key, 'clicks' => 0.0, 'impressions' => 0.0, 'position' => 0.0];
                $date = $date->modify('+1 day');
            }
            $map[$query] = $filled;
        }

        return $map;
    }

    public function recentRuns(int $propertyId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM import_runs WHERE property_id = :id ORDER BY id DESC LIMIT ' . (int)$limit);
        $stmt->execute(['id' => $propertyId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function queryPageOpportunityRows(int $propertyId, int $days): array
    {
        $range = $this->periods($propertyId, $days);
        if (!$range) {
            return [];
        }

        $pageExpression = $this->canonicalPageExpression();
        $sql = "SELECT query_text, {$pageExpression} page_url,
                    SUM(CASE WHEN data_date BETWEEN :current_start AND :current_end THEN clicks ELSE 0 END) current_clicks,
                    SUM(CASE WHEN data_date BETWEEN :current_start2 AND :current_end2 THEN impressions ELSE 0 END) current_impressions,
                    SUM(CASE WHEN data_date BETWEEN :current_start3 AND :current_end3 THEN position * impressions ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN data_date BETWEEN :current_start4 AND :current_end4 THEN impressions ELSE 0 END),0) current_position,
                    SUM(CASE WHEN data_date BETWEEN :previous_start AND :previous_end THEN clicks ELSE 0 END) previous_clicks,
                    SUM(CASE WHEN data_date BETWEEN :previous_start2 AND :previous_end2 THEN impressions ELSE 0 END) previous_impressions,
                    SUM(CASE WHEN data_date BETWEEN :previous_start3 AND :previous_end3 THEN position * impressions ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN data_date BETWEEN :previous_start4 AND :previous_end4 THEN impressions ELSE 0 END),0) previous_position
                FROM search_performance
                WHERE property_id = :property_id AND data_date BETWEEN :previous_start5 AND :current_end5
                GROUP BY query_hash, query_text, {$pageExpression}
                HAVING current_impressions > 0
                ORDER BY current_impressions DESC
                LIMIT 10000";

        $params = ['property_id' => $propertyId];
        foreach (['current_start','current_start2','current_start3','current_start4'] as $key) {
            $params[$key] = $range['current_start'];
        }
        foreach (['current_end','current_end2','current_end3','current_end4','current_end5'] as $key) {
            $params[$key] = $range['current_end'];
        }
        foreach (['previous_start','previous_start2','previous_start3','previous_start4','previous_start5'] as $key) {
            $params[$key] = $range['previous_start'];
        }
        foreach (['previous_end','previous_end2','previous_end3','previous_end4'] as $key) {
            $params[$key] = $range['previous_end'];
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, $key === 'property_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function aggregateRows(int $propertyId, string $dimension, string $search, int $page, int $perPage): array
    {
        $range = $this->periods($propertyId, 28);
        $perPage = Paginator::normalizePerPage($perPage, [25, 50, 100]);
        if (!$range) {
            return [
                'rows' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0,
                'total_pages' => 1, 'has_previous' => false, 'has_next' => false,
                'from' => 0, 'to' => 0,
            ];
        }

        $isPage = $dimension === 'page';
        $labelExpression = $isPage ? $this->canonicalPageExpression() : 'query_text';
        $groupExpression = $isPage ? $labelExpression : 'query_hash, query_text';
        $whereSearch = $search !== '' ? " AND {$labelExpression} LIKE :search" : '';

        $countSql = "SELECT COUNT(*) total FROM (
                SELECT 1
                FROM search_performance
                WHERE property_id = :property_id
                  AND data_date BETWEEN :start_date AND :end_date {$whereSearch}
                GROUP BY {$groupExpression}
            ) grouped_rows";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
        $countStmt->bindValue(':start_date', $range['current_start']);
        $countStmt->bindValue(':end_date', $range['current_end']);
        if ($search !== '') {
            $countStmt->bindValue(':search', '%' . $search . '%');
        }
        $countStmt->execute();
        $total = (int)($countStmt->fetchColumn() ?: 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "SELECT {$labelExpression} label, SUM(clicks) clicks, SUM(impressions) impressions,
                       SUM(clicks)/NULLIF(SUM(impressions),0) ctr,
                       SUM(position*impressions)/NULLIF(SUM(impressions),0) position,
                       COUNT(DISTINCT data_date) active_days
                FROM search_performance
                WHERE property_id = :property_id AND data_date BETWEEN :start_date AND :end_date {$whereSearch}
                GROUP BY {$groupExpression}
                ORDER BY impressions DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $range['current_start']);
        $stmt->bindValue(':end_date', $range['current_end']);
        if ($search !== '') {
            $stmt->bindValue(':search', '%' . $search . '%');
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $from = $total > 0 ? $offset + 1 : 0;
        $to = $total > 0 ? min($offset + count($rows), $total) : 0;

        return [
            'rows' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'from' => $from,
            'to' => $to,
            'range' => $range,
        ];
    }

    private function canonicalPageExpression(): string
    {
        return "CASE
            WHEN normalized_page_url IS NOT NULL AND normalized_page_url <> '' THEN normalized_page_url
            WHEN SUBSTRING_INDEX(page_url, '#', 1) REGEXP '^https?://[^/]+/$' THEN SUBSTRING_INDEX(page_url, '#', 1)
            ELSE TRIM(TRAILING '/' FROM SUBSTRING_INDEX(page_url, '#', 1))
        END";
    }

    private function periods(int $propertyId, int $days): ?array
    {
        $dateRange = $this->dateRange($propertyId);
        if (!$dateRange) {
            return null;
        }
        $end = new DateTimeImmutable((string)$dateRange['max_date']);
        $currentStart = $end->modify('-' . ($days - 1) . ' days');
        $previousEnd = $currentStart->modify('-1 day');
        $previousStart = $previousEnd->modify('-' . ($days - 1) . ' days');
        return [
            'current_start' => $currentStart->format('Y-m-d'),
            'current_end' => $end->format('Y-m-d'),
            'previous_start' => $previousStart->format('Y-m-d'),
            'previous_end' => $previousEnd->format('Y-m-d'),
        ];
    }
}
