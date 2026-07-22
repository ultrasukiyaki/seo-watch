<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly TokenStore $tokens
    ) {
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTH_URL . '?' . $query;
    }

    public function exchangeCode(string $code): array
    {
        $token = $this->http->request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        $token['created_at'] = time();
        $this->tokens->put($token);
        return $token;
    }

    public function accessToken(): string
    {
        $token = $this->tokens->get();
        if (!$token) {
            throw new \RuntimeException('Google Search Consoleが未連携です。');
        }

        $expiresAt = (int)($token['created_at'] ?? 0) + (int)($token['expires_in'] ?? 0);
        if (!empty($token['access_token']) && $expiresAt > time() + 60) {
            return (string)$token['access_token'];
        }

        $refreshToken = (string)($token['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new \RuntimeException('更新トークンがありません。Google連携をやり直してください。');
        }

        $fresh = $this->http->request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $fresh['refresh_token'] = $refreshToken;
        $fresh['created_at'] = time();
        $this->tokens->put($fresh);
        return (string)$fresh['access_token'];
    }

    public function connected(): bool
    {
        return $this->tokens->get() !== null;
    }

    private function clientId(): string
    {
        $clientId = $this->withoutWhitespace((string)$this->config->get('google.client_id'));

        if (!preg_match('/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/', $clientId)) {
            throw new \RuntimeException(
                'Google OAuthクライアントIDの形式が不正です。config/local.phpのclient_idを、Google Cloudのコピーボタンから貼り直してください。'
            );
        }

        return $clientId;
    }

    private function clientSecret(): string
    {
        $clientSecret = $this->withoutWhitespace((string)$this->config->get('google.client_secret'));
        if ($clientSecret === '') {
            throw new \RuntimeException('Google OAuthクライアントシークレットが空です。');
        }
        return $clientSecret;
    }

    private function redirectUri(): string
    {
        $redirectUri = trim((string)$this->config->get('google.redirect_uri'));
        if (!filter_var($redirectUri, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Google OAuthリダイレクトURIの形式が不正です。');
        }
        return $redirectUri;
    }

    private function withoutWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', '', $value);
        return is_string($normalized) ? $normalized : trim($value);
    }
}
