<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class AuthenticationAuditLogger
{
    public function __construct(private readonly PDO $pdo, private readonly string $key)
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
            $where[] = 'created_at >= :from_date';
            $params['from_date'] = (string)$filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)';
            $params['to_date'] = (string)$filters['to'];
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
