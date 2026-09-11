<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: guest default share shows social logos (trust); no "free share" copy wall.
 */
final class AffiliateProductShareHookContractTest extends TestCase
{
    public function testAfterAddToCartResolvesFromStorefrontOffer(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('storefront_offer', $src);
        self::assertStringContainsString('StorefrontOfferResolver', $src);
        self::assertStringContainsString('AffiliateStorefrontPolicy', $src);
        self::assertStringContainsString('data-affiliate-share-root', $src);
        self::assertStringContainsString('分销分享', $src);
        self::assertStringContainsString('data-weline-load="api,account,affiliateProductShare"', $src);
        self::assertStringContainsString('data-login-url', $src);
        self::assertStringContainsString('data-apply-url', $src);
        self::assertStringContainsString('data-free-share-url', $src);
        self::assertStringContainsString('data-affiliate-share-guest', $src);
        self::assertStringContainsString('登录前往申请分销', $src);
        self::assertStringContainsString('data-affiliate-default-platform', $src);
        self::assertStringContainsString('affiliate-share-platform__logo', $src);
        self::assertStringContainsString('facebook.com/sharer', $src);
        self::assertStringContainsString('<svg', $src);
        self::assertStringNotContainsString('也可以直接免费分享', $src);
        self::assertStringNotContainsString('data-affiliate-share-free', $src);
        self::assertStringNotContainsString('<script>', $src);
        self::assertStringContainsString('repeat(4, minmax(0, 1fr))', $src);
    }

    public function testProductShareJsWaitsForAccountAndCachesShare(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/affiliate-product-share.js';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('checkFrontendUserLogin', $src);
        self::assertStringContainsString('weline:account:frontend:login', $src);
        self::assertStringContainsString('weline:account:frontend:logout', $src);
        self::assertStringContainsString('syncDefaultPlatformHrefs', $src);
        self::assertStringContainsString('Never wipe SSR default icons', $src);
        self::assertStringContainsString('data-affiliate-default-platform', $src);
        self::assertStringContainsString('20260909-default-icons2', file_get_contents(dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js'));
        self::assertStringContainsString('getProductShareLinks', $src);
        self::assertStringContainsString('showGuestPromo', $src);
        self::assertStringNotContainsString('freeShareHint', $src);
        self::assertStringNotContainsString('clip: rect(0, 0, 0, 0)', $src);
        self::assertStringContainsString('<svg', $src);
    }

    public function testModulesRegisterAffiliateProductShare(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('affiliateProductShare', $src);
        self::assertStringContainsString('affiliate-product-share.js', $src);
    }
}
