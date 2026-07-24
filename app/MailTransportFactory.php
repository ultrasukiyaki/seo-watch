<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class MailTransportFactory
{
    public function __construct(private readonly MailSettingsRepository $repository)
    {
    }

    /** @param array<string,mixed>|null $settings */
    public function create(?array $settings = null): MailTransportInterface
    {
        $settings ??= $this->repository->get();
        return match ($settings['transport'] ?? 'disabled') {
            'php_mail' => new PhpMailTransport($settings),
            'smtp' => new SmtpMailTransport($settings, $this->repository->password($settings)),
            default => new NullMailTransport(),
        };
    }
}
