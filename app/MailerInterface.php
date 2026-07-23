<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

interface MailerInterface
{
    public function enabled(): bool;

    public function send(string $to, string $subject, string $body): bool;
}
