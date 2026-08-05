<?php

declare(strict_types=1);

namespace Weline\Product\Service\Harness;

use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Api\Data\ProductSceneRenderResult;
use Weline\Product\Api\ProductSceneRendererInterface;

/** E2E harness：handled_empty → 真真空，不 fallback。 */
final class HandledEmptyHarnessRenderer implements ProductSceneRendererInterface
{
    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        return new ProductSceneRenderResult(html: '', handledEmpty: true);
    }
}
