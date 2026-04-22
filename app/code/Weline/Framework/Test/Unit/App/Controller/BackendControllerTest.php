<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Controller\BackendController;

final class BackendControllerTest extends TestCase
{
    public function testBackendWhitelistAcceptsRoutesBehindEntryPrefix(): void
    {
        self::assertTrue($this->invokeWhitelistCheck(
            'fihcOt0KAaSGD7NDdqHsCcD05Qo6PfR1/admin/login',
            ['admin/login']
        ));
    }

    public function testBackendWhitelistRejectsUnlistedPrefixedRoutes(): void
    {
        self::assertFalse($this->invokeWhitelistCheck(
            'fihcOt0KAaSGD7NDdqHsCcD05Qo6PfR1/admin/dashboard',
            ['admin/login']
        ));
    }

    private function invokeWhitelistCheck(string $routeUrlPath, array $whitelistUrls): bool
    {
        $controller = (new \ReflectionClass(BackendController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BackendController::class, 'isBackendWhitelistedRoute');
        $method->setAccessible(true);

        return $method->invoke($controller, $routeUrlPath, $whitelistUrls);
    }
}
