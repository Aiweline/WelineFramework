<?php

declare(strict_types=1);

namespace Weline\ModuleRouter\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\ModuleRouter\Observer\ProcessUrlBefore;

final class ProcessUrlBeforeRoutingOrderTest extends TestCase
{
    public function testThemeFallbackRouterRunsAfterBusinessRouters(): void
    {
        $method = new ReflectionMethod(ProcessUrlBefore::class, 'orderFallbackRoutersLast');
        $routers = [
            'Weline_Theme' => ['class' => 'ThemeRouter'],
            'Weline_Product' => ['class' => 'ProductRouter'],
            'Vendor_Custom' => ['class' => 'CustomRouter'],
        ];

        $ordered = $method->invoke(null, $routers);

        self::assertSame(
            ['Weline_Product', 'Vendor_Custom', 'Weline_Theme'],
            array_keys($ordered),
        );
    }

    public function testMissingThemeRouterKeepsDiscoveryOrder(): void
    {
        $method = new ReflectionMethod(ProcessUrlBefore::class, 'orderFallbackRoutersLast');
        $routers = [
            'Weline_Product' => ['class' => 'ProductRouter'],
            'Vendor_Custom' => ['class' => 'CustomRouter'],
        ];

        $ordered = $method->invoke(null, $routers);

        self::assertSame(array_keys($routers), array_keys($ordered));
    }
}
