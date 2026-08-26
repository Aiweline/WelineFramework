<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;

final class StorefrontHeaderNavFragmentCacheTest extends TestCase
{
    private function service(): StorefrontHeaderNavFragmentCache
    {
        return (new \ReflectionClass(StorefrontHeaderNavFragmentCache::class))->newInstanceWithoutConstructor();
    }

    public function testMegaMenuPanelLogicalKeyVariesByPlacementAndStructure(): void
    {
        $service = $this->service();
        $item = [
            'text' => 'Electronics',
            'url' => '/categories/electronics',
            'children' => [
                ['text' => 'Phones', 'url' => '/categories/phones'],
            ],
        ];

        $top = $service->megaMenuPanelLogicalKey('mega-menu-electronics', false, $item);
        $drawer = $service->megaMenuPanelLogicalKey('mega-menu-electronics', true, $item);
        $other = $service->megaMenuPanelLogicalKey('mega-menu-electronics', false, [
            'text' => 'Electronics',
            'url' => '/categories/electronics',
            'children' => [
                ['text' => 'Laptops', 'url' => '/categories/laptops'],
            ],
        ]);

        self::assertStringContainsString('theme.header.mega_panel.top.', $top);
        self::assertStringContainsString('theme.header.mega_panel.drawer.', $drawer);
        self::assertNotSame($top, $drawer);
        self::assertNotSame($top, $other);
    }

    public function testSidebarNavLogicalKeyDependsOnNavList(): void
    {
        $service = $this->service();

        $first = $service->sidebarNavLogicalKey([
            ['text' => 'A', 'url' => '/a', 'children' => []],
        ]);
        $second = $service->sidebarNavLogicalKey([
            ['text' => 'B', 'url' => '/b', 'children' => []],
        ]);

        self::assertStringStartsWith('theme.header.sidebar_nav.', $first);
        self::assertNotSame($first, $second);
    }
}
