<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\ForbiddenException;
use Tenyendama\SeoWatch\Paginator;
use Tenyendama\SeoWatch\RoutePolicy;
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

    if ($route === 'import/run') {
        postOnly();
        set_time_limit(0);
        $active = $propertyRepo->active();
        if (!$active) {
            throw new RuntimeException('先に分析対象プロパティを選んでください。');
        }
        $days = max(1, min(90, (int)($_POST['days'] ?? 7)));
        $lag = max(1, min(7, (int)$config->get('app.import_lag_days', 3)));
        $end = (new DateTimeImmutable('today', new DateTimeZone('America/Los_Angeles')))->modify("-{$lag} days");
        $start = $end->modify('-' . ($days - 1) . ' days');
        $rows = $importer->import($active, $start->format('Y-m-d'), $end->format('Y-m-d'));
        flash('success', number_format($rows) . "行を取り込みました（{$start->format('Y-m-d')}〜{$end->format('Y-m-d')}）。URL正規化も反映済みです。");
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
    ];

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
        ]);
        exit;
    }

    if ($route === 'settings') {
        $properties = $propertyRepo->all();
        $runs = $activeProperty ? $analytics->recentRuns((int)$activeProperty['id']) : [];
        View::render('settings', $common + [
            'title' => '設定',
            'oauthConnected' => $oauth->connected(),
            'properties' => $properties,
            'runs' => $runs,
            'redirectUri' => $config->get('google.redirect_uri'),
            'importLagDays' => $config->get('app.import_lag_days', 3),
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
