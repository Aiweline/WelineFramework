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
}
