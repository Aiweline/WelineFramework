<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\StorefrontHanfuDemoCatalog;

final class StorefrontHanfuDemoCatalogTest extends TestCase
{
    public function testOnlyKnownHanfuWebsiteCodesAllowShellDemos(): void
    {
        self::assertTrue(StorefrontHanfuDemoCatalog::allowed('default'));
        self::assertTrue(StorefrontHanfuDemoCatalog::allowed('hanfu'));
        self::assertTrue(StorefrontHanfuDemoCatalog::allowed('changanhanfu'));
        self::assertFalse(StorefrontHanfuDemoCatalog::allowed('grocery'));
        self::assertFalse(StorefrontHanfuDemoCatalog::allowed('daocharms'));
        self::assertFalse(StorefrontHanfuDemoCatalog::allowed('brand-new-site'));
    }
}
