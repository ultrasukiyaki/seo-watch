<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class Crypto
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $raw = str_starts_with($encodedKey, 'base64:') ? base64_decode(substr($encodedKey, 7), true) : $encodedKey;
        if (!is_string($raw) || strlen($raw) < 32) {
            throw new \RuntimeException('APPキーは32バイト以上必要です。');
        }
        $this->key = hash('sha256', $raw, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('暗号化に失敗しました。');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 29) {
            throw new \RuntimeException('暗号化データが不正です。');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('暗号化データを復号できません。');
        }
        return $plaintext;
    }
}
