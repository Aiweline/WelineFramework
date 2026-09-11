<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelEventVendorScope;

final class PixelEventVendorScopeTest extends TestCase
{
    public function testNormalizeDefaults(): void
    {
        $scope = PixelEventVendorScope::normalize([]);
        self::assertSame(['*'], $scope['path_include']);
        self::assertSame([], $scope['path_exclude']);
        self::assertSame(['frontend'], $scope['areas']);
    }

    public function testMatchAllPathsOnFrontend(): void
    {
        $scope = PixelEventVendorScope::normalize([
            'path_include' => ['*'],
            'path_exclude' => [],
            'areas' => ['frontend'],
        ]);
        self::assertTrue(PixelEventVendorScope::matches($scope, '/product/abc', 'frontend'));
        self::assertFalse(PixelEventVendorScope::matches($scope, '/product/abc', 'backend'));
    }

    public function testExcludeWinsOverInclude(): void
    {
        $scope = PixelEventVendorScope::normalize([
            'path_include' => ['*'],
            'path_exclude' => ['/checkout*'],
            'areas' => ['frontend'],
        ]);
        self::assertTrue(PixelEventVendorScope::matches($scope, '/cart', 'frontend'));
        self::assertFalse(PixelEventVendorScope::matches($scope, '/checkout/success', 'frontend'));
    }

    public function testPrefixInclude(): void
    {
        $scope = PixelEventVendorScope::normalize([
            'path_include' => ['/product*', '/p/*'],
            'path_exclude' => [],
            'areas' => ['frontend'],
        ]);
        self::assertTrue(PixelEventVendorScope::matches($scope, '/product/1', 'frontend'));
        self::assertTrue(PixelEventVendorScope::matches($scope, '/p/x', 'frontend'));
        self::assertFalse(PixelEventVendorScope::matches($scope, '/blog', 'frontend'));
    }
}
