<?php

declare(strict_types=1);

/**
 * 一次影子编译的结果。
 *
 * `ok` 的含义只有一个：**重放产物与输入源码逐字节相同**。
 * 标签语义等价不在这里判——那是 A1/A2 的渲染比对（Phase P1 起）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Shadow;

final class ShadowResult
{
    public function __construct(
        public readonly string $source,
        public readonly string $output,
        public readonly int $fragments,
        public readonly int $nodes,
        public readonly int $statements,
        public readonly float $durationUs,
    ) {
    }

    public function ok(): bool
    {
        return $this->output === $this->source;
    }

    public function sourceBytes(): int
    {
        return \strlen($this->source);
    }

    /**
     * @return array{ok:bool,bytes:int,fragments:int,nodes:int,statements:int,duration_us:float}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'bytes' => $this->sourceBytes(),
            'fragments' => $this->fragments,
            'nodes' => $this->nodes,
            'statements' => $this->statements,
            'duration_us' => $this->durationUs,
        ];
    }
}
