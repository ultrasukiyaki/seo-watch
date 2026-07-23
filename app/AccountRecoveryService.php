<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;
use RuntimeException;

final class AccountRecoveryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserActionTokenRepository $tokens,
        private readonly MailerInterface $mailer,
        private readonly AuthenticationAuditLogger $audit,
        private readonly string $baseUrl
    ) {
    }

    public function requestPasswordReset(string $identifier, ?string $ip = null, ?string $userAgent = null): void
    {
        $started = microtime(true);
        $normalized = strtolower(trim($identifier));
        $stmt = $this->pdo->prepare(
            "SELECT id, email FROM admins
             WHERE (LOWER(username) = :identifier OR email = :identifier)
               AND account_status = 'active' LIMIT 1"
        );
        $stmt->execute(['identifier' => $normalized]);
        $user = $stmt->fetch();
        $subjectId = $user ? (int)$user['id'] : null;
        $outcome = 'accepted';
        if ($user && !empty($user['email']) && $this->mailer->enabled()) {
            $issued = $this->tokens->issue($subjectId, UserActionTokenRepository::PASSWORD_RESET);
            $url = $this->url('password-reset', $issued['token']);
            $sent = $this->mailer->send(
                (string)$user['email'],
                'パスワード再設定のご案内',
                "パスワード再設定を受け付けました。\n\n{$url}\n\nこのURLは30分間、一度だけ利用できます。心当たりがなければ無視してください。"
            );
            $outcome = $sent ? 'sent' : 'send_failed';
            $this->audit->log($sent ? 'mail_send_success' : 'mail_send_failure', $outcome, null, $subjectId, [], $ip, $userAgent);
        } else {
            // 存在しない識別子でもハッシュ計算を行い、極端な時間差を減らす。
            hash('sha256', random_bytes(32) . $normalized);
        }
        $this->audit->log('password_reset_requested', $outcome, null, $subjectId, [], $ip, $userAgent);
        $remaining = 0.35 - (microtime(true) - $started);
        if ($remaining > 0) {
            usleep((int)($remaining * 1_000_000));
        }
    }

    public function resetPassword(string $token, string $password, string $confirmation): bool
    {
        $current = $this->tokens->findValid($token, UserActionTokenRepository::PASSWORD_RESET);
        if (!$current || !hash_equals($password, $confirmation)) {
            return false;
        }
        $error = PasswordPolicy::validate(
            $password,
            (string)$current['username'],
            $current['email'] !== null ? (string)$current['email'] : null,
            (string)$current['password_hash']
        );
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $userId = (int)$current['user_id'];
        $consumed = $this->tokens->consume(
            $token,
            UserActionTokenRepository::PASSWORD_RESET,
            function (array $row, PDO $pdo) use ($password): void {
                $pdo->prepare(
                    "UPDATE admins SET password_hash = :hash, password_changed_at = CURRENT_TIMESTAMP,
                     session_version = session_version + 1, account_status = 'active'
                     WHERE id = :id"
                )->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int)$row['user_id']]);
                $pdo->prepare(
                    'UPDATE user_action_tokens SET used_at = CURRENT_TIMESTAMP
                     WHERE user_id = :id AND used_at IS NULL AND id <> :token_id'
                )->execute(['id' => (int)$row['user_id'], 'token_id' => (int)$row['id']]);
            }
        );
        $this->audit->log($consumed ? 'password_reset_completed' : 'password_reset_failed', $consumed ? 'success' : 'failure', null, $userId);
        return $consumed;
    }

    /** @return array{token:string,expires_at:string} */
    public function issueResetForUser(int $userId, int $actorId): array
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM admins WHERE id = :id AND role = 'viewer'");
        $stmt->execute(['id' => $userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('対象の閲覧ユーザーが見つかりません。');
        }
        $issued = $this->tokens->issue($userId, UserActionTokenRepository::PASSWORD_RESET, 1800, null, $actorId);
        $this->audit->log('admin_reset_link_created', 'success', $actorId, $userId);
        return $issued + ['url' => $this->url('password-reset', $issued['token'])];
    }

    public function sendResetForUser(int $userId, int $actorId): bool
    {
        $stmt = $this->pdo->prepare("SELECT email FROM admins WHERE id = :id AND role = 'viewer' AND account_status = 'active'");
        $stmt->execute(['id' => $userId]);
        $email = $stmt->fetchColumn();
        if (!$email || !$this->mailer->enabled()) {
            return false;
        }
        $issued = $this->tokens->issue($userId, UserActionTokenRepository::PASSWORD_RESET, 1800, null, $actorId);
        $sent = $this->mailer->send(
            (string)$email,
            'パスワード再設定のご案内',
            "管理者からパスワード再設定URLが発行されました。\n\n" . $this->url('password-reset', $issued['token']) .
            "\n\nこのURLは30分間、一度だけ利用できます。"
        );
        $this->audit->log('password_reset_requested', $sent ? 'sent' : 'send_failed', $actorId, $userId);
        return $sent;
    }

    public function sendInvitation(int $userId, int $actorId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT email FROM admins WHERE id = :id AND role = 'viewer' AND account_status = 'invited'"
        );
        $stmt->execute(['id' => $userId]);
        $email = $stmt->fetchColumn();
        if (!$email || !$this->mailer->enabled()) {
            return false;
        }
        $issued = $this->tokens->issue($userId, UserActionTokenRepository::INVITATION, 86400, null, $actorId);
        $sent = $this->mailer->send(
            (string)$email,
            'SEO Watchへの招待',
            "閲覧ユーザーとして招待されました。\n\n" . $this->url('invitation', $issued['token']) .
            "\n\nこのURLは24時間、一度だけ利用できます。"
        );
        $this->audit->log('invitation_resent', $sent ? 'sent' : 'send_failed', $actorId, $userId);
        return $sent;
    }

    public function acceptInvitation(string $token, string $password, string $confirmation): bool
    {
        $current = $this->tokens->findValid($token, UserActionTokenRepository::INVITATION);
        if (!$current || !hash_equals($password, $confirmation)
            || (string)$current['account_status'] !== UserAccountPolicy::STATUS_INVITED) {
            return false;
        }
        $error = PasswordPolicy::validate($password, (string)$current['username'], (string)$current['email']);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $userId = (int)$current['user_id'];
        $result = $this->tokens->consume($token, UserActionTokenRepository::INVITATION, function (array $row, PDO $pdo) use ($password): void {
            $pdo->prepare(
                "UPDATE admins SET password_hash = :hash, account_status = 'active',
                 email_verified_at = CURRENT_TIMESTAMP, password_changed_at = CURRENT_TIMESTAMP,
                 session_version = session_version + 1 WHERE id = :id AND account_status = 'invited'"
            )->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int)$row['user_id']]);
        });
        $this->audit->log('invitation_accepted', $result ? 'success' : 'failure', $userId, $userId);
        return $result;
    }

    public function requestEmailChange(int $userId, string $currentPassword, string $newEmail): void
    {
        if (!$this->mailer->enabled()) {
            throw new RuntimeException('メール送信が無効のため、メールアドレス変更を開始できません。');
        }
        $newEmail = EmailAddress::normalize($newEmail);
        $duplicate = $this->pdo->prepare(
            'SELECT 1 FROM admins WHERE id <> :id AND (email = :email OR pending_email = :email) LIMIT 1'
        );
        $duplicate->execute(['id' => $userId, 'email' => $newEmail]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('そのメールアドレスはすでに使用されています。');
        }
        $stmt = $this->pdo->prepare('SELECT password_hash FROM admins WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($currentPassword, (string)$hash)) {
            throw new RuntimeException('現在のパスワードが違います。');
        }
        try {
            $this->pdo->prepare('UPDATE admins SET pending_email = :email WHERE id = :id')
                ->execute(['email' => $newEmail, 'id' => $userId]);
        } catch (\PDOException $e) {
            throw new RuntimeException('そのメールアドレスはすでに使用されています。', 0, $e);
        }
        $issued = $this->tokens->issue($userId, UserActionTokenRepository::EMAIL_VERIFICATION, 1800, $newEmail, $userId);
        $sent = $this->mailer->send(
            $newEmail,
            'メールアドレス確認のご案内',
            "次のURLでメールアドレスを確認してください。\n\n" . $this->url('email-verify', $issued['token']) .
            "\n\nこのURLは30分間、一度だけ利用できます。"
        );
        if (!$sent) {
            throw new RuntimeException('確認メールを送信できませんでした。設定を確認して再度お試しください。');
        }
        $this->audit->log('email_change_requested', 'success', $userId, $userId);
    }

    public function verifyEmail(string $token): bool
    {
        $row = $this->tokens->findValid($token, UserActionTokenRepository::EMAIL_VERIFICATION);
        if (!$row || empty($row['pending_value'])) {
            return false;
        }
        $userId = (int)$row['user_id'];
        $oldEmail = $row['email'] !== null ? (string)$row['email'] : null;
        $result = $this->tokens->consume($token, UserActionTokenRepository::EMAIL_VERIFICATION, function (array $tokenRow, PDO $pdo): void {
            $pdo->prepare(
                'UPDATE admins SET email = :email, pending_email = NULL, email_verified_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND pending_email = :pending'
            )->execute([
                'email' => (string)$tokenRow['pending_value'],
                'id' => (int)$tokenRow['user_id'],
                'pending' => (string)$tokenRow['pending_value'],
            ]);
        });
        if ($result && $oldEmail && $this->mailer->enabled()) {
            try {
                $sent = $this->mailer->send(
                    $oldEmail,
                    'メールアドレス変更のお知らせ',
                    "SEO Watchの登録メールアドレスが変更されました。\n心当たりがない場合は管理者へ連絡し、パスワードとセッションを確認してください。\n"
                );
                $this->audit->log($sent ? 'mail_send_success' : 'mail_send_failure', $sent ? 'sent' : 'send_failed', $userId, $userId);
            } catch (\Throwable) {
                $this->audit->log('mail_send_failure', 'send_failed', $userId, $userId);
            }
        }
        $this->audit->log('email_verified', $result ? 'success' : 'failure', $userId, $userId);
        return $result;
    }

    private function url(string $route, string $token): string
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException('アカウント回復にはHTTPSのapp.base_url設定が必要です。');
        }
        return rtrim($this->baseUrl, '/') . '/index.php?r=' . rawurlencode($route) . '&token=' . rawurlencode($token);
    }
}
