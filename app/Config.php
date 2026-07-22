<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('設定ファイルがありません: ' . $path);
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('設定ファイルの形式が不正です。');
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function all(): array
    {
        return $this->values;
    }
}
