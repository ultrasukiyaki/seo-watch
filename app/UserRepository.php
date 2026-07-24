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
            "SELECT id, username, role, email, email_verified_at, last_login_at,
                    password_changed_at, session_version, account_status, invited_at, created_at, updated_at
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

    public function createInvitation(string $username, string $email): int
    {
        $username = trim($username);
        if (($error = UserAccountPolicy::validateUsername($username)) !== null) {
            throw new RuntimeException($error);
        }
        $email = EmailAddress::normalize($email);
        try {
            $duplicate = $this->pdo->prepare(
                'SELECT 1 FROM admins
                 WHERE LOWER(email) = :verified_email OR LOWER(pending_email) = :pending_email
                 LIMIT 1'
            );
            $duplicate->execute([
                'verified_email' => $email,
                'pending_email' => $email,
            ]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('ユーザー名またはメールアドレスはすでに使用されています。');
            }
            $stmt = $this->pdo->prepare(
                "INSERT INTO admins (username, password_hash, role, email, account_status, invited_at)
                 VALUES (:username, '', 'viewer', :email, 'invited', CURRENT_TIMESTAMP)"
            );
            $stmt->execute(['username' => $username, 'email' => $email]);
        } catch (PDOException $e) {
            throw new RuntimeException('ユーザー名またはメールアドレスはすでに使用されています。', 0, $e);
        }
        return (int)$this->pdo->lastInsertId();
    }

    public function find(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $user = $this->find($userId);
        return $user !== null && password_verify($password, (string)$user['password_hash']);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmation): int
    {
        $user = $this->find($userId);
        if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
            throw new RuntimeException('現在のパスワードが違います。');
        }
        if (!hash_equals($newPassword, $confirmation)) {
            throw new RuntimeException('確認用パスワードが一致しません。');
        }
        $error = PasswordPolicy::validate(
            $newPassword,
            (string)$user['username'],
            $user['email'] !== null ? (string)$user['email'] : null,
            (string)$user['password_hash']
        );
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE admins SET password_hash = :hash, password_changed_at = CURRENT_TIMESTAMP,
             session_version = session_version + 1 WHERE id = :id'
        );
        $stmt->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
        return (int)$user['session_version'] + 1;
    }

    public function setStatus(int $userId, string $status): bool
    {
        if (!in_array($status, [UserAccountPolicy::STATUS_ACTIVE, UserAccountPolicy::STATUS_DISABLED], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE admins SET account_status = :status, session_version = session_version + 1
             WHERE id = :id AND role = 'viewer'"
        );
        $stmt->execute(['status' => $status, 'id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function invalidateSessions(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE admins SET session_version = session_version + 1 WHERE id = :id AND role = 'viewer'"
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->rowCount() === 1;
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
