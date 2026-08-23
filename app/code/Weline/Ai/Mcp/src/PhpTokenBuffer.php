<?php

declare(strict_types=1);

namespace LearningMcp;

use ArrayAccess;
use Countable;
use LogicException;
use OutOfBoundsException;
use SplFixedArray;

/**
 * Memory-bounded random access over PHP's native tokenizer output.
 *
 * Native token arrays are retained once. Byte offsets and effective line
 * numbers use fixed vectors, while the parser's associative token shape is
 * materialized only for the individual token currently being inspected.
 *
 * @implements ArrayAccess<int,array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}>
 */
final class PhpTokenBuffer implements ArrayAccess, Countable
{
    /** @var list<array{0:int,1:string,2:int}|string> */
    private array $tokens;

    /** @var SplFixedArray<int,int> */
    private SplFixedArray $startBytes;

    /** @var SplFixedArray<int,int> */
    private SplFixedArray $lines;

    public function __construct(string $content)
    {
        $this->tokens = token_get_all($content);
        $count = count($this->tokens);
        $this->startBytes = new SplFixedArray($count);
        $this->lines = new SplFixedArray($count);
        $offset = 0;
        $line = 1;

        foreach ($this->tokens as $index => $raw) {
            if (is_array($raw)) {
                [, $text, $tokenLine] = $raw;
                $line = $tokenLine;
            } else {
                $text = $raw;
            }
            $this->startBytes[$index] = $offset;
            $this->lines[$index] = $line;
            $offset += strlen($text);
            $line += substr_count($text, "\n");
        }
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && isset($this->tokens[$offset]);
    }

    /** @return array{id:int|null,text:string,line:int,start_byte:int,end_byte:int} */
    public function offsetGet(mixed $offset): array
    {
        if (!is_int($offset) || !isset($this->tokens[$offset])) {
            throw new OutOfBoundsException('PHP token offset is outside the buffer');
        }

        $raw = $this->tokens[$offset];
        $id = is_array($raw) ? $raw[0] : null;
        $text = is_array($raw) ? $raw[1] : $raw;
        $startByte = (int) $this->startBytes[$offset];

        return [
            'id' => $id,
            'text' => $text,
            'line' => (int) $this->lines[$offset],
            'start_byte' => $startByte,
            'end_byte' => $startByte + strlen($text),
        ];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('PHP token buffers are immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('PHP token buffers are immutable');
    }
}
