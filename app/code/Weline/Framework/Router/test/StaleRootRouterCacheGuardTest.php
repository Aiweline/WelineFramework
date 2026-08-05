<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Data\DataInterface;
use Weline\Framework\Router\Core;

class StaleRootRouterCacheGuardTest extends TestCase
{
    public function testEmptyFrontendRootDropsStalePageBuilderRouterCache(): void
    {
        $this->assertTrue($this->invokeGuard(
            DataInterface::type_pc_FRONTEND,
            '',
            [],
            [
                'module' => 'GuoLaiRen_PageBuilder',
                'class' => [
                    'name' => 'GuoLaiRen\\PageBuilder\\Controller\\Frontend\\Page',
                    'method' => 'view',
                ],
            ]
        ));
    }

    public function testGuardSkipsWhenCurrentRequestAlreadyHasResolvedRule(): void
    {
        $this->assertFalse($this->invokeGuard(
            DataInterface::type_pc_FRONTEND,
            '',
            ['module' => 'GuoLaiRen_PageBuilder'],
            [
                'module' => 'GuoLaiRen_PageBuilder',
                'class' => [
                    'name' => 'GuoLaiRen\\PageBuilder\\Controller\\Frontend\\Page',
                    'method' => 'view',
                ],
            ]
        ));
    }

    public function testGuardSkipsNonEmptyPathsAndNonPageBuilderRouters(): void
    {
        $this->assertFalse($this->invokeGuard(
            DataInterface::type_pc_FRONTEND,
            'product/view',
            [],
            [
                'module' => 'WeShop_Product',
                'class' => [
                    'name' => 'WeShop\\Product\\Controller\\Frontend\\Product',
                    'method' => 'view',
                ],
            ]
        ));
    }

    private function invokeGuard(string $requestArea, string $url, array|string|null $rule, mixed $router): bool
    {
        $method = new \ReflectionMethod(Core::class, 'isStaleEmptyRootRouterCache');
        $method->setAccessible(true);

        return (bool)$method->invoke(null, $requestArea, $url, $rule, $router);
    }
}
