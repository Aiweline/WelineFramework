<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Auth;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class BinQueryAuthenticatorInterfaceFactory implements FactoryObjectInterface
{
    public function __construct(
        private readonly ServiceProviderRegistry $providers,
    ) {
    }

    public function create(): BinQueryAuthenticatorInterface
    {
        $implementation = $this->providers->implementationFor(BinQueryAuthenticatorInterface::class);
        if ($implementation === null) {
            throw new \RuntimeException(
                'No BinQuery authenticator is registered. Enable a provider module and run: php bin/w framework:compile',
            );
        }

        $authenticator = ObjectManager::getInstance($implementation);
        if (!$authenticator instanceof BinQueryAuthenticatorInterface) {
            throw new \RuntimeException("Configured BinQuery authenticator {$implementation} violates its contract.");
        }

        return $authenticator;
    }
}
