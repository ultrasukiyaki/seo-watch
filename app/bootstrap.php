<?php
declare(strict_types=1);

use Tenyendama\SeoWatch\AnalyticsRepository;
use Tenyendama\SeoWatch\Auth;
use Tenyendama\SeoWatch\Config;
use Tenyendama\SeoWatch\Crypto;
use Tenyendama\SeoWatch\Database;
use Tenyendama\SeoWatch\DataMaintenance;
use Tenyendama\SeoWatch\GoogleOAuth;
use Tenyendama\SeoWatch\HttpClient;
use Tenyendama\SeoWatch\Importer;
use Tenyendama\SeoWatch\ImprovementAdvisor;
use Tenyendama\SeoWatch\OAuthState;
use Tenyendama\SeoWatch\OpportunityScorer;
use Tenyendama\SeoWatch\PageMetadataRepository;
use Tenyendama\SeoWatch\PropertyRepository;
use Tenyendama\SeoWatch\SchemaManager;
use Tenyendama\SeoWatch\SearchConsoleApi;
use Tenyendama\SeoWatch\TokenStore;
use Tenyendama\SeoWatch\UrlNormalizer;
use Tenyendama\SeoWatch\UserRepository;
use Tenyendama\SeoWatch\WordPressTitleResolver;
use Tenyendama\SeoWatch\WordPressContentInspector;

require_once __DIR__ . '/autoload.php';

$configPath = dirname(__DIR__) . '/config/local.php';
if (!is_file($configPath)) {
    throw new RuntimeException('NOT_INSTALLED');
}
$config = Config::load($configPath);
date_default_timezone_set((string)$config->get('app.timezone', 'Asia/Tokyo'));

if (PHP_SAPI !== 'cli') {
    $baseUrl = (string)$config->get('app.base_url', '');
    $basePath = parse_url($baseUrl, PHP_URL_PATH);
    $cookiePath = is_string($basePath) ? '/' . trim($basePath, '/') : '/';
    $cookiePath = $cookiePath === '/' ? '/' : rtrim($cookiePath, '/') . '/';

    ini_set('session.use_strict_mode', '1');
    session_name((string)$config->get('app.session_name', 'seo_watch_session'));
    session_set_cookie_params([
        'httponly' => true,
        'secure' => str_starts_with($baseUrl, 'https://'),
        'samesite' => 'Lax',
        'path' => $cookiePath,
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

$db = new Database($config);
$pdo = $db->pdo();
(new SchemaManager($pdo))->migrate();

$crypto = new Crypto((string)$config->get('app.key'));
$http = new HttpClient();
$tokenStore = new TokenStore($pdo, $crypto);
$oauth = new GoogleOAuth($config, $http, $tokenStore);
$oauthState = new OAuthState($config);
$api = new SearchConsoleApi($http, $oauth);
$propertyRepo = new PropertyRepository($pdo);
$urlNormalizer = new UrlNormalizer();
$importer = new Importer($pdo, $api, $urlNormalizer);
$analytics = new AnalyticsRepository($pdo, new OpportunityScorer());
$dataMaintenance = new DataMaintenance($pdo, $urlNormalizer);
$pageMetadata = new PageMetadataRepository($pdo);
$titleResolver = new WordPressTitleResolver($config, $http, $pageMetadata, $urlNormalizer);
$contentInspector = new WordPressContentInspector($config, $http, $pageMetadata, $urlNormalizer);
$improvementAdvisor = new ImprovementAdvisor();
$auth = new Auth($pdo);
$userRepo = new UserRepository($pdo);

return compact(
    'config',
    'pdo',
    'tokenStore',
    'oauth',
    'oauthState',
    'api',
    'propertyRepo',
    'urlNormalizer',
    'importer',
    'analytics',
    'dataMaintenance',
    'titleResolver',
    'contentInspector',
    'improvementAdvisor',
    'auth',
    'userRepo'
);
