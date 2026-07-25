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
use Tenyendama\SeoWatch\AccountRecoveryService;
use Tenyendama\SeoWatch\AuthenticationAuditLogger;
use Tenyendama\SeoWatch\AuthRateLimiter;
use Tenyendama\SeoWatch\MailService;
use Tenyendama\SeoWatch\MailSettingsRepository;
use Tenyendama\SeoWatch\MailTransportFactory;
use Tenyendama\SeoWatch\UserActionTokenRepository;
use Tenyendama\SeoWatch\AppSettings;
use Tenyendama\SeoWatch\DateTimeFormatter;
use Tenyendama\SeoWatch\SearchConsoleDate;
use Tenyendama\SeoWatch\SystemClock;
use Tenyendama\SeoWatch\TimezoneService;
use Tenyendama\SeoWatch\ImportLockService;
use Tenyendama\SeoWatch\ImprovementTaskRepository;
use Tenyendama\SeoWatch\EffectComparisonService;
use Tenyendama\SeoWatch\AlertRepository;
use Tenyendama\SeoWatch\AlertRuleEvaluator;
use Tenyendama\SeoWatch\AlertLockService;
use Tenyendama\SeoWatch\AlertDetectionService;
use Tenyendama\SeoWatch\AlertDeliveryService;

require_once __DIR__ . '/autoload.php';

$configPath = dirname(__DIR__) . '/config/local.php';
if (!is_file($configPath)) {
    throw new RuntimeException('NOT_INSTALLED');
}
$config = Config::load($configPath);
$phpDefaultTimezone = TimezoneService::phpDefaultOrUtc();
date_default_timezone_set('UTC');

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
$settings = new AppSettings($pdo);
$configuredTimezone = $settings->get(AppSettings::DISPLAY_TIMEZONE);
$legacyTimezone = (string)$config->get('app.timezone', '');
$displayTimezoneConfirmed = $configuredTimezone !== null && TimezoneService::isValid($configuredTimezone);
$displayTimezone = $displayTimezoneConfirmed
    ? $configuredTimezone
    : (TimezoneService::isValid($legacyTimezone) ? $legacyTimezone : $phpDefaultTimezone);
$clock = new SystemClock();
$dateTime = new DateTimeFormatter($clock, $displayTimezone);
$searchConsoleDate = new SearchConsoleDate($clock);

$crypto = new Crypto((string)$config->get('app.key'));
$http = new HttpClient();
$tokenStore = new TokenStore($pdo, $crypto);
$oauth = new GoogleOAuth($config, $http, $tokenStore);
$oauthState = new OAuthState($config);
$api = new SearchConsoleApi($http, $oauth);
$propertyRepo = new PropertyRepository($pdo);
$urlNormalizer = new UrlNormalizer();
$importLocks = new ImportLockService($pdo);
$importer = new Importer($pdo, $api, $urlNormalizer, $importLocks);
$improvementTasks = new ImprovementTaskRepository($pdo, $urlNormalizer);
$effectComparison = new EffectComparisonService($pdo);
$alertRepository = new AlertRepository($pdo);
$alertLocks = new AlertLockService($pdo);
$alertDetection = new AlertDetectionService($alertRepository, new AlertRuleEvaluator(), $alertLocks);
$analytics = new AnalyticsRepository($pdo, new OpportunityScorer());
$dataMaintenance = new DataMaintenance($pdo, $urlNormalizer);
$pageMetadata = new PageMetadataRepository($pdo);
$titleResolver = new WordPressTitleResolver($config, $http, $pageMetadata, $urlNormalizer);
$contentInspector = new WordPressContentInspector($config, $http, $pageMetadata, $urlNormalizer);
$improvementAdvisor = new ImprovementAdvisor();
$audit = new AuthenticationAuditLogger($pdo, (string)$config->get('app.key'), $dateTime);
$rateLimiter = new AuthRateLimiter($pdo, (string)$config->get('app.key'));
$mailSettings = new MailSettingsRepository($pdo, $crypto);
$mailSettingsData = $mailSettings->get();
$mailTransportFactory = new MailTransportFactory($mailSettings);
$mailer = new MailService((string)$mailSettingsData['transport'], $mailTransportFactory->create($mailSettingsData));
$actionTokens = new UserActionTokenRepository($pdo, $clock);
$accountRecovery = new AccountRecoveryService(
    $pdo,
    $actionTokens,
    $mailer,
    $audit,
    (string)$config->get('app.base_url'),
    $dateTime
);
$alertDelivery = new AlertDeliveryService(
    $pdo,
    $mailer,
    (string)$config->get('app.base_url'),
    $dateTime->timezoneName()
);
$auth = new Auth($pdo, $audit);
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
    'userRepo',
    'audit',
    'rateLimiter',
    'mailer',
    'mailSettings',
    'mailSettingsData',
    'mailTransportFactory',
    'actionTokens',
    'accountRecovery',
    'settings',
    'clock',
    'importLocks',
    'improvementTasks',
    'effectComparison',
    'dateTime',
    'searchConsoleDate',
    'displayTimezoneConfirmed',
    'alertRepository',
    'alertLocks',
    'alertDetection',
    'alertDelivery'
);
