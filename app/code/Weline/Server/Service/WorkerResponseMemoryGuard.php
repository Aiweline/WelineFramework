<?php
declare(strict_types=1);

namespace Weline\Server\Service;

final class WorkerResponseMemoryGuard
{
    public const LARGE_RESPONSE_BYTES = 262144;
    public const LARGE_BUFFER_BYTES = 524288;

    public static function shouldForceConnectionClose(
        bool $keepAlive,
        bool $isLongLivedProtocol,
        int $responseBytes,
        int $bufferedBytes = 0
    ): bool {
        if (!$keepAlive || $isLongLivedProtocol) {
            return false;
        }

        if ($responseBytes >= self::LARGE_RESPONSE_BYTES) {
            return true;
        }

        if ($bufferedBytes >= self::LARGE_BUFFER_BYTES) {
            return true;
        }

        return ($responseBytes + $bufferedBytes) >= self::LARGE_BUFFER_BYTES;
    }

    public static function forceConnectionCloseHeader(string $httpResponse): string
    {
        $headerEnd = \strpos($httpResponse, "\r\n\r\n");
        if ($headerEnd === false) {
            return $httpResponse;
        }

        $headers = \substr($httpResponse, 0, $headerEnd);
        $body = \substr($httpResponse, $headerEnd);

        if (\preg_match('/\r\nConnection:\s*[^\r\n]*/i', $headers) === 1) {
            $headers = (string) \preg_replace(
                '/\r\nConnection:\s*[^\r\n]*/i',
                "\r\nConnection: close",
                $headers,
                1
            );
        } else {
            $headers .= "\r\nConnection: close";
        }

        return $headers . $body;
    }

    public static function shouldCompactAfterDrain(int $releasedBytes): bool
    {
        return $releasedBytes >= self::LARGE_RESPONSE_BYTES;
    }

    /**
     * @return array{cycles:int, trimmed_bytes:int}
     */
    public static function compact(): array
    {
        $cycles = \gc_collect_cycles();
        $trimmedBytes = 0;

        if (\function_exists('gc_mem_caches')) {
            $trimmedBytes = \max(0, (int) \gc_mem_caches());
        }

        return [
            'cycles' => $cycles,
            'trimmed_bytes' => $trimmedBytes,
        ];
    }
}
