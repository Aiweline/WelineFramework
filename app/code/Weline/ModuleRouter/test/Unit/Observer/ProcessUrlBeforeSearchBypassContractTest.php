<?php

declare(strict_types=1);

namespace Weline\ModuleRouter\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\ModuleRouter\Observer\ProcessUrlBefore;

final class ProcessUrlBeforeSearchBypassContractTest extends TestCase
{
    public function testStorefrontSearchIsNotBypassedSoSearchRouterCanOwnIt(): void
    {
        $method = new ReflectionMethod(ProcessUrlBefore::class, 'shouldBypassNormalizedPath');

        self::assertFalse($method->invoke(null, 'search'));
        self::assertFalse($method->invoke(null, 'search/frontend'));
        self::assertFalse($method->invoke(null, 'wishlist'));
        self::assertFalse($method->invoke(null, 'compare'));
        self::assertTrue($method->invoke(null, 'cart'));
        self::assertTrue($method->invoke(null, 'static/theme.css'));
    }
}
