<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Configuration;

use Weline\Ai\Service\Configuration\ScenarioConfiguration;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ScenarioConfigurationInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ScenarioConfigurationInterface
    {
        return ObjectManager::getInstance(ScenarioConfiguration::class);
    }
}
