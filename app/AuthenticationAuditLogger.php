<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class AuthenticationAuditLogger
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $key,
        private readonly ?DateTimeFormatter $dateTime = null
    )
    {
    }

    public function log(
        string $event,
        string $outcome,
        ?int $actorId = null,
        ?int $subjectId = null,
        array $metadata = [],
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        $safe = [];
        foreach ($metadata as $name => $value) {
            if (preg_match('/password|token|url|email|secret|session/i', (string)$name)) {
                continue;
            }
            $safe[(string)$name] = is_scalar($value) ? substr((string)$value, 0, 200) : null;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO authentication_audit_logs
             (event_type, outcome, actor_user_id, subject_user_id, pseudonymized_ip, user_agent_hash, metadata_json)
             VALUES (:event, :outcome, :actor, :subject, :ip, :ua, :metadata)'
        );
        $stmt->execute([
            'event' => substr($event, 0, 64),
            'outcome' => substr($outcome, 0, 20),
            'actor' => $actorId,
            'subject' => $subjectId,
            'ip' => $ip === null ? null : hash_hmac('sha256', $ip, $this->key),
            'ua' => $userAgent === null ? null : hash('sha256', substr($userAgent, 0, 512)),
            'metadata' => $safe ? json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        ]);
    }

    public function recent(array $filters, int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        foreach (['event_type' => 'event_type', 'outcome' => 'outcome'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = (string)$filters[$input];
            }
        }
        if (!empty($filters['from'])) {
            $from = $this->dateTime?->localDateBoundaryToUtc((string)$filters['from']);
            if ($from !== null || $this->dateTime === null) {
                $where[] = 'created_at >= :from_date';
                $params['from_date'] = $from ?? (string)$filters['from'] . ' 00:00:00';
            }
        }
        if (!empty($filters['to'])) {
            $to = $this->dateTime?->localDateBoundaryToUtc((string)$filters['to'], true);
            if ($to !== null || $this->dateTime === null) {
                $where[] = 'created_at < :to_date';
                $params['to_date'] = $to ?? (string)$filters['to'] . ' 23:59:59';
            }
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM authentication_audit_logs' . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            'SELECT * FROM authentication_audit_logs' . $sqlWhere .
            ' ORDER BY id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }
}
