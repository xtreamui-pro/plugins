<?php
/**
 * Loads config.php and refuses to run when it is unsafe: readable by other
 * system users, or placed inside the web-exposed public/ folder.
 */

declare(strict_types=1);

namespace XtreamPro\Bridge;

class Config
{
    private array $data;
    private string $root;

    private function __construct(array $data, string $root)
    {
        $this->data = $data;
        $this->root = $root;
    }

    /**
     * @param string $path Absolute path of config.php
     * @param string $root Application root (the folder holding public/, src/, var/)
     * @throws \RuntimeException with a readable message
     */
    public static function load(string $path, string $root): Config
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException('config.php not found. Copy config.sample.php to config.php and fill it in.');
        }
        $public = realpath($root . '/public');
        if ($public !== false && strpos($real . '/', $public . '/') === 0) {
            throw new \RuntimeException('config.php must not be inside public/ (it would be served to the web). Move it next to public/.');
        }
        $perms = fileperms($real);
        if ($perms !== false && ($perms & 0007) !== 0) {
            throw new \RuntimeException('config.php is readable by other users. Run: chmod 600 config.php');
        }
        $data = (static function (string $file) {
            return include $file;
        })($real);
        if (!is_array($data)) {
            throw new \RuntimeException('config.php must return an array.');
        }
        return new Config($data, $root);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Value at a dotted path, e.g. get('mail.smtp.host'). */
    public function get(string $path, $default = null)
    {
        $node = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public function string(string $path, string $default = ''): string
    {
        $v = $this->get($path, $default);
        return is_string($v) ? trim($v) : $default;
    }

    /** Folder for the database and the log (default: var/ next to public/). */
    public function dataDir(): string
    {
        $dir = $this->string('data_dir', $this->root . '/var');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the data folder: ' . $dir);
        }
        return rtrim($dir, '/');
    }

    /** Product map of one platform: key => settings. */
    public function products(string $platform): array
    {
        $map = $this->get('products.' . $platform, []);
        return is_array($map) ? $map : [];
    }

    /** Every secret value of the config, for the log scrubber. */
    public function secrets(): array
    {
        $out = [$this->string('api_key')];
        $secrets = $this->get('secrets', []);
        if (is_array($secrets)) {
            foreach ($secrets as $s) {
                $out[] = is_string($s) ? $s : '';
            }
        }
        $out[] = $this->string('mail.smtp.password');
        return array_values(array_filter($out, static fn ($s) => $s !== ''));
    }
}
