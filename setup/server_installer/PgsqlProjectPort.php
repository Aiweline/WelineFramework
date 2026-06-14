<?php

declare(strict_types=1);

final class PgsqlProjectPort
{
    public const MIN = 55432;
    public const MAX = 65535;
    public const RESERVED_DEFAULT = 5432;

    public static function preferredForProject(string $projectRoot): int
    {
        $normalized = strtolower(str_replace('\\', '/', rtrim($projectRoot, "\\/")));
        $range = self::MAX - self::MIN + 1;
        $seed = (int) hexdec(substr(hash('sha256', $normalized), 0, 8));
        return self::MIN + ($seed % $range);
    }

    public static function isValid(int $port): bool
    {
        return $port > 0 && $port <= 65535;
    }

    public static function isReservedDefault(int $port): bool
    {
        return $port === self::RESERVED_DEFAULT;
    }

    public static function normalizeCandidate(?int $port, string $projectRoot): int
    {
        if ($port !== null && self::isValid($port) && !self::isReservedDefault($port)) {
            return $port;
        }
        return self::preferredForProject($projectRoot);
    }

    public static function nextInProjectRange(int $port): int
    {
        $next = $port + 1;
        if ($next > self::MAX || $next < self::MIN) {
            return self::MIN;
        }
        return $next;
    }
}
