<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderNavFragment;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;

/** R2: header nav mega/horizontal keys collapse into one prefetchPolicy MGET. */
final class HeaderNavFragmentPrefetchContractTest extends TestCase
{
    public function testPrefetchCategoryNavFragmentsApiExists(): void
    {
        self::assertTrue(
            method_exists(StorefrontHeaderNavFragmentCache::class, 'prefetchCategoryNavFragments'),
        );
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontHeaderNavFragmentCache.php',
        );
        self::assertStringContainsString('prefetchPolicy(self::cachePolicy()', $src);
        self::assertStringContainsString('megaMenuPanelLogicalKey', $src);
        self::assertStringContainsString('horizontalNavLogicalKey', $src);
    }

    public function testHorizontalFetchPrimesPrefetchBeforeRemember(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php',
        );
        self::assertStringContainsString('prefetchCategoryNavFragments', $src);
        self::assertStringContainsString('rememberCategoriesHorizontalNav', $src);
        $prefetchPos = \strpos($src, 'prefetchCategoryNavFragments');
        $rememberPos = \strpos($src, 'rememberCategoriesHorizontalNav');
        self::assertNotFalse($prefetchPos);
        self::assertNotFalse($rememberPos);
        self::assertLessThan($rememberPos, $prefetchPos);
        self::assertTrue(class_exists(HeaderNavFragment::class));
    }
}
