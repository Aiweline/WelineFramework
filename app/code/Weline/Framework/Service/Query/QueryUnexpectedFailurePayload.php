<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Binary\EmergencyPacket;

/**
 * Maps uncaught QueryBin/BinQuery failures to client-safe error payloads.
 * In DEV the full exception message and debug context are returned.
 */
final class QueryUnexpectedFailurePayload
{
    /**
     * @return array{code: string, message: string, debug?: array<string, mixed>}
     */
    public static function build(\Throwable $throwable): array
    {
        if (\defined('DEV') && DEV) {
            return self::buildDev($throwable);
        }

        return [
            'code' => EmergencyPacket::ERROR_CODE,
            'message' => EmergencyPacket::ERROR_MESSAGE,
        ];
    }

    /**
     * @return array{code: string, message: string, debug: array<string, mixed>}
     */
    private static function buildDev(\Throwable $throwable): array
    {
        $message = \sprintf(
            '%s: %s in %s:%d',
            $throwable::class,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
        );

        $debug = \function_exists('w_log_exception_build_context')
            ? \w_log_exception_build_context($throwable)
            : [
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                '_exception_trace' => $throwable->getTraceAsString(),
            ];

        return [
            'code' => EmergencyPacket::ERROR_CODE,
            'message' => $message,
            'debug' => $debug,
        ];
    }

    private function __construct()
    {
    }
}
