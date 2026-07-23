<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\RuntimeEnvironment;
use Tenyendama\SeoWatch\SchemaManager;
use Tenyendama\SeoWatch\UserAccountPolicy;
use Tenyendama\SeoWatch\TimezoneService;
use Tenyendama\SeoWatch\AppSettings;

require_once __DIR__ . '/app/autoload.php';

$configPath = __DIR__ . '/config/local.php';
if (is_file($configPath)) {
    header('Location: index.php');
    exit;
}

session_start();
if (empty($_SESSION['_install_csrf'])) {
    $_SESSION['_install_csrf'] = bin2hex(random_bytes(32));
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$requirements = RuntimeEnvironment::requirements($configPath);
$errors = [];
$success = false;
$values = [
    'base_url' => RuntimeEnvironment::detectedBaseUrl(),
    'db_host' => '',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'admin_user' => 'admin',
    'admin_email' => '',
    'timezone' => TimezoneService::phpDefaultOrUtc(),
    'import_lag_days' => '3',
    'google_client_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $key) {
        if (isset($_POST[$key])) {
            $values[$key] = trim((string)$_POST[$key]);
        }
    }

    $dbPassword = (string)($_POST['db_pass'] ?? '');
    $adminPassword = (string)($_POST['admin_pass'] ?? '');
    $googleClientSecret = trim((string)($_POST['google_client_secret'] ?? ''));

    if (!hash_equals((string)$_SESSION['_install_csrf'], (string)($_POST['_csrf'] ?? ''))) {
        $errors[] = 'CSRFトークンが不正です。画面を再読み込みしてください。';
    }
    foreach ($requirements as $name => $ok) {
        if (!$ok) {
            $errors[] = $name . 'が利用できません。';
        }
    }

    $baseUrlError = RuntimeEnvironment::validateBaseUrl($values['base_url']);
    if ($baseUrlError !== null) {
        $errors[] = $baseUrlError;
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $values['db_name'])) {
        $errors[] = 'DB名は英数字・ハイフン・アンダースコアのみ使用できます。';
    }
    if ($values['db_host'] === '' || $values['db_user'] === '') {
        $errors[] = 'DBホストとDBユーザー名は必須です。';
    }
    if (!TimezoneService::isValid($values['timezone'])) {
        $errors[] = '表示タイムゾーンは有効なIANAタイムゾーンから選択してください。';
    }
    $dbPort = (int)$values['db_port'];
    if ($dbPort < 1 || $dbPort > 65535) {
        $errors[] = 'DBポートは1〜65535で指定してください。';
    }

    $usernameError = UserAccountPolicy::validateUsername($values['admin_user']);
    if ($usernameError !== null) {
        $errors[] = $usernameError;
    }
    $passwordError = UserAccountPolicy::validatePassword($adminPassword);
    if ($passwordError !== null) {
        $errors[] = $passwordError;
    }
    try {
        $values['admin_email'] = \Tenyendama\SeoWatch\EmailAddress::normalize($values['admin_email']);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }

    if ($values['google_client_id'] === '' || $googleClientSecret === '') {
        $errors[] = 'Google OAuthのクライアントIDとクライアントシークレットは必須です。';
    } elseif (preg_match('/^[^\s]+\.apps\.googleusercontent\.com$/', $values['google_client_id']) !== 1) {
        $errors[] = 'Google OAuthクライアントIDの形式を確認してください。通常は.apps.googleusercontent.comで終わります。';
    }

    if (!$errors) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $values['db_host'],
                $dbPort,
                $values['db_name']
            );
            $pdo = new PDO($dsn, $values['db_user'], $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");

            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if (!is_string($schema)) {
                throw new RuntimeException('database/schema.sqlを読み込めません。');
            }
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }

            require_once __DIR__ . '/app/SchemaManager.php';
            (new SchemaManager($pdo))->migrate();

            $pdo->exec("UPDATE admins SET role = 'viewer' WHERE role = 'superuser'");
            $stmt = $pdo->prepare(
                'INSERT INTO admins (username, password_hash, role, email) VALUES (:username, :password_hash, :role, :email)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role),
                 email = VALUES(email), updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'username' => $values['admin_user'],
                'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role' => UserAccountPolicy::ROLE_SUPERUSER,
                'email' => $values['admin_email'],
            ]);
            $adminId = (int)$pdo->query(
                'SELECT id FROM admins WHERE username = ' . $pdo->quote($values['admin_user'])
            )->fetchColumn();
            (new AppSettings($pdo))->set(AppSettings::DISPLAY_TIMEZONE, $values['timezone'], $adminId);

            $baseUrl = rtrim($values['base_url'], '/');
            $config = [
                'app' => [
                    'name' => '10yendama SEO Watch',
                    'base_url' => $baseUrl,
                    'timezone' => 'UTC',
                    'key' => 'base64:' . base64_encode(random_bytes(32)),
                    'session_name' => 'seo_watch_session',
                    'import_lag_days' => max(1, min(7, (int)$values['import_lag_days'])),
                ],
                'db' => [
                    'host' => $values['db_host'],
                    'port' => $dbPort,
                    'name' => $values['db_name'],
                    'user' => $values['db_user'],
                    'pass' => $dbPassword,
                    'charset' => 'utf8mb4',
                ],
                'wordpress' => [
                    'base_url' => '',
                ],
                'google' => [
                    'client_id' => $values['google_client_id'],
                    'client_secret' => $googleClientSecret,
                    'redirect_uri' => $baseUrl . '/oauth-callback.php',
                ],
                'mail' => [
                    'enabled' => false,
                    'from_address' => 'no-reply@example.com',
                    'from_name' => '10yendama SEO Watch',
                ],
            ];

            $php = "<?php\nreturn " . var_export($config, true) . ";\n";
            $temp = $configPath . '.tmp';
            if (file_put_contents($temp, $php, LOCK_EX) === false || !rename($temp, $configPath)) {
                throw new RuntimeException(
                    'config/local.phpを書き込めません。configディレクトリの書き込み権限を確認してください。'
                );
            }
            @chmod($configPath, 0600);

            $success = true;
            unset($_SESSION['_install_csrf']);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            if (is_file($configPath . '.tmp')) {
                unlink($configPath . '.tmp');
            }
        }
    }
}

$callbackUrl = rtrim($values['base_url'], '/') . '/oauth-callback.php';
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>10yendama SEO Watch Installer</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="install-body">
<main class="install-wrap">
    <div class="brand">🔭 10yendama SEO Watch</div>
    <h1>セットアップ</h1>
    <?php if ($success): ?>
        <div class="alert success">インストールが完了しました。Google Cloud側のリダイレクトURIが次のURLと完全一致していることを確認してください。</div>
        <pre><?=h($callbackUrl)?></pre>
        <p>インストール後は以下の手順を順に実行してください。</p>
        <ol class="install-tasks">
            <li>SEO Watchへログイン</li>
            <li>Googleと連携</li>
            <li>Search Consoleプロパティを選択</li>
            <li>初回データを取得</li>
            <li>Cronによる定期取得を設定</li>
        </ol>
        <p><a class="button primary" href="index.php">管理画面を開く</a></p>
    <?php else: ?>
        <?php if ($errors): ?><div class="alert danger"><strong>入力内容を確認してください。</strong><ul><?php foreach ($errors as $error): ?><li><?=h($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <section class="card requirement-card">
            <h2>動作要件</h2>
            <div class="requirements">
                <?php foreach ($requirements as $name => $ok): ?><span class="badge <?=$ok ? 'ok' : 'ng'?>"><?=$ok ? '✓' : '×'?> <?=h($name)?></span><?php endforeach; ?>
            </div>
        </section>
        <section class="card">
            <h2>現在の検出結果</h2>
            <dl class="install-summary">
                <div><dt>検出された公開URL</dt><dd><?=h(RuntimeEnvironment::detectedBaseUrl())?></dd></div>
                <div><dt>OAuthコールバックURL</dt><dd><?=h($callbackUrl)?></dd></div>
                <div><dt>HTTPS</dt><dd><?=RuntimeEnvironment::requestIsHttps() ? '有効' : '無効'?></dd></div>
                <div><dt>configディレクトリ書き込み</dt><dd><?=is_writable(dirname($configPath)) ? '可能' : '不可'?></dd></div>
            </dl>
            <p class="hint">インストール中に<code>config/local.php</code>が書き込める必要があります。権限がない場合はFTP/ホスティング管理画面で確認してください。</p>
        </section>
        <section class="card">
            <h2>Google Cloudへ登録するURI</h2>
            <p class="hint">OAuthクライアントの「承認済みのリダイレクトURI」へ、次のURLを<strong>完全一致</strong>で登録します。</p>
            <pre><?=h($callbackUrl)?></pre>
            <p class="hint">詳しい作成手順は <code>GOOGLE_OAUTH_SETUP.md</code> を参照してください。</p>
        </section>
        <form method="post" class="card form-grid">
            <input type="hidden" name="_csrf" value="<?=h($_SESSION['_install_csrf'])?>">
            <h2>アプリ</h2>
            <label class="wide">公開ベースURL<input name="base_url" value="<?=h($values['base_url'])?>" required></label>
            <p class="hint wide">例: https://www.example.com/seo-watch（末尾のスラッシュ、install.php、oauth-callback.phpは付けません）</p>
            <label>表示タイムゾーン
                <select name="timezone" required>
                    <?php foreach (TimezoneService::identifiers() as $timezone): ?>
                    <option value="<?=h($timezone)?>" <?=$timezone === $values['timezone'] ? 'selected' : ''?>><?=h($timezone)?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="hint wide">画面、メール、CLIで日時を表示するときに使用します。データベースへの保存はUTCで行われます。</p>
            <label>確定データ待機日数<input type="number" name="import_lag_days" min="1" max="7" value="<?=h($values['import_lag_days'])?>"></label>

            <h2>MySQL</h2>
            <p class="hint wide">先に空のデータベースを作成し、ホスティング事業者またはサーバー管理者から案内された接続情報を入力してください。DBホストはlocalhostとは限りません。</p>
            <label class="wide">DBホスト<input name="db_host" value="<?=h($values['db_host'])?>" placeholder="例: localhost またはDBサーバー名" required></label>
            <label>ポート<input type="number" name="db_port" min="1" max="65535" value="<?=h($values['db_port'])?>" required></label>
            <label>DB名<input name="db_name" value="<?=h($values['db_name'])?>" required></label>
            <label>DBユーザー<input name="db_user" value="<?=h($values['db_user'])?>" required></label>
            <label class="wide">DBパスワード<input type="password" name="db_pass" required></label>

            <h2>スーパーユーザー</h2>
            <p class="hint wide">ここで作成するアカウントだけが、Google連携・データ更新・設定変更・閲覧ユーザー管理を行えます。</p>
            <label>ユーザー名<input name="admin_user" value="<?=h($values['admin_user'])?>" minlength="3" maxlength="64" required></label>
            <label>メールアドレス<input type="email" name="admin_email" value="<?=h($values['admin_email'])?>" autocomplete="email" required></label>
            <label>パスワード<input type="password" name="admin_pass" minlength="12" maxlength="128" autocomplete="new-password" required></label>

            <h2>Google OAuth</h2>
            <p class="hint wide">OAuthクライアントは「ウェブ アプリケーション」で作成し、上に表示されたHTTPSコールバックURLを登録してください。</p>
            <label class="wide">クライアントID<input name="google_client_id" value="<?=h($values['google_client_id'])?>" autocomplete="off" required></label>
            <label class="wide">クライアントシークレット<input type="password" name="google_client_secret" autocomplete="new-password" required></label>
            <div class="wide"><button class="button primary" type="submit">インストール実行</button></div>
        </form>
    <?php endif; ?>
    <?php require __DIR__ . '/views/partials/footer.php'; ?>
</main>
</body>
</html>
