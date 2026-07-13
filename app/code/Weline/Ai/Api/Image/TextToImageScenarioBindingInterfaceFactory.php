<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Image;

use Weline\Ai\Service\Image\TextToImageScenarioBindingManager;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class TextToImageScenarioBindingInterfaceFactory implements FactoryObjectInterface
{
    public function create(): TextToImageScenarioBindingInterface
    {
        return ObjectManager::getInstance(TextToImageScenarioBindingManager::class);
    }
}
