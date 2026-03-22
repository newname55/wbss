<?php
declare(strict_types=1);

namespace DakeLife\Support;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;

final class Clock
{
    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone('Asia/Tokyo');
    }

    public static function now(): DateTime
    {
        return new DateTime('now', self::timezone());
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function normalizeDate(?string $value, ?string $fallback = null): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return $fallback !== null ? $fallback : self::today();
        }

        $date = DateTime::createFromFormat('Y-m-d', $trimmed, self::timezone());
        if (!$date || $date->format('Y-m-d') !== $trimmed) {
            throw new InvalidArgumentException('Invalid date format. Expected Y-m-d.');
        }

        return $trimmed;
    }

    public static function normalizeDateTime(?string $value, ?string $date = null): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'c',
        ];

        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $trimmed, self::timezone());
            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $baseDate = $date !== null ? self::normalizeDate($date) : self::today();
        $time = DateTime::createFromFormat('H:i:s', $trimmed, self::timezone());
        if ($time instanceof DateTime) {
            return $baseDate . ' ' . $time->format('H:i:s');
        }

        $time = DateTime::createFromFormat('H:i', $trimmed, self::timezone());
        if ($time instanceof DateTime) {
            return $baseDate . ' ' . $time->format('H:i:s');
        }

        throw new InvalidArgumentException('Invalid datetime format.');
    }
}
