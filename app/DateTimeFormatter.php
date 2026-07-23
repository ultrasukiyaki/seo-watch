<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeImmutable;
use DateTimeZone;

final class DateTimeFormatter
{
    private DateTimeZone $displayTimezone;
    private DateTimeZone $utc;

    public function __construct(private readonly Clock $clock, string $displayTimezone)
    {
        if (!TimezoneService::isValid($displayTimezone)) {
            $displayTimezone = TimezoneService::FALLBACK;
        }
        $this->displayTimezone = new DateTimeZone($displayTimezone);
        $this->utc = new DateTimeZone('UTC');
    }

    public function timezoneName(): string
    {
        return $this->displayTimezone->getName();
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->clock->nowUtc();
    }

    public function nowDisplay(): DateTimeImmutable
    {
        return $this->nowUtc()->setTimezone($this->displayTimezone);
    }

    public function parseDatabase(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $this->utc);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))
            || $parsed->format('Y-m-d H:i:s') !== $value) {
            error_log('Invalid UTC database datetime encountered');
            return null;
        }
        return $parsed;
    }

    public function database(DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->utc)->format('Y-m-d H:i:s');
    }

    public function isoUtc(DateTimeImmutable|string|null $value): ?string
    {
        $date = $this->coerce($value);
        return $date?->setTimezone($this->utc)->format('Y-m-d\TH:i:s\Z');
    }

    public function short(DateTimeImmutable|string|null $value, string $fallback = '—'): string
    {
        return $this->coerce($value)?->setTimezone($this->displayTimezone)->format('Y-m-d H:i') ?? $fallback;
    }

    public function detail(DateTimeImmutable|string|null $value, string $fallback = '—'): string
    {
        return $this->coerce($value)?->setTimezone($this->displayTimezone)->format('Y-m-d H:i:s T') ?? $fallback;
    }

    public function mail(DateTimeImmutable|string|null $value, string $fallback = '—'): string
    {
        $formatted = $this->detail($value, $fallback);
        return $formatted === $fallback ? $fallback : $formatted . ' (' . $this->timezoneName() . ')';
    }

    public function time(DateTimeImmutable|string|null $value, bool $detailed = true): string
    {
        $date = $this->coerce($value);
        if (!$date) {
            return '—';
        }
        $label = $detailed ? $this->detail($date) : $this->short($date);
        return '<time datetime="' . View::e((string)$this->isoUtc($date)) . '">' . View::e($label) . '</time>';
    }

    public function localDateBoundaryToUtc(string $date, bool $endExclusive = false): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $date . ' 00:00:00',
            $this->displayTimezone
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))
            || $parsed->format('Y-m-d') !== $date) {
            return null;
        }
        if ($endExclusive) {
            $parsed = $parsed->modify('+1 day');
        }
        return $this->database($parsed);
    }

    private function coerce(DateTimeImmutable|string|null $value): ?DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : $this->parseDatabase($value);
    }
}
