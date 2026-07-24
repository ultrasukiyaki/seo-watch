<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\ForbiddenException;
use Tenyendama\SeoWatch\Paginator;
use Tenyendama\SeoWatch\RoutePolicy;
use Tenyendama\SeoWatch\RuntimeEnvironment;
use Tenyendama\SeoWatch\View;

try {
    $services = require __DIR__ . '/app/bootstrap.php';
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'NOT_INSTALLED') {
        header('Location: install.php');
        exit;
    }
    throw $e;
}

extract($services, EXTR_SKIP);

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function pullFlashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

function redirectTo(string $route): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: index.php?r=' . rawurlencode($route));
    exit;
}

function postOnly(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POSTメソッドのみ受け付けます。');
    }
    Csrf::verify($_POST['_csrf'] ?? null);
}

function isFragmentRequest(): bool
{
    return (string)($_GET['_fragment'] ?? '') === '1';
}

function renderFragment(string $template, array $data): never
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-SEO-Watch-Fragment: 1');
    echo View::partial($template, $data);
    exit;
}

/** @param list<array<string,mixed>> $items @param array<string,string> $titles */
function attachPageTitles(array $items, array $titles, string $urlKey = 'page_url'): array
{
    foreach ($items as &$item) {
        $url = (string)($item[$urlKey] ?? '');
        $item['page_title'] = $titles[$url] ?? null;
    }
    unset($item);
    return $items;
}

$route = (string)($_GET['r'] ?? 'dashboard');

try {
    if ($route === 'password-forgot') {
        $message = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify($_POST['_csrf'] ?? null);
            $identifier = trim((string)($_POST['identifier'] ?? ''));
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ipAllowed = $rateLimiter->consume('password_reset_request', 'ip', $ip, 5, 900);
            $accountAllowed = $rateLimiter->consume('password_reset_request', 'account', $identifier, 3, 3600);
            if ($ipAllowed && $accountAllowed) {
                $accountRecovery->requestPasswordReset($identifier, $ip, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            } else {
                $audit->log('rate_limit_triggered', 'limited', null, null, ['action' => 'password_reset_request'], $ip);
                usleep(350000);
            }
            $message = '登録情報が確認できた場合、パスワード再設定方法を送信しました。';
        }
        View::render('password-forgot', [
            'title' => 'パスワード再設定',
            'message' => $message,
            'auth' => $auth,
            'route' => $route,
            'appName' => $config->get('app.name'),
        ]);
        exit;
    }

    if ($route === 'password-reset') {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        $token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify($_POST['_csrf'] ?? null);
            try {
                if ($accountRecovery->resetPassword(
                    $token,
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['password_confirmation'] ?? '')
                )) {
                    flash('success', 'パスワードを変更しました。新しいパスワードでログインしてください。');
                    redirectTo('login');
                }
                $error = '再設定できませんでした。URLを再確認するか、再設定をやり直してください。';
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        $valid = $actionTokens->findValid($token, \Tenyendama\SeoWatch\UserActionTokenRepository::PASSWORD_RESET) !== null;
        if (!$valid && $token !== '') {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $rateLimiter->consume('token_failure', 'ip', $ip, 10, 900);
            $audit->log('password_reset_failed', 'invalid_token', null, null, [], $ip);
        }
        View::render('password-reset', [
            'title' => 'パスワード再設定',
            'token' => $token,
            'valid' => $valid,
            'error' => $error,
            'auth' => $auth,
            'route' => $route,
            'appName' => $config->get('app.name'),
        ]);
        exit;
    }

    if ($route === 'email-verify') {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        if ($accountRecovery->verifyEmail((string)($_GET['token'] ?? ''))) {
            flash('success', 'メールアドレスを確認しました。');
        } else {
            flash('danger', '確認URLを利用できません。期限切れまたは使用済みの可能性があります。');
        }
        redirectTo($auth->check() ? 'account' : 'login');
    }

    if ($route === 'invitation') {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        $token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify($_POST['_csrf'] ?? null);
            try {
                if ($accountRecovery->acceptInvitation(
                    $token,
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['password_confirmation'] ?? '')
                )) {
                    flash('success', '招待を受け入れました。設定したパスワードでログインしてください。');
                    redirectTo('login');
                }
                $error = '招待を受け入れられませんでした。';
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        $valid = $actionTokens->findValid(
            $token,
            \Tenyendama\SeoWatch\UserActionTokenRepository::INVITATION
        ) !== null;
        View::render('invitation', [
            'title' => '招待',
            'token' => $token,
            'valid' => $valid,
            'error' => $error,
            'auth' => $auth,
            'route' => $route,
            'appName' => $config->get('app.name'),
        ]);
        exit;
    }

    if ($route === 'login') {
        if ($auth->check()) {
            redirectTo('dashboard');
        }
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify($_POST['_csrf'] ?? null);
            if ($auth->attempt(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
                redirectTo('dashboard');
            }
            $error = 'ユーザー名またはパスワードが違います。';
        }
        View::render('login', [
            'title' => 'ログイン',
            'error' => $error,
            'auth' => $auth,
            'route' => $route,
            'flashes' => pullFlashes(),
            'appName' => $config->get('app.name'),
        ]);
        exit;
    }

    $auth->requireLogin();

    if ($route === 'logout') {
        postOnly();
        $auth->logout();
        header('Location: index.php?r=login');
        exit;
    }

    if (RoutePolicy::requiresSuperuser($route)) {
        $auth->requireSuperuser();
    }

    if ($route === 'oauth/start') {
        $state = $oauthState->issue();
        $_SESSION['oauth_state_hash'] = hash('sha256', $state);
        $_SESSION['oauth_state_issued_at'] = time();
        session_write_close();
        header('Location: ' . $oauth->authorizationUrl($state));
        exit;
    }

    if ($route === 'oauth/callback') {
        if (isset($_GET['error'])) {
            throw new RuntimeException('Google連携が拒否されました: ' . (string)$_GET['error']);
        }
        $state = (string)($_GET['state'] ?? '');
        unset($_SESSION['oauth_state'], $_SESSION['oauth_state_hash'], $_SESSION['oauth_state_issued_at']);
        if (!$oauthState->verify($state)) {
            throw new RuntimeException('OAuth stateを検証できませんでした。設定画面を再読み込みして、Google連携を最初からやり直してください。');
        }
        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            throw new RuntimeException('Googleから認可コードが返されませんでした。');
        }
        $oauth->exchangeCode($code);
        $count = $propertyRepo->sync($api->listSites());
        flash('success', "Google Search Consoleと連携しました。{$count}件のプロパティを取得しました。");
        redirectTo('settings');
    }

    if ($route === 'oauth/disconnect') {
        postOnly();
        $tokenStore->delete();
        flash('success', 'Google連携を解除しました。保存済み分析データは残しています。');
        redirectTo('settings');
    }

    if ($route === 'properties/refresh') {
        postOnly();
        $count = $propertyRepo->sync($api->listSites());
        flash('success', "Search Consoleから{$count}件のプロパティを更新しました。");
        redirectTo('settings');
    }

    if ($route === 'properties/activate') {
        postOnly();
        $propertyRepo->activate((int)($_POST['property_id'] ?? 0));
        flash('success', '分析対象プロパティを切り替えました。');
        redirectTo('settings');
    }

    if ($route === 'settings/timezone') {
        postOnly();
        $timezone = trim((string)($_POST['display_timezone'] ?? ''));
        if (!\Tenyendama\SeoWatch\TimezoneService::isValid($timezone)) {
            flash('danger', '有効なIANAタイムゾーンを選択してください。');
            redirectTo('settings');
        }
        $actor = $auth->user();
        $previous = $dateTime->timezoneName();
        $settings->set(\Tenyendama\SeoWatch\AppSettings::DISPLAY_TIMEZONE, $timezone, (int)$actor['id']);
        $audit->log('display_timezone_changed', 'success', (int)$actor['id'], null, [
            'previous_timezone' => $previous,
            'new_timezone' => $timezone,
        ]);
        flash('success', '表示タイムゾーンを ' . $timezone . ' へ変更しました。');
        redirectTo('settings');
    }

    if ($route === 'mail/settings') {
        postOnly();
        $actor = $auth->user();
        try {
            $before = (string)$mailSettingsData['transport'];
            $mailSettings->save($_POST, (int)$actor['id']);
            $after = (string)($_POST['transport'] ?? 'disabled');
            $audit->log('mail_settings_updated', 'success', (int)$actor['id'], null, [
                'previous_transport' => $before, 'transport' => $after,
            ]);
            flash('success', 'メール設定を保存しました。');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
        redirectTo('settings');
    }

    if ($route === 'mail/connection-test') {
        postOnly();
        $actor = $auth->user();
        if (!$rateLimiter->consume('smtp_connection_test', 'account', (string)$actor['id'], 5, 3600)) {
            flash('danger', 'しばらく時間をおいてから再度お試しください。');
            redirectTo('settings');
        }
        $current = $mailSettings->get();
        if (($current['transport'] ?? '') !== 'smtp') {
            flash('danger', 'SMTP設定を保存してから接続テストを実行してください。');
            redirectTo('settings');
        }
        $transport = $mailTransportFactory->create($current);
        $result = $transport instanceof \Tenyendama\SeoWatch\SmtpMailTransport
            ? $transport->testConnection()
            : \Tenyendama\SeoWatch\MailResult::failed('configuration', 'SMTP設定がありません。');
        $mailSettings->recordTest('connection', $result);
        $audit->log($result->success ? 'smtp_connection_test_succeeded' : 'smtp_connection_test_failed',
            $result->success ? 'success' : 'failure', (int)$actor['id'], null, ['category' => $result->category]);
        flash($result->success ? 'success' : 'danger', $result->message);
        redirectTo('settings');
    }

    if ($route === 'import/run') {
        postOnly();
        set_time_limit(0);
        $active = $propertyRepo->active();
        if (!$active) {
            throw new RuntimeException('先に分析対象プロパティを選んでください。');
        }
        $days = max(1, min(90, (int)($_POST['days'] ?? 7)));
        $lag = max(1, min(7, (int)$config->get('app.import_lag_days', 3)));
        $range = $searchConsoleDate->importRange($days, $lag);
        $actor = $auth->user();
        $rows = $importer->import($active, $range['start'], $range['end'], 'web', 'web', (int)$actor['id']);
        flash('success', number_format($rows) . "行を取り込みました（{$range['start']}〜{$range['end']}、Search Console基準日 PT）。URL正規化も反映済みです。");
        redirectTo('dashboard');
    }

    if ($route === 'maintenance/normalize') {
        postOnly();
        set_time_limit(0);
        $active = $propertyRepo->active();
        if (!$active) {
            throw new RuntimeException('先に分析対象プロパティを選んでください。');
        }
        $count = $dataMaintenance->normalizeExisting((int)$active['id']);
        flash('success', number_format($count) . '行の既存URLを正規化しました。アンカー・末尾スラッシュ・計測用パラメータを統合します。');
        redirectTo('dashboard');
    }

    if ($route === 'users/create') {
        postOnly();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
        try {
            if (!hash_equals($password, $passwordConfirmation)) {
                throw new RuntimeException('確認用パスワードが一致しません。');
            }
            $userRepo->createViewer($username, $password);
            flash('success', "閲覧ユーザー「{$username}」を作成しました。");
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
        redirectTo('users');
    }

    if ($route === 'users/invite') {
        postOnly();
        if (!$mailer->enabled()) {
            flash('danger', 'メール送信が無効のため、メール招待は利用できません。');
            redirectTo('users');
        }
        try {
            $userId = $userRepo->createInvitation(
                trim((string)($_POST['username'] ?? '')),
                (string)($_POST['email'] ?? '')
            );
            if (!$accountRecovery->sendInvitation($userId, (int)$auth->user()['id'])) {
                throw new RuntimeException('招待メールを送信できませんでした。');
            }
            $audit->log('invitation_created', 'success', (int)$auth->user()['id'], $userId);
            flash('success', '閲覧ユーザーへ招待メールを送信しました。');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
        redirectTo('users');
    }

    if ($route === 'users/invite-resend') {
        postOnly();
        $actor = $auth->user();
        $targetId = (int)($_POST['user_id'] ?? 0);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$userRepo->verifyPassword((int)$actor['id'], (string)($_POST['current_password'] ?? ''))) {
            flash('danger', 'スーパーユーザーの現在のパスワードが違います。');
        } elseif (!$rateLimiter->consume('invitation_resend', 'ip', $ip, 10, 900)
            || !$rateLimiter->consume('invitation_resend', 'account', (string)$targetId, 3, 3600)) {
            $audit->log('rate_limit_triggered', 'limited', (int)$actor['id'], $targetId, ['action' => 'invitation_resend'], $ip);
            flash('danger', 'しばらく時間をおいてから再度お試しください。');
        } else {
            $sent = $accountRecovery->sendInvitation($targetId, (int)$actor['id']);
            flash($sent ? 'success' : 'danger', $sent ? '招待メールを再送しました。' : '招待メールを送信できませんでした。');
        }
        redirectTo('users');
    }

    if ($route === 'users/delete') {
        postOnly();
        $userId = (int)($_POST['user_id'] ?? 0);
        if (!$userRepo->deleteViewer($userId)) {
            flash('danger', '削除対象の閲覧ユーザーが見つかりません。スーパーユーザーは削除できません。');
        } else {
            flash('success', '閲覧ユーザーを削除しました。対象ユーザーのログイン状態も次のアクセスで無効になります。');
        }
        redirectTo('users');
    }

    if ($route === 'account/password') {
        postOnly();
        $user = $auth->user();
        try {
            $version = $userRepo->changePassword(
                (int)$user['id'],
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['password_confirmation'] ?? '')
            );
            $actionTokens->invalidateForUser((int)$user['id']);
            $auth->refreshCurrentSession($version);
            $audit->log('password_changed', 'success', (int)$user['id'], (int)$user['id']);
            flash('success', 'パスワードを変更しました。他の端末のセッションは無効になりました。');
        } catch (RuntimeException $e) {
            $audit->log('password_changed', 'failure', (int)$user['id'], (int)$user['id']);
            flash('danger', $e->getMessage());
        }
        redirectTo('account');
    }

    if ($route === 'account/email') {
        postOnly();
        $user = $auth->user();
        try {
            $accountRecovery->requestEmailChange(
                (int)$user['id'],
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['email'] ?? '')
            );
            flash('success', $mailer->enabled()
                ? '新しいメールアドレスへ確認メールを送信しました。'
                : 'メールアドレスを確認待ちとして保存しました。配送設定後に確認メールを送信してください。');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
        redirectTo('account');
    }

    if ($route === 'account/email-send' || $route === 'account/email-cancel') {
        postOnly();
        $user = $auth->user();
        if ($route === 'account/email-cancel') {
            $accountRecovery->cancelPendingEmail((int)$user['id']);
            flash('success', '確認待ちメールアドレスを取り消しました。');
        } elseif (!$rateLimiter->consume('email_verification', 'account', (string)$user['id'], 3, 3600)) {
            flash('danger', 'しばらく時間をおいてから再度お試しください。');
        } else {
            $sent = $accountRecovery->sendEmailVerification((int)$user['id']);
            flash($sent ? 'success' : 'danger', $sent ? '確認メールを送信しました。' : '確認メールを送信できませんでした。');
        }
        redirectTo('account');
    }

    if ($route === 'users/status' || $route === 'users/sessions'
        || $route === 'users/reset-link' || $route === 'users/reset-mail') {
        postOnly();
        $actor = $auth->user();
        $targetId = (int)($_POST['user_id'] ?? 0);
        if (!$userRepo->verifyPassword((int)$actor['id'], (string)($_POST['current_password'] ?? ''))) {
            flash('danger', 'スーパーユーザーの現在のパスワードが違います。');
            redirectTo('users');
        }
        if (in_array($route, ['users/reset-link', 'users/reset-mail'], true)) {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $limitedAction = str_replace('/', '_', $route);
            if (!$rateLimiter->consume($limitedAction, 'ip', $ip, 10, 900)
                || !$rateLimiter->consume($limitedAction, 'account', (string)$actor['id'], 5, 3600)) {
                $audit->log('rate_limit_triggered', 'limited', (int)$actor['id'], $targetId, ['action' => $limitedAction], $ip);
                flash('danger', 'しばらく時間をおいてから再度お試しください。');
                redirectTo('users');
            }
        }
        if ($route === 'users/status') {
            $status = (string)($_POST['status'] ?? '');
            $ok = $userRepo->setStatus($targetId, $status);
            $audit->log($status === 'disabled' ? 'account_disabled' : 'account_enabled', $ok ? 'success' : 'failure', (int)$actor['id'], $targetId);
            flash($ok ? 'success' : 'danger', $ok ? 'アカウント状態を変更しました。' : '対象の閲覧ユーザーが見つかりません。');
        } elseif ($route === 'users/sessions') {
            $ok = $userRepo->invalidateSessions($targetId);
            $audit->log('sessions_invalidated', $ok ? 'success' : 'failure', (int)$actor['id'], $targetId);
            flash($ok ? 'success' : 'danger', $ok ? '全セッションを無効化しました。' : '対象の閲覧ユーザーが見つかりません。');
        } elseif ($route === 'users/reset-mail') {
            $ok = $accountRecovery->sendResetForUser($targetId, (int)$actor['id']);
            flash($ok ? 'success' : 'danger', $ok ? '再設定メールを送信しました。' : 'メールを送信できませんでした。');
        } else {
            $issued = $accountRecovery->issueResetForUser($targetId, (int)$actor['id']);
            header('Cache-Control: no-store');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
            View::render('manual-token', [
                'title' => '再設定URL',
                'manualUrl' => $issued['url'],
                'expiresAt' => $issued['expires_at'],
                'auth' => $auth,
                'route' => $route,
                'appName' => $config->get('app.name'),
                'currentUser' => $actor,
                'isSuperuser' => true,
                'flashes' => [],
            ]);
            exit;
        }
        redirectTo('users');
    }

    if ($route === 'mail/test') {
        postOnly();
        $actor = $auth->user();
        $account = $userRepo->find((int)$actor['id']);
        if (!$userRepo->verifyPassword((int)$actor['id'], (string)($_POST['current_password'] ?? ''))) {
            flash('danger', '現在のパスワードが違います。');
        } elseif (!$rateLimiter->consume('mail_test', 'account', (string)$actor['id'], 3, 3600)) {
            $audit->log('rate_limit_triggered', 'limited', (int)$actor['id'], (int)$actor['id'], ['action' => 'mail_test']);
            flash('danger', 'しばらく時間をおいてから再度お試しください。');
        } elseif (!$mailer->enabled() || empty($account['email']) || empty($account['email_verified_at'])) {
            flash('danger', '確認済みのスーパーユーザーメールと有効なメール設定が必要です。');
        } else {
            $sent = $mailer->send((string)$account['email'], '[10yendama SEO Watch] テストメール',
                "配送方式: " . ($mailSettingsData['transport'] ?? 'disabled') . "\n送信日時: "
                . $dateTime->detail($dateTime->nowUtc()) . "\n表示タイムゾーン: " . $dateTime->timezoneName() . "\n");
            $mailSettings->recordTest('mail', $sent
                ? \Tenyendama\SeoWatch\MailResult::ok()
                : \Tenyendama\SeoWatch\MailResult::failed('unknown', '送信失敗'));
            $audit->log($sent ? 'mail_send_success' : 'mail_send_failure', $sent ? 'success' : 'failure', (int)$actor['id'], (int)$actor['id']);
            flash($sent ? 'success' : 'danger', $sent ? 'テストメールを送信しました。' : 'テストメールを送信できませんでした。');
        }
        redirectTo('settings');
    }

    $activeProperty = $propertyRepo->active();
    $currentUser = $auth->user();
    $isSuperuser = $auth->isSuperuser();
    $common = [
        'auth' => $auth,
        'route' => $route,
        'flashes' => pullFlashes(),
        'appName' => $config->get('app.name'),
        'activeProperty' => $activeProperty,
        'currentUser' => $currentUser,
        'isSuperuser' => $isSuperuser,
        'dateTime' => $dateTime,
    ];

    if ($route === 'improvements/create') {
        postOnly();
        if (!$activeProperty) {
            throw new RuntimeException('先に分析対象プロパティを選んでください。');
        }
        $input = $_POST;
        $input['property_id'] = (int)$activeProperty['id'];
        $improvementTasks->create($input, (int)$currentUser['id']);
        flash('success', '改善タスクを追加しました。');
        redirectTo('improvements');
    }

    if ($route === 'improvements/update') {
        postOnly();
        if (!$activeProperty) {
            throw new RuntimeException('先に分析対象プロパティを選んでください。');
        }
        $improvementTasks->update(
            (int)($_POST['task_id'] ?? 0),
            (int)$activeProperty['id'],
            $_POST,
            (int)$currentUser['id']
        );
        flash('success', '改善タスクを更新しました。');
        redirectTo('improvements');
    }

    if ($route === 'improvements') {
        $filters = ['status' => trim((string)($_GET['status'] ?? ''))];
        $tasks = $activeProperty ? $improvementTasks->list((int)$activeProperty['id'], $filters) : [];
        if ($activeProperty) {
            foreach ($tasks as &$task) {
                $task['history'] = array_slice($improvementTasks->historyFor((int)$task['id'], (int)$activeProperty['id']), 0, 5);
                $task['comparison'] = !empty($task['revision_date'])
                    ? $effectComparison->compare((int)$activeProperty['id'], (string)$task['normalized_page_url'], (string)$task['revision_date'])
                    : null;
            }
            unset($task);
        }
        View::render('improvements', $common + [
            'title' => '改善管理',
            'tasks' => $tasks,
            'filters' => $filters,
            'users' => $isSuperuser ? $userRepo->all() : [],
        ]);
        exit;
    }

    if ($route === 'dashboard') {
        $summary = $activeProperty ? $analytics->summary((int)$activeProperty['id']) : null;
        $pageOpportunities = $activeProperty ? $analytics->pageOpportunities((int)$activeProperty['id'], 28, 10) : [];
        $opportunities = $activeProperty ? $analytics->opportunities((int)$activeProperty['id'], 28, 10) : [];

        if ($activeProperty) {
            $urls = array_merge(
                array_column($pageOpportunities, 'page_url'),
                array_column($opportunities, 'page_url')
            );
            $titles = $titleResolver->resolveMany($activeProperty, $urls);
            $pageOpportunities = attachPageTitles($pageOpportunities, $titles);
            $opportunities = attachPageTitles($opportunities, $titles);
        }

        View::render('dashboard', $common + [
            'title' => 'ダッシュボード',
            'summary' => $summary,
            'pageOpportunities' => $pageOpportunities,
            'opportunities' => $opportunities,
        ]);
        exit;
    }

    if ($route === 'opportunities') {
        $pageNo = max(1, (int)($_GET['p'] ?? 1));
        $perPage = Paginator::normalizePerPage((int)($_GET['pp'] ?? 25), [25, 50, 100]);
        $result = $activeProperty
            ? $analytics->opportunityPage((int)$activeProperty['id'], 28, $pageNo, $perPage)
            : Paginator::slice([], 1, $perPage, [25, 50, 100]);
        if ($activeProperty && $result['rows']) {
            $titles = $titleResolver->resolveMany($activeProperty, array_column($result['rows'], 'page_url'));
            $result['rows'] = attachPageTitles($result['rows'], $titles);
        }
        if (isFragmentRequest()) {
            renderFragment('partials/opportunities-table', compact('result'));
        }
        View::render('opportunities', $common + [
            'title' => '伸びしろキーワード',
            'result' => $result,
        ]);
        exit;
    }

    if ($route === 'queries') {
        $search = trim((string)($_GET['q'] ?? ''));
        $pageNo = max(1, (int)($_GET['p'] ?? 1));
        $perPage = Paginator::normalizePerPage((int)($_GET['pp'] ?? 50), [25, 50, 100]);
        $result = $activeProperty
            ? $analytics->queryRows((int)$activeProperty['id'], $search, $pageNo, $perPage)
            : Paginator::slice([], 1, $perPage, [25, 50, 100]);
        $dimension = 'query';
        if (isFragmentRequest()) {
            renderFragment('partials/dimension-table', compact('dimension', 'result', 'search'));
        }
        View::render('dimension-list', $common + [
            'title' => '検索語',
            'dimension' => $dimension,
            'result' => $result,
            'search' => $search,
        ]);
        exit;
    }

    if ($route === 'pages') {
        $search = trim((string)($_GET['q'] ?? ''));
        $pageNo = max(1, (int)($_GET['p'] ?? 1));
        $perPage = Paginator::normalizePerPage((int)($_GET['pp'] ?? 50), [25, 50, 100]);
        $result = $activeProperty
            ? $analytics->pageRows((int)$activeProperty['id'], $search, $pageNo, $perPage)
            : Paginator::slice([], 1, $perPage, [25, 50, 100]);
        if ($activeProperty && $result['rows']) {
            $titles = $titleResolver->resolveMany($activeProperty, array_column($result['rows'], 'label'));
            foreach ($result['rows'] as &$row) {
                $row['page_title'] = $titles[(string)$row['label']] ?? null;
            }
            unset($row);
        }
        $dimension = 'page';
        if (isFragmentRequest()) {
            renderFragment('partials/dimension-table', compact('dimension', 'result', 'search'));
        }
        View::render('dimension-list', $common + [
            'title' => 'ページ',
            'dimension' => $dimension,
            'result' => $result,
            'search' => $search,
        ]);
        exit;
    }

    if ($route === 'page-detail') {
        if (!$activeProperty) {
            throw new RuntimeException('先に分析対象プロパティを選んでください。');
        }
        $requestedUrl = trim((string)($_GET['u'] ?? ''));
        $pageUrl = $urlNormalizer->normalize($requestedUrl);
        if ($pageUrl === '' || filter_var($pageUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('記事URLが不正です。ページ一覧から開き直してください。');
        }
        $days = (int)($_GET['days'] ?? 28);
        if (!in_array($days, [7, 28, 56, 90], true)) {
            $days = 28;
        }
        $detail = $analytics->pageDetail((int)$activeProperty['id'], $pageUrl, $days);
        if (!$detail) {
            http_response_code(404);
            View::render('error', $common + [
                'title' => '記事が見つかりません',
                'message' => '選択したURLのSearch Consoleデータがありません。URL正規化後にもう一度確認してください。',
            ]);
            exit;
        }

        $queryPage = max(1, (int)($_GET['p'] ?? 1));
        $queryPerPage = Paginator::normalizePerPage((int)($_GET['pp'] ?? 20), [20, 50, 100]);
        $queryResult = Paginator::slice($detail['queries'], $queryPage, $queryPerPage, [20, 50, 100]);
        if (isFragmentRequest()) {
            renderFragment('partials/page-detail-query-table', compact('queryResult', 'pageUrl', 'days'));
        }

        $titles = $titleResolver->resolveMany($activeProperty, [$pageUrl]);
        $inspection = $contentInspector->inspect($activeProperty, $pageUrl);
        $pageTitle = trim((string)($inspection['title'] ?? ''));
        if ($pageTitle === '') {
            $pageTitle = (string)($titles[$pageUrl] ?? $pageUrl);
        }
        $detail['title'] = $pageTitle;
        $advice = $improvementAdvisor->advise($detail, $detail['queries'], $inspection);

        View::render('page-detail', $common + [
            'title' => '記事詳細',
            'pageTitle' => $pageTitle,
            'pageUrl' => $pageUrl,
            'days' => $days,
            'detail' => $detail,
            'inspection' => $inspection,
            'advice' => $advice,
            'queryResult' => $queryResult,
        ]);
        exit;
    }

    if ($route === 'users') {
        View::render('users', $common + [
            'title' => 'ユーザー管理',
            'users' => $userRepo->all(),
            'mailEnabled' => $mailer->enabled(),
        ]);
        exit;
    }

    if ($route === 'account') {
        View::render('account', $common + [
            'title' => 'アカウント',
            'account' => $userRepo->find((int)$currentUser['id']),
            'mailEnabled' => $mailer->enabled(),
        ]);
        exit;
    }

    if ($route === 'audit') {
        $filters = [
            'event_type' => trim((string)($_GET['event_type'] ?? '')),
            'outcome' => trim((string)($_GET['outcome'] ?? '')),
            'from' => trim((string)($_GET['from'] ?? '')),
            'to' => trim((string)($_GET['to'] ?? '')),
        ];
        View::render('audit', $common + [
            'title' => '認証監査ログ',
            'filters' => $filters,
            'result' => $audit->recent($filters, max(1, (int)($_GET['p'] ?? 1))),
        ]);
        exit;
    }

    if ($route === 'settings') {
        $properties = $propertyRepo->all();
        $runs = $activeProperty ? $analytics->recentRuns((int)$activeProperty['id']) : [];
        $configPath = __DIR__ . '/config/local.php';
        $diagnostics = [];

        $phpVersionOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $diagnostics[] = [
            'label' => 'PHPバージョン',
            'status' => $phpVersionOk ? '正常' : 'エラー',
            'message' => $phpVersionOk
                ? '現在のPHPバージョンは ' . PHP_VERSION . ' です。' 
                : 'PHP 8.1以上が必要です。現在のPHPバージョンは ' . PHP_VERSION . ' です。',
            'type' => $phpVersionOk ? 'ok' : 'error',
        ];

        foreach (['pdo_mysql' => 'PDO MySQL', 'curl' => 'cURL', 'openssl' => 'OpenSSL', 'json' => 'JSON', 'mbstring' => 'mbstring'] as $extension => $name) {
            $loaded = extension_loaded($extension);
            $diagnostics[] = [
                'label' => $name,
                'status' => $loaded ? '正常' : 'エラー',
                'message' => $loaded
                    ? $name . '拡張が読み込まれています。'
                    : $name . '拡張が利用できません。サーバー管理画面でPHPの' . $name . '拡張を有効にしてください。',
                'type' => $loaded ? 'ok' : 'error',
            ];
        }

        $https = RuntimeEnvironment::requestIsHttps();
        $diagnostics[] = [
            'label' => 'HTTPS',
            'status' => $https ? '正常' : '注意',
            'message' => $https
                ? 'HTTPS経由でアクセスされています。' 
                : '公開環境ではHTTPSを必須にしてください。サーバー設定またはリバースプロキシでHTTPSを有効にしてください。',
            'type' => $https ? 'ok' : 'warning',
        ];

        $configExists = is_file($configPath);
        $configReadable = is_readable($configPath);
        $diagnostics[] = [
            'label' => 'config/local.php',
            'status' => $configExists && $configReadable ? '正常' : 'エラー',
            'message' => $configExists
                ? ($configReadable ? '設定ファイルが存在し、読み込み可能です。' : '設定ファイルは存在しますが、読み込み権限がありません。')
                : '設定ファイルが見つかりません。インストールを完了してください。',
            'type' => $configExists && $configReadable ? 'ok' : 'error',
        ];

        $diagnostics[] = [
            'label' => 'DB接続',
            'status' => '正常',
            'message' => 'データベース接続は正常に読み込まれました。',
            'type' => 'ok',
        ];
        $dbTimezones = $pdo->query('SELECT @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone')->fetch();
        $diagnostics[] = [
            'label' => 'DBセッションタイムゾーン',
            'status' => ($dbTimezones['session_timezone'] ?? '') === '+00:00' ? '正常' : 'エラー',
            'message' => (string)($dbTimezones['session_timezone'] ?? '取得不可'),
            'type' => ($dbTimezones['session_timezone'] ?? '') === '+00:00' ? 'ok' : 'error',
        ];
        $diagnostics[] = [
            'label' => 'DBサーバーシステムタイムゾーン',
            'status' => '情報',
            'message' => (string)($dbTimezones['system_timezone'] ?? '取得不可') . '（接続セッションはUTC固定）',
            'type' => 'info',
        ];
        $diagnostics[] = [
            'label' => 'PHP標準タイムゾーン',
            'status' => '情報',
            'message' => date_default_timezone_get() . '（内部処理はUTC固定）',
            'type' => 'info',
        ];
        $diagnostics[] = [
            'label' => 'アプリ表示タイムゾーン',
            'status' => $displayTimezoneConfirmed ? '正常' : '注意',
            'message' => $dateTime->timezoneName() . ($displayTimezoneConfirmed ? '' : '（未確認。設定画面で確認してください）'),
            'type' => $displayTimezoneConfirmed ? 'ok' : 'warning',
        ];
        $diagnostics[] = [
            'label' => '現在日時',
            'status' => '情報',
            'message' => 'UTC: ' . $dateTime->nowUtc()->format('Y-m-d H:i:s T') . ' / 表示: ' . $dateTime->detail($dateTime->nowUtc()),
            'type' => 'info',
        ];
        $diagnostics[] = [
            'label' => 'Search Console基準',
            'status' => '正常',
            'message' => \Tenyendama\SeoWatch\SearchConsoleDate::TIMEZONE . ' / ' . $searchConsoleDate->today() . ' PT',
            'type' => 'ok',
        ];

        $oauthConfigured = (string)$config->get('google.client_id') !== '' && (string)$config->get('google.client_secret') !== '';
        $oauthConnected = $oauth->connected();
        $diagnostics[] = [
            'label' => 'Google OAuth設定',
            'status' => $oauthConfigured ? '正常' : '注意',
            'message' => $oauthConfigured
                ? 'OAuthクライアントIDとシークレットが設定されています。' 
                : 'Google OAuthクライアントIDとシークレットを設定してください。',
            'type' => $oauthConfigured ? 'ok' : 'warning',
        ];

        $diagnostics[] = [
            'label' => 'Google連携',
            'status' => $oauthConnected ? '正常' : '注意',
            'message' => $oauthConnected
                ? 'Googleと連携済みです。' 
                : 'Google連携が完了していません。設定画面から「Googleと連携する」を実行してください。',
            'type' => $oauthConnected ? 'ok' : 'warning',
        ];

        $activePropertyStatus = $activeProperty !== null;
        $diagnostics[] = [
            'label' => 'Search Consoleプロパティ選択',
            'status' => $activePropertyStatus ? '正常' : '注意',
            'message' => $activePropertyStatus
                ? '分析対象プロパティが選択されています。' 
                : '分析対象プロパティが選択されていません。プロパティを選択してください。',
            'type' => $activePropertyStatus ? 'ok' : 'warning',
        ];

        $lastRun = $runs[0] ?? null;
        $phpCliPath = PHP_BINARY ?: 'php';
        $appPath = realpath(__DIR__);
        $cronImportCommand = sprintf('%s %s/bin/import.php --days=3', $phpCliPath, $appPath);
        $cronWrapperCommand = sprintf('PHP_BIN=%s %s/bin/cron.sh', $phpCliPath, $appPath);

        View::render('settings', $common + [
            'title' => '設定',
            'oauthConnected' => $oauthConnected,
            'properties' => $properties,
            'runs' => $runs,
            'redirectUri' => $config->get('google.redirect_uri'),
            'importLagDays' => $config->get('app.import_lag_days', 3),
            'diagnostics' => $diagnostics,
            'cliPhpPath' => $phpCliPath,
            'appRootPath' => $appPath,
            'cronImportCommand' => $cronImportCommand,
            'cronWrapperCommand' => $cronWrapperCommand,
            'lastRun' => $lastRun,
            'mailEnabled' => $mailer->enabled(),
            'mailSettings' => $mailSettingsData,
            'mailFromName' => (string)$mailSettingsData['from_name'],
            'mailFromAddress' => \Tenyendama\SeoWatch\EmailAddress::mask((string)$mailSettingsData['from_address']),
            'mailFunctionAvailable' => function_exists('mail'),
            'superuserAccount' => $userRepo->find((int)$currentUser['id']),
            'timezoneIdentifiers' => \Tenyendama\SeoWatch\TimezoneService::identifiers(),
            'displayTimezoneConfirmed' => $displayTimezoneConfirmed,
            'searchConsoleDate' => $searchConsoleDate,
        ]);
        exit;
    }

    http_response_code(404);
    View::render('error', $common + ['title' => '404', 'message' => 'ページが見つかりません。']);
} catch (ForbiddenException $e) {
    http_response_code(403);
    if (isFragmentRequest()) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="async-server-error">この操作を行う権限がありません。</div>';
        exit;
    }
    View::render('error', [
        'title' => '403 Forbidden',
        'message' => $e->getMessage(),
        'auth' => $auth,
        'route' => $route,
        'flashes' => pullFlashes(),
        'appName' => $config->get('app.name'),
        'activeProperty' => $propertyRepo->active(),
        'currentUser' => $auth->user(),
        'isSuperuser' => $auth->isSuperuser(),
    ]);
} catch (Throwable $e) {
    error_log((string)$e);
    if (isFragmentRequest()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="async-server-error">一覧を取得できませんでした。時間を置いて再試行してください。</div>';
        exit;
    }
    if ($route === 'oauth/callback') {
        flash('danger', $e->getMessage());
        redirectTo('settings');
    }
    http_response_code(500);
    View::render('error', [
        'title' => 'エラー',
        'message' => $e->getMessage(),
        'auth' => $auth,
        'route' => $route,
        'flashes' => pullFlashes(),
        'appName' => $config->get('app.name'),
        'activeProperty' => $propertyRepo->active(),
        'currentUser' => $auth->user(),
        'isSuperuser' => $auth->isSuperuser(),
    ]);
}
