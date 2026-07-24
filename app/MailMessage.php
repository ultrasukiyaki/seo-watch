<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use InvalidArgumentException;

final class MailMessage
{
    public readonly string $to;
    public readonly string $subject;
    public readonly string $body;

    public function __construct(string $to, string $subject, string $body)
    {
        $this->to = EmailAddress::normalize($to);
        if ($subject === '' || preg_match('/[\r\n]/', $subject)) {
            throw new InvalidArgumentException('メール件名が不正です。');
        }
        $this->subject = $subject;
        $this->body = str_replace(["\r\n", "\r"], "\n", $body);
    }
}
