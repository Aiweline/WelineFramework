<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

final class RuntimeProviderResolver
{
    /** @var array<class-string, object|null> */
    private array $resolved = [];

    public function __construct(
        private readonly ServiceProviderRegistry $providers,
    ) {
    }

    public function resolve(string $contract): ?object
    {
        if (array_key_exists($contract, $this->resolved)) {
            return $this->resolved[$contract];
        }

        try {
            $implementation = $this->providers->implementationFor($contract);
            if ($implementation === null) {
                return $this->resolved[$contract] = null;
            }
            $provider = ObjectManager::getInstance($implementation);
            if (!$provider instanceof $contract) {
                return $this->resolved[$contract] = null;
            }
            return $this->resolved[$contract] = $provider;
        } catch (\Throwable) {
            return $this->resolved[$contract] = null;
        }
    }

    /**
     * Resolve a runtime contract without collapsing an absent declaration and
     * a declared-but-broken provider into the same null result.
     *
     * The legacy resolve() path intentionally remains unchanged. Async durable
     * work uses this method because a configured provider failure is recoverable,
     * while a missing declaration means the optional capability is disabled.
     */
    public function resolveDetailed(string $contract): RuntimeProviderResolution
    {
        try {
            $implementation = $this->providers->implementationFor($contract);
        } catch (\Throwable $exception) {
            return new RuntimeProviderResolution(
                RuntimeProviderResolution::CONFIGURED_UNAVAILABLE,
                errorCode: 'provider_registry_unavailable',
                error: $exception->getMessage(),
            );
        }
        if ($implementation === null) {
            return new RuntimeProviderResolution(RuntimeProviderResolution::NOT_CONFIGURED);
        }

        try {
            $provider = ObjectManager::getInstance($implementation);
        } catch (\Throwable $exception) {
            return new RuntimeProviderResolution(
                RuntimeProviderResolution::CONFIGURED_UNAVAILABLE,
                implementation: $implementation,
                errorCode: 'provider_construction_failed',
                error: $exception->getMessage(),
            );
        }
        if (!$provider instanceof $contract) {
            return new RuntimeProviderResolution(
                RuntimeProviderResolution::CONFIGURED_UNAVAILABLE,
                provider: $provider,
                implementation: $implementation,
                errorCode: 'provider_contract_mismatch',
                error: __('Provider %{1} 未实现 %{2}', [$implementation, $contract]),
            );
        }

        return new RuntimeProviderResolution(
            RuntimeProviderResolution::AVAILABLE,
            provider: $provider,
            implementation: $implementation,
        );
    }
}
