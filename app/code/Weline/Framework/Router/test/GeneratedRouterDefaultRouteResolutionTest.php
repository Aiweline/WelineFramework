<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\Core;

final class GeneratedRouterDefaultRouteResolutionTest extends TestCase
{
    public function testFrontendRootCanResolveGeneratedEmptyGetRoute(): void
    {
        $router = [
            'module' => 'Weline_Frontend',
            'class' => [
                'name' => 'Weline\\Frontend\\Controller\\Index',
                'method' => 'getIndex',
            ],
        ];

        self::assertSame($router, $this->resolve([
            '::GET' => $router,
        ], '', '::GET', '', 'index/index'));
    }

    public function testIndexIndexAliasCanResolveGeneratedEmptyGetRoute(): void
    {
        $router = [
            'module' => 'Weline_Frontend',
            'class' => [
                'name' => 'Weline\\Frontend\\Controller\\Index',
                'method' => 'getIndex',
            ],
        ];

        self::assertSame($router, $this->resolve([
            '::GET' => $router,
        ], 'index/index', '::GET', '', 'index/index'));
    }

    public function testExplicitRouteStillWinsOverRootFallback(): void
    {
        $explicitRouter = ['module' => 'Weline_Custom'];
        $rootRouter = ['module' => 'Weline_Frontend'];

        self::assertSame($explicitRouter, $this->resolve([
            'index/index::GET' => $explicitRouter,
            '::GET' => $rootRouter,
        ], 'index/index', '::GET', '', 'index/index'));
    }

    private function resolve(
        array $routers,
        string $url,
        string $method,
        string $getFallback,
        string $defaultRoute
    ): ?array {
        $reflection = new \ReflectionMethod(Core::class, 'resolveGeneratedRouterRule');
        $reflection->setAccessible(true);

        /** @var array|null $router */
        $router = $reflection->invoke(null, $routers, $url, $method, $getFallback, $defaultRoute);
        return $router;
    }
}
