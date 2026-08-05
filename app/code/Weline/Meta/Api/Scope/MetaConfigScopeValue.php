<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Scope;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;

/**
 * MetaConfig typed 解析结果（TASK-P1C-005-META）。
 */
final class MetaConfigScopeValue
{
    /**
     * @param list<string> $fallbackStorageScopes
     */
    public function __construct(
        public readonly ?MetaConfigRecord $record,
        public readonly MetaConfigScopeSource $source,
        public readonly ScopeIdentity $requestedScope,
        public readonly ?string $requestedLocale,
        public readonly array $fallbackStorageScopes,
    ) {
    }

    public function found(): bool
    {
        return $this->record !== null
            && ($this->source->sourceKind === MetaConfigScopeSource::KIND_EXACT
                || $this->source->sourceKind === MetaConfigScopeSource::KIND_FALLBACK);
    }

    public function value(): ?string
    {
        return $this->record?->value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'found' => $this->found(),
            'value' => $this->value(),
            'requested_scope_kind' => $this->requestedScope->scopeKind,
            'requested_scope' => $this->requestedScope->toArray(),
            'requested_locale' => $this->requestedLocale,
            'fallback_storage_scopes' => $this->fallbackStorageScopes,
            'source' => $this->source->toArray(),
        ];
    }
}
