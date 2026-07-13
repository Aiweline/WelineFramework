<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

final class WlsRuntimeAdapterResolver
{
    private bool $resolved = false;
    private ?WlsRuntimeAdapterInterface $adapter = null;

    public function __construct(
        private readonly ServiceProviderRegistry $providers,
    ) {
    }

    public function resolve(): ?WlsRuntimeAdapterInterface
    {
        if ($this->resolved) {
            return $this->adapter;
        }
        $this->resolved = true;

        try {
            $implementation = $this->providers->implementationFor(WlsRuntimeAdapterInterface::class);
            if ($implementation === null) {
                return null;
            }
            $adapter = ObjectManager::getInstance($implementation);
            if ($adapter instanceof WlsRuntimeAdapterInterface) {
                $this->adapter = $adapter;
            }
        } catch (\Throwable) {
            $this->adapter = null;
        }

        return $this->adapter;
    }
}
