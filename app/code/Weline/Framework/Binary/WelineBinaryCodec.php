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

    public function encodePacket(mixed $payload): string
    {
        $writer = new BufferWriter(Limits::PACKET_BYTES);
        $writer->append(self::MAGIC);
        $writer->append(\chr(self::VERSION));
        $this->encodeValueInto($payload, 0, $writer);

        return $writer->finish();
    }

    public function decodePacket(string $packet): mixed
    {
        $length = \strlen($packet);
        if ($length > Limits::PACKET_BYTES) {
            throw new \InvalidArgumentException(Limits::PACKET_ERROR);
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

    private function encodeValueInto(mixed $value, int $depth, BufferWriter $writer): void
    {
        if ($depth > Limits::VALUE_DEPTH) {
            throw new \InvalidArgumentException(Limits::VALUE_DEPTH_ERROR);
        }

        if ($value === null) {
            $writer->append(\chr(self::TYPE_NULL));
            return;
        }
        if ($value === false) {
            $writer->append(\chr(self::TYPE_FALSE));
            return;
        }
        if ($value === true) {
            $writer->append(\chr(self::TYPE_TRUE));
            return;
        }
        if (\is_int($value)) {
            if ($value < -Limits::SAFE_INTEGER || $value > Limits::SAFE_INTEGER) {
                throw new \InvalidArgumentException('Integer is outside JavaScript safe integer range.');
            }
            $writer->append(\chr(self::TYPE_INT));
            $writer->append($value < 0 ? "\x01" : "\x00");
            $writer->append($this->encodeVarUint(\abs($value)));
            return;
        }
        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException('Non-finite float is not allowed.');
            }
            $writer->append(\chr(self::TYPE_FLOAT64));
            $writer->append(\pack('E', $value));
            return;
        }
        if (\is_string($value)) {
            if (\strlen($value) > Limits::STRING_BYTES) {
                throw new \InvalidArgumentException(Limits::STRING_BYTES_ERROR);
            }
            if ($value !== '' && \preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Invalid UTF-8 string.');
            }
            $writer->append(\chr(self::TYPE_STRING));
            $writer->append($this->encodeVarUint(\strlen($value)));
            $writer->append($value);
            return;
        }
        if (\is_object($value)) {
            $value = \get_object_vars($value);
        }
        if (\is_array($value)) {
            if (\array_is_list($value)) {
                $this->encodeListInto($value, $depth, $writer);
            } else {
                $this->encodeMapInto($value, $depth, $writer);
            }
            return;
        }

        throw new \InvalidArgumentException('Unsupported Weline binary value type: ' . \gettype($value));
    }

    private function encodeListInto(array $items, int $depth, BufferWriter $writer): void
    {
        if (\count($items) > Limits::LIST_ITEMS) {
            throw new \InvalidArgumentException(Limits::LIST_ITEMS_ERROR);
        }

        $writer->append(\chr(self::TYPE_LIST));
        $writer->append($this->encodeVarUint(\count($items)));
        foreach ($items as $item) {
            $this->encodeValueInto($item, $depth + 1, $writer);
        }
    }

    private function encodeMapInto(array $map, int $depth, BufferWriter $writer): void
    {
        if (\count($map) > Limits::MAP_KEYS) {
            throw new \InvalidArgumentException(Limits::MAP_KEYS_ERROR);
        }

        $writer->append(\chr(self::TYPE_MAP));
        $writer->append($this->encodeVarUint(\count($map)));
        foreach ($map as $key => $value) {
            $key = (string)$key;
            if (\strlen($key) > Limits::STRING_BYTES || \preg_match('//u', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid Weline binary map key.');
            }
            $writer->append($this->encodeVarUint(\strlen($key)));
            $writer->append($key);
            $this->encodeValueInto($value, $depth + 1, $writer);
        }
    }

    private function decodeValue(string $packet, int &$offset, int $depth): mixed
    {
        if ($depth > Limits::VALUE_DEPTH) {
            throw new \InvalidArgumentException(Limits::VALUE_DEPTH_ERROR);
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
        if ($magnitude > Limits::SAFE_INTEGER) {
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
        if ($length > Limits::STRING_BYTES) {
            throw new \InvalidArgumentException(Limits::STRING_BYTES_ERROR);
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
        if ($length > Limits::STRING_BYTES) {
            throw new \InvalidArgumentException(Limits::BYTE_STRING_BYTES_ERROR);
        }

        return $this->readBytes($packet, $offset, $length);
    }

    private function decodeList(string $packet, int &$offset, int $depth): array
    {
        $count = $this->decodeVarUint($packet, $offset);
        if ($count > Limits::LIST_ITEMS) {
            throw new \InvalidArgumentException(Limits::LIST_ITEMS_ERROR);
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
        if ($count > Limits::MAP_KEYS) {
            throw new \InvalidArgumentException(Limits::MAP_KEYS_ERROR);
        }

        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $keyLength = $this->decodeVarUint($packet, $offset);
            if ($keyLength > Limits::STRING_BYTES) {
                throw new \InvalidArgumentException(Limits::MAP_KEY_BYTES_ERROR);
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
