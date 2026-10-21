<?php

declare(strict_types=1);

namespace Weline\Server\Service\Diagnostics;

use Weline\Framework\App\Env;
use Weline\Framework\Http\ResponseTerminateException;

/**
 * Production gate for WLS diagnostic endpoints (SSE probes, concurrency probes).
 *
 * DEV is always allowed. Outside DEV the endpoint exists only when
 * `wls.diagnostics.enabled` is explicitly on; otherwise it answers 404 so the
 * route's presence is not disclosed.
 */
final class DiagnosticEndpointGate
{
    public static function enabled(): bool
    {
        $dev = \defined('DEV') && DEV === true;
        try {
            $configured = Env::getInstance()->getConfig('wls.diagnostics.enabled');
        } catch (\Throwable) {
            $configured = null;
        }

        return self::allows($dev, $configured);
    }

    public static function allows(bool $dev, mixed $configured): bool
    {
        if ($dev) {
            return true;
        }
        if (\is_bool($configured)) {
            return $configured;
        }
        if (\is_int($configured)) {
            return $configured === 1;
        }
        if (\is_string($configured)) {
            return \in_array(
                \strtolower(\trim($configured)),
                ['1', 'true', 'yes', 'on'],
                true,
            );
        }

        return false;
    }

    public static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new ResponseTerminateException(
                404,
                'Not Found',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }
    }
}
