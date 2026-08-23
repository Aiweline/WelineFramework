<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;

/**
 * Safe baseline when no module owns Website/Store/Channel identities.
 *
 * An identity-owner module may contribute one authoritative catalog through
 * the public capability prefix. Keeping this class as the sole interface
 * provider avoids cross-module replacement while preserving global-only use.
 */
final class GlobalOnlyScopeIdentityCatalog implements ScopeIdentityCatalogInterface
{
    private bool $delegateResolved = false;

    private ?ScopeIdentityCatalogInterface $resolvedDelegate = null;

    public function __construct(
        private readonly ServiceProviderRegistry $providerRegistry,
    ) {
    }

    public function websiteIdForCode(string $websiteCode): int
    {
        if (($delegate = $this->delegate()) !== null) {
            return $delegate->websiteIdForCode($websiteCode);
        }

        throw new \InvalidArgumentException('system_config_website_scope_not_available');
    }

    public function authoritativeIdentity(ScopeIdentity $candidate): ScopeIdentity
    {
        if (($delegate = $this->delegate()) !== null) {
            return $delegate->authoritativeIdentity($candidate);
        }

        if (!$candidate->isGlobal()) {
            throw new \InvalidArgumentException('system_config_non_global_scope_not_available');
        }

        return ScopeIdentity::global($candidate->contextVersion);
    }

    public function options(): array
    {
        if (($delegate = $this->delegate()) !== null) {
            return $delegate->options();
        }

        return [];
    }

    private function delegate(): ?ScopeIdentityCatalogInterface
    {
        if ($this->delegateResolved) {
            return $this->resolvedDelegate;
        }

        $implementations = \array_values($this->providerRegistry->implementationsWithPrefix(
            ScopeIdentityCatalogInterface::CONTRIBUTOR_CAPABILITY_PREFIX,
        ));
        if ($implementations === []) {
            $this->delegateResolved = true;
            return null;
        }
        if (\count($implementations) !== 1) {
            throw new \RuntimeException('system_config_scope_identity_catalog_contributor_ambiguous');
        }

        $implementation = $implementations[0];
        if ($implementation === self::class
            || !\class_exists($implementation)
            || !\is_a($implementation, ScopeIdentityCatalogInterface::class, true)
        ) {
            throw new \RuntimeException('system_config_scope_identity_catalog_contributor_invalid');
        }
        $delegate = ObjectManager::getInstance($implementation);
        if (!$delegate instanceof ScopeIdentityCatalogInterface) {
            throw new \RuntimeException('system_config_scope_identity_catalog_contributor_invalid');
        }

        $this->resolvedDelegate = $delegate;
        $this->delegateResolved = true;

        return $this->resolvedDelegate;
    }
}
