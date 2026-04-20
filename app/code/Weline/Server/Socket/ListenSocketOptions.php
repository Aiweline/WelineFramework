<?php
declare(strict_types=1);

namespace Weline\Server\Socket;

final class ListenSocketOptions
{
    public static function isWindows(?bool $isWindows = null): bool
    {
        if ($isWindows !== null) {
            return $isWindows;
        }

        if (\defined('PHP_OS_FAMILY')) {
            return \PHP_OS_FAMILY === 'Windows';
        }

        return \DIRECTORY_SEPARATOR === '\\' || \strncasecmp(\PHP_OS, 'WIN', 3) === 0;
    }

    /**
     * @param array<string, mixed> $socketOptions
     * @return array<string, mixed>
     */
    public static function streamContextOptions(array $socketOptions, ?bool $isWindows = null): array
    {
        if (self::isWindows($isWindows)) {
            unset($socketOptions['so_reuseaddr']);

            return $socketOptions;
        }

        $socketOptions['so_reuseaddr'] = true;

        return $socketOptions;
    }

    /**
     * @param resource $socket
     * @return array{attempted: bool, success: bool, label: string, errno: int, error: string}
     */
    public static function applyRawListenSocketReuseOption($socket, ?bool $isWindows = null): array
    {
        $isWindows = self::isWindows($isWindows);
        $label = $isWindows ? 'SO_EXCLUSIVEADDRUSE' : 'SO_REUSEADDR';

        if ($isWindows) {
            if (!\defined('SO_EXCLUSIVEADDRUSE')) {
                return [
                    'attempted' => false,
                    'success' => true,
                    'label' => $label,
                    'errno' => 0,
                    'error' => '',
                ];
            }

            $success = @\socket_set_option($socket, \SOL_SOCKET, \SO_EXCLUSIVEADDRUSE, 1);
        } else {
            $success = @\socket_set_option($socket, \SOL_SOCKET, \SO_REUSEADDR, 1);
        }

        if ($success) {
            return [
                'attempted' => true,
                'success' => true,
                'label' => $label,
                'errno' => 0,
                'error' => '',
            ];
        }

        $errno = \socket_last_error($socket);

        return [
            'attempted' => true,
            'success' => false,
            'label' => $label,
            'errno' => $errno,
            'error' => \socket_strerror($errno),
        ];
    }
}
