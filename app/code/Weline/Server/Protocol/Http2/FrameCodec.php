<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http2;

/**
 * Bounded HTTP/2 binary frame codec.
 */
final class FrameCodec
{
    public const CLIENT_CONNECTION_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    public const TYPE_DATA = 0x0;
    public const TYPE_HEADERS = 0x1;
    public const TYPE_PRIORITY = 0x2;
    public const TYPE_RST_STREAM = 0x3;
    public const TYPE_SETTINGS = 0x4;
    public const TYPE_PUSH_PROMISE = 0x5;
    public const TYPE_PING = 0x6;
    public const TYPE_GOAWAY = 0x7;
    public const TYPE_WINDOW_UPDATE = 0x8;
    public const TYPE_CONTINUATION = 0x9;

    public const FLAG_END_STREAM = 0x1;
    public const FLAG_ACK = 0x1;
    public const FLAG_END_HEADERS = 0x4;
    public const FLAG_PADDED = 0x8;
    public const FLAG_PRIORITY = 0x20;

    public const SETTINGS_HEADER_TABLE_SIZE = 0x1;
    public const SETTINGS_ENABLE_PUSH = 0x2;
    public const SETTINGS_MAX_CONCURRENT_STREAMS = 0x3;
    public const SETTINGS_INITIAL_WINDOW_SIZE = 0x4;
    public const SETTINGS_MAX_FRAME_SIZE = 0x5;
    public const SETTINGS_MAX_HEADER_LIST_SIZE = 0x6;

    public const ERROR_NO_ERROR = 0x0;
    public const ERROR_PROTOCOL_ERROR = 0x1;
    public const ERROR_INTERNAL_ERROR = 0x2;
    public const ERROR_FLOW_CONTROL_ERROR = 0x3;
    public const ERROR_SETTINGS_TIMEOUT = 0x4;
    public const ERROR_STREAM_CLOSED = 0x5;
    public const ERROR_FRAME_SIZE_ERROR = 0x6;
    public const ERROR_REFUSED_STREAM = 0x7;
    public const ERROR_CANCEL = 0x8;
    public const ERROR_COMPRESSION_ERROR = 0x9;
    public const ERROR_CONNECT_ERROR = 0xa;
    public const ERROR_ENHANCE_YOUR_CALM = 0xb;
    public const ERROR_INADEQUATE_SECURITY = 0xc;
    public const ERROR_HTTP_1_1_REQUIRED = 0xd;

    public const DEFAULT_MAX_FRAME_SIZE = 16384;

    /**
     * @return array{status:'incomplete'|'frame'|'error',consumed:int,type?:int,flags?:int,stream_id?:int,payload?:string,error?:string}
     */
    public static function decodeOne(string $buffer, int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE): array
    {
        $bufferLength = \strlen($buffer);
        if ($bufferLength < 9) {
            return ['status' => 'incomplete', 'consumed' => 0];
        }

        $length = (\ord($buffer[0]) << 16) | (\ord($buffer[1]) << 8) | \ord($buffer[2]);
        if ($length > $maxFrameSize) {
            return ['status' => 'error', 'consumed' => 0, 'error' => 'frame_size_error'];
        }
        $total = 9 + $length;
        if ($bufferLength < $total) {
            return ['status' => 'incomplete', 'consumed' => 0];
        }

        $streamId = ((\ord($buffer[5]) << 24)
            | (\ord($buffer[6]) << 16)
            | (\ord($buffer[7]) << 8)
            | \ord($buffer[8])) & 0x7fffffff;

        return [
            'status' => 'frame',
            'consumed' => $total,
            'type' => \ord($buffer[3]),
            'flags' => \ord($buffer[4]),
            'stream_id' => $streamId,
            'payload' => \substr($buffer, 9, $length),
        ];
    }

    public static function encode(int $type, int $flags, int $streamId, string $payload = ''): string
    {
        $length = \strlen($payload);
        if ($length > 0x00ffffff) {
            throw new \InvalidArgumentException('HTTP/2 frame payload is larger than 24-bit length.');
        }
        if ($streamId < 0 || $streamId > 0x7fffffff) {
            throw new \InvalidArgumentException('HTTP/2 stream id must be a 31-bit unsigned integer.');
        }

        return \chr(($length >> 16) & 0xff)
            . \chr(($length >> 8) & 0xff)
            . \chr($length & 0xff)
            . \chr($type & 0xff)
            . \chr($flags & 0xff)
            . \pack('N', $streamId & 0x7fffffff)
            . $payload;
    }

    /** @param array<int,int> $settings */
    public static function settings(array $settings = []): string
    {
        $payload = '';
        foreach ($settings as $id => $value) {
            $id = (int)$id;
            $value = (int)$value;
            if ($id < 1 || $id > 0xffff || $value < 0 || $value > 0xffffffff) {
                throw new \InvalidArgumentException('Invalid HTTP/2 SETTINGS parameter.');
            }
            $payload .= \pack('nN', $id, $value);
        }

        return self::encode(self::TYPE_SETTINGS, 0, 0, $payload);
    }

    public static function settingsAck(): string
    {
        return self::encode(self::TYPE_SETTINGS, self::FLAG_ACK, 0, '');
    }

    public static function windowUpdate(int $streamId, int $increment): string
    {
        if ($increment < 1 || $increment > 0x7fffffff) {
            throw new \InvalidArgumentException('HTTP/2 WINDOW_UPDATE increment must be 1..2^31-1.');
        }

        return self::encode(self::TYPE_WINDOW_UPDATE, 0, $streamId, \pack('N', $increment));
    }

    public static function rstStream(int $streamId, int $errorCode = self::ERROR_CANCEL): string
    {
        if ($streamId <= 0) {
            throw new \InvalidArgumentException('HTTP/2 RST_STREAM requires a non-zero stream id.');
        }
        return self::encode(self::TYPE_RST_STREAM, 0, $streamId, \pack('N', $errorCode & 0xffffffff));
    }

    public static function goaway(int $lastStreamId, int $errorCode = self::ERROR_NO_ERROR, string $debug = ''): string
    {
        return self::encode(
            self::TYPE_GOAWAY,
            0,
            0,
            \pack('NN', $lastStreamId & 0x7fffffff, $errorCode & 0xffffffff) . $debug
        );
    }
}
