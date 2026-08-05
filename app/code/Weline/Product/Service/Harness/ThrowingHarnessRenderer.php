<?php

declare(strict_types=1);

namespace Weline\Product\Service\Harness;

use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Api\Data\ProductSceneRenderResult;
use Weline\Product\Api\ProductSceneRendererInterface;

/** E2E harness：抛异常（→ fallback + ERROR_CUSTOM_EXCEPTION）。 */
final class ThrowingHarnessRenderer implements ProductSceneRendererInterface
{
    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        throw new \RuntimeException('harness_boom');
    }
}
