<?php
declare(strict_types=1);

namespace Weline\Framework\Binary;

/**
 * WQB1 protocol limits shared by codecs and HTTP ingress guards.
 *
 * Changing any value is a protocol compatibility decision. Keep the numeric
 * limit and its public error text together so clients never observe a stale
 * limit description.
 */
final class Limits
{
    public const PACKET_BYTES = 4_194_304;
    public const VALUE_DEPTH = 32;
    public const LIST_ITEMS = 200;
    public const MAP_KEYS = 100;
    public const STRING_BYTES = 2_097_152;
    public const SAFE_INTEGER = 9_007_199_254_740_991;

    public const PACKET_ERROR = 'Weline binary packet exceeds 4MB limit.';
    public const VALUE_DEPTH_ERROR = 'Weline binary value exceeds max depth.';
    public const LIST_ITEMS_ERROR = 'List exceeds 200 item limit.';
    public const MAP_KEYS_ERROR = 'Map exceeds 100 key limit.';
    public const STRING_BYTES_ERROR = 'String exceeds 2MB limit.';
    public const BYTE_STRING_BYTES_ERROR = 'Byte string exceeds 2MB limit.';
    public const MAP_KEY_BYTES_ERROR = 'Map key exceeds 2MB limit.';

    private function __construct()
    {
    }
}
