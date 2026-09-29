<?php

declare(strict_types=1);

namespace Checkmate\Support;

/**
 * Clock abstraction. All timestamps in the app are UTC; SQL comparisons use
 * values produced here so tests can manipulate stored rows deterministically.
 */
final class Clock
{
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function unix(): int
    {
        return time();
    }

    /** SQL-friendly UTC datetime: 2026-09-29 20:00:00 */
    public static function sql(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    public static function sqlNow(): string
    {
        return self::sql(self::now());
    }

    /** ISO-8601 with offset, e.g. 2026-09-29T20:00:00+00:00 */
    public static function iso(\DateTimeImmutable $at): string
    {
        return $at->format(\DateTimeImmutable::ATOM);
    }

    public static function isoNow(): string
    {
        return self::iso(self::now());
    }

    /** Parse a stored SQL datetime (UTC) into DateTimeImmutable. */
    public static function fromSql(string $sqlDateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($sqlDateTime, new \DateTimeZone('UTC'));
    }
}
