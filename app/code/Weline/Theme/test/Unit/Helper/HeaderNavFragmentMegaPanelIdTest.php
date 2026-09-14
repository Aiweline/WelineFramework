<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderNavFragment;

/**
 * Mega panel ids must stay unique when two top-level categories share a slug.
 */
final class HeaderNavFragmentMegaPanelIdTest extends TestCase
{
    public function testAllocateMegaPanelIdUniquifiesSameSlugDifferentUrls(): void
    {
        $used = [];
        $first = HeaderNavFragment::allocateMegaPanelId(
            'yun-dong-hu-wai',
            '/category/sourcing/cj/yun-dong-hu-wai',
            $used
        );
        $second = HeaderNavFragment::allocateMegaPanelId(
            'yun-dong-hu-wai',
            '/category/yun-dong-hu-wai',
            $used
        );

        self::assertSame('mega-menu-yun-dong-hu-wai', $first);
        self::assertNotSame($first, $second);
        self::assertStringStartsWith('mega-menu-yun-dong-hu-wai-', $second);
        self::assertCount(2, $used);
        self::assertArrayHasKey($first, $used);
        self::assertArrayHasKey($second, $used);
    }

    public function testAllocateMegaPanelIdUsesIdentitySuffixOnCollision(): void
    {
        $used = [];
        $first = HeaderNavFragment::allocateMegaPanelId('sports', '/a', $used, '10');
        $second = HeaderNavFragment::allocateMegaPanelId('sports', '/b', $used, '99');

        self::assertSame('mega-menu-sports', $first);
        self::assertSame('mega-menu-sports-99', $second);
    }

    public function testHorizontalNavUsesAllocateMegaPanelId(): void
    {
        $partial = dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/categories-horizontal-nav.phtml';
        $src = (string) file_get_contents($partial);
        self::assertStringContainsString('allocateMegaPanelId', $src);
        self::assertStringContainsString('$usedMegaPanelIds', $src);
        self::assertStringNotContainsString(
            "\$panelId = 'mega-menu-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(\$text));",
            $src
        );
    }
}
