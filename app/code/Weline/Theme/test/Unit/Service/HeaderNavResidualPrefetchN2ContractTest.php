<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderCommerceData;
use Weline\Theme\Helper\HeaderNavFragment;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;

/**
 * N2: residual header shared_read — sidebar/mega/search + category_nav must
 * protocol-MGET before remember (R2 covered horizontal only).
 */
final class HeaderNavResidualPrefetchN2ContractTest extends TestCase
{
    public function testPrefetchApiAcceptsExtraKeysAndSearchTypes(): void
    {
        $cacheSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontHeaderNavFragmentCache.php',
        );
        self::assertStringContainsString('bothBannerVariants', $cacheSrc);
        self::assertStringContainsString('extraLogicalKeys', $cacheSrc);
        self::assertStringContainsString('prefetchSearchTypeDropdown', $cacheSrc);
        self::assertTrue(
            method_exists(StorefrontHeaderNavFragmentCache::class, 'prefetchSearchTypeDropdown'),
        );
    }

    public function testSidebarAndMegaPrefetchBeforeRemember(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php',
        );
        self::assertStringContainsString('prefetchCategoryNavFragments', $src);
        self::assertStringContainsString('prefetchSearchTypeDropdown', $src);

        foreach ([
            ['fetchCategoriesSidebarNav', 'prefetchCategoryNavFragments', 'rememberCategoriesSidebarNav'],
            ['fetchMegaMenuPanel', 'prefetchCategoryNavFragments', 'rememberMegaMenuPanel'],
            ['fetchSearchTypeDropdown', 'prefetchSearchTypeDropdown', 'rememberSearchTypeDropdown'],
        ] as [$fn, $prefetch, $remember]) {
            $fnPos = \strpos($src, 'function ' . $fn);
            self::assertNotFalse($fnPos, $fn . ' missing');
            $slice = \substr($src, $fnPos, 2800);
            $sp = \strpos($slice, $prefetch);
            $sr = \strpos($slice, $remember);
            self::assertNotFalse($sp, $fn . ' missing ' . $prefetch);
            self::assertNotFalse($sr, $fn . ' missing ' . $remember);
            self::assertLessThan($sr, $sp, $fn . ' must prefetch before remember');
        }

        self::assertTrue(class_exists(HeaderNavFragment::class));
    }

    public function testCategoryNavPrimesSharedKeyThenFragments(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/HeaderCommerceData.php',
        );
        $fn = \strpos($src, 'function resolveCategoryNavItems');
        self::assertNotFalse($fn);
        $slice = \substr($src, $fn, 4500);
        self::assertStringContainsString('prefetchCategoryNavFragments', $slice);
        $firstPrefetch = \strpos($slice, 'prefetchCategoryNavFragments([], true, [$sharedKey]');
        $remember = \strpos($slice, 'rememberRequestMemo');
        self::assertNotFalse($firstPrefetch);
        self::assertNotFalse($remember);
        self::assertLessThan($remember, $firstPrefetch);
        self::assertTrue(class_exists(HeaderCommerceData::class));
    }
}
