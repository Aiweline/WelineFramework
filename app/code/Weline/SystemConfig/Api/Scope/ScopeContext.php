<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

use Weline\Framework\Runtime\ScopeIdentity;

/** Immutable, server-canonical scope context used by scoped writers. */
final readonly class ScopeContext
{
    /**
     * @param list<string> $fallbackStorageScopes Nearest-to-farthest, including self.
     */
    public function __construct(
        public ScopeIdentity $identity,
        public string $storageScope,
        public string $storeMode,
        public array $fallbackStorageScopes,
    ) {
        if ($storageScope === '' || \count(\explode('.', $storageScope)) !== 3) {
            throw new \InvalidArgumentException('system_config_scope_context_storage_invalid');
        }
        if ($fallbackStorageScopes === [] || $fallbackStorageScopes[0] !== $storageScope) {
            throw new \InvalidArgumentException('system_config_scope_context_chain_invalid');
        }
        if (!\in_array($storeMode, [
            ScopeIdentity::MODE_NORMAL,
            ScopeIdentity::MODE_DEV,
            ScopeIdentity::MODE_TEST,
        ], true)) {
            throw new \InvalidArgumentException('system_config_scope_context_store_mode_invalid');
        }
    }

    public function canonicalKey(): string
    {
        return $this->identity->canonicalKey() . '|storage=' . $this->storageScope . '|mode=' . $this->storeMode;
    }

    /**
     * @return array{
     *   identity:array<string,mixed>,
     *   storage_scope:string,
     *   store_mode:string,
     *   fallback_storage_scopes:list<string>,
     *   canonical_key:string
     * }
     */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'storage_scope' => $this->storageScope,
            'store_mode' => $this->storeMode,
            'fallback_storage_scopes' => $this->fallbackStorageScopes,
            'canonical_key' => $this->canonicalKey(),
        ];
    }
}
