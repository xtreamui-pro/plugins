<?php
/**
 * Plain log file (var/bridge.log). Every line is scrubbed: configured secrets
 * and anything that looks like a line password never reach the file.
 * Event ids, order ids and error texts are logged; credentials and the
 * customer's email address are not.
 */

declare(strict_types=1);

namespace XtreamPro\Bridge;

class Logger
{
    private string $file;
    /** @var string[] */
    private array $secrets;

    /** @param string[] $secrets values to hide (API key, webhook secrets, SMTP password) */
    public function __construct(string $file, array $secrets)
    {
        $this->file = $file;
        $this->secrets = array_values(array_filter($secrets, static fn ($s) => is_string($s) && strlen($s) >= 4));
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function scrub(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, '********', $text);
        }
        $text = (string) preg_replace('/(password=)[^&"\\s\\\\]+/i', '$1********', $text);
        return (string) preg_replace('/("password"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"********"', $text);
    }

    private function write(string $level, string $message): void
    {
        // One line per entry: no line breaks smuggled in from a payload.
        $message = str_replace(["\r", "\n"], ' ', $this->scrub($message));
        $line = date('Y-m-d H:i:s') . ' ' . $level . ' ' . $message . "\n";
        $isNew = !is_file($this->file);
        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== false && $isNew) {
            @chmod($this->file, 0600);
        }
    }
}
