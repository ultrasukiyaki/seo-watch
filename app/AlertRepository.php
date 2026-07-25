<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class AlertRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enabledRules(?int $window = null): array
    {
        $sql = 'SELECT * FROM alert_rules WHERE enabled = 1';
        $params = [];
        if ($window !== null) {
            $sql .= ' AND comparison_days = :comparison_days';
            $params['comparison_days'] = $window;
        }
        $sql .= ' ORDER BY sort_order, id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function allRules(): array
    {
        return $this->pdo->query('SELECT * FROM alert_rules ORDER BY sort_order, id')->fetchAll();
    }

    public function latestDate(int $propertyId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MAX(data_date) FROM search_performance WHERE property_id = :property_id');
        $stmt->execute(['property_id' => $propertyId]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function ranges(string $asOf, int $days): array
    {
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!in_array($days, [7, 28], true) || $end === false
            || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $end->format('Y-m-d') !== $asOf) {
            throw new RuntimeException('比較期間または基準日が不正です。');
        }
        return [
            'current_end' => $end->format('Y-m-d'),
            'current_start' => $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'previous_end' => $end->modify('-' . $days . ' days')->format('Y-m-d'),
            'previous_start' => $end->modify('-' . ($days * 2 - 1) . ' days')->format('Y-m-d'),
        ];
    }

    public function completeness(int $propertyId, array $range, int $days): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT data_date) AS available_days
             FROM search_performance
             WHERE property_id = :property_id AND data_date BETWEEN :previous_start AND :current_end'
        );
        $stmt->execute([
            'property_id' => $propertyId,
            'previous_start' => $range['previous_start'],
            'current_end' => $range['current_end'],
        ]);
        $available = (int)$stmt->fetchColumn();
        return ['complete' => $available === $days * 2, 'available_days' => $available, 'required_days' => $days * 2];
    }

    public function importRunning(int $propertyId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS(SELECT 1 FROM import_locks WHERE property_id = :property_id AND expires_at >= UTC_TIMESTAMP())'
        );
        $stmt->execute(['property_id' => $propertyId]);
        return (bool)$stmt->fetchColumn();
    }

    public function aggregate(int $propertyId, string $subjectType, array $range): array
    {
        $page = 'COALESCE(NULLIF(normalized_page_url, ""), page_url)';
        $querySelect = $subjectType === 'page_query' ? ', query_text' : ", '' AS query_text";
        $queryGroup = $subjectType === 'page_query' ? ', query_text' : '';
        $sql = "SELECT {$page} AS normalized_page_url{$querySelect},
            SUM(CASE WHEN data_date BETWEEN :previous_start1 AND :previous_end1 THEN clicks ELSE 0 END) previous_clicks,
            SUM(CASE WHEN data_date BETWEEN :current_start1 AND :current_end1 THEN clicks ELSE 0 END) current_clicks,
            SUM(CASE WHEN data_date BETWEEN :previous_start2 AND :previous_end2 THEN impressions ELSE 0 END) previous_impressions,
            SUM(CASE WHEN data_date BETWEEN :current_start2 AND :current_end2 THEN impressions ELSE 0 END) current_impressions,
            SUM(CASE WHEN data_date BETWEEN :previous_start3 AND :previous_end3 THEN position * impressions ELSE 0 END) previous_position_weight,
            SUM(CASE WHEN data_date BETWEEN :current_start3 AND :current_end3 THEN position * impressions ELSE 0 END) current_position_weight
            FROM search_performance
            WHERE property_id = :property_id AND data_date BETWEEN :previous_start4 AND :current_end4
            GROUP BY {$page}{$queryGroup}";
        $stmt = $this->pdo->prepare($sql);
        $params = ['property_id' => $propertyId];
        foreach ([1, 2, 3] as $i) {
            $params['previous_start' . $i] = $range['previous_start'];
            $params['previous_end' . $i] = $range['previous_end'];
            $params['current_start' . $i] = $range['current_start'];
            $params['current_end' . $i] = $range['current_end'];
        }
        $params['previous_start4'] = $range['previous_start'];
        $params['current_end4'] = $range['current_end'];
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function startRun(int $propertyId, string $trigger, ?int $actorId, ?string $asOf): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO alert_detection_runs (property_id, trigger_type, requested_by_user_id, as_of_date)
             VALUES (:property_id, :trigger_type, :actor_id, :as_of_date)'
        );
        $stmt->execute([
            'property_id' => $propertyId, 'trigger_type' => $trigger,
            'actor_id' => $actorId, 'as_of_date' => $asOf,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function finishRun(int $runId, string $status, array $counts, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE alert_detection_runs SET finished_at = UTC_TIMESTAMP(), status = :status,
             rules_evaluated = :rules_evaluated, subjects_evaluated = :subjects_evaluated,
             alerts_created = :alerts_created, alerts_updated = :alerts_updated,
             occurrences_created = :occurrences_created, suppressed_by_cooldown = :suppressed,
             skipped_insufficient_data = :skipped, errors_count = :errors_count,
             safe_error_summary = :safe_error WHERE id = :run_id'
        );
        $stmt->execute([
            'status' => $status, 'rules_evaluated' => $counts['rules_evaluated'] ?? 0,
            'subjects_evaluated' => $counts['subjects_evaluated'] ?? 0,
            'alerts_created' => $counts['alerts_created'] ?? 0,
            'alerts_updated' => $counts['alerts_updated'] ?? 0,
            'occurrences_created' => $counts['occurrences_created'] ?? 0,
            'suppressed' => $counts['suppressed_by_cooldown'] ?? 0,
            'skipped' => $counts['skipped_insufficient_data'] ?? 0,
            'errors_count' => $counts['errors_count'] ?? 0,
            'safe_error' => $error === null ? null : mb_substr($error, 0, 500),
            'run_id' => $runId,
        ]);
    }

    public function record(int $propertyId, array $rule, array $subject, array $match, array $range, string $asOf, int $runId): array
    {
        $query = trim((string)($subject['query_text'] ?? ''));
        $page = (string)$subject['normalized_page_url'];
        $hash = hash('sha256', (string)$rule['subject_type'] . "\0" . $page . "\0" . $query, true);
        $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare(
                'SELECT id, last_detected_at FROM alerts
                 WHERE property_id = :property_id AND rule_id = :rule_id AND subject_hash = :subject_hash FOR UPDATE'
            );
            $find->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
            $find->bindValue(':rule_id', (int)$rule['id'], PDO::PARAM_INT);
            $find->bindValue(':subject_hash', $hash, PDO::PARAM_LOB);
            $find->execute();
            $existing = $find->fetch();
            $created = false;
            if (!$existing) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO alerts (property_id, rule_id, subject_type, normalized_page_url, query_text,
                     subject_hash, severity, first_detected_at, last_detected_at)
                     VALUES (:property_id, :rule_id, :subject_type, :page_url, :query_text,
                     :subject_hash, :severity, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                $insert->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
                $insert->bindValue(':rule_id', (int)$rule['id'], PDO::PARAM_INT);
                $insert->bindValue(':subject_type', (string)$rule['subject_type']);
                $insert->bindValue(':page_url', $page);
                $insert->bindValue(':query_text', $query === '' ? null : $query);
                $insert->bindValue(':subject_hash', $hash, PDO::PARAM_LOB);
                $insert->bindValue(':severity', (string)$rule['severity']);
                $insert->execute();
                $alertId = (int)$this->pdo->lastInsertId();
                $created = true;
                $lastDetected = null;
            } else {
                $alertId = (int)$existing['id'];
                $lastDetected = (string)$existing['last_detected_at'];
            }
            $cooldown = (int)$rule['cooldown_days'];
            $emailEligible = $lastDetected === null
                || (new DateTimeImmutable($lastDetected))->modify('+' . $cooldown . ' days') <= new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $threshold = json_encode($this->ruleSnapshot($rule), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $occurrence = $this->pdo->prepare(
                'INSERT IGNORE INTO alert_occurrences
                 (alert_id,detection_run_id,detected_for_date,comparison_days,previous_start_date,previous_end_date,
                  current_start_date,current_end_date,previous_clicks,current_clicks,previous_impressions,current_impressions,
                  previous_ctr,current_ctr,previous_position,current_position,absolute_delta,relative_delta,
                  threshold_snapshot,explanation_snapshot,email_eligible)
                 VALUES (:alert_id,:run_id,:as_of,:comparison_days,:previous_start,:previous_end,:current_start,:current_end,
                  :previous_clicks,:current_clicks,:previous_impressions,:current_impressions,:previous_ctr,:current_ctr,
                  :previous_position,:current_position,:absolute_delta,:relative_delta,:threshold_snapshot,:explanation,:email_eligible)'
            );
            $occurrence->execute([
                'alert_id' => $alertId, 'run_id' => $runId, 'as_of' => $asOf,
                'comparison_days' => (int)$rule['comparison_days'],
                'previous_start' => $range['previous_start'], 'previous_end' => $range['previous_end'],
                'current_start' => $range['current_start'], 'current_end' => $range['current_end'],
                'previous_clicks' => $match['previous_clicks'], 'current_clicks' => $match['current_clicks'],
                'previous_impressions' => $match['previous_impressions'], 'current_impressions' => $match['current_impressions'],
                'previous_ctr' => $match['previous_ctr'], 'current_ctr' => $match['current_ctr'],
                'previous_position' => $match['previous_position'], 'current_position' => $match['current_position'],
                'absolute_delta' => $match['absolute_delta'], 'relative_delta' => $match['relative_delta'],
                'threshold_snapshot' => $threshold, 'explanation' => $match['explanation'],
                'email_eligible' => $emailEligible ? 1 : 0,
            ]);
            $occurrenceCreated = $occurrence->rowCount() === 1;
            if ($occurrenceCreated) {
                $occurrenceId = (int)$this->pdo->lastInsertId();
                $update = $this->pdo->prepare(
                    'UPDATE alerts SET last_detected_at = UTC_TIMESTAMP(), occurrence_count = occurrence_count + 1,
                     latest_occurrence_id = :occurrence_id, severity = :severity WHERE id = :alert_id'
                );
                $update->execute(['occurrence_id' => $occurrenceId, 'severity' => $rule['severity'], 'alert_id' => $alertId]);
            }
            $this->pdo->commit();
            return [
                'alert_id' => $alertId, 'created' => $created && $occurrenceCreated,
                'occurrence_created' => $occurrenceCreated,
                'cooldown_suppressed' => $occurrenceCreated && !$emailEligible,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listForUser(int $propertyId, int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['a.property_id = :property_id'];
        $params = ['property_id' => $propertyId, 'user_id' => $userId];
        if (empty($filters['include_hidden'])) {
            $where[] = 's.hidden_at IS NULL';
        }
        if (!empty($filters['unread'])) {
            $where[] = 's.read_at IS NULL';
        }
        if (isset($filters['severity']) && in_array($filters['severity'], ['info', 'warning', 'critical'], true)) {
            $where[] = 'a.severity = :severity';
            $params['severity'] = $filters['severity'];
        }
        $sql = 'SELECT a.*,r.rule_key,r.name AS rule_name,r.comparison_days,s.read_at,s.hidden_at,
                o.previous_clicks,o.current_clicks,o.previous_impressions,o.current_impressions,
                o.previous_ctr,o.current_ctr,o.previous_position,o.current_position,o.absolute_delta,o.relative_delta
                FROM alerts a JOIN alert_rules r ON r.id=a.rule_id
                LEFT JOIN alert_user_states s ON s.alert_id=a.id AND s.user_id=:user_id
                LEFT JOIN alert_occurrences o ON o.id=a.latest_occurrence_id
                WHERE ' . implode(' AND ', $where) .
                ' ORDER BY (s.read_at IS NULL) DESC, FIELD(a.severity,"critical","warning","info"), a.last_detected_at DESC
                 LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function detail(int $alertId, int $propertyId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*,r.rule_key,r.name AS rule_name,r.description,r.comparison_days,r.severity AS rule_severity,
             s.read_at,s.hidden_at,t.status AS task_status
             FROM alerts a JOIN alert_rules r ON r.id=a.rule_id
             LEFT JOIN alert_user_states s ON s.alert_id=a.id AND s.user_id=:user_id
             LEFT JOIN improvement_tasks t ON t.id=a.improvement_task_id
             WHERE a.id=:alert_id AND a.property_id=:property_id'
        );
        $stmt->execute(['user_id' => $userId, 'alert_id' => $alertId, 'property_id' => $propertyId]);
        $alert = $stmt->fetch();
        if (!$alert) {
            return null;
        }
        $history = $this->pdo->prepare(
            'SELECT o.*,d.status AS run_status,d.trigger_type FROM alert_occurrences o
             JOIN alert_detection_runs d ON d.id=o.detection_run_id WHERE o.alert_id=:alert_id ORDER BY o.id DESC'
        );
        $history->execute(['alert_id' => $alertId]);
        $alert['occurrences'] = $history->fetchAll();
        return $alert;
    }

    public function setUserState(int $alertId, int $userId, string $action, ?int $propertyId = null): void
    {
        if (!in_array($action, ['read', 'unread', 'hide', 'unhide'], true)) {
            throw new RuntimeException('通知操作が不正です。');
        }
        if ($propertyId !== null) {
            $check = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM alerts WHERE id=:alert_id AND property_id=:property_id)');
            $check->execute(['alert_id' => $alertId, 'property_id' => $propertyId]);
            if (!(bool)$check->fetchColumn()) {
                throw new RuntimeException('変動通知が見つかりません。');
            }
        }
        $read = $action === 'read' ? 'UTC_TIMESTAMP()' : ($action === 'unread' ? 'NULL' : 'read_at');
        $hidden = $action === 'hide' ? 'UTC_TIMESTAMP()' : ($action === 'unhide' ? 'NULL' : 'hidden_at');
        $stmt = $this->pdo->prepare(
            "INSERT INTO alert_user_states (alert_id,user_id,read_at,hidden_at)
             VALUES (:alert_id,:user_id," . ($action === 'read' ? 'UTC_TIMESTAMP()' : 'NULL') . ','
             . ($action === 'hide' ? 'UTC_TIMESTAMP()' : 'NULL') . ")
             ON DUPLICATE KEY UPDATE read_at={$read}, hidden_at={$hidden}"
        );
        $stmt->execute(['alert_id' => $alertId, 'user_id' => $userId]);
    }

    public function createImprovementTask(int $alertId, int $propertyId, int $actorId): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT a.*,r.rule_key,r.name AS rule_name,o.detected_for_date,o.previous_start_date,o.previous_end_date,
                 o.current_start_date,o.current_end_date,o.previous_clicks,o.current_clicks,o.previous_impressions,
                 o.current_impressions,o.previous_ctr,o.current_ctr,o.previous_position,o.current_position,o.absolute_delta
                 FROM alerts a JOIN alert_rules r ON r.id=a.rule_id
                 JOIN alert_occurrences o ON o.id=a.latest_occurrence_id
                 WHERE a.id=:alert_id AND a.property_id=:property_id FOR UPDATE'
            );
            $stmt->execute(['alert_id' => $alertId, 'property_id' => $propertyId]);
            $alert = $stmt->fetch();
            if (!$alert) {
                throw new RuntimeException('変動通知が見つかりません。');
            }
            if (!empty($alert['improvement_task_id'])) {
                throw new RuntimeException('この通知には改善タスクが作成済みです。');
            }
            $type = in_array($alert['rule_key'], ['ranking_drop', 'ranking_gain', 'entered_rank_threshold', 'left_rank_threshold'], true)
                ? 'ranking' : ($alert['rule_key'] === 'ctr_drop' || $alert['rule_key'] === 'low_ctr_opportunity' ? 'ctr' : 'content');
            $description = implode("\n", [
                '変動通知: ' . $alert['rule_name'],
                '比較期間: ' . $alert['previous_start_date'] . '〜' . $alert['previous_end_date'] . ' / ' . $alert['current_start_date'] . '〜' . $alert['current_end_date'],
                '前期間クリック/表示: ' . $alert['previous_clicks'] . ' / ' . $alert['previous_impressions'],
                '現期間クリック/表示: ' . $alert['current_clicks'] . ' / ' . $alert['current_impressions'],
                '変化量: ' . ($alert['absolute_delta'] ?? '—'),
                '初回検知: ' . $alert['first_detected_at'],
                '通知参照ID: ' . $alertId,
                '推奨確認: Search Consoleの検索語・ページ内容・表示スニペットを確認してください。因果関係は自動断定しません。',
            ]);
            $suggestion = hash('sha256', 'alert:' . $alertId, true);
            $insert = $this->pdo->prepare(
                'INSERT INTO improvement_tasks
                 (property_id,normalized_page_hash,normalized_page_url,task_type,title,description,source_query,
                  suggestion_hash,created_by_user_id,updated_by_user_id)
                 VALUES (:property_id,:page_hash,:page_url,:task_type,:title,:description,:source_query,
                  :suggestion_hash,:created_by,:updated_by)'
            );
            $insert->bindValue(':property_id', $propertyId, PDO::PARAM_INT);
            $insert->bindValue(':page_hash', hash('sha256', (string)$alert['normalized_page_url'], true), PDO::PARAM_LOB);
            $insert->bindValue(':page_url', (string)$alert['normalized_page_url']);
            $insert->bindValue(':task_type', $type);
            $insert->bindValue(':title', '変動通知を確認: ' . mb_substr((string)$alert['rule_name'], 0, 220));
            $insert->bindValue(':description', $description);
            $insert->bindValue(':source_query', $alert['query_text']);
            $insert->bindValue(':suggestion_hash', $suggestion, PDO::PARAM_LOB);
            $insert->bindValue(':created_by', $actorId, PDO::PARAM_INT);
            $insert->bindValue(':updated_by', $actorId, PDO::PARAM_INT);
            $insert->execute();
            $taskId = (int)$this->pdo->lastInsertId();
            $history = $this->pdo->prepare(
                'INSERT INTO improvement_history (task_id,event_type,after_json,actor_user_id,metadata_json)
                 VALUES (:task_id,"task_created",:after_json,:actor_id,:metadata_json)'
            );
            $history->execute([
                'task_id' => $taskId, 'after_json' => '{"status":"open"}', 'actor_id' => $actorId,
                'metadata_json' => json_encode(['alert_id' => $alertId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $link = $this->pdo->prepare(
                'UPDATE alerts SET improvement_task_id=:task_id WHERE id=:alert_id AND improvement_task_id IS NULL'
            );
            $link->execute(['task_id' => $taskId, 'alert_id' => $alertId]);
            if ($link->rowCount() !== 1) {
                throw new RuntimeException('改善タスクを通知へ関連付けできませんでした。');
            }
            $this->pdo->commit();
            return $taskId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function preference(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_notification_preferences WHERE user_id=:user_id');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch() ?: [
            'user_id' => $userId, 'in_app_enabled' => 1, 'email_enabled' => 0,
            'delivery_mode' => 'none', 'minimum_severity' => 'info',
            'enabled_rule_types' => null, 'digest_time' => '08:00:00',
        ];
    }

    public function savePreference(int $userId, array $input, bool $hasVerifiedEmail): void
    {
        $mode = (string)($input['delivery_mode'] ?? 'none');
        $severity = (string)($input['minimum_severity'] ?? 'info');
        $digestTime = (string)($input['digest_time'] ?? '08:00');
        $emailEnabled = !empty($input['email_enabled']);
        if (!in_array($mode, ['none', 'immediate', 'daily_digest'], true)
            || !in_array($severity, ['info', 'warning', 'critical'], true)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $digestTime)
            || ($emailEnabled && !$hasVerifiedEmail)) {
            throw new RuntimeException('通知設定を確認してください。');
        }
        $enabled = array_values(array_intersect(AlertRuleEvaluator::RULE_KEYS, (array)($input['enabled_rule_types'] ?? [])));
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_notification_preferences
             (user_id,in_app_enabled,email_enabled,delivery_mode,minimum_severity,enabled_rule_types,digest_time)
             VALUES (:user_id,:in_app,:email_enabled,:delivery_mode,:minimum_severity,:rule_types,:digest_time)
             ON DUPLICATE KEY UPDATE in_app_enabled=VALUES(in_app_enabled),email_enabled=VALUES(email_enabled),
             delivery_mode=VALUES(delivery_mode),minimum_severity=VALUES(minimum_severity),
             enabled_rule_types=VALUES(enabled_rule_types),digest_time=VALUES(digest_time)'
        );
        $stmt->execute([
            'user_id' => $userId, 'in_app' => !empty($input['in_app_enabled']) ? 1 : 0,
            'email_enabled' => $emailEnabled ? 1 : 0, 'delivery_mode' => $emailEnabled ? $mode : 'none',
            'minimum_severity' => $severity,
            'rule_types' => $enabled ? json_encode($enabled, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'digest_time' => $digestTime . ':00',
        ]);
    }

    public function saveRule(int $ruleId, array $input, int $actorId): void
    {
        $days = (int)($input['comparison_days'] ?? 0);
        $severity = (string)($input['severity'] ?? '');
        $nonNegative = [
            'minimum_impressions', 'minimum_clicks', 'absolute_change_threshold',
            'relative_change_threshold', 'ctr_point_threshold', 'position_change_threshold',
            'cooldown_days',
        ];
        if ($ruleId < 1 || !in_array($days, [7, 28], true)
            || !in_array($severity, ['info', 'warning', 'critical'], true)) {
            throw new RuntimeException('ルール設定を確認してください。');
        }
        foreach ($nonNegative as $key) {
            if (!is_numeric($input[$key] ?? null) || (float)$input[$key] < 0) {
                throw new RuntimeException('ルール設定は0以上の数値で入力してください。');
            }
        }
        foreach (['relative_change_threshold', 'ctr_point_threshold'] as $key) {
            if ((float)$input[$key] > 1) {
                throw new RuntimeException('割合は0〜1で入力してください。');
            }
        }
        $rank = trim((string)($input['rank_threshold'] ?? ''));
        $maximumCtr = trim((string)($input['maximum_ctr'] ?? ''));
        $minimumPosition = trim((string)($input['minimum_position'] ?? ''));
        if ($rank !== '' && (!is_numeric($rank) || (float)$rank <= 0)) {
            throw new RuntimeException('順位閾値は正数で入力してください。');
        }
        if ($minimumPosition !== '' && (!is_numeric($minimumPosition) || (float)$minimumPosition <= 0)) {
            throw new RuntimeException('最低順位は正数で入力してください。');
        }
        if ($maximumCtr !== '' && (!is_numeric($maximumCtr) || (float)$maximumCtr < 0 || (float)$maximumCtr > 1)) {
            throw new RuntimeException('最大CTRは0〜1で入力してください。');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE alert_rules SET name=:name,comparison_days=:comparison_days,enabled=:enabled,severity=:severity,
             minimum_impressions=:minimum_impressions,minimum_clicks=:minimum_clicks,
             absolute_change_threshold=:absolute_change_threshold,relative_change_threshold=:relative_change_threshold,
             ctr_point_threshold=:ctr_point_threshold,position_change_threshold=:position_change_threshold,
             rank_threshold=:rank_threshold,maximum_ctr=:maximum_ctr,minimum_position=:minimum_position,
             cooldown_days=:cooldown_days,updated_by_user_id=:actor_id WHERE id=:rule_id'
        );
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            throw new RuntimeException('表示名を正しく入力してください。');
        }
        $stmt->execute([
            'name' => $name, 'comparison_days' => $days, 'enabled' => !empty($input['enabled']) ? 1 : 0,
            'severity' => $severity, 'minimum_impressions' => (int)$input['minimum_impressions'],
            'minimum_clicks' => (int)$input['minimum_clicks'],
            'absolute_change_threshold' => (float)$input['absolute_change_threshold'],
            'relative_change_threshold' => (float)$input['relative_change_threshold'],
            'ctr_point_threshold' => (float)$input['ctr_point_threshold'],
            'position_change_threshold' => (float)$input['position_change_threshold'],
            'rank_threshold' => $rank === '' ? null : (float)$rank,
            'maximum_ctr' => $maximumCtr === '' ? null : (float)$maximumCtr,
            'minimum_position' => $minimumPosition === '' ? null : (float)$minimumPosition,
            'cooldown_days' => (int)$input['cooldown_days'], 'actor_id' => $actorId, 'rule_id' => $ruleId,
        ]);
    }

    public function recentRuns(int $propertyId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*,a.username FROM alert_detection_runs d
             LEFT JOIN admins a ON a.id=d.requested_by_user_id
             WHERE d.property_id=:property_id ORDER BY d.id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['property_id' => $propertyId]);
        return $stmt->fetchAll();
    }

    public function resetRule(int $ruleId, int $actorId): void
    {
        $defaults = [
            'ranking_drop' => [7, 1, 'warning', 100, 0, 0, 0, 0, 3, null, null, null, 7],
            'ranking_gain' => [7, 1, 'info', 100, 0, 0, 0, 0, 3, null, null, null, 7],
            'clicks_drop' => [7, 1, 'critical', 0, 10, 5, .3, 0, 0, null, null, null, 7],
            'clicks_gain' => [7, 1, 'info', 0, 0, 5, .3, 0, 0, null, null, null, 7],
            'impressions_drop' => [7, 1, 'warning', 100, 0, 100, .3, 0, 0, null, null, null, 7],
            'impressions_gain' => [7, 1, 'info', 100, 0, 100, .3, 0, 0, null, null, null, 7],
            'ctr_drop' => [7, 1, 'warning', 100, 0, 0, 0, .02, 0, null, null, null, 7],
            'low_ctr_opportunity' => [28, 0, 'info', 200, 0, 0, 0, 0, 0, 10, .02, 1, 14],
            'entered_rank_threshold' => [7, 1, 'info', 50, 0, 0, 0, 0, 0, 10, null, null, 7],
            'left_rank_threshold' => [7, 1, 'warning', 50, 0, 0, 0, 0, 0, 10, null, null, 7],
        ];
        $find = $this->pdo->prepare('SELECT rule_key FROM alert_rules WHERE id=:rule_id AND is_system=1');
        $find->execute(['rule_id' => $ruleId]);
        $key = $find->fetchColumn();
        if (!is_string($key) || !isset($defaults[$key])) {
            throw new RuntimeException('標準ルールが見つかりません。');
        }
        [$days,$enabled,$severity,$impressions,$clicks,$absolute,$relative,$ctr,$position,$rank,$maxCtr,$minPosition,$cooldown] = $defaults[$key];
        $stmt = $this->pdo->prepare(
            'UPDATE alert_rules SET comparison_days=:days,enabled=:enabled,severity=:severity,
             minimum_impressions=:impressions,minimum_clicks=:clicks,absolute_change_threshold=:absolute,
             relative_change_threshold=:relative,ctr_point_threshold=:ctr,position_change_threshold=:position,
             rank_threshold=:rank,maximum_ctr=:max_ctr,minimum_position=:min_position,cooldown_days=:cooldown,
             updated_by_user_id=:actor_id WHERE id=:rule_id'
        );
        $stmt->execute([
            'days' => $days, 'enabled' => $enabled, 'severity' => $severity,
            'impressions' => $impressions, 'clicks' => $clicks, 'absolute' => $absolute,
            'relative' => $relative, 'ctr' => $ctr, 'position' => $position, 'rank' => $rank,
            'max_ctr' => $maxCtr, 'min_position' => $minPosition,
            'cooldown' => $cooldown,
            'actor_id' => $actorId, 'rule_id' => $ruleId,
        ]);
    }

    private function ruleSnapshot(array $rule): array
    {
        return array_intersect_key($rule, array_flip([
            'rule_key', 'comparison_days', 'minimum_impressions', 'minimum_clicks',
            'absolute_change_threshold', 'relative_change_threshold', 'ctr_point_threshold',
            'position_change_threshold', 'rank_threshold', 'maximum_ctr', 'minimum_position', 'cooldown_days',
        ]));
    }
}
