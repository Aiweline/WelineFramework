<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Platform-aware TCP keep-alive for accepted client sockets/streams.
 *
 * macOS PHP exposes TCP_KEEPALIVE (idle seconds) but not Linux TCP_KEEPIDLE /
 * TCP_KEEPINTVL / TCP_KEEPCNT. Enabling only SO_KEEPALIVE keeps the OS default
 * idle (often ~2h), so a half-open peer after Worker death leaves browsers in
 * Network "pending" until that default fires.
 */
final class ClientTcpKeepAliveTuner
{
    /** Storefront: detect half-open peers within ~30s (idle + interval×probes). */
    public const IDLE_SEC = 15;
    public const INTERVAL_SEC = 5;
    public const PROBES = 3;

    /**
     * @return array{
     *     so_keepalive:bool,
     *     tcp_nodelay:bool,
     *     idle_option:?string,
     *     idle_sec:int,
     *     interval_option:?string,
     *     interval_sec:int,
     *     probes_option:?string,
     *     probes:int
     * }
     */
    public static function plan(): array
    {
        $idleOption = null;
        if (\defined('TCP_KEEPIDLE')) {
            $idleOption = 'TCP_KEEPIDLE';
        } elseif (\defined('TCP_KEEPALIVE')) {
            // Darwin: TCP_KEEPALIVE is the idle timeout before probes start.
            $idleOption = 'TCP_KEEPALIVE';
        }

        $intervalOption = \defined('TCP_KEEPINTVL') ? 'TCP_KEEPINTVL' : null;
        $probesOption = \defined('TCP_KEEPCNT') ? 'TCP_KEEPCNT' : null;

        return [
            'so_keepalive' => true,
            'tcp_nodelay' => \defined('TCP_NODELAY') && \defined('SOL_TCP'),
            'idle_option' => $idleOption,
            'idle_sec' => self::IDLE_SEC,
            'interval_option' => $intervalOption,
            'interval_sec' => self::INTERVAL_SEC,
            'probes_option' => $probesOption,
            'probes' => self::PROBES,
        ];
    }

    public static function applyToSocket(mixed $socket): bool
    {
        if (!$socket instanceof \Socket && !\is_resource($socket)) {
            return false;
        }

        $plan = self::plan();
        try {
            @\socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
            if ($plan['tcp_nodelay']) {
                @\socket_set_option($socket, \SOL_TCP, (int)\TCP_NODELAY, 1);
            }
            if ($plan['idle_option'] !== null) {
                @\socket_set_option(
                    $socket,
                    \SOL_TCP,
                    (int)\constant($plan['idle_option']),
                    $plan['idle_sec'],
                );
            }
            if ($plan['interval_option'] !== null) {
                @\socket_set_option(
                    $socket,
                    \SOL_TCP,
                    (int)\constant($plan['interval_option']),
                    $plan['interval_sec'],
                );
            }
            if ($plan['probes_option'] !== null) {
                @\socket_set_option(
                    $socket,
                    \SOL_TCP,
                    (int)\constant($plan['probes_option']),
                    $plan['probes'],
                );
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public static function applyToStream(mixed $stream): bool
    {
        if (!\is_resource($stream) || !\function_exists('socket_import_stream')) {
            return false;
        }

        $socket = @\socket_import_stream($stream);
        if (!$socket instanceof \Socket) {
            return false;
        }

        return self::applyToSocket($socket);
    }
}
