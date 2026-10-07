<?php
/**
 * Class loader for the bridge: XtreamPro\Bridge\Foo\Bar -> src/Foo/Bar.php.
 * No Composer needed.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'XtreamPro\\Bridge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
