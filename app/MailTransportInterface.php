<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

interface MailTransportInterface
{
    public function send(MailMessage $message): MailResult;
}
