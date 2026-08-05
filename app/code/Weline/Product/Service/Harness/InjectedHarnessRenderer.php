<?php

declare(strict_types=1);

namespace Weline\Product\Service\Harness;

use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Api\Data\ProductSceneRenderResult;
use Weline\Product\Api\ProductSceneRendererInterface;

/** E2E harness：可注入依赖的成功 custom renderer。 */
final class InjectedHarnessRenderer implements ProductSceneRendererInterface
{
    public function __construct(
        private readonly InjectedHarnessRendererDependency $dependency,
    ) {
    }

    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        return new ProductSceneRenderResult(
            html: '<strong>' . htmlspecialchars($this->dependency->status(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>',
        );
    }
}
