<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use DateTimeImmutable;

interface Clock
{
    public function nowUtc(): DateTimeImmutable;
}
