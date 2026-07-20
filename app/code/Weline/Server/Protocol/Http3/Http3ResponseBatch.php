<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Shared response batching budget for the PHP and native HTTP/3 data planes.
 *
 * The native write loop uses the same datagram count. Keeping the first PHP
 * response buffer inside one native write window avoids a second allocation
 * for the common warmed homepage response.
 */
final class Http3ResponseBatch
{
    public const MAX_WRITE_DATAGRAMS = 64;
    public const MAX_CHANNEL_DATAGRAM_BYTES = 1452;
    public const RESPONSE_BUFFER_FLOOR_BYTES =
        self::MAX_WRITE_DATAGRAMS * self::MAX_CHANNEL_DATAGRAM_BYTES;

    private function __construct()
    {
    }

    public static function bufferCapacity(
        int $requiredBytes,
        int $currentCapacity,
        int $maximumBytes,
    ): int {
        if ($requiredBytes < 1 || $maximumBytes < 1 || $requiredBytes > $maximumBytes) {
            throw new \LengthException('HTTP/3 response buffer cannot satisfy the requested capacity.');
        }

        $capacity = \max(
            \min($maximumBytes, self::RESPONSE_BUFFER_FLOOR_BYTES),
            \min($maximumBytes, \max(0, $currentCapacity)),
        );
        while ($capacity < $requiredBytes) {
            $next = \min($maximumBytes, $capacity * 2);
            if ($next <= $capacity) {
                throw new \LengthException('HTTP/3 response buffer cannot satisfy the requested capacity.');
            }
            $capacity = $next;
        }

        return $capacity;
    }
}
