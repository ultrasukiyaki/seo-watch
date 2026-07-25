<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class AlertDeliveryService
{
    private const MAX_ITEMS = 20;
    private const SEVERITY = ['info' => 1, 'warning' => 2, 'critical' => 3];

    public function __construct(
        private readonly PDO $pdo,
        private readonly MailerInterface $mailer,
        private readonly string $baseUrl,
        private readonly string $timezone
    ) {
    }

    public function sendImmediate(int $runId): array
    {
        if (!$this->mailer->enabled() || $runId < 1) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }
        $users = $this->eligibleUsers('immediate');
        return $this->deliver($users, 'immediate', 'run:' . $runId, 'o.detection_run_id = :run_id', ['run_id' => $runId]);
    }

    public function sendDigests(?int $userId, string $localDate): array
    {
        if (!$this->mailer->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }
        $users = $this->eligibleUsers('daily_digest', $userId);
        $zone = new \DateTimeZone($this->timezone);
        $now = new \DateTimeImmutable('now', $zone);
        $users = array_values(array_filter($users, static function (array $user) use ($localDate, $now, $zone): bool {
            if ($localDate === $now->format('Y-m-d') && substr((string)$user['digest_time'], 0, 5) > $now->format('H:i')) {
                return false;
            }
            if (empty($user['last_digest_at'])) {
                return true;
            }
            $last = (new \DateTimeImmutable((string)$user['last_digest_at'], new \DateTimeZone('UTC')))->setTimezone($zone);
            return $last->format('Y-m-d') < $localDate;
        }));
        return $this->deliver(
            $users,
            'daily_digest',
            'digest:' . $localDate,
            'o.created_at <= UTC_TIMESTAMP()',
            []
        );
    }

    private function eligibleUsers(string $mode, ?int $userId = null): array
    {
        $sql = 'SELECT a.id,a.username,a.email,p.minimum_severity,p.enabled_rule_types,p.digest_time,p.last_digest_at
                FROM admins a JOIN user_notification_preferences p ON p.user_id=a.id
                WHERE a.account_status="active" AND a.email IS NOT NULL AND a.email_verified_at IS NOT NULL
                  AND p.email_enabled=1 AND p.delivery_mode=:delivery_mode';
        $params = ['delivery_mode' => $mode];
        if ($userId !== null) {
            $sql .= ' AND a.id=:user_id';
            $params['user_id'] = $userId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function deliver(array $users, string $mode, string $batchKey, string $scope, array $scopeParams): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($users as $user) {
            $sql = 'SELECT a.id AS alert_id,a.severity,a.normalized_page_url,a.query_text,
                    r.rule_key,r.name AS rule_name,o.id AS occurrence_id,o.detected_for_date,
                    o.previous_clicks,o.current_clicks,o.previous_impressions,o.current_impressions,
                    o.previous_ctr,o.current_ctr,o.previous_position,o.current_position,o.absolute_delta
                    FROM alert_occurrences o JOIN alerts a ON a.id=o.alert_id JOIN alert_rules r ON r.id=a.rule_id
                    LEFT JOIN alert_user_states s ON s.alert_id=a.id AND s.user_id=:state_user_id
                    LEFT JOIN alert_deliveries d ON d.alert_id=a.id AND d.occurrence_id=o.id
                     AND d.user_id=:delivery_user_id AND d.delivery_mode=:existing_mode AND d.status="sent"
                    WHERE ' . $scope . ' AND o.email_eligible=1 AND s.hidden_at IS NULL AND d.id IS NULL
                    ORDER BY FIELD(a.severity,"critical","warning","info"),o.id';
            $params = [
                'state_user_id' => (int)$user['id'],
                'delivery_user_id' => (int)$user['id'],
                'existing_mode' => $mode,
            ] + $scopeParams;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $ruleTypes = json_decode((string)($user['enabled_rule_types'] ?? ''), true);
            $minimum = self::SEVERITY[(string)$user['minimum_severity']] ?? 1;
            $rows = array_values(array_filter($stmt->fetchAll(), static function (array $row) use ($ruleTypes, $minimum): bool {
                return (self::SEVERITY[(string)$row['severity']] ?? 0) >= $minimum
                    && (!is_array($ruleTypes) || $ruleTypes === [] || in_array($row['rule_key'], $ruleTypes, true));
            }));
            if (!$rows) {
                $result['skipped']++;
                continue;
            }
            $effectiveBatch = $batchKey . ':user:' . $user['id'];
            foreach ($rows as $row) {
                $insert = $this->pdo->prepare(
                    'INSERT IGNORE INTO alert_deliveries
                     (alert_id,occurrence_id,user_id,delivery_mode,delivery_batch_key,status,attempted_at)
                     VALUES (:alert_id,:occurrence_id,:user_id,:delivery_mode,:batch_key,"pending",UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE attempted_at=IF(status="failed",UTC_TIMESTAMP(),attempted_at),
                     failed_at=IF(status="failed",NULL,failed_at),
                     safe_error_code=IF(status="failed",NULL,safe_error_code),
                     safe_error_message=IF(status="failed",NULL,safe_error_message),
                     status=IF(status="failed","pending",status)'
                );
                $insert->execute([
                    'alert_id' => $row['alert_id'], 'occurrence_id' => $row['occurrence_id'],
                    'user_id' => $user['id'], 'delivery_mode' => $mode, 'batch_key' => $effectiveBatch,
                ]);
            }
            $shown = array_slice($rows, 0, self::MAX_ITEMS);
            $body = $this->body($user, $shown, count($rows) - count($shown));
            $subject = sprintf('[10yendama SEO Watch] SEO変動通知 %d件', count($rows));
            $ok = false;
            try {
                $ok = $this->mailer->send((string)$user['email'], $subject, $body);
            } catch (\Throwable) {
                $ok = false;
            }
            $update = $this->pdo->prepare(
                'UPDATE alert_deliveries SET status=:status,
                 sent_at=CASE WHEN :sent_status="sent" THEN UTC_TIMESTAMP() ELSE NULL END,
                 failed_at=CASE WHEN :failed_status="failed" THEN UTC_TIMESTAMP() ELSE NULL END,
                 safe_error_code=:error_code,safe_error_message=:error_message
                 WHERE user_id=:user_id AND delivery_batch_key=:batch_key AND status="pending"'
            );
            $update->execute([
                'status' => $ok ? 'sent' : 'failed', 'sent_status' => $ok ? 'sent' : 'failed',
                'failed_status' => $ok ? 'sent' : 'failed', 'error_code' => $ok ? null : 'transport_failed',
                'error_message' => $ok ? null : 'メール配送に失敗しました。',
                'user_id' => $user['id'], 'batch_key' => $effectiveBatch,
            ]);
            $result[$ok ? 'sent' : 'failed']++;
            if ($ok && $mode === 'daily_digest') {
                $mark = $this->pdo->prepare(
                    'UPDATE user_notification_preferences SET last_digest_at=UTC_TIMESTAMP() WHERE user_id=:user_id'
                );
                $mark->execute(['user_id' => $user['id']]);
            }
        }
        return $result;
    }

    private function body(array $user, array $rows, int $omitted): string
    {
        $lines = [(string)$user['username'] . " 様", '', 'Search Consoleデータの変動を検知しました。'];
        foreach ($rows as $row) {
            $lines[] = '';
            $lines[] = sprintf('[%s] %s（基準日 %s）', $row['severity'], $row['rule_name'], $row['detected_for_date']);
            $lines[] = 'ページ: ' . $row['normalized_page_url'];
            if ((string)$row['query_text'] !== '') {
                $lines[] = '検索語: ' . $row['query_text'];
            }
            $lines[] = '詳細: ' . rtrim($this->baseUrl, '/') . '/index.php?r=alerts/detail&id=' . (int)$row['alert_id'];
        }
        if ($omitted > 0) {
            $lines[] = '';
            $lines[] = 'ほか ' . $omitted . ' 件は管理画面で確認してください。';
        }
        $lines[] = '';
        $lines[] = '配信設定: ' . rtrim($this->baseUrl, '/') . '/index.php?r=account';
        $lines[] = 'この通知はデータ変化を示すもので、原因や因果関係を断定するものではありません。';
        return implode("\n", $lines);
    }
}
