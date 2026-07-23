<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeImmutable;
use DateTimeZone;

final class FrozenClock implements Clock
{
    private DateTimeImmutable $instant;

    public function __construct(DateTimeImmutable|string $instant)
    {
        $this->instant = is_string($instant)
            ? new DateTimeImmutable($instant, new DateTimeZone('UTC'))
            : $instant;
        $this->instant = $this->instant->setTimezone(new DateTimeZone('UTC'));
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->instant;
    }
}
