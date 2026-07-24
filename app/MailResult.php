<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class MailResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $category = 'unknown',
        public readonly string $message = ''
    ) {
    }

    public static function ok(string $message = ''): self
    {
        return new self(true, '', $message);
    }

    public static function failed(string $category, string $message): self
    {
        return new self(false, $category, $message);
    }
}
