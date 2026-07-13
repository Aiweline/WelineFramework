<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Log\WlsLogger;

/**
 * Builds the public response for a Worker whose framework runtime failed to boot.
 *
 * Internal exception details are correlated by request id and written only to
 * the WLS log. The public response is deliberately topology/transport neutral.
 */
final class WorkerRuntimeFailureResponse
{
    /**
     * @param array<string, scalar|null> $context
     */
    public static function create(?string $internalDetail, array $context = []): string
    {
        $requestId = self::requestId();
        $logContext = [
            'request_id' => $requestId,
            'detail' => $internalDetail !== null ? \substr($internalDetail, 0, 16384) : '',
        ];
        foreach ($context as $name => $value) {
            if ($name === '' || (!\is_scalar($value) && $value !== null)) {
                continue;
            }
            $logContext[$name] = $value;
        }
        $encodedLog = \json_encode(
            $logContext,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        try {
            WlsLogger::error_(
                '[RuntimeInitializationFailure] ' . (\is_string($encodedLog) ? $encodedLog : "request_id={$requestId}"),
            );
        } catch (\Throwable) {
            // Runtime bootstrap is already unavailable; logging must not suppress the generic 500 response.
        }

        $body = \json_encode(
            [
                'error' => true,
                'message' => 'Internal Server Error',
                'request_id' => $requestId,
            ],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (!\is_string($body)) {
            $body = '{"error":true,"message":"Internal Server Error","request_id":"' . $requestId . '"}';
        }

        return "HTTP/1.1 500 Internal Server Error\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Cache-Control: no-store\r\n"
            . 'X-Weline-Request-Id: ' . $requestId . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
    }

    private static function requestId(): string
    {
        try {
            return \bin2hex(\random_bytes(16));
        } catch (\Throwable) {
            return \dechex((int)(\hrtime(true) & PHP_INT_MAX)) . '-' . (string)(\getmypid() ?: 0);
        }
    }
}
