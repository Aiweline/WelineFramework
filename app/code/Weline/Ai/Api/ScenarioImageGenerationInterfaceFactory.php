<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

use Weline\Ai\Service\ScenarioImageGenerationGateway;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ScenarioImageGenerationInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ScenarioImageGenerationInterface
    {
        return ObjectManager::getInstance(ScenarioImageGenerationGateway::class);
    }
}
