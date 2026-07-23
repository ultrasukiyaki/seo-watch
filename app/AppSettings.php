<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class AppSettings
{
    public const DISPLAY_TIMEZONE = 'display_timezone';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_by_user_id)
             VALUES (:key, :value, :user_id)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
             updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['key' => $key, 'value' => $value, 'user_id' => $userId]);
    }
}
