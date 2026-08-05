<?php

declare(strict_types=1);

namespace Weline\Framework\Hook;

/**
 * Structured hook render result (P2C / REQ-008).
 *
 * - handled_empty：实现方显式声明真空，禁止再走 fallback
 * - use_fallback：允许 Taglib `<else/>` 在运行时 opt-in 替换（默认不因 debug 触发）
 */
final class HookRenderResult
{
    public function __construct(
        public readonly string $html,
        public readonly bool $handledEmpty = false,
        public readonly bool $useFallback = false,
        public readonly int $fileCount = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }

    public function shouldUseFallback(): bool
    {
        return $this->useFallback && !$this->handledEmpty;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'handled_empty' => $this->handledEmpty,
            'use_fallback' => $this->useFallback,
            'file_count' => $this->fileCount,
        ];
    }
}
