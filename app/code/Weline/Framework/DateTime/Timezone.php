<?php

declare(strict_types=1);

namespace Weline\Framework\DateTime;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Weline\Framework\Runtime\RequestContext;

/**
 * Website-local wall clock ↔ UTC SQL facade for schedule windows.
 *
 * Business modules MUST use this class for start/end / timed-activation paths.
 * Do not compare windows with process-local date()/strtotime().
 */
final class Timezone
{
    public const SQL_FORMAT = 'Y-m-d H:i:s';
    public const LOCAL_INPUT_FORMAT = 'Y-m-d\TH:i';
    public const FALLBACK_TIMEZONE = 'UTC';

    public static function resolveWebsiteTimezone(?string $explicit = null): string
    {
        $candidates = [
            trim((string)$explicit),
            trim(RequestContext::getWelineTimezone()),
        ];
        if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
            try {
                $fromWebsite = \Weline\Websites\Data\WebsiteData::getDefaultTimezone();
                if (\is_string($fromWebsite)) {
                    $candidates[] = trim($fromWebsite);
                }
            } catch (Throwable) {
                // Website module optional at bootstrap.
            }
        }
        $candidates[] = self::FALLBACK_TIMEZONE;

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            try {
                new DateTimeZone($candidate);

                return $candidate;
            } catch (Throwable) {
                continue;
            }
        }

        return self::FALLBACK_TIMEZONE;
    }

    public static function localInputToUtcSql(string $input, ?string $timezone = null): ?string
    {
        $normalized = self::normalizeLocalInput($input);
        if ($normalized === null) {
            return null;
        }
        $tz = new DateTimeZone(self::resolveWebsiteTimezone($timezone));
        try {
            $local = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $tz);
            if ($local === false) {
                return null;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if (\is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                return null;
            }

            return $local->setTimezone(new DateTimeZone('UTC'))->format(self::SQL_FORMAT);
        } catch (Throwable) {
            return null;
        }
    }

    public static function utcSqlToLocalInput(string $utcSql, ?string $timezone = null): string
    {
        $utc = self::parseUtcSql($utcSql);
        if ($utc === null) {
            return '';
        }
        $tz = new DateTimeZone(self::resolveWebsiteTimezone($timezone));

        return $utc->setTimezone($tz)->format(self::LOCAL_INPUT_FORMAT);
    }

    public static function utcSqlToLocalDisplay(
        string $utcSql,
        ?string $timezone = null,
        string $format = self::SQL_FORMAT,
    ): string {
        $utc = self::parseUtcSql($utcSql);
        if ($utc === null) {
            return '';
        }
        $tz = new DateTimeZone(self::resolveWebsiteTimezone($timezone));

        return $utc->setTimezone($tz)->format($format);
    }

    public static function utcNowSql(): string
    {
        return \gmdate(self::SQL_FORMAT);
    }

    public static function isWithinUtcWindow(
        ?string $startUtc,
        ?string $endUtc,
        ?string $nowUtc = null,
    ): bool {
        $now = trim((string)($nowUtc ?? self::utcNowSql()));
        if ($now === '' || self::parseUtcSql($now) === null) {
            return false;
        }
        $start = trim((string)$startUtc);
        $end = trim((string)$endUtc);
        if ($start !== '') {
            if (self::parseUtcSql($start) === null || $now < $start) {
                return false;
            }
        }
        if ($end !== '') {
            if (self::parseUtcSql($end) === null || $now > $end) {
                return false;
            }
        }

        return true;
    }

    public static function migrateNaiveLocalToUtcSql(string $naive, ?string $timezone = null): string
    {
        $converted = self::localInputToUtcSql($naive, $timezone);
        if ($converted !== null) {
            return $converted;
        }
        $trimmed = trim($naive);

        return $trimmed !== '' ? $trimmed : self::utcNowSql();
    }

    private static function normalizeLocalInput(string $input): ?string
    {
        $value = trim(str_replace('T', ' ', $input));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            $value .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $value) === 1) {
            $value .= ':00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private static function parseUtcSql(string $utcSql): ?DateTimeImmutable
    {
        $value = trim(str_replace('T', ' ', $utcSql));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $value) === 1) {
            $value .= ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            return null;
        }
        try {
            $dt = DateTimeImmutable::createFromFormat(self::SQL_FORMAT, $value, new DateTimeZone('UTC'));
            if ($dt === false) {
                return null;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if (\is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                return null;
            }

            return $dt;
        } catch (Throwable) {
            return null;
        }
    }
}
