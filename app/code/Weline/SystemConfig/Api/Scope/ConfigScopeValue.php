<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * SystemConfig typed 解析结果（TASK-P1C-001）。
 */
final class ConfigScopeValue
{
    /**
     * @param list<string> $fallbackStorageScopes
     */
    public function __construct(
        public readonly mixed $value,
        public readonly ConfigScopeSource $source,
        public readonly ScopeIdentity $requestedScope,
        public readonly string $requestedLocale,
        public readonly array $fallbackStorageScopes,
    ) {
    }

    public function found(): bool
    {
        return $this->source->sourceKind === ConfigScopeSource::KIND_EXACT
            || $this->source->sourceKind === ConfigScopeSource::KIND_FALLBACK;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'found' => $this->found(),
            'value' => $this->value,
            'requested_scope_kind' => $this->requestedScope->scopeKind,
            'requested_scope' => $this->requestedScope->toArray(),
            'requested_locale' => $this->requestedLocale,
            'fallback_storage_scopes' => $this->fallbackStorageScopes,
            'source' => $this->source->toArray(),
        ];
    }
}
