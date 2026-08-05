<?php

declare(strict_types=1);

namespace Weline\Product\Service\Harness;

/** E2E harness：InjectedHarnessRenderer 构造依赖。 */
final class InjectedHarnessRendererDependency
{
    public function status(): string
    {
        return 'dependency-ready';
    }
}
