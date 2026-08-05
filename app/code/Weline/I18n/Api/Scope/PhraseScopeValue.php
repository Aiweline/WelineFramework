<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Scope;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Phrase typed 解析结果（TASK-P1C-005-I18N）。
 */
final class PhraseScopeValue
{
    /**
     * @param list<string> $fallbackStorageScopes
     * @param list<string> $localeFallbackChain
     */
    public function __construct(
        public readonly string $text,
        public readonly PhraseScopeSource $source,
        public readonly ScopeIdentity $requestedScope,
        public readonly string $requestedLocale,
        public readonly array $fallbackStorageScopes,
        public readonly array $localeFallbackChain,
    ) {
    }

    public function foundScoped(): bool
    {
        return $this->source->sourceKind === PhraseScopeSource::KIND_EXACT
            || $this->source->sourceKind === PhraseScopeSource::KIND_FALLBACK;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'found_scoped' => $this->foundScoped(),
            'requested_scope_kind' => $this->requestedScope->scopeKind,
            'requested_scope' => $this->requestedScope->toArray(),
            'requested_locale' => $this->requestedLocale,
            'fallback_storage_scopes' => $this->fallbackStorageScopes,
            'locale_fallback_chain' => $this->localeFallbackChain,
            'source' => $this->source->toArray(),
        ];
    }
}
