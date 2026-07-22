<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use PDOException;
use RuntimeException;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, username, role, last_login_at, created_at, updated_at
             FROM admins
             ORDER BY CASE WHEN role = 'superuser' THEN 0 ELSE 1 END, username ASC"
        );
        return $stmt->fetchAll();
    }

    public function createViewer(string $username, string $password): int
    {
        $username = trim($username);
        if (($error = UserAccountPolicy::validateUsername($username)) !== null) {
            throw new RuntimeException($error);
        }
        if (($error = UserAccountPolicy::validatePassword($password)) !== null) {
            throw new RuntimeException($error);
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO admins (username, password_hash, role)
                 VALUES (:username, :password_hash, :role)'
            );
            $stmt->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => UserAccountPolicy::ROLE_VIEWER,
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('そのユーザー名はすでに使用されています。');
            }
            throw new RuntimeException('閲覧ユーザーを作成できませんでした。データベース状態を確認してください。', 0, $e);
        }

        return (int)$this->pdo->lastInsertId();
    }

    public function deleteViewer(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "DELETE FROM admins WHERE id = :id AND role = 'viewer'"
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->rowCount() === 1;
    }
}
