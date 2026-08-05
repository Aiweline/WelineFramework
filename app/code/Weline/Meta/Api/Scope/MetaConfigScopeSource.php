<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Scope;

/**
 * MetaConfig typed 来源 DTO（TASK-P1C-005-META / TEST-P1C-02）。
 * 形状对齐 SystemConfig ConfigScopeSource；lock/version 预留。
 */
final class MetaConfigScopeSource
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
        public readonly array $metadata,
        public readonly bool $locked = false,
        public readonly bool $suppressed = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_kind' => $this->sourceKind,
            'scope_kind' => $this->scopeKind,
            'storage_scope' => $this->storageScope,
            'locale' => $this->locale,
            'version' => $this->version,
            'metadata' => $this->metadata,
            'locked' => $this->locked,
            'suppressed' => $this->suppressed,
        ];
    }

    public static function unresolved(): self
    {
        return new self(self::KIND_UNRESOLVED, null, null, null, null, []);
    }

    public static function fromDefault(): self
    {
        return new self(self::KIND_DEFAULT, null, null, null, null, []);
    }
}
