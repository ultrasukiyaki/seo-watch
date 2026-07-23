<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeZone;

final class TimezoneService
{
    public const FALLBACK = 'UTC';

    /** @var array<string,true>|null */
    private static ?array $identifiers = null;

    public static function isValid(string $identifier): bool
    {
        self::$identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(), true);
        return isset(self::$identifiers[$identifier]) || $identifier === 'UTC';
    }

    /** @return list<string> */
    public static function identifiers(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public static function phpDefaultOrUtc(): string
    {
        $timezone = date_default_timezone_get();
        return self::isValid($timezone) ? $timezone : self::FALLBACK;
    }
}
