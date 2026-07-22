<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class UrlNormalizer
{
    /** @var list<string> */
    private const TRACKING_PARAMETERS = [
        'gclid', 'dclid', 'fbclid', 'msclkid', 'yclid', 'twclid',
        '_ga', '_gl', 'mc_cid', 'mc_eid', 'igshid',
    ];

    public function normalize(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $this->fallback($url);
        }

        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $path = (string)($parts['path'] ?? '/');

        if ($path === '') {
            $path = '/';
        }
        $path = preg_replace('~/+~', '/', $path) ?: $path;
        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        $query = $this->normalizeQuery((string)($parts['query'] ?? ''));

        if ($scheme === '' || $host === '') {
            $relative = $path;
            if ($query !== '') {
                $relative .= '?' . $query;
            }
            return $relative;
        }

        $authority = $host;
        if (isset($parts['user'])) {
            $authority = (string)$parts['user']
                . (isset($parts['pass']) ? ':' . (string)$parts['pass'] : '')
                . '@' . $authority;
        }
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $authority .= ':' . $port;
        }

        $normalized = $scheme . '://' . $authority . $path;
        if ($query !== '') {
            $normalized .= '?' . $query;
        }
        return $normalized;
    }

    private function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        if (!is_array($params)) {
            return '';
        }

        foreach (array_keys($params) as $key) {
            $lower = strtolower((string)$key);
            if (str_starts_with($lower, 'utm_') || in_array($lower, self::TRACKING_PARAMETERS, true)) {
                unset($params[$key]);
            }
        }

        if ($params === []) {
            return '';
        }

        ksort($params, SORT_STRING);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function fallback(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        if ($url !== '/' && !preg_match('~^https?://[^/]+/$~i', $url)) {
            $url = rtrim($url, '/');
        }
        return $url;
    }
}
