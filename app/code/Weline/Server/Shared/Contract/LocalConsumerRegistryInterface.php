<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Contract;

interface LocalConsumerRegistryInterface
{
    public function registerLocalConsumer(
        string $consumerCode,
        string $instanceName = '',
        string $serviceRole = '',
        string $ownerType = 'instance'
    ): void;

    public function unregisterLocalConsumer(string $consumerCode): void;
}

