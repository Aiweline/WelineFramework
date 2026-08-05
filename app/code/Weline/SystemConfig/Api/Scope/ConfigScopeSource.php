<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

/**
 * SystemConfig 解析来源 DTO（TASK-P1C-001 / TEST-P1C-01）。
 *
 * source_kind：exact|fallback|default|unresolved
 * lock / suppressed 归属 TASK-P1C-002：
 * - locked：命中行 metadata.active_lock_version > 0
 * - suppressed：行带 suppressed_by_lock_version（解析链会跳过，一般不会作为 source）
 */
final class ConfigScopeSource
{
    public const KIND_EXACT = 'exact';
    public const KIND_FALLBACK = 'fallback';
    public const KIND_DEFAULT = 'default';
    public const KIND_UNRESOLVED = 'unresolved';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $sourceKind,
        public readonly ?string $scopeKind,
        public readonly ?string $storageScope,
        public readonly ?string $locale,
        public readonly ?int $version,
        public readonly bool $isSensitive,
        public readonly array $metadata,
        public readonly bool $locked = false,
        public readonly bool $suppressed = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_kind' => $this->sourceKind,
            'scope_kind' => $this->scopeKind,
            'storage_scope' => $this->storageScope,
            'locale' => $this->locale,
            'version' => $this->version,
            'is_sensitive' => $this->isSensitive,
            'metadata' => $this->metadata,
            'locked' => $this->locked,
            'suppressed' => $this->suppressed,
        ];
    }

    public static function unresolved(): self
    {
        return new self(self::KIND_UNRESOLVED, null, null, null, null, false, []);
    }

    public static function fromDefault(): self
    {
        return new self(self::KIND_DEFAULT, null, null, null, null, false, []);
    }
}
