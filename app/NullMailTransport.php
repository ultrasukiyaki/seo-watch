<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class NullMailTransport implements MailTransportInterface
{
    public function send(MailMessage $message): MailResult
    {
        return MailResult::failed('disabled', 'メール配送は無効です。');
    }
}
