<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Api\Data\ProductSceneRenderResult;

/**
 * Custom product scene renderer (FQCN from ProductRendererCapabilityInterface::getRendererClass()).
 */
interface ProductSceneRendererInterface
{
    public function render(ProductSceneContext $context): ProductSceneRenderResult;
}
