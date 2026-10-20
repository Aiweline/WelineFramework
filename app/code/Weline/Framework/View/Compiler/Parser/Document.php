<?php

declare(strict_types=1);

/**
 * 一次解析的结果。
 *
 * 保留 `source` 是为了让校验与报错能回查原文，而 `replay()` 则是 P0 的核心判据：
 * 节点树顺序拼接必须与原文逐字节相同。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

final class Document
{
    /**
     * @param list<Node> $nodes
     */
    public function __construct(
        private readonly string $source,
        private readonly array $nodes,
    ) {
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return list<Node>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function replay(): string
    {
        $out = '';
        foreach ($this->nodes as $node) {
            $out .= $node->raw();
        }

        return $out;
    }

    public function isRoundTrip(): bool
    {
        return $this->replay() === $this->source;
    }
}
