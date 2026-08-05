<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Byte-bounded text that is always safe to place in a JSON protocol record.
 */
final class GatewayBoundedText
{
    public static function singleLine(
        string $value,
        int $maximumBytes,
        string $fallback = '',
    ): string {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Gateway text bound must be positive.');
        }
        $value = \str_replace("\0", '', $value);
        $value = \preg_replace('/[\x01-\x1f\x7f]+/', ' ', $value) ?? '';
        $value = \trim(\preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            $value = $fallback;
        }
        return self::prefix($value, $maximumBytes, $fallback);
    }

    public static function prefix(
        string $value,
        int $maximumBytes,
        string $fallback = '',
    ): string {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Gateway text bound must be positive.');
        }
        $value = \substr($value, 0, $maximumBytes);
        while ($value !== '' && \json_encode($value) === false) {
            $value = \substr($value, 0, -1);
        }
        if ($value !== '') {
            return $value;
        }
        $fallback = \substr($fallback, 0, $maximumBytes);
        while ($fallback !== '' && \json_encode($fallback) === false) {
            $fallback = \substr($fallback, 0, -1);
        }
        return $fallback;
    }

    public static function tail(string $value, int $maximumBytes): string
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Gateway text bound must be positive.');
        }
        if (\strlen($value) > $maximumBytes) {
            $value = \substr($value, -$maximumBytes);
        }
        while ($value !== '' && \json_encode($value) === false) {
            $value = \substr($value, 1);
        }
        return $value;
    }
}
