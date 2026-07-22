<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class WordPressTitleResolver
{
    private const SUCCESS_TTL = 604800;
    private const FAILURE_TTL = 86400;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly PageMetadataRepository $metadata,
        private readonly UrlNormalizer $normalizer
    ) {
    }

    /** @param list<string> $urls @return array<string,string> */
    public function resolveMany(array $property, array $urls): array
    {
        $propertyId = (int)($property['id'] ?? 0);
        if ($propertyId <= 0) {
            return [];
        }

        $normalizedUrls = [];
        foreach ($urls as $url) {
            $normalized = $this->normalizer->normalize((string)$url);
            if ($normalized !== '') {
                $normalizedUrls[$normalized] = true;
            }
        }
        $normalizedUrls = array_keys($normalizedUrls);
        if ($normalizedUrls === []) {
            return [];
        }

        $cached = $this->metadata->findMany($propertyId, $normalizedUrls);
        $titles = [];
        $pending = [];
        $now = time();

        foreach ($normalizedUrls as $url) {
            $row = $cached[$url] ?? null;
            if (!$row) {
                $pending[] = $url;
                continue;
            }
            $age = $now - (strtotime((string)$row['fetched_at']) ?: 0);
            $status = (string)$row['fetch_status'];
            $ttl = $status === 'success' ? self::SUCCESS_TTL : self::FAILURE_TTL;
            if ($age > $ttl) {
                $pending[] = $url;
            }
            if ($status === 'success' && trim((string)$row['page_title']) !== '') {
                $titles[$url] = (string)$row['page_title'];
            }
        }

        if ($pending !== []) {
            $fetched = $this->fetchFromWordPress($property, $pending);
            foreach ($pending as $url) {
                if (isset($fetched[$url]) && $fetched[$url] !== '') {
                    $titles[$url] = $fetched[$url];
                    $this->metadata->put($propertyId, $url, $fetched[$url]);
                } else {
                    $this->metadata->put($propertyId, $url, null, 'not_found');
                }
            }
        }

        return $titles;
    }

    /** @param list<string> $urls @return array<string,string> */
    private function fetchFromWordPress(array $property, array $urls): array
    {
        $baseUrl = $this->wordpressBaseUrl((string)($property['site_url'] ?? ''));
        if ($baseUrl === '') {
            return [];
        }

        $idToUrls = [];
        foreach ($urls as $url) {
            $id = $this->extractPostId($url);
            if ($id !== null) {
                $idToUrls[$id][] = $url;
            }
        }
        if ($idToUrls === []) {
            return [];
        }

        $ids = array_keys($idToUrls);
        $foundById = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $foundById += $this->fetchEndpoint($baseUrl, 'posts', $chunk);
            $missing = array_values(array_diff($chunk, array_keys($foundById)));
            if ($missing !== []) {
                $foundById += $this->fetchEndpoint($baseUrl, 'pages', $missing);
            }
        }

        $result = [];
        foreach ($foundById as $id => $title) {
            foreach ($idToUrls[(int)$id] ?? [] as $url) {
                $result[$url] = $title;
            }
        }
        return $result;
    }

    /** @param list<int> $ids @return array<int,string> */
    private function fetchEndpoint(string $baseUrl, string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $url = $baseUrl . '/wp-json/wp/v2/' . $type . '?' . http_build_query([
            'include' => implode(',', $ids),
            'per_page' => min(100, count($ids)),
            '_fields' => 'id,title',
        ], '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->http->request('GET', $url, ['Accept' => 'application/json']);
        } catch (\Throwable $e) {
            error_log('WordPress REST title lookup failed: ' . $e->getMessage());
            return [];
        }

        $result = [];
        foreach ($response as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $rendered = (string)($item['title']['rendered'] ?? '');
            $title = trim(html_entity_decode(strip_tags($rendered), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') {
                $result[(int)$item['id']] = $title;
            }
        }
        return $result;
    }

    private function wordpressBaseUrl(string $siteUrl): string
    {
        $configured = trim((string)$this->config->get('wordpress.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            return '';
        }
        $parts = parse_url($siteUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $base = strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']);
        if (isset($parts['port'])) {
            $base .= ':' . (int)$parts['port'];
        }
        return $base;
    }

    private function extractPostId(string $url): ?int
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('~/(?:archives|posts?)/(\d+)(?:/|$)~i', $path, $matches)) {
            return (int)$matches[1];
        }
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
        parse_str($query, $params);
        foreach (['p', 'page_id'] as $key) {
            if (isset($params[$key]) && ctype_digit((string)$params[$key])) {
                return (int)$params[$key];
            }
        }
        return null;
    }
}
