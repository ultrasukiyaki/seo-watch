<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SearchConsoleDate
{
    public const TIMEZONE = 'America/Los_Angeles';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function today(): string
    {
        return $this->clock->nowUtc()->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    public function importRange(int $days, int $lagDays): array
    {
        $days = max(1, min(365, $days));
        $lagDays = max(1, min(7, $lagDays));
        $end = new DateTimeImmutable($this->today(), new DateTimeZone(self::TIMEZONE));
        $end = $end->modify("-{$lagDays} days");
        return [
            'start' => $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    public function validate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TIMEZONE));
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Search Console基準日が不正です。');
        }
        return $date;
    }
}
