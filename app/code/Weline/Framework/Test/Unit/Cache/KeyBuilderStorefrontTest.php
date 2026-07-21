<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\KeyBuilder;

class KeyBuilderStorefrontTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER['WELINE_WEBSITE_CODE'],
            $_SERVER['WELINE_WEBSITE_ID'],
            $_SERVER['WELINE_AREA'],
            $_SERVER['WELINE_USER_LANG'],
            $_SERVER['WELINE_USER_CURRENCY']
        );
        parent::tearDown();
    }

    public function testResolveWebsiteCodePrefersCodeAndFallsBackToDefault(): void
    {
        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_a';
        self::assertSame('shop_a', KeyBuilder::resolveWebsiteCode());

        unset($_SERVER['WELINE_WEBSITE_CODE']);
        $_SERVER['WELINE_WEBSITE_ID'] = '12';
        self::assertSame('default', KeyBuilder::resolveWebsiteCode());
    }

    public function testApplyDimensionFlagsFullEscapeLeavesLogicalKey(): void
    {
        self::assertSame('phrase:zh_Hans_CN', KeyBuilder::applyDimensionFlags('phrase:zh_Hans_CN'));
    }

    public function testApplyDimensionFlagsSelectiveAndDefaultStorefront(): void
    {
        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_a';
        $_SERVER['WELINE_AREA'] = 'frontend';
        $_SERVER['WELINE_USER_LANG'] = 'en_US';
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';

        $langOnly = KeyBuilder::applyDimensionFlags('menu', false, true, false, false);
        self::assertStringStartsWith('menu|lang=', $langOnly);
        self::assertStringContainsString('lang=', $langOnly);
        self::assertStringNotContainsString('website=', $langOnly);
        self::assertStringNotContainsString('currency=', $langOnly);
        self::assertStringNotContainsString('area=', $langOnly);

        $full = KeyBuilder::applyDimensionFlags('menu', true, true, true, true);
        self::assertStringContainsString('area=', $full);
        self::assertStringContainsString('website=shop_a', $full);
        self::assertStringContainsString('lang=', $full);
        self::assertStringContainsString('currency=', $full);
    }

    public function testStorefrontDimensionsNeverUsesWebsiteId(): void
    {
        unset($_SERVER['WELINE_WEBSITE_CODE']);
        $_SERVER['WELINE_WEBSITE_ID'] = '99';
        $dims = KeyBuilder::storefrontDimensions();
        self::assertSame('default', $dims['website']);
        self::assertArrayHasKey('lang', $dims);
        self::assertArrayHasKey('currency', $dims);
        self::assertArrayHasKey('area', $dims);
    }
}
