<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class DisabledMailer implements MailerInterface
{
    public function enabled(): bool
    {
        return false;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        return false;
    }
}
