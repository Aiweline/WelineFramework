<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

use Weline\Framework\Database\Helper\Standar;

/**
 * PostgreSQL index-name mapping shared by CREATE, incremental DDL and schema reads.
 *
 * New names are truncated on a valid UTF-8 boundary and retain the complete
 * hash suffix. candidates() also keeps the historical 55+hash mapping so an
 * existing installation can converge without losing its index.
 */
final class PgsqlIndexName
{
    public const MAX_IDENTIFIER_BYTES = 63;

    public static function canonicalPhysical(string $table, string $logicalName): string
    {
        $name = Standar::getIndexName($table, self::clean($logicalName));
        $name = self::clean($name);
        if (strlen($name) > self::MAX_IDENTIFIER_BYTES) {
            $name = mb_strcut($name, 0, 54, 'UTF-8') . '_' . substr(md5($name), 0, 8);
        }

        return self::serverPhysical($name);
    }

    public static function legacyCanonicalPhysical(string $table, string $logicalName): string
    {
        $name = self::clean(Standar::getIndexName($table, self::clean($logicalName)));
        if (strlen($name) > self::MAX_IDENTIFIER_BYTES) {
            $name = substr($name, 0, 55) . '_' . substr(md5($name), 0, 8);
        }
        return strlen($name) > self::MAX_IDENTIFIER_BYTES
            ? substr($name, 0, self::MAX_IDENTIFIER_BYTES)
            : $name;
    }

    public static function rawPhysical(string $logicalName): string
    {
        return self::serverPhysical(self::clean($logicalName));
    }

    /** @return list<string> */
    public static function candidates(string $table, string $logicalName): array
    {
        return array_values(array_unique(array_filter([
            self::rawPhysical($logicalName),
            self::canonicalPhysical($table, $logicalName),
            self::legacyCanonicalPhysical($table, $logicalName),
        ], static fn(string $candidate): bool => mb_check_encoding($candidate, 'UTF-8'))));
    }

    private static function serverPhysical(string $name): string
    {
        return strlen($name) > self::MAX_IDENTIFIER_BYTES
            ? mb_strcut($name, 0, self::MAX_IDENTIFIER_BYTES, 'UTF-8')
            : $name;
    }

    private static function clean(string $name): string
    {
        return trim(str_replace(['`', '"'], '', $name));
    }
}
