<?php

declare(strict_types=1);

namespace Weline\Server\Runtime\Resumable;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Runtime\ResumableTaskWatchdogGateway;

final class RuntimeWatchdogGatewayInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RuntimeWatchdogGatewayInterface
    {
        $gateway = ObjectManager::getInstance(ResumableTaskWatchdogGateway::class);
        if (!$gateway instanceof RuntimeWatchdogGatewayInterface) {
            throw new \RuntimeException('Configured Runtime watchdog gateway violates its contract.');
        }
        return $gateway;
    }
}
