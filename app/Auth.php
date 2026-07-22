<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class Auth
{
    /** @var array<string,mixed>|null */
    private ?array $currentUser = null;
    private bool $currentUserLoaded = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role
             FROM admins
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        $role = UserAccountPolicy::normalizeRole((string)($user['role'] ?? ''));
        $this->pdo->prepare('UPDATE admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => (int)$user['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = (string)$user['username'];
        $_SESSION['admin_role'] = $role;

        $this->currentUser = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => $role,
        ];
        $this->currentUserLoaded = true;
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

        $stmt = $this->pdo->prepare('SELECT id, username, role FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $this->clearAuthentication();
            return null;
        }

        $role = UserAccountPolicy::normalizeRole((string)($user['role'] ?? ''));
        $this->currentUser = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => $role,
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
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role']);
        $this->currentUser = null;
    }
}
