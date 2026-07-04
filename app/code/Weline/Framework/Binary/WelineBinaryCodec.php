<?php
declare(strict_types=1);

namespace Weline\Framework\Binary;

final class WelineBinaryCodec
{
    public const MAGIC = 'WQB1';
    public const VERSION = 1;
    public const CONTENT_TYPE = 'application/x-weline-query-bin';

    private const TYPE_NULL = 0x00;
    private const TYPE_FALSE = 0x01;
    private const TYPE_TRUE = 0x02;
    private const TYPE_INT = 0x03;
    private const TYPE_FLOAT64 = 0x04;
    private const TYPE_STRING = 0x05;
    private const TYPE_BYTES = 0x06;
    private const TYPE_LIST = 0x07;
    private const TYPE_MAP = 0x08;

    private const MAX_PACKET_BYTES = 4194304;
    private const MAX_DEPTH = 32;
    private const MAX_LIST_ITEMS = 200;
    private const MAX_MAP_KEYS = 100;
    private const MAX_STRING_BYTES = 2097152;
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public function encodePacket(mixed $payload): string
    {
        $packet = self::MAGIC . \chr(self::VERSION) . $this->encodeValue($payload, 0);
        if (\strlen($packet) > self::MAX_PACKET_BYTES) {
            throw new \InvalidArgumentException('Weline binary packet exceeds 64KB limit.');
        }

        return $packet;
    }

    public function decodePacket(string $packet): mixed
    {
        $length = \strlen($packet);
        if ($length > self::MAX_PACKET_BYTES) {
            throw new \InvalidArgumentException('Weline binary packet exceeds 64KB limit.');
        }
        if ($length < 5 || \substr($packet, 0, 4) !== self::MAGIC) {
            throw new \InvalidArgumentException('Invalid Weline binary packet magic.');
        }

        $version = \ord($packet[4]);
        if ($version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported Weline binary packet version.');
        }

        $offset = 5;
        $value = $this->decodeValue($packet, $offset, 0);
        if ($offset !== $length) {
            throw new \InvalidArgumentException('Trailing bytes in Weline binary packet.');
        }

        return $value;
    }

    private function encodeValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Weline binary value exceeds max depth.');
        }

        if ($value === null) {
            return \chr(self::TYPE_NULL);
        }
        if ($value === false) {
            return \chr(self::TYPE_FALSE);
        }
        if ($value === true) {
            return \chr(self::TYPE_TRUE);
        }
        if (\is_int($value)) {
            if ($value < -self::MAX_SAFE_INTEGER || $value > self::MAX_SAFE_INTEGER) {
                throw new \InvalidArgumentException('Integer is outside JavaScript safe integer range.');
            }
            return \chr(self::TYPE_INT) . ($value < 0 ? "\x01" : "\x00") . $this->encodeVarUint(\abs($value));
        }
        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException('Non-finite float is not allowed.');
            }
            return \chr(self::TYPE_FLOAT64) . \pack('E', $value);
        }
        if (\is_string($value)) {
            if (\strlen($value) > self::MAX_STRING_BYTES) {
                throw new \InvalidArgumentException('String exceeds 16KB limit.');
            }
            if ($value !== '' && \preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Invalid UTF-8 string.');
            }
            return \chr(self::TYPE_STRING) . $this->encodeVarUint(\strlen($value)) . $value;
        }
        if (\is_object($value)) {
            $value = \get_object_vars($value);
        }
        if (\is_array($value)) {
            return \array_is_list($value)
                ? $this->encodeList($value, $depth)
                : $this->encodeMap($value, $depth);
        }

        throw new \InvalidArgumentException('Unsupported Weline binary value type: ' . \gettype($value));
    }

    private function encodeList(array $items, int $depth): string
    {
        if (\count($items) > self::MAX_LIST_ITEMS) {
            throw new \InvalidArgumentException('List exceeds 200 item limit.');
        }

        $encoded = \chr(self::TYPE_LIST) . $this->encodeVarUint(\count($items));
        foreach ($items as $item) {
            $encoded .= $this->encodeValue($item, $depth + 1);
        }

        return $encoded;
    }

    private function encodeMap(array $map, int $depth): string
    {
        if (\count($map) > self::MAX_MAP_KEYS) {
            throw new \InvalidArgumentException('Map exceeds 100 key limit.');
        }

        $encoded = \chr(self::TYPE_MAP) . $this->encodeVarUint(\count($map));
        foreach ($map as $key => $value) {
            $key = (string)$key;
            if (\strlen($key) > self::MAX_STRING_BYTES || \preg_match('//u', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid Weline binary map key.');
            }
            $encoded .= $this->encodeVarUint(\strlen($key)) . $key;
            $encoded .= $this->encodeValue($value, $depth + 1);
        }

        return $encoded;
    }

    private function decodeValue(string $packet, int &$offset, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Weline binary value exceeds max depth.');
        }
        if ($offset >= \strlen($packet)) {
            throw new \InvalidArgumentException('Unexpected end of Weline binary packet.');
        }

        $type = \ord($packet[$offset++]);
        return match ($type) {
            self::TYPE_NULL => null,
            self::TYPE_FALSE => false,
            self::TYPE_TRUE => true,
            self::TYPE_INT => $this->decodeInt($packet, $offset),
            self::TYPE_FLOAT64 => $this->decodeFloat64($packet, $offset),
            self::TYPE_STRING => $this->decodeString($packet, $offset),
            self::TYPE_BYTES => $this->decodeBytes($packet, $offset),
            self::TYPE_LIST => $this->decodeList($packet, $offset, $depth),
            self::TYPE_MAP => $this->decodeMap($packet, $offset, $depth),
            default => throw new \InvalidArgumentException('Unknown Weline binary type tag.'),
        };
    }

    private function decodeInt(string $packet, int &$offset): int
    {
        $sign = $this->readByte($packet, $offset);
        if ($sign !== 0 && $sign !== 1) {
            throw new \InvalidArgumentException('Invalid integer sign marker.');
        }

        $magnitude = $this->decodeVarUint($packet, $offset);
        if ($magnitude > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('Integer is outside JavaScript safe integer range.');
        }

        return $sign === 1 ? -$magnitude : $magnitude;
    }

    private function decodeFloat64(string $packet, int &$offset): float
    {
        $bytes = $this->readBytes($packet, $offset, 8);
        $value = \unpack('E', $bytes)[1];
        if (!\is_float($value) || !\is_finite($value)) {
            throw new \InvalidArgumentException('Non-finite float is not allowed.');
        }

        return $value;
    }

    private function decodeString(string $packet, int &$offset): string
    {
        $length = $this->decodeVarUint($packet, $offset);
        if ($length > self::MAX_STRING_BYTES) {
            throw new \InvalidArgumentException('String exceeds 16KB limit.');
        }

        $value = $this->readBytes($packet, $offset, $length);
        if ($value !== '' && \preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid UTF-8 string.');
        }

        return $value;
    }

    private function decodeBytes(string $packet, int &$offset): string
    {
        $length = $this->decodeVarUint($packet, $offset);
        if ($length > self::MAX_STRING_BYTES) {
            throw new \InvalidArgumentException('Byte string exceeds 16KB limit.');
        }

        return $this->readBytes($packet, $offset, $length);
    }

    private function decodeList(string $packet, int &$offset, int $depth): array
    {
        $count = $this->decodeVarUint($packet, $offset);
        if ($count > self::MAX_LIST_ITEMS) {
            throw new \InvalidArgumentException('List exceeds 200 item limit.');
        }

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->decodeValue($packet, $offset, $depth + 1);
        }

        return $items;
    }

    private function decodeMap(string $packet, int &$offset, int $depth): array
    {
        $count = $this->decodeVarUint($packet, $offset);
        if ($count > self::MAX_MAP_KEYS) {
            throw new \InvalidArgumentException('Map exceeds 100 key limit.');
        }

        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $keyLength = $this->decodeVarUint($packet, $offset);
            if ($keyLength > self::MAX_STRING_BYTES) {
                throw new \InvalidArgumentException('Map key exceeds 16KB limit.');
            }
            $key = $this->readBytes($packet, $offset, $keyLength);
            if ($key === '' || \preg_match('//u', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid UTF-8 map key.');
            }
            if (\array_key_exists($key, $map)) {
                throw new \InvalidArgumentException('Duplicate map key in Weline binary packet.');
            }
            $map[$key] = $this->decodeValue($packet, $offset, $depth + 1);
        }

        return $map;
    }

    private function encodeVarUint(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Varuint cannot be negative.');
        }

        $encoded = '';
        do {
            $byte = $value & 0x7f;
            $value = intdiv($value, 128);
            if ($value > 0) {
                $byte |= 0x80;
            }
            $encoded .= \chr($byte);
        } while ($value > 0);

        return $encoded;
    }

    private function decodeVarUint(string $packet, int &$offset): int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($shift > 56) {
                throw new \InvalidArgumentException('Varuint is too large.');
            }
            $byte = $this->readByte($packet, $offset);
            $result += (($byte & 0x7f) << $shift);
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }
    }

    private function readByte(string $packet, int &$offset): int
    {
        if ($offset >= \strlen($packet)) {
            throw new \InvalidArgumentException('Unexpected end of Weline binary packet.');
        }

        return \ord($packet[$offset++]);
    }

    private function readBytes(string $packet, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > \strlen($packet)) {
            throw new \InvalidArgumentException('Unexpected end of Weline binary packet.');
        }

        $bytes = \substr($packet, $offset, $length);
        $offset += $length;

        return $bytes;
    }
}
