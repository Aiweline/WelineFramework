<?php

declare(strict_types=1);

namespace Weline\Product\Service\Harness;

use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Api\Data\ProductSceneRenderResult;
use Weline\Product\Api\ProductSceneRendererInterface;

/** E2E harness：custom renderer 返回空串（bug empty → fallback）。 */
final class EmptyBugHarnessRenderer implements ProductSceneRendererInterface
{
    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        return new ProductSceneRenderResult(html: '');
    }
}
