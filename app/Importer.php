<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;

final class Importer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SearchConsoleApi $api,
        private readonly UrlNormalizer $normalizer
    ) {
    }

    public function import(array $property, string $startDate, string $endDate, string $searchType = 'web'): int
    {
        $propertyId = (int)$property['id'];
        $siteUrl = (string)$property['site_url'];
        $runId = $this->startRun($propertyId, $startDate, $endDate);
        $total = 0;

        try {
            $period = new DatePeriod(
                new DateTimeImmutable($startDate),
                new DateInterval('P1D'),
                (new DateTimeImmutable($endDate))->modify('+1 day')
            );
            foreach ($period as $date) {
                $total += $this->importDay($propertyId, $siteUrl, $date->format('Y-m-d'), $searchType);
            }
            $stmt = $this->pdo->prepare('UPDATE search_properties SET last_synced_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['id' => $propertyId]);
            $this->finishRun($runId, 'success', $total, null);
            return $total;
        } catch (\Throwable $e) {
            $this->finishRun($runId, 'failed', $total, $e->getMessage());
            throw $e;
        }
    }

    private function importDay(int $propertyId, string $siteUrl, string $date, string $searchType): int
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM search_performance WHERE property_id = :property_id AND data_date = :data_date AND search_type = :search_type'
            );
            $delete->execute(['property_id' => $propertyId, 'data_date' => $date, 'search_type' => $searchType]);

            $insert = $this->pdo->prepare(
                'INSERT INTO search_performance
                (property_id, data_date, query_text, query_hash, page_url, page_hash,
                 normalized_page_url, normalized_page_hash, country, device, search_type,
                 clicks, impressions, ctr, position)
                VALUES
                (:property_id, :data_date, :query_text, :query_hash, :page_url, :page_hash,
                 :normalized_page_url, :normalized_page_hash, :country, :device, :search_type,
                 :clicks, :impressions, :ctr, :position)'
            );

            $startRow = 0;
            $rowLimit = 25000;
            $count = 0;
            do {
                $response = $this->api->query($siteUrl, [
                    'startDate' => $date,
                    'endDate' => $date,
                    'dimensions' => ['date', 'query', 'page', 'country', 'device'],
                    'type' => $searchType,
                    'aggregationType' => 'auto',
                    'dataState' => 'final',
                    'rowLimit' => $rowLimit,
                    'startRow' => $startRow,
                ]);
                $rows = $response['rows'] ?? [];
                foreach ($rows as $row) {
                    $keys = $row['keys'] ?? [];
                    $query = (string)($keys[1] ?? '');
                    $page = (string)($keys[2] ?? '');
                    $normalizedPage = $this->normalizer->normalize($page);
                    $insert->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
                    $insert->bindValue(':data_date', $date);
                    $insert->bindValue(':query_text', $query);
                    $insert->bindValue(':query_hash', hash('sha256', $query, true), PDO::PARAM_LOB);
                    $insert->bindValue(':page_url', $page);
                    $insert->bindValue(':page_hash', hash('sha256', $page, true), PDO::PARAM_LOB);
                    $insert->bindValue(':normalized_page_url', $normalizedPage);
                    $insert->bindValue(':normalized_page_hash', hash('sha256', $normalizedPage, true), PDO::PARAM_LOB);
                    $insert->bindValue(':country', (string)($keys[3] ?? ''));
                    $insert->bindValue(':device', (string)($keys[4] ?? ''));
                    $insert->bindValue(':search_type', $searchType);
                    $insert->bindValue(':clicks', (float)($row['clicks'] ?? 0));
                    $insert->bindValue(':impressions', (float)($row['impressions'] ?? 0));
                    $insert->bindValue(':ctr', (float)($row['ctr'] ?? 0));
                    $insert->bindValue(':position', (float)($row['position'] ?? 0));
                    $insert->execute();
                    $count++;
                }
                $fetched = count($rows);
                $startRow += $fetched;
            } while ($fetched === $rowLimit);

            $this->pdo->commit();
            return $count;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function startRun(int $propertyId, string $startDate, string $endDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_runs (property_id, start_date, end_date, status) VALUES (:property_id, :start_date, :end_date, "running")'
        );
        $stmt->execute(['property_id' => $propertyId, 'start_date' => $startDate, 'end_date' => $endDate]);
        return (int)$this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, string $status, int $rows, ?string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE import_runs SET finished_at = CURRENT_TIMESTAMP, status = :status, rows_imported = :rows, message = :message WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'rows' => $rows, 'message' => $message, 'id' => $runId]);
    }
}
