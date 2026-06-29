<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\Core;

final class GeneratedRouterLookupCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Core::resetGeneratedRouterFileCache();
    }

    public function testGeneratedRouterLookupPrefersUnqualifiedRouteBeforeMethodRoute(): void
    {
        $routers = [
            'bench/json' => ['id' => 'any'],
            'bench/json::POST' => ['id' => 'post'],
        ];

        $match = $this->matchGeneratedRouterEntry('unit-router-priority.php', $routers, 'bench/json', 'POST', 'index/index');

        self::assertSame('any', $match['id'] ?? null);
    }

    public function testGeneratedRouterLookupFallsBackFromHeadToGetRoute(): void
    {
        $routers = [
            'bench/framework::GET' => ['id' => 'get'],
        ];

        $match = $this->matchGeneratedRouterEntry('unit-router-head.php', $routers, 'bench/framework', 'HEAD', 'index/index');

        self::assertSame('get', $match['id'] ?? null);
    }

    public function testGeneratedRouterLookupUsesDefaultRouteOnlyForEmptyUrl(): void
    {
        $routers = [
            'index/index' => ['id' => 'default'],
        ];

        self::assertNull($this->matchGeneratedRouterEntry('unit-router-default.php', $routers, 'missing', 'GET', 'index/index'));

        $match = $this->matchGeneratedRouterEntry('unit-router-default.php', $routers, '', 'GET', 'index/index');

        self::assertSame('default', $match['id'] ?? null);
    }

    public function testGeneratedRouterLookupCacheIsClearedWithGeneratedRouterFileCache(): void
    {
        $routers = [
            'bench/event::GET' => ['id' => 'event'],
        ];

        $this->matchGeneratedRouterEntry('unit-router-reset.php', $routers, 'bench/event', 'GET', 'index/index');
        self::assertNotSame([], $this->readGeneratedRouterLookupCache());

        Core::resetGeneratedRouterFileCache();

        self::assertSame([], $this->readGeneratedRouterLookupCache());
    }

    private function matchGeneratedRouterEntry(
        string $routerFilepath,
        array $routers,
        string $url,
        string $requestMethod,
        string $defaultRoute
    ): ?array {
        $method = new \ReflectionMethod(Core::class, 'matchGeneratedRouterEntry');
        $method->setAccessible(true);

        /** @var ?array $match */
        $match = $method->invoke(null, $routerFilepath, $routers, $url, $requestMethod, $defaultRoute);

        return $match;
    }

    private function readGeneratedRouterLookupCache(): array
    {
        $property = new \ReflectionProperty(Core::class, 'generatedRouterLookupCache');
        $property->setAccessible(true);

        /** @var array $cache */
        $cache = $property->getValue();

        return $cache;
    }
}
