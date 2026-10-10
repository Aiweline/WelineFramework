<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * 类型策略判定结果。
 */
final class AdjustmentDecision
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reasonCode,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function allow(array $context = []): self
    {
        return new self(true, '', '', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function deny(string $reasonCode, string $message, array $context = []): self
    {
        return new self(false, $reasonCode, $message, $context);
    }
}
