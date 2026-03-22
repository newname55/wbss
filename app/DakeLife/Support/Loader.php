<?php
declare(strict_types=1);

namespace DakeLife\Support;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

final class Loader
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register(static function (string $class): void {
            $prefix = 'DakeLife\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', '/', $relative) . '.php';
            $fullPath = dirname(__DIR__) . '/' . $relativePath;
            if (is_file($fullPath)) {
                require_once $fullPath;
            }
        });

        self::$registered = true;
    }
}
