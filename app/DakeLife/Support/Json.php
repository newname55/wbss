<?php
declare(strict_types=1);

namespace DakeLife\Support;

final class Json
{
    public static function decodeObject(?string $json): array
    {
        $trimmed = trim((string) $json);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
