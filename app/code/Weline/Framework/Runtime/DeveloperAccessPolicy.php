<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

final class DeveloperAccessPolicy implements DeveloperAccessProviderInterface
{
    private bool $resolved = false;
    private ?DeveloperAccessProviderInterface $provider = null;

    public function __construct(
        private readonly ServiceProviderRegistry $serviceProviders,
    ) {
    }

    public function shouldInjectBootstrap(): bool
    {
        return $this->provider()?->shouldInjectBootstrap() ?? false;
    }

    public function canAccessPanel(?Request $request = null): bool
    {
        return $this->provider()?->canAccessPanel($request) ?? false;
    }

    public function canAccessApi(?Request $request = null): bool
    {
        return $this->provider()?->canAccessApi($request) ?? false;
    }

    public function canAccessRawHttp(string $rawRequest): bool
    {
        $provider = $this->provider();
        return $provider instanceof RawDeveloperAccessProviderInterface
            && $provider->canAccessRawHttp($rawRequest);
    }

    private function provider(): ?DeveloperAccessProviderInterface
    {
        if ($this->resolved) {
            return $this->provider;
        }
        $this->resolved = true;

        try {
            $implementation = $this->serviceProviders->implementationFor(DeveloperAccessProviderInterface::class);
            if ($implementation === null) {
                return null;
            }
            $provider = ObjectManager::getInstance($implementation);
            if ($provider instanceof DeveloperAccessProviderInterface) {
                $this->provider = $provider;
            }
        } catch (\Throwable) {
            $this->provider = null;
        }

        return $this->provider;
    }
}
