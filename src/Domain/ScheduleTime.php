<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class ScheduleTime
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const PAST = 'past';
    public const FAR = 'far';

    /**
     * @return array{status:string, at:?DateTimeImmutable}
     */
    public static function parse(string $text, DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $clean = self::normalize($text);
        $at = null;

        if (preg_match('/^hoje\s+(\d{1,2})(?::(\d{2}))?$/', $clean, $m) === 1) {
            $at = self::onDay($now, (int) $m[1], (int) ($m[2] ?? 0));
        } elseif (preg_match('/^amanha\s+(\d{1,2})(?::(\d{2}))?$/', $clean, $m) === 1) {
            $at = self::onDay($now->modify('+1 day'), (int) $m[1], (int) ($m[2] ?? 0));
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?\s+(\d{1,2})(?::(\d{2}))?$/', $clean, $m) === 1) {
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $now->format('Y');
            $at = self::date($year, (int) $m[2], (int) $m[1], (int) $m[4], (int) ($m[5] ?? 0));
            if ($at !== null && ($m[3] === '') && $at < $now->setTime(0, 0)) {
                $at = $at->modify('+1 year');
            }
        } elseif (preg_match('/^(\d{1,2}):(\d{2})$/', $clean, $m) === 1) {
            $at = self::onDay($now, (int) $m[1], (int) $m[2]);
            if ($at !== null && $at <= $now) {
                $at = self::onDay($now->modify('+1 day'), (int) $m[1], (int) $m[2]);
            }
        } else {
            return ['status' => self::INVALID, 'at' => null];
        }

        if ($at === null) {
            return ['status' => self::INVALID, 'at' => null];
        }

        return self::accept($at, $now);
    }

    /**
     * @return array{status:string, at:?DateTimeImmutable}
     */
    public static function accept(DateTimeImmutable $at, DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $at = $at->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        if ($at < $now->modify('+2 minutes')) {
            return ['status' => self::PAST, 'at' => null];
        }
        if ($at > $now->modify('+30 days')) {
            return ['status' => self::FAR, 'at' => null];
        }

        return ['status' => self::OK, 'at' => $at];
    }

    public static function label(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s');
    }

    private static function normalize(string $text): string
    {
        $clean = mb_strtolower(trim($text));
        $clean = str_replace(
            ["\u{00e3}", "\u{00e1}", "\u{00e0}", "\u{00e2}", "\u{00e9}", "\u{00ea}"],
            ['a', 'a', 'a', 'a', 'e', 'e'],
            $clean,
        );
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = str_replace(' as ', ' ', ' ' . $clean . ' ');
        $clean = trim($clean);
        $clean = preg_replace('/(\d{1,2})\s*h\s*(\d{2})\b/u', '$1:$2', $clean) ?? $clean;
        $clean = preg_replace('/(\d{1,2})\s*h\b/u', '$1:00', $clean) ?? $clean;

        return trim($clean);
    }

    private static function onDay(DateTimeImmutable $day, int $hour, int $minute): ?DateTimeImmutable
    {
        return self::date((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'), $hour, $minute);
    }

    private static function date(int $year, int $month, int $day, int $hour, int $minute): ?DateTimeImmutable
    {
        if ($hour > 23 || $minute > 59 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        $tz = new DateTimeZone('America/Sao_Paulo');
        $at = DateTimeImmutable::createFromFormat(
            '!Y-n-j G:i',
            sprintf('%d-%d-%d %d:%02d', $year, $month, $day, $hour, $minute),
            $tz,
        );
        if ($at === false) {
            return null;
        }
        if ((int) $at->format('Y') !== $year || (int) $at->format('n') !== $month || (int) $at->format('j') !== $day) {
            return null;
        }

        return $at;
    }
}
