<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class WordPressContentInspector
{
    private const SUCCESS_TTL = 86400;
    private const FAILURE_TTL = 3600;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly PageMetadataRepository $metadata,
        private readonly UrlNormalizer $normalizer
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(array $property, string $url): array
    {
        $propertyId = (int)($property['id'] ?? 0);
        $url = $this->normalizer->normalize($url);
        if ($propertyId <= 0 || $url === '') {
            return $this->emptyResult('invalid_url');
        }

        $cached = $this->metadata->findOne($propertyId, $url);
        if ($cached && !empty($cached['content_fetched_at'])) {
            $age = time() - (strtotime((string)$cached['content_fetched_at']) ?: 0);
            $status = (string)($cached['content_status'] ?? 'not_found');
            $ttl = $status === 'success' ? self::SUCCESS_TTL : self::FAILURE_TTL;
            if ($age <= $ttl) {
                return $this->fromCache($cached);
            }
        }

        $baseUrl = $this->wordpressBaseUrl((string)($property['site_url'] ?? ''));
        $postId = $this->extractPostId($url);
        if ($baseUrl === '' || $postId === null) {
            return $cached ? $this->fromCache($cached) : $this->emptyResult('not_supported');
        }

        foreach (['posts', 'pages'] as $type) {
            $result = $this->fetch($baseUrl, $type, $postId);
            if ($result !== null) {
                $this->metadata->putInspection(
                    $propertyId,
                    $url,
                    $result['title'],
                    $result['modified_at'],
                    $result['headings'],
                    'success'
                );
                return $result + ['status' => 'success'];
            }
        }

        $this->metadata->putInspection($propertyId, $url, $cached['page_title'] ?? null, null, [], 'not_found');
        return $cached ? $this->fromCache($cached) : $this->emptyResult('not_found');
    }

    /** @return array<string,mixed>|null */
    private function fetch(string $baseUrl, string $type, int $postId): ?array
    {
        $url = $baseUrl . '/wp-json/wp/v2/' . $type . '/' . $postId . '?' . http_build_query([
            '_fields' => 'id,title,modified_gmt,content,link',
        ], '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->http->request('GET', $url, ['Accept' => 'application/json']);
        } catch (\Throwable $e) {
            error_log('WordPress REST content inspection failed: ' . $e->getMessage());
            return null;
        }

        if (!isset($response['id'])) {
            return null;
        }
        $titleHtml = (string)($response['title']['rendered'] ?? '');
        $contentHtml = (string)($response['content']['rendered'] ?? '');
        $title = trim(html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $modified = trim((string)($response['modified_gmt'] ?? ''));
        if ($modified !== '') {
            $modified = str_replace('T', ' ', $modified);
        }

        return [
            'title' => $title,
            'modified_at' => $modified !== '' ? $modified : null,
            'headings' => $this->extractHeadings($contentHtml),
            'status' => 'success',
        ];
    }

    /** @return list<array{level:int,text:string}> */
    private function extractHeadings(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $result = [];
        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(true);
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($loaded) {
                $xpath = new \DOMXPath($document);
                $nodes = $xpath->query('//h2 | //h3');
                if ($nodes !== false) {
                    foreach ($nodes as $node) {
                        $level = strtolower($node->nodeName) === 'h3' ? 3 : 2;
                        $text = trim(html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if ($text !== '') {
                            $result[] = ['level' => $level, 'text' => $text];
                        }
                    }
                }
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } else {
            if (preg_match_all('~<h([23])\b[^>]*>(.*?)</h\1>~isu', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $text = trim(html_entity_decode(strip_tags((string)$match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($text !== '') {
                        $result[] = ['level' => (int)$match[1], 'text' => $text];
                    }
                }
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function fromCache(array $row): array
    {
        $headings = json_decode((string)($row['headings_json'] ?? '[]'), true);
        return [
            'title' => (string)($row['page_title'] ?? ''),
            'modified_at' => $row['page_modified_at'] ?: null,
            'headings' => is_array($headings) ? $headings : [],
            'status' => (string)($row['content_status'] ?? 'not_found'),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyResult(string $status): array
    {
        return ['title' => '', 'modified_at' => null, 'headings' => [], 'status' => $status];
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
