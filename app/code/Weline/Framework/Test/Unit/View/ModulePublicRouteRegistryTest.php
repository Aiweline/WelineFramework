<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\ModulePublicRouteRegistry;

/**
 * 模块公开路由注册表：从编译 manifest 的 `theme.public_route.*` 收集处理器。
 */
final class ModulePublicRouteRegistryTest extends TestCase
{
    public function testCollectsRegisteredHandlersFromCompiledManifest(): void
    {
        $handlers = ModulePublicRouteRegistry::handlers();

        self::assertNotEmpty($handlers, 'theme.public_route.* 处理器不应为空');
        foreach ($handlers as $handler) {
            self::assertTrue(
                \is_a($handler, \Weline\Framework\View\ModulePublicRouteInterface::class, true),
                "处理器 {$handler} 必须实现 ModulePublicRouteInterface"
            );
        }
        // 已注册的模块路由必须全部被收集到。
        $expected = [
            'Weline\\Search\\Controller\\Router',
            'Weline\\Compare\\Controller\\Router',
            'Weline\\Product\\Controller\\Router',
            'Weline\\Blog\\Controller\\Router',
            'Weline\\Payment\\Controller\\Router',
            'Weline\\Customer\\Controller\\Router',
            'Weline\\Order\\Controller\\Router',
            'Weline\\Promotion\\Controller\\Router',
        ];
        foreach ($expected as $cls) {
            self::assertContains($cls, $handlers, "缺少处理器 {$cls}");
        }
    }

    public function testKnownNamespaceHandlersOwnTheirPrefixes(): void
    {
        self::assertTrue(\Weline\Search\Controller\Router::handles('search'));
        self::assertFalse(\Weline\Search\Controller\Router::handles('other'));

        self::assertTrue(\Weline\Compare\Controller\Router::handles('compare'));
        self::assertFalse(\Weline\Compare\Controller\Router::handles('other'));

        self::assertTrue(\Weline\Product\Controller\Router::handles('product'));
        self::assertTrue(\Weline\Product\Controller\Router::handles('product/foo'));
        self::assertTrue(\Weline\Product\Controller\Router::handles('category/3'));
        self::assertTrue(\Weline\Product\Controller\Router::handles('products'));
        self::assertFalse(\Weline\Product\Controller\Router::handles('guide/payment'));
    }
}
