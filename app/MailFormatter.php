<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use InvalidArgumentException;

final class MailFormatter
{
    /** @param array<string,mixed> $settings */
    public static function format(MailMessage $message, array $settings): string
    {
        $from = (string)$settings['from_address'];
        $name = (string)$settings['from_name'];
        foreach ([$from, $name, $message->to, $message->subject] as $value) {
            if (preg_match('/[\r\n]/', $value)) {
                throw new InvalidArgumentException('メールヘッダーに改行は使用できません。');
            }
        }
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'From: ' . self::phrase($name) . ' <' . $from . '>',
            'To: <' . $message->to . '>',
        ];
        if (!empty($settings['reply_to'])) {
            $headers[] = 'Reply-To: <' . $settings['reply_to'] . '>';
        }
        $headers[] = 'Subject: ' . mb_encode_mimeheader($message->subject, 'UTF-8', 'B', "\r\n");
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        return implode("\r\n", $headers) . "\r\n\r\n"
            . str_replace("\n", "\r\n", $message->body) . "\r\n";
    }

    public static function phrase(string $value): string
    {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }
}
