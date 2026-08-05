<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Scope;

/**
 * EAV typed scope 值语义（P1b 骨架，v1）。
 *
 * 一个属性值在某个 Scope 层上的状态只有三种：
 * - explicit：本层显式赋值；
 * - cleared：本层显式清除 —— 语义为「停止并阻断向上继承」，解析立即返回空值；
 * - inherit：本层未设置，继续向上层查找。
 */
final class EavScopeValue
{
    public const SOURCE_EXPLICIT = 'explicit';
    public const SOURCE_CLEARED = 'cleared';
    public const SOURCE_INHERIT = 'inherit';
    public const SOURCES = [self::SOURCE_EXPLICIT, self::SOURCE_CLEARED, self::SOURCE_INHERIT];

    private function __construct(
        public readonly string $source,
        public readonly mixed $value,
        /** 命中层的 scope 串（website.store.channel 或空串=global） */
        public readonly string $resolvedScope,
    ) {
    }

    public static function explicit(mixed $value, string $scope): self
    {
        return new self(self::SOURCE_EXPLICIT, $value, $scope);
    }

    /** cleared：值为 null 且不再向上回落 */
    public static function cleared(string $scope): self
    {
        return new self(self::SOURCE_CLEARED, null, $scope);
    }

    /** inherit（未在任何层命中）：值为 null */
    public static function unresolved(): self
    {
        return new self(self::SOURCE_INHERIT, null, '');
    }

    public function isExplicit(): bool
    {
        return $this->source === self::SOURCE_EXPLICIT;
    }

    public function isCleared(): bool
    {
        return $this->source === self::SOURCE_CLEARED;
    }
}
