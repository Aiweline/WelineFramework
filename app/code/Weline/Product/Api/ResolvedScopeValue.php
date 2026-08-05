<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/**
 * Website/Store overlay 解析结果（EAV / Price 共用语义）。
 *
 * - explicit：命中显式值
 * - cleared：命中 cleared，终止剩余 locale / 父 Scope / 默认回退
 * - inherit：无行，可继续回退（已无更多层时视为未解析）
 */
final class ResolvedScopeValue
{
    public const SOURCE_EXPLICIT = 'explicit';
    public const SOURCE_CLEARED = 'cleared';
    public const SOURCE_INHERIT = 'inherit';

    private function __construct(
        public readonly string $source,
        public readonly mixed $value,
        /** store_id 命中层；Website 层恒为 0 */
        public readonly int $resolvedStoreId,
        public readonly string $resolvedLocale = '',
        /** 诊断码，如 cleared_at_scope */
        public readonly ?string $diagnostic = null,
    ) {
    }

    public static function explicit(mixed $value, int $storeId, string $locale = ''): self
    {
        return new self(self::SOURCE_EXPLICIT, $value, $storeId, $locale, null);
    }

    public static function cleared(int $storeId, string $locale = '', ?string $diagnostic = null): self
    {
        return new self(
            self::SOURCE_CLEARED,
            null,
            $storeId,
            $locale,
            $diagnostic ?? 'cleared_at_scope',
        );
    }

    public static function unresolved(): self
    {
        return new self(self::SOURCE_INHERIT, null, -1, '', null);
    }

    public function isExplicit(): bool
    {
        return $this->source === self::SOURCE_EXPLICIT;
    }

    public function isCleared(): bool
    {
        return $this->source === self::SOURCE_CLEARED;
    }

    public function isUnresolved(): bool
    {
        return $this->source === self::SOURCE_INHERIT;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'value' => $this->value,
            'resolved_store_id' => $this->resolvedStoreId,
            'resolved_locale' => $this->resolvedLocale,
            'diagnostic' => $this->diagnostic,
        ];
    }
}
