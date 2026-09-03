<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\BrandBasicsIdentityProviderInterface;

/** Discovers BrandBasicsIdentityProviderInterface implementations. */
final class BrandBasicsIdentityRegistry
{
    /** @var list<BrandBasicsIdentityProviderInterface>|null */
    private ?array $providers = null;

    /**
     * @return list<BrandBasicsIdentityProviderInterface>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        foreach (ObjectManager::getInstance(ServiceProviderRegistry::class)
            ->implementationsWithPrefix('theme.brand_basics_identity.') as $implementation
        ) {
            try {
                $provider = ObjectManager::getInstance($implementation);
            } catch (\Throwable) {
                continue;
            }
            if ($provider instanceof BrandBasicsIdentityProviderInterface) {
                $providers[] = $provider;
            }
        }

        return $this->providers = $providers;
    }

    public function forIdentity(ScopeIdentity $identity): ?BrandBasicsIdentityProviderInterface
    {
        foreach ($this->all() as $provider) {
            try {
                if ($provider->supports($identity)) {
                    return $provider;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
