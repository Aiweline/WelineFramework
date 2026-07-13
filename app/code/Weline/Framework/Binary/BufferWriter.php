<?php
declare(strict_types=1);

namespace Weline\Framework\Binary;

/**
 * Bounded segmented writer for WQB1 packet encoding.
 *
 * Small writes are coalesced before the final implode so a packet containing
 * many scalar values cannot amplify memory through millions of PHP array
 * entries. The byte budget is enforced before each append.
 */
final class BufferWriter
{
    private const SEGMENT_BYTES = 16_384;

    /** @var list<string> */
    private array $segments = [];

    private string $buffer = '';
    private int $length = 0;

    public function __construct(
        private readonly int $maxBytes = Limits::PACKET_BYTES,
    ) {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('Buffer writer byte limit must be positive.');
        }
    }

    public function append(string $bytes): void
    {
        $bytesLength = \strlen($bytes);
        if ($bytesLength === 0) {
            return;
        }
        if ($bytesLength > $this->maxBytes - $this->length) {
            throw new \InvalidArgumentException(Limits::PACKET_ERROR);
        }

        $this->length += $bytesLength;
        if (\strlen($this->buffer) + $bytesLength <= self::SEGMENT_BYTES) {
            $this->buffer .= $bytes;
            return;
        }

        $this->flushBuffer();
        if ($bytesLength >= self::SEGMENT_BYTES) {
            $this->segments[] = $bytes;
            return;
        }

        $this->buffer = $bytes;
    }

    public function finish(): string
    {
        $this->flushBuffer();
        if ($this->segments === []) {
            return '';
        }
        if (\count($this->segments) === 1) {
            return $this->segments[0];
        }

        return \implode('', $this->segments);
    }

    private function flushBuffer(): void
    {
        if ($this->buffer === '') {
            return;
        }

        $this->segments[] = $this->buffer;
        $this->buffer = '';
    }
}
