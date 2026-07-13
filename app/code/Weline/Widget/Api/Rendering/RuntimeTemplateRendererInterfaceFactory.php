<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Rendering;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetRuntimeTemplateRenderer;

final class RuntimeTemplateRendererInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RuntimeTemplateRendererInterface
    {
        return ObjectManager::getInstance(WidgetRuntimeTemplateRenderer::class);
    }
}
