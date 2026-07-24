<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use RuntimeException;

final class MailSettingsRepository
{
    public const TRANSPORTS = ['disabled', 'php_mail', 'smtp'];
    public const ENCRYPTIONS = ['starttls', 'tls', 'none'];

    public function __construct(private readonly PDO $pdo, private readonly Crypto $crypto)
    {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        $row = $this->pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch();
        return $row ?: [
            'id' => 1, 'transport' => 'disabled', 'from_name' => '', 'from_address' => '',
            'reply_to' => null, 'envelope_from' => null, 'smtp_host' => '', 'smtp_port' => 587,
            'smtp_encryption' => 'starttls', 'smtp_auth_enabled' => 1, 'smtp_username' => '',
            'smtp_password_ciphertext' => null, 'smtp_timeout' => 10,
            'last_connection_test_at' => null, 'last_connection_test_status' => null,
            'last_test_mail_at' => null, 'last_test_mail_status' => null,
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input, int $actorId): void
    {
        $current = $this->get();
        $transport = (string)($input['transport'] ?? 'disabled');
        $encryption = (string)($input['smtp_encryption'] ?? 'starttls');
        if (!in_array($transport, self::TRANSPORTS, true) || !in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new RuntimeException('メール配送方式または暗号化方式が不正です。');
        }
        $fromName = trim((string)($input['from_name'] ?? ''));
        $from = trim((string)($input['from_address'] ?? ''));
        $replyTo = trim((string)($input['reply_to'] ?? ''));
        $envelope = trim((string)($input['envelope_from'] ?? ''));
        $host = trim((string)($input['smtp_host'] ?? ''));
        $username = trim((string)($input['smtp_username'] ?? ''));
        foreach ([$fromName, $from, $replyTo, $envelope, $host, $username] as $headerValue) {
            if (preg_match('/[\r\n]/', $headerValue)) {
                throw new RuntimeException('改行を含むメール設定は保存できません。');
            }
        }
        if ($transport !== 'disabled' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('送信元メールアドレスが不正です。');
        }
        foreach ([$replyTo, $envelope] as $optionalEmail) {
            if ($optionalEmail !== '' && filter_var($optionalEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Reply-ToまたはEnvelope-Fromが不正です。');
            }
        }
        $port = (int)($input['smtp_port'] ?? 587);
        $timeout = (int)($input['smtp_timeout'] ?? 10);
        if ($transport === 'smtp' && ($host === '' || $port < 1 || $port > 65535 || $timeout < 1 || $timeout > 60)) {
            throw new RuntimeException('SMTPホスト、ポートまたはタイムアウトが不正です。');
        }
        $ciphertext = $current['smtp_password_ciphertext'] ?? null;
        if (!empty($input['smtp_password_delete'])) {
            $ciphertext = null;
        } elseif ((string)($input['smtp_password'] ?? '') !== '') {
            $ciphertext = $this->crypto->encrypt((string)$input['smtp_password']);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_settings
             (id, transport, from_name, from_address, reply_to, envelope_from, smtp_host, smtp_port,
              smtp_encryption, smtp_auth_enabled, smtp_username, smtp_password_ciphertext, smtp_timeout, updated_by_user_id)
             VALUES (1,:transport,:from_name,:from_address,:reply_to,:envelope_from,:smtp_host,:smtp_port,
              :smtp_encryption,:smtp_auth_enabled,:smtp_username,:password,:smtp_timeout,:actor)
             ON DUPLICATE KEY UPDATE transport=VALUES(transport), from_name=VALUES(from_name),
              from_address=VALUES(from_address), reply_to=VALUES(reply_to), envelope_from=VALUES(envelope_from),
              smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port), smtp_encryption=VALUES(smtp_encryption),
              smtp_auth_enabled=VALUES(smtp_auth_enabled), smtp_username=VALUES(smtp_username),
              smtp_password_ciphertext=VALUES(smtp_password_ciphertext), smtp_timeout=VALUES(smtp_timeout),
              updated_by_user_id=VALUES(updated_by_user_id), updated_at=UTC_TIMESTAMP()'
        );
        $stmt->execute([
            'transport' => $transport, 'from_name' => $fromName, 'from_address' => $from,
            'reply_to' => $replyTo ?: null, 'envelope_from' => $envelope ?: null, 'smtp_host' => $host,
            'smtp_port' => $port, 'smtp_encryption' => $encryption,
            'smtp_auth_enabled' => !empty($input['smtp_auth_enabled']) ? 1 : 0,
            'smtp_username' => $username, 'password' => $ciphertext, 'smtp_timeout' => $timeout, 'actor' => $actorId,
        ]);
    }

    public function password(array $settings): string
    {
        $value = $settings['smtp_password_ciphertext'] ?? null;
        return is_string($value) && $value !== '' ? $this->crypto->decrypt($value) : '';
    }

    public function recordTest(string $kind, MailResult $result): void
    {
        $prefix = $kind === 'connection' ? 'last_connection_test' : 'last_test_mail';
        $stmt = $this->pdo->prepare(
            "UPDATE mail_settings SET {$prefix}_at=UTC_TIMESTAMP(), {$prefix}_status=:status WHERE id=1"
        );
        $stmt->execute(['status' => $result->success ? 'success' : $result->category]);
    }
}
