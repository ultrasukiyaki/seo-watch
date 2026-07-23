<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class Auth
{
    /** @var array<string,mixed>|null */
    private ?array $currentUser = null;
    private bool $currentUserLoaded = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?AuthenticationAuditLogger $audit = null
    )
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, session_version, account_status
             FROM admins
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        if (!$user || (string)$user['account_status'] !== UserAccountPolicy::STATUS_ACTIVE
            || !password_verify($password, (string)$user['password_hash'])) {
            $this->audit?->log('login_failure', 'failure', null, $user ? (int)$user['id'] : null, [], self::clientIp(), self::userAgent());
            return false;
        }

        $role = UserAccountPolicy::normalizeRole((string)($user['role'] ?? ''));
        $this->pdo->prepare('UPDATE admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => (int)$user['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = (string)$user['username'];
        $_SESSION['admin_role'] = $role;
        $_SESSION['session_version'] = (int)$user['session_version'];

        $this->currentUser = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => $role,
            'session_version' => (int)$user['session_version'],
            'account_status' => (string)$user['account_status'],
        ];
        $this->currentUserLoaded = true;
        $this->audit?->log('login_success', 'success', (int)$user['id'], (int)$user['id'], [], self::clientIp(), self::userAgent());
        return true;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return array{id:int,username:string,role:string}|null */
    public function user(): ?array
    {
        if ($this->currentUserLoaded) {
            return $this->currentUser;
        }
        $this->currentUserLoaded = true;

        $userId = (int)($_SESSION['admin_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, email, email_verified_at, last_login_at, password_changed_at,
                    session_version, account_status
             FROM admins WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if ($user && !array_key_exists('session_version', $_SESSION)) {
            // v0.6.0以前から継続中のセッションは、移行後の初回アクセスで現在値へ結び付ける。
            $_SESSION['session_version'] = (int)$user['session_version'];
        }
        if (!$user || (string)$user['account_status'] !== UserAccountPolicy::STATUS_ACTIVE
            || (int)($_SESSION['session_version'] ?? -1) !== (int)$user['session_version']) {
            if ($user) {
                $_SESSION['_flash'][] = ['type' => 'warning', 'message' => 'セキュリティ設定が変更されたため、再度ログインしてください。'];
            }
            $this->clearAuthentication();
            return null;
        }

        $role = UserAccountPolicy::normalizeRole((string)($user['role'] ?? ''));
        $this->currentUser = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => $role,
            'email' => $user['email'],
            'email_verified_at' => $user['email_verified_at'],
            'last_login_at' => $user['last_login_at'],
            'password_changed_at' => $user['password_changed_at'],
            'session_version' => (int)$user['session_version'],
            'account_status' => (string)$user['account_status'],
        ];
        $_SESSION['admin_username'] = $this->currentUser['username'];
        $_SESSION['admin_role'] = $role;
        return $this->currentUser;
    }

    public function isSuperuser(): bool
    {
        $user = $this->user();
        return $user !== null && UserAccountPolicy::isSuperuser($user['role']);
    }

    public function requireLogin(): void
    {
        if (!$this->check()) {
            header('Location: index.php?r=login');
            exit;
        }
    }

    public function requireSuperuser(): void
    {
        $this->requireLogin();
        if (!$this->isSuperuser()) {
            throw new ForbiddenException('この画面はスーパーユーザーだけが利用できます。');
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
        $this->currentUser = null;
        $this->currentUserLoaded = true;
    }

    private function clearAuthentication(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role'], $_SESSION['session_version']);
        $this->currentUser = null;
    }

    public function refreshCurrentSession(int $sessionVersion): void
    {
        session_regenerate_id(true);
        $_SESSION['session_version'] = $sessionVersion;
        $this->currentUserLoaded = false;
        $this->currentUser = null;
    }

    private static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function userAgent(): string
    {
        return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}
