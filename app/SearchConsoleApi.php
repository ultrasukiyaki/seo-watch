<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class SearchConsoleApi
{
    private const API_BASE = 'https://www.googleapis.com/webmasters/v3';

    public function __construct(private readonly HttpClient $http, private readonly GoogleOAuth $oauth)
    {
    }

    public function listSites(): array
    {
        $response = $this->http->request('GET', self::API_BASE . '/sites', [
            'Authorization' => 'Bearer ' . $this->oauth->accessToken(),
            'Accept' => 'application/json',
        ]);
        return $response['siteEntry'] ?? [];
    }

    public function query(string $siteUrl, array $payload): array
    {
        $url = self::API_BASE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
        return $this->http->request('POST', $url, [
            'Authorization' => 'Bearer ' . $this->oauth->accessToken(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
