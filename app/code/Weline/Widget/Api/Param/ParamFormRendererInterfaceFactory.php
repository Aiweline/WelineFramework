<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Param;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\ParamTypeRenderer;

final class ParamFormRendererInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ParamFormRendererInterface
    {
        return ObjectManager::getInstance(ParamTypeRenderer::class);
    }
}
