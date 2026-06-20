<?php
declare(strict_types=1);

namespace Aiweline\BinQuery;

final class BinaryCodec
{
    public const CONTENT_TYPE = 'application/x-weline-query-bin';

    private const MAGIC = 'WQB1';
    private const VERSION = 1;
    private const TYPE_NULL = 0x00;
    private const TYPE_FALSE = 0x01;
    private const TYPE_TRUE = 0x02;
    private const TYPE_INT = 0x03;
    private const TYPE_FLOAT64 = 0x04;
    private const TYPE_STRING = 0x05;
    private const TYPE_BYTES = 0x06;
    private const TYPE_LIST = 0x07;
    private const TYPE_MAP = 0x08;
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public function encodePacket(mixed $payload): string
    {
        return self::MAGIC . \chr(self::VERSION) . $this->encodeValue($payload);
    }

    public function decodePacket(string $packet): mixed
    {
        if (\strlen($packet) < 5 || \substr($packet, 0, 4) !== self::MAGIC) {
            throw new \InvalidArgumentException('Invalid Weline binary packet magic.');
        }
        if (\ord($packet[4]) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported Weline binary packet version.');
        }
        $offset = 5;
        $value = $this->decodeValue($packet, $offset);
        if ($offset !== \strlen($packet)) {
            throw new \InvalidArgumentException('Trailing bytes in Weline binary packet.');
        }

        return $value;
    }

    private function encodeValue(mixed $value): string
    {
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
            if (\abs($value) > self::MAX_SAFE_INTEGER) {
                throw new \InvalidArgumentException('Integer is outside JavaScript safe integer range.');
            }
            return \chr(self::TYPE_INT) . ($value < 0 ? "\x01" : "\x00") . $this->encodeVarUint(\abs($value));
        }
        if (\is_float($value)) {
            return \chr(self::TYPE_FLOAT64) . \pack('E', $value);
        }
        if (\is_string($value)) {
            return \chr(self::TYPE_STRING) . $this->encodeVarUint(\strlen($value)) . $value;
        }
        if (\is_object($value)) {
            $value = \get_object_vars($value);
        }
        if (\is_array($value)) {
            return \array_is_list($value) ? $this->encodeList($value) : $this->encodeMap($value);
        }

        throw new \InvalidArgumentException('Unsupported Weline binary value type.');
    }

    private function encodeList(array $items): string
    {
        $encoded = \chr(self::TYPE_LIST) . $this->encodeVarUint(\count($items));
        foreach ($items as $item) {
            $encoded .= $this->encodeValue($item);
        }

        return $encoded;
    }

    private function encodeMap(array $map): string
    {
        $encoded = \chr(self::TYPE_MAP) . $this->encodeVarUint(\count($map));
        foreach ($map as $key => $value) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException('Map key must be a string.');
            }
            $encoded .= $this->encodeVarUint(\strlen($key)) . $key . $this->encodeValue($value);
        }

        return $encoded;
    }

    private function decodeValue(string $packet, int &$offset): mixed
    {
        $type = \ord($packet[$offset++]);
        return match ($type) {
            self::TYPE_NULL => null,
            self::TYPE_FALSE => false,
            self::TYPE_TRUE => true,
            self::TYPE_INT => $this->decodeInt($packet, $offset),
            self::TYPE_FLOAT64 => $this->decodeFloat($packet, $offset),
            self::TYPE_STRING => $this->decodeString($packet, $offset),
            self::TYPE_BYTES => $this->decodeString($packet, $offset),
            self::TYPE_LIST => $this->decodeList($packet, $offset),
            self::TYPE_MAP => $this->decodeMap($packet, $offset),
            default => throw new \InvalidArgumentException('Unknown Weline binary type tag.'),
        };
    }

    private function decodeInt(string $packet, int &$offset): int
    {
        $sign = \ord($packet[$offset++]);
        $magnitude = $this->decodeVarUint($packet, $offset);
        return $sign === 1 ? -$magnitude : $magnitude;
    }

    private function decodeFloat(string $packet, int &$offset): float
    {
        $bytes = \substr($packet, $offset, 8);
        $offset += 8;
        return \unpack('E', $bytes)[1];
    }

    private function decodeString(string $packet, int &$offset): string
    {
        $length = $this->decodeVarUint($packet, $offset);
        $value = \substr($packet, $offset, $length);
        $offset += $length;

        return $value;
    }

    private function decodeList(string $packet, int &$offset): array
    {
        $count = $this->decodeVarUint($packet, $offset);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->decodeValue($packet, $offset);
        }

        return $items;
    }

    private function decodeMap(string $packet, int &$offset): array
    {
        $count = $this->decodeVarUint($packet, $offset);
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $keyLength = $this->decodeVarUint($packet, $offset);
            $key = \substr($packet, $offset, $keyLength);
            $offset += $keyLength;
            $map[$key] = $this->decodeValue($packet, $offset);
        }

        return $map;
    }

    private function encodeVarUint(int $value): string
    {
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
            $byte = \ord($packet[$offset++]);
            $result += (($byte & 0x7f) << $shift);
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }
    }
}
