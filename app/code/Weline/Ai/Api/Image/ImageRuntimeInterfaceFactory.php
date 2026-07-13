<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Image;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ImageRuntimeInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ImageRuntimeInterface
    {
        return ObjectManager::getInstance(ImageRuntime::class);
    }
}
