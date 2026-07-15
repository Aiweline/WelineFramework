<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http2;

/**
 * Small, bounded HPACK decoder for WLS HTTP/2 request headers.
 *
 * The decoder intentionally starts conservative: it supports RFC 7541 static
 * table, indexed headers, literal headers with/without indexing, dynamic table
 * size updates and Huffman-coded strings. The dynamic table is bounded and can
 * be set to zero by the future h2 adapter if a no-dynamic-table profile is
 * desired for simpler memory behavior.
 */
final class HpackDecoder
{
    private const HUFFMAN_CODES = [
        0x1ff8,0x7fffd8,0xfffffe2,0xfffffe3,0xfffffe4,0xfffffe5,0xfffffe6,0xfffffe7,
        0xfffffe8,0xffffea,0x3ffffffc,0xfffffe9,0xfffffea,0x3ffffffd,0xfffffeb,0xfffffec,
        0xfffffed,0xfffffee,0xfffffef,0xffffff0,0xffffff1,0xffffff2,0x3ffffffe,0xffffff3,
        0xffffff4,0xffffff5,0xffffff6,0xffffff7,0xffffff8,0xffffff9,0xffffffa,0xffffffb,
        0x14,0x3f8,0x3f9,0xffa,0x1ff9,0x15,0xf8,0x7fa,0x3fa,0x3fb,0xf9,0x7fb,0xfa,0x16,0x17,0x18,
        0x0,0x1,0x2,0x19,0x1a,0x1b,0x1c,0x1d,0x1e,0x1f,0x5c,0xfb,0x7ffc,0x20,0xffb,0x3fc,
        0x1ffa,0x21,0x5d,0x5e,0x5f,0x60,0x61,0x62,0x63,0x64,0x65,0x66,0x67,0x68,0x69,0x6a,0x6b,0x6c,0x6d,0x6e,0x6f,
        0x70,0x71,0x72,0xfc,0x73,0xfd,0x1ffb,0x7fff0,0x1ffc,0x3ffc,0x22,0x7ffd,0x3,0x23,0x4,0x24,0x5,
        0x25,0x26,0x27,0x6,0x74,0x75,0x28,0x29,0x2a,0x7,0x2b,0x76,0x2c,0x8,0x9,0x2d,0x77,0x78,0x79,0x7a,
        0x7b,0x7ffe,0x7fc,0x3ffd,0x1ffd,0xffffffc,0xfffe6,0x3fffd2,0xfffe7,0xfffe8,0x3fffd3,0x3fffd4,0x3fffd5,0x7fffd9,
        0x3fffd6,0x7fffda,0x7fffdb,0x7fffdc,0x7fffdd,0x7fffde,0xffffeb,0x7fffdf,0xffffec,0xffffed,0x3fffd7,0x7fffe0,
        0xffffee,0x7fffe1,0x7fffe2,0x7fffe3,0x7fffe4,0x1fffdc,0x3fffd8,0x7fffe5,0x3fffd9,0x7fffe6,0x7fffe7,0xffffef,
        0x3fffda,0x1fffdd,0xfffe9,0x3fffdb,0x3fffdc,0x7fffe8,0x7fffe9,0x1fffde,0x7fffea,0x3fffdd,0x3fffde,0xfffff0,
        0x1fffdf,0x3fffdf,0x7fffeb,0x7fffec,0x1fffe0,0x1fffe1,0x3fffe0,0x1fffe2,0x7fffed,0x3fffe1,0x7fffee,0x7fffef,
        0xfffea,0x3fffe2,0x3fffe3,0x3fffe4,0x7ffff0,0x3fffe5,0x3fffe6,0x7ffff1,0x3ffffe0,0x3ffffe1,0xfffeb,0x7fff1,
        0x3fffe7,0x7ffff2,0x3fffe8,0x1ffffec,0x3ffffe2,0x3ffffe3,0x3ffffe4,0x7ffffde,0x7ffffdf,0x3ffffe5,
        0xfffff1,0x1ffffed,0x7fff2,0x1fffe3,0x3ffffe6,0x7ffffe0,0x7ffffe1,0x3ffffe7,0x7ffffe2,0xfffff2,
        0x1fffe4,0x1fffe5,0x3ffffe8,0x3ffffe9,0xffffffd,0x7ffffe3,0x7ffffe4,0x7ffffe5,0xfffec,0xfffff3,
        0xfffed,0x1fffe6,0x3fffe9,0x1fffe7,0x1fffe8,0x7ffff3,0x3fffea,0x3fffeb,0x1ffffee,0x1ffffef,
        0xfffff4,0xfffff5,0x3ffffea,0x7ffff4,0x3ffffeb,0x7ffffe6,0x3ffffec,0x3ffffed,0x7ffffe7,0x7ffffe8,
        0x7ffffe9,0x7ffffea,0x7ffffeb,0xffffffe,0x7ffffec,0x7ffffed,0x7ffffee,0x7ffffef,0x7fffff0,0x3ffffee,
    ];

    private const HUFFMAN_LENGTHS = [
        13,23,28,28,28,28,28,28,28,24,30,28,28,30,28,28,28,28,28,28,28,28,30,28,28,28,28,28,28,28,28,28,
        6,10,10,12,13,6,8,11,10,10,8,11,8,6,6,6,5,5,5,6,6,6,6,6,6,6,7,8,15,6,12,10,
        13,6,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,8,7,8,13,19,13,14,6,15,5,6,5,6,5,
        6,6,6,5,7,7,6,6,6,5,6,7,6,5,5,6,7,7,7,7,7,15,11,14,13,28,20,22,20,20,22,22,22,23,
        22,23,23,23,23,23,24,23,24,24,22,23,24,23,23,23,23,21,22,23,22,23,23,24,22,21,20,22,22,23,23,21,
        23,22,22,24,21,22,23,23,21,21,22,21,23,22,23,23,20,22,22,22,23,22,22,23,26,26,20,19,22,23,
        22,25,26,26,26,27,27,26,24,25,19,21,26,27,27,26,27,24,21,21,26,26,28,27,27,27,20,24,20,21,22,21,
        21,23,22,22,25,25,24,24,26,23,26,27,26,26,27,27,27,27,27,28,27,27,27,27,27,26,
    ];

    /** @var list<array{name:string,value:string,size:int}> */
    private array $dynamicTable = [];
    private int $dynamicTableSize = 0;

    public function __construct(private int $maxDynamicTableSize = 4096, private int $maxHeaderListSize = 65536)
    {
        $this->maxDynamicTableSize = \max(0, $this->maxDynamicTableSize);
        $this->maxHeaderListSize = \max(1024, $this->maxHeaderListSize);
    }

    /** @return list<array{name:string,value:string}> */
    public function decode(string $block): array
    {
        $headers = [];
        $offset = 0;
        $length = \strlen($block);
        $headerBytes = 0;
        while ($offset < $length) {
            $byte = \ord($block[$offset]);
            if (($byte & 0x80) === 0x80) {
                [$index, $offset] = $this->decodeInteger($block, $offset, 7);
                $entry = $this->entry($index);
                $headers[] = ['name' => $entry['name'], 'value' => $entry['value']];
                $headerBytes += \strlen($entry['name']) + \strlen($entry['value']);
                $this->guardHeaderBytes($headerBytes);
                continue;
            }

            if (($byte & 0x40) === 0x40) {
                [$index, $offset] = $this->decodeInteger($block, $offset, 6);
                [$name, $offset] = $index === 0 ? $this->decodeString($block, $offset) : [$this->entry($index)['name'], $offset];
                [$value, $offset] = $this->decodeString($block, $offset);
                $this->validateHeader($name, $value);
                $headers[] = ['name' => $name, 'value' => $value];
                $this->addDynamic($name, $value);
                $headerBytes += \strlen($name) + \strlen($value);
                $this->guardHeaderBytes($headerBytes);
                continue;
            }

            if (($byte & 0xe0) === 0x20) {
                [$size, $offset] = $this->decodeInteger($block, $offset, 5);
                if ($size > $this->maxDynamicTableSize) {
                    throw new \UnexpectedValueException('HPACK dynamic table size update exceeds configured maximum.');
                }
                $this->evictTo($size);
                continue;
            }

            if (($byte & 0xf0) === 0x00 || ($byte & 0xf0) === 0x10) {
                [$index, $offset] = $this->decodeInteger($block, $offset, 4);
                [$name, $offset] = $index === 0 ? $this->decodeString($block, $offset) : [$this->entry($index)['name'], $offset];
                [$value, $offset] = $this->decodeString($block, $offset);
                $this->validateHeader($name, $value);
                $headers[] = ['name' => $name, 'value' => $value];
                $headerBytes += \strlen($name) + \strlen($value);
                $this->guardHeaderBytes($headerBytes);
                continue;
            }

            throw new \UnexpectedValueException('Unsupported HPACK header representation.');
        }

        return $headers;
    }

    /** @return array{0:int,1:int} */
    private function decodeInteger(string $data, int $offset, int $prefixBits): array
    {
        if ($offset >= \strlen($data)) {
            throw new \UnexpectedValueException('Truncated HPACK integer.');
        }
        $mask = (1 << $prefixBits) - 1;
        $value = \ord($data[$offset]) & $mask;
        $offset++;
        if ($value < $mask) {
            return [$value, $offset];
        }

        $shift = 0;
        while (true) {
            if ($offset >= \strlen($data) || $shift > 28) {
                throw new \UnexpectedValueException('Invalid HPACK integer continuation.');
            }
            $byte = \ord($data[$offset]);
            $offset++;
            $value += ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                return [$value, $offset];
            }
            $shift += 7;
        }
    }

    /** @return array{0:string,1:int} */
    private function decodeString(string $data, int $offset): array
    {
        if ($offset >= \strlen($data)) {
            throw new \UnexpectedValueException('Truncated HPACK string.');
        }
        $huffman = (\ord($data[$offset]) & 0x80) === 0x80;
        [$length, $offset] = $this->decodeInteger($data, $offset, 7);
        if ($length < 0 || $offset + $length > \strlen($data)) {
            throw new \UnexpectedValueException('Truncated HPACK string payload.');
        }
        $payload = \substr($data, $offset, $length);
        $offset += $length;
        if ($huffman) {
            throw new \UnexpectedValueException('HPACK Huffman strings are not enabled until the RFC 7541 table is verified.');
        }
        return [$payload, $offset];
    }

    private static function decodeHuffman(string $data): string
    {
        static $tree = null;
        if ($tree === null) {
            $tree = [];
            foreach (self::HUFFMAN_CODES as $symbol => $code) {
                $node =& $tree;
                $bits = self::HUFFMAN_LENGTHS[$symbol];
                for ($i = $bits - 1; $i >= 0; $i--) {
                    $bit = ($code >> $i) & 1;
                    if (!isset($node[$bit])) {
                        $node[$bit] = [];
                    }
                    $node =& $node[$bit];
                }
                $node['sym'] = $symbol;
                unset($node);
            }
        }

        $out = '';
        $node = $tree;
        $pendingBits = 0;
        $pendingValue = 0;
        $bytes = \strlen($data);
        for ($i = 0; $i < $bytes; $i++) {
            $byte = \ord($data[$i]);
            for ($bitIndex = 7; $bitIndex >= 0; $bitIndex--) {
                $bit = ($byte >> $bitIndex) & 1;
                $pendingBits++;
                $pendingValue = (($pendingValue << 1) | $bit) & 0x7fffffff;
                if (!isset($node[$bit])) {
                    throw new \UnexpectedValueException('Invalid HPACK Huffman code.');
                }
                $node = $node[$bit];
                if (isset($node['sym'])) {
                    $symbol = (int)$node['sym'];
                    if ($symbol === 256) {
                        throw new \UnexpectedValueException('Unexpected HPACK Huffman EOS symbol.');
                    }
                    $out .= \chr($symbol);
                    $node = $tree;
                    $pendingBits = 0;
                    $pendingValue = 0;
                }
            }
        }

        if ($pendingBits > 0) {
            if ($pendingBits > 7 || $pendingValue !== ((1 << $pendingBits) - 1)) {
                throw new \UnexpectedValueException('Invalid HPACK Huffman padding.');
            }
        }

        return $out;
    }

    /** @return array{name:string,value:string} */
    private function entry(int $index): array
    {
        if ($index < 1) {
            throw new \UnexpectedValueException('Invalid HPACK index 0.');
        }
        $static = self::staticTable();
        $staticCount = \count($static);
        if ($index <= $staticCount) {
            return $static[$index];
        }
        $dynamicIndex = $index - $staticCount - 1;
        if (!isset($this->dynamicTable[$dynamicIndex])) {
            throw new \UnexpectedValueException('Invalid HPACK dynamic index.');
        }
        return ['name' => $this->dynamicTable[$dynamicIndex]['name'], 'value' => $this->dynamicTable[$dynamicIndex]['value']];
    }

    private function addDynamic(string $name, string $value): void
    {
        $size = 32 + \strlen($name) + \strlen($value);
        if ($size > $this->maxDynamicTableSize) {
            $this->dynamicTable = [];
            $this->dynamicTableSize = 0;
            return;
        }
        \array_unshift($this->dynamicTable, ['name' => $name, 'value' => $value, 'size' => $size]);
        $this->dynamicTableSize += $size;
        $this->evictTo($this->maxDynamicTableSize);
    }

    private function evictTo(int $maxSize): void
    {
        while ($this->dynamicTableSize > $maxSize && $this->dynamicTable !== []) {
            $removed = \array_pop($this->dynamicTable);
            $this->dynamicTableSize -= (int)($removed['size'] ?? 0);
        }
    }

    private function guardHeaderBytes(int $bytes): void
    {
        if ($bytes > $this->maxHeaderListSize) {
            throw new \UnexpectedValueException('HTTP/2 header list exceeds WLS limit.');
        }
    }

    private function validateHeader(string $name, string $value): void
    {
        $isPseudoHeader = \str_starts_with($name, ':');
        $nameToken = $isPseudoHeader ? \substr($name, 1) : $name;
        if ($name === ''
            || $name !== \strtolower($name)
            || $nameToken === ''
            || \str_contains($nameToken, ':')
            || \preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $nameToken) !== 1
        ) {
            throw new \UnexpectedValueException('Invalid HTTP/2 header name.');
        }
        if (\preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new \UnexpectedValueException('Invalid HTTP/2 header value.');
        }
    }

    /** @return array<int,array{name:string,value:string}> */
    private static function staticTable(): array
    {
        return [
            1 => ['name' => ':authority', 'value' => ''],
            2 => ['name' => ':method', 'value' => 'GET'],
            3 => ['name' => ':method', 'value' => 'POST'],
            4 => ['name' => ':path', 'value' => '/'],
            5 => ['name' => ':path', 'value' => '/index.html'],
            6 => ['name' => ':scheme', 'value' => 'http'],
            7 => ['name' => ':scheme', 'value' => 'https'],
            8 => ['name' => ':status', 'value' => '200'],
            9 => ['name' => ':status', 'value' => '204'],
            10 => ['name' => ':status', 'value' => '206'],
            11 => ['name' => ':status', 'value' => '304'],
            12 => ['name' => ':status', 'value' => '400'],
            13 => ['name' => ':status', 'value' => '404'],
            14 => ['name' => ':status', 'value' => '500'],
            15 => ['name' => 'accept-charset', 'value' => ''],
            16 => ['name' => 'accept-encoding', 'value' => 'gzip, deflate'],
            17 => ['name' => 'accept-language', 'value' => ''],
            18 => ['name' => 'accept-ranges', 'value' => ''],
            19 => ['name' => 'accept', 'value' => ''],
            20 => ['name' => 'access-control-allow-origin', 'value' => ''],
            21 => ['name' => 'age', 'value' => ''],
            22 => ['name' => 'allow', 'value' => ''],
            23 => ['name' => 'authorization', 'value' => ''],
            24 => ['name' => 'cache-control', 'value' => ''],
            25 => ['name' => 'content-disposition', 'value' => ''],
            26 => ['name' => 'content-encoding', 'value' => ''],
            27 => ['name' => 'content-language', 'value' => ''],
            28 => ['name' => 'content-length', 'value' => ''],
            29 => ['name' => 'content-location', 'value' => ''],
            30 => ['name' => 'content-range', 'value' => ''],
            31 => ['name' => 'content-type', 'value' => ''],
            32 => ['name' => 'cookie', 'value' => ''],
            33 => ['name' => 'date', 'value' => ''],
            34 => ['name' => 'etag', 'value' => ''],
            35 => ['name' => 'expect', 'value' => ''],
            36 => ['name' => 'expires', 'value' => ''],
            37 => ['name' => 'from', 'value' => ''],
            38 => ['name' => 'host', 'value' => ''],
            39 => ['name' => 'if-match', 'value' => ''],
            40 => ['name' => 'if-modified-since', 'value' => ''],
            41 => ['name' => 'if-none-match', 'value' => ''],
            42 => ['name' => 'if-range', 'value' => ''],
            43 => ['name' => 'if-unmodified-since', 'value' => ''],
            44 => ['name' => 'last-modified', 'value' => ''],
            45 => ['name' => 'link', 'value' => ''],
            46 => ['name' => 'location', 'value' => ''],
            47 => ['name' => 'max-forwards', 'value' => ''],
            48 => ['name' => 'proxy-authenticate', 'value' => ''],
            49 => ['name' => 'proxy-authorization', 'value' => ''],
            50 => ['name' => 'range', 'value' => ''],
            51 => ['name' => 'referer', 'value' => ''],
            52 => ['name' => 'refresh', 'value' => ''],
            53 => ['name' => 'retry-after', 'value' => ''],
            54 => ['name' => 'server', 'value' => ''],
            55 => ['name' => 'set-cookie', 'value' => ''],
            56 => ['name' => 'strict-transport-security', 'value' => ''],
            57 => ['name' => 'transfer-encoding', 'value' => ''],
            58 => ['name' => 'user-agent', 'value' => ''],
            59 => ['name' => 'vary', 'value' => ''],
            60 => ['name' => 'via', 'value' => ''],
            61 => ['name' => 'www-authenticate', 'value' => ''],
        ];
    }
}
