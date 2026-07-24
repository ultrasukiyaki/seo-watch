<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch\Tests;

use Tenyendama\SeoWatch\MailerInterface;

final class FakeMailer implements MailerInterface
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $messages = [];
    public bool $sendResult = true;

    public function enabled(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $this->messages[] = compact('to', 'subject', 'body');
        return $this->sendResult;
    }
}
