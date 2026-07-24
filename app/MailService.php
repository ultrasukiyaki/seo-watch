<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class MailService implements MailerInterface
{
    public function __construct(
        private readonly string $transportName,
        private readonly MailTransportInterface $transport
    ) {
    }

    public function enabled(): bool
    {
        return $this->transportName !== 'disabled';
    }

    public function send(string $to, string $subject, string $body): bool
    {
        return $this->transport->send(new MailMessage($to, $subject, $body))->success;
    }

    public function sendMessage(MailMessage $message): MailResult
    {
        return $this->transport->send($message);
    }
}
