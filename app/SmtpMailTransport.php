<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class SmtpMailTransport implements MailTransportInterface
{
    /** @param array<string,mixed> $settings */
    public function __construct(private readonly array $settings, private readonly string $password)
    {
    }

    public function send(MailMessage $message): MailResult
    {
        return $this->run($message);
    }

    public function testConnection(): MailResult
    {
        return $this->run(null);
    }

    private function run(?MailMessage $message): MailResult
    {
        $socket = null;
        try {
            $host = (string)$this->settings['smtp_host'];
            $port = (int)$this->settings['smtp_port'];
            $timeout = (int)$this->settings['smtp_timeout'];
            $scheme = $this->settings['smtp_encryption'] === 'tls' ? 'tls' : 'tcp';
            $context = stream_context_create(['ssl' => [
                'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
                'peer_name' => $host, 'SNI_enabled' => true,
            ]]);
            $errorNo = 0;
            $error = '';
            $socket = @stream_socket_client(
                "{$scheme}://{$host}:{$port}", $errorNo, $error, $timeout,
                STREAM_CLIENT_CONNECT, $context
            );
            if (!is_resource($socket)) {
                return MailResult::failed($errorNo === 0 ? 'dns' : 'connection', 'SMTPサーバーへ接続できません。');
            }
            stream_set_timeout($socket, $timeout);
            $this->expect($socket, [220]);
            $caps = $this->hello($socket);
            if ($this->settings['smtp_encryption'] === 'starttls') {
                if (!str_contains(strtoupper($caps), 'STARTTLS')) {
                    throw new SmtpException('tls', 'SMTPサーバーがSTARTTLSを提供していません。');
                }
                $this->command($socket, "STARTTLS\r\n", [220], 'tls');
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new SmtpException('tls', 'TLS接続を確立できません。');
                }
                $caps = $this->hello($socket);
            }
            if (!empty($this->settings['smtp_auth_enabled'])) {
                $this->authenticate($socket, $caps);
            }
            if ($message !== null) {
                $from = (string)($this->settings['envelope_from'] ?: $this->settings['from_address']);
                $this->command($socket, "MAIL FROM:<{$from}>\r\n", [250], 'sender_rejected');
                $this->command($socket, "RCPT TO:<{$message->to}>\r\n", [250, 251], 'recipient_rejected');
                $this->command($socket, "DATA\r\n", [354], 'message_rejected');
                $raw = MailFormatter::format($message, $this->settings);
                $raw = preg_replace('/(?m)^\\./', '..', $raw) ?? $raw;
                fwrite($socket, $raw . ".\r\n");
                $this->expect($socket, [250], 'message_rejected');
            }
            $this->command($socket, "QUIT\r\n", [221]);
            return MailResult::ok($message === null ? 'SMTP接続、TLS、認証に成功しました。メール送信は未実施です。' : '送信しました。');
        } catch (SmtpException $e) {
            return MailResult::failed($e->category, $e->getMessage());
        } catch (\Throwable) {
            return MailResult::failed('unknown', 'SMTP処理に失敗しました。');
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** @param resource $socket */
    private function hello($socket): string
    {
        try {
            return $this->command($socket, "EHLO seo-watch\r\n", [250]);
        } catch (SmtpException) {
            return $this->command($socket, "HELO seo-watch\r\n", [250]);
        }
    }

    /** @param resource $socket */
    private function authenticate($socket, string $caps): void
    {
        $user = (string)$this->settings['smtp_username'];
        if ($user === '' || $this->password === '') {
            throw new SmtpException('configuration', 'SMTP認証情報が未設定です。');
        }
        if (str_contains(strtoupper($caps), 'PLAIN')) {
            $value = base64_encode("\0{$user}\0{$this->password}");
            $this->command($socket, "AUTH PLAIN {$value}\r\n", [235], 'authentication');
            return;
        }
        $this->command($socket, "AUTH LOGIN\r\n", [334], 'authentication');
        $this->command($socket, base64_encode($user) . "\r\n", [334], 'authentication');
        $this->command($socket, base64_encode($this->password) . "\r\n", [235], 'authentication');
    }

    /** @param resource $socket @param list<int> $codes */
    private function command($socket, string $command, array $codes, string $category = 'connection'): string
    {
        if (fwrite($socket, $command) === false) {
            throw new SmtpException('connection', 'SMTP接続への書き込みに失敗しました。');
        }
        return $this->expect($socket, $codes, $category);
    }

    /** @param resource $socket @param list<int> $codes */
    private function expect($socket, array $codes, string $category = 'connection'): string
    {
        $response = '';
        do {
            $line = fgets($socket, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new SmtpException(!empty($meta['timed_out']) ? 'timeout' : $category, 'SMTPサーバーから応答がありません。');
            }
            $response .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');
        $code = (int)substr($line, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new SmtpException($category, 'SMTPサーバーが処理を拒否しました（応答コード ' . $code . '）。');
        }
        return $response;
    }
}

final class SmtpException extends \RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }
}
