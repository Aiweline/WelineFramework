<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Scope;

/**
 * Phrase typed 来源 DTO（TASK-P1C-005-I18N / TEST-P1C-04）。
 */
final class PhraseScopeSource
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
        public readonly ?string $lookupWord,
        public readonly array $metadata = [],
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
            'lookup_word' => $this->lookupWord,
            'metadata' => $this->metadata,
        ];
    }

    public static function unresolved(): self
    {
        return new self(self::KIND_UNRESOLVED, null, null, null, null);
    }

    public static function fromDefault(?string $locale = null): self
    {
        return new self(self::KIND_DEFAULT, null, null, $locale, null);
    }
}
