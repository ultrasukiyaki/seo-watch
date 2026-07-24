<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use RuntimeException;

final class MaintenanceService
{
    public const RETENTION_DAYS = [
        'tokens' => 7,
        'rate-limits' => 30,
        'auth-audit' => 365,
        'import-runs' => 180,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(bool $execute, ?string $target = null): array
    {
        $queries = [
            'tokens' => 'FROM user_action_tokens WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) OR used_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
            'rate-limits' => 'FROM auth_rate_limits WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)',
            'auth-audit' => 'FROM authentication_audit_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY)',
            'import-runs' => 'FROM import_runs WHERE status <> "running" AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)',
            'import-locks' => 'FROM import_locks WHERE expires_at < UTC_TIMESTAMP()',
        ];
        if ($target === 'auth') {
            $queries = array_intersect_key($queries, array_flip(['tokens', 'rate-limits', 'auth-audit']));
        } elseif ($target !== null) {
            if (!isset($queries[$target])) {
                throw new RuntimeException('不明なtargetです。');
            }
            $queries = [$target => $queries[$target]];
        }
        $result = [];
        if ($execute) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($queries as $name => $fragment) {
                $planned = (int)$this->pdo->query('SELECT COUNT(*) ' . $fragment)->fetchColumn();
                $deleted = $execute && $planned > 0 ? $this->pdo->exec('DELETE ' . $fragment) : 0;
                $result[$name] = ['planned' => $planned, 'deleted' => $deleted];
            }
            if ($execute) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $result;
    }
}
