<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class PhpMailTransport implements MailTransportInterface
{
    /** @param array<string,mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function send(MailMessage $message): MailResult
    {
        if (!function_exists('mail')) {
            return MailResult::failed('php_mail', 'PHP mail()を利用できません。');
        }
        $raw = MailFormatter::format($message, $this->settings);
        [$headerBlock, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = explode("\r\n", $headerBlock);
        $subject = '';
        $kept = [];
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Subject: ')) {
                $subject = substr($header, 9);
            } elseif (!str_starts_with($header, 'To: ')) {
                $kept[] = $header;
            }
        }
        $args = '';
        if (!empty($this->settings['envelope_from'])) {
            $args = '-f' . escapeshellarg((string)$this->settings['envelope_from']);
        }
        $ok = $args === ''
            ? mail($message->to, $subject, $body, implode("\r\n", $kept))
            : mail($message->to, $subject, $body, implode("\r\n", $kept), $args);
        return $ok
            ? MailResult::ok('mail()がメッセージを受け付けました。到達を保証するものではありません。')
            : MailResult::failed('php_mail', 'PHP mail()がメッセージを受け付けませんでした。');
    }
}
