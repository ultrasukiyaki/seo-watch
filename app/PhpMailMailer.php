<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use InvalidArgumentException;

final class PhpMailMailer implements MailerInterface
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName
    ) {
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $fromAddress . $fromName) === 1) {
            throw new InvalidArgumentException('メール送信元設定が不正です。');
        }
    }

    public function enabled(): bool
    {
        return function_exists('mail');
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $to = EmailAddress::normalize($to);
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new InvalidArgumentException('メール件名が不正です。');
        }
        $encodedName = mb_encode_mimeheader($this->fromName, 'UTF-8');
        $headers = [
            'From: ' . $encodedName . ' <' . $this->fromAddress . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
    }
}
