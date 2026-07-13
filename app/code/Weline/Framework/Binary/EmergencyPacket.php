<?php
declare(strict_types=1);

namespace Weline\Framework\Binary;

/**
 * Deterministic WQB1 packet used only when the regular response encoder fails.
 *
 * @internal
 */
final class EmergencyPacket
{
    public const ERROR_CODE = 'business_error';
    public const ERROR_MESSAGE = 'Internal server error.';

    public static function internalServerError(string $requestId): string
    {
        $requestId = \strtolower(\trim($requestId));
        if (\preg_match('/^[a-f0-9]{16}$/D', $requestId) !== 1) {
            $requestId = '0000000000000000';
        }

        return WelineBinaryCodec::MAGIC
            . \chr(WelineBinaryCodec::VERSION)
            . "\x08\x04"
            . "\x02ok\x01"
            . "\x04data\x00"
            . "\x05error\x08\x02"
            . "\x04code\x05\x0ebusiness_error"
            . "\x07message\x05\x16Internal server error."
            . "\x0arequest_id\x05\x10"
            . $requestId;
    }

    private function __construct()
    {
    }
}
