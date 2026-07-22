<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class HttpClient
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('cURLを初期化できません。');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            if (is_array($body)) {
                $body = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('HTTP通信に失敗しました: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? (string)($decoded['error']['message'] ?? $decoded['error_description'] ?? $response)
                : $response;
            throw new \RuntimeException("Google APIエラー (HTTP {$status}): {$message}");
        }

        return is_array($decoded) ? $decoded : ['raw' => $response];
    }
}
