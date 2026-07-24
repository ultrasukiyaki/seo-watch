<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use RuntimeException;

final class ImprovementTaskRepository
{
    public const STATUSES = ['open', 'in_progress', 'completed', 'on_hold', 'ignored'];
    public const TYPES = ['title', 'heading', 'ctr', 'ranking', 'content', 'internal_link', 'technical', 'other'];

    public function __construct(private readonly PDO $pdo, private readonly UrlNormalizer $normalizer)
    {
    }

    public function create(array $input, int $actorId): int
    {
        $type = (string)($input['task_type'] ?? 'other');
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('タスク種別が不正です。');
        }
        $url = $this->normalizer->normalize((string)($input['normalized_page_url'] ?? ''));
        $title = trim((string)($input['title'] ?? ''));
        if ($url === '' || $title === '' || mb_strlen($title) > 255) {
            throw new RuntimeException('URLとタイトルを正しく入力してください。');
        }
        $query = trim((string)($input['source_query'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $fingerprint = implode("\0", [$type, mb_strtolower($query), preg_replace('/\s+/u', ' ', mb_strtolower($title . "\n" . $description))]);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO improvement_tasks
                 (property_id, normalized_page_hash, normalized_page_url, task_type, title, description,
                  source_query, source_score, suggestion_hash, created_by_user_id, updated_by_user_id)
                 VALUES (:property, :url_hash, :url, :type, :title, :description, :query, :score, :suggestion, :actor, :actor)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), description = VALUES(description),
                  source_score = VALUES(source_score), updated_by_user_id = VALUES(updated_by_user_id)'
            );
            $stmt->bindValue(':property', (int)$input['property_id'], PDO::PARAM_INT);
            $stmt->bindValue(':url_hash', hash('sha256', $url, true), PDO::PARAM_LOB);
            $stmt->bindValue(':url', $url);
            $stmt->bindValue(':type', $type);
            $stmt->bindValue(':title', $title);
            $stmt->bindValue(':description', $description !== '' ? $description : null);
            $stmt->bindValue(':query', $query !== '' ? $query : null);
            $stmt->bindValue(':score', isset($input['source_score']) ? (float)$input['source_score'] : null);
            $stmt->bindValue(':suggestion', hash('sha256', $fingerprint, true), PDO::PARAM_LOB);
            $stmt->bindValue(':actor', $actorId, PDO::PARAM_INT);
            $stmt->execute();
            $id = (int)$this->pdo->lastInsertId();
            if ($stmt->rowCount() === 1) {
                $this->history($id, 'task_created', null, ['status' => 'open'], $actorId);
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function update(int $id, int $propertyId, array $changes, int $actorId): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM improvement_tasks WHERE id = :id AND property_id = :property FOR UPDATE');
            $stmt->execute(['id' => $id, 'property' => $propertyId]);
            $before = $stmt->fetch();
            if (!$before) {
                throw new RuntimeException('改善タスクが見つかりません。');
            }
            $status = (string)($changes['status'] ?? $before['status']);
            if (!in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('状態が不正です。');
            }
            $note = trim((string)($changes['note'] ?? $before['note'] ?? ''));
            $revision = trim((string)($changes['revision_date'] ?? $before['revision_date'] ?? ''));
            if ($revision !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $revision)) {
                throw new RuntimeException('記事修正日はYYYY-MM-DDで入力してください。');
            }
            $assigned = ($changes['assigned_user_id'] ?? $before['assigned_user_id']) ?: null;
            $update = $this->pdo->prepare(
                'UPDATE improvement_tasks SET status = :status, note = :note, assigned_user_id = :assigned,
                 revision_date = :revision, started_at = CASE WHEN :status = "in_progress" AND started_at IS NULL THEN UTC_TIMESTAMP() ELSE started_at END,
                 completed_at = CASE WHEN :status = "completed" THEN COALESCE(completed_at, UTC_TIMESTAMP()) ELSE NULL END,
                 updated_by_user_id = :actor WHERE id = :id AND property_id = :property'
            );
            $update->execute([
                'status' => $status, 'note' => $note !== '' ? $note : null, 'assigned' => $assigned,
                'revision' => $revision !== '' ? $revision : null, 'actor' => $actorId, 'id' => $id, 'property' => $propertyId,
            ]);
            $after = ['status' => $status, 'note' => $note, 'assigned_user_id' => $assigned, 'revision_date' => $revision];
            $event = $status !== $before['status'] ? 'status_changed' : 'task_updated';
            $this->history($id, $event, $before, $after, $actorId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function list(int $propertyId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['t.property_id = :property'];
        $params = ['property' => $propertyId];
        if (isset($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 't.status = :status';
            $params['status'] = $filters['status'];
        }
        $sql = 'SELECT t.*, a.username AS assigned_username FROM improvement_tasks t
                LEFT JOIN admins a ON a.id = t.assigned_user_id WHERE ' . implode(' AND ', $where) .
               ' ORDER BY t.updated_at DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function historyFor(int $taskId, int $propertyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.*, a.username FROM improvement_history h
             JOIN improvement_tasks t ON t.id = h.task_id
             LEFT JOIN admins a ON a.id = h.actor_user_id
             WHERE h.task_id = :task AND t.property_id = :property ORDER BY h.id DESC'
        );
        $stmt->execute(['task' => $taskId, 'property' => $propertyId]);
        return $stmt->fetchAll();
    }

    private function history(int $taskId, string $event, ?array $before, array $after, int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO improvement_history (task_id, event_type, before_json, after_json, actor_user_id)
             VALUES (:task, :event, :before, :after, :actor)'
        );
        $stmt->execute([
            'task' => $taskId, 'event' => $event,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'actor' => $actorId,
        ]);
    }
}
