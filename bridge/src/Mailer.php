<?php
/**
 * Sends the buyer their credentials. Plain text, UTF-8, one recipient: the
 * customer who bought. Never copied to anyone else.
 *
 * Transport (config "mail.transport"):
 *   mail  PHP mail() - the default, uses the server's sendmail
 *   smtp  own SMTP client (mail.smtp: host, port, encryption ssl|tls|none, user, password)
 *   file  appends the message to mail.file instead of sending (for tests only)
 */

declare(strict_types=1);

namespace XtreamPro\Bridge;

class Mailer
{
    private string $transport;
    private string $from;
    private array $smtp;
    private string $file;

    public function __construct(Config $config)
    {
        $this->transport = $config->string('mail.transport', 'mail');
        $this->from = self::clean($config->string('mail.from', ''));
        $smtp = $config->get('mail.smtp', []);
        $this->smtp = is_array($smtp) ? $smtp : [];
        $this->file = $config->string('mail.file', '');
    }

    /** @throws \RuntimeException when the message could not be handed over */
    public function send(string $to, string $subject, string $body): void
    {
        $to = self::clean($to);
        $subject = self::clean($subject);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('The customer email address is not valid.');
        }
        if ($this->from === '' || filter_var($this->from, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('"mail.from" in config.php is not a valid email address.');
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        switch ($this->transport) {
            case 'file':
                $this->sendToFile($to, $subject, $body);
                return;
            case 'smtp':
                $this->sendSmtp($to, $subject, $body);
                return;
            case 'mail':
                $headers = "From: {$this->from}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
                $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
                if (!mail($to, $encoded, str_replace("\n", "\r\n", $body), $headers)) {
                    throw new \RuntimeException('mail() refused the message. Check the server\'s mail setup or use SMTP.');
                }
                return;
        }
        throw new \RuntimeException('Unknown "mail.transport": ' . $this->transport);
    }

    /** Header values must be one line. */
    private static function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    private function sendToFile(string $to, string $subject, string $body): void
    {
        if ($this->file === '') {
            throw new \RuntimeException('"mail.file" is not set.');
        }
        $record = json_encode(['to' => $to, 'subject' => $subject, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents($this->file, $record, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write mail.file.');
        }
        @chmod($this->file, 0600);
    }

    private function sendSmtp(string $to, string $subject, string $body): void
    {
        $host = (string) ($this->smtp['host'] ?? '');
        $enc = (string) ($this->smtp['encryption'] ?? 'tls');
        $port = (int) ($this->smtp['port'] ?? ($enc === 'ssl' ? 465 : 587));
        if ($host === '') {
            throw new \RuntimeException('"mail.smtp.host" is not set.');
        }
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        if (!$fp) {
            throw new \RuntimeException('Cannot connect to the SMTP server.');
        }
        stream_set_timeout($fp, 15);
        try {
            $this->expect($fp, '220');
            $this->command($fp, 'EHLO bridge.local', '250');
            if ($enc === 'tls') {
                $this->command($fp, 'STARTTLS', '220');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS failed.');
                }
                $this->command($fp, 'EHLO bridge.local', '250');
            }
            $user = (string) ($this->smtp['user'] ?? '');
            if ($user !== '') {
                $this->command($fp, 'AUTH LOGIN', '334');
                $this->command($fp, base64_encode($user), '334');
                $this->command($fp, base64_encode((string) ($this->smtp['password'] ?? '')), '235');
            }
            $this->command($fp, 'MAIL FROM:<' . $this->from . '>', '250');
            $this->command($fp, 'RCPT TO:<' . $to . '>', '250');
            $this->command($fp, 'DATA', '354');
            $message = 'From: ' . $this->from . "\r\nTo: " . $to
                . "\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?="
                . "\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . str_replace("\n", "\r\n", $body);
            // Dot-stuffing: a line starting with "." would end the message.
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($fp, $message . "\r\n.\r\n");
            $this->expect($fp, '250');
            $this->command($fp, 'QUIT', '221');
        } finally {
            fclose($fp);
        }
    }

    private function command($fp, string $line, string $expect): void
    {
        fwrite($fp, $line . "\r\n");
        $this->expect($fp, $expect);
    }

    private function expect($fp, string $code): void
    {
        $reply = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $reply .= $line;
            // A multi-line reply continues while the 4th character is "-".
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (strncmp($reply, $code, 3) !== 0) {
            // The server's text is not repeated: it could echo the credentials.
            throw new \RuntimeException('The SMTP server answered unexpectedly (expected ' . $code . ').');
        }
    }
}
