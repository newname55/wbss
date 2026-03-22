<?php
declare(strict_types=1);

namespace DakeLife\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = self::required('DAKE_LIFE_DB_HOST', 'WBSS_DB_HOST', 'SEIKA_DB_HOST');
        $port = self::port();
        $name = self::value('DAKE_LIFE_DB_NAME');
        if ($name === '') {
            $name = 'dake_life';
        }
        $user = self::required('DAKE_LIFE_DB_USER', 'WBSS_DB_USER', 'SEIKA_DB_USER');
        $pass = self::value('DAKE_LIFE_DB_PASS');
        if ($pass === '') {
            $pass = self::value('WBSS_DB_PASS', 'SEIKA_DB_PASS');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('DAKE_LIFE database connection failed', 0, $e);
        }

        return self::$pdo;
    }

    private static function required(string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = self::value($key);
            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('Missing required DB setting: ' . implode(' or ', $keys));
    }

    private static function port(): string
    {
        $value = self::value('DAKE_LIFE_DB_PORT');
        if ($value === '') {
            $value = self::value('WBSS_DB_PORT', 'SEIKA_DB_PORT');
        }
        if ($value === '') {
            return '3306';
        }
        if (!preg_match('/^\d{1,5}$/', $value)) {
            throw new RuntimeException('Invalid DAKE_LIFE DB port');
        }

        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid DAKE_LIFE DB port range');
        }

        return (string) $port;
    }

    private static function value(string ...$keys): string
    {
        foreach ($keys as $key) {
            if (\function_exists('conf')) {
                $value = trim((string) conf($key));
                if ($value !== '') {
                    return $value;
                }
            }

            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
