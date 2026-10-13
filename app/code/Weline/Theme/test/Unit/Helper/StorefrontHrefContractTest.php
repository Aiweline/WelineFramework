<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\StorefrontHref;

final class StorefrontHrefContractTest extends TestCase
{
    public function testPathRelativeLinksStayForDocumentBase(): void
    {
        self::assertSame('promotion/deals', StorefrontHref::localize('promotion/deals'));
        self::assertSame('about/us', StorefrontHref::localize('about/us'));
    }

    public function testFragmentHrefUsesFallbackPathWhenRequestPathMissing(): void
    {
        // localize() may no-op in CLI (no frontend URL builder); path+#id shape must remain.
        $href = StorefrontHref::fragmentHref('shipping-s5', '/policy/shipping');
        self::assertStringEndsWith('#shipping-s5', $href);
        self::assertStringContainsString('policy/shipping', $href);

        $href2 = StorefrontHref::fragmentHref('#shipping-s1', '/policy/shipping');
        self::assertStringEndsWith('#shipping-s1', $href2);
        self::assertStringContainsString('policy/shipping', $href2);

        self::assertSame('#', StorefrontHref::fragmentHref('', '/policy/shipping'));
    }

    public function testFragmentHrefSourceCallsLocalizeBeforeAppend(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/StorefrontHref.php');
        self::assertStringContainsString('self::localize(self::currentDocumentPath($fallbackPath))', $src);
    }

    public function testExternalAbsoluteUrlsPassThrough(): void
    {
        self::assertSame(
            'https://example.com/promotion/deals',
            StorefrontHref::localize('https://example.com/promotion/deals')
        );
    }

    public function testAdBannerAndPromoUseSiteBlockLink(): void
    {
        $ad = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/ad-banner/default.phtml';
        $promo = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/promo-banner/default.phtml';
        self::assertFileExists($ad);
        self::assertFileExists($promo);
        $adSrc = (string)file_get_contents($ad);
        $promoSrc = (string)file_get_contents($promo);
        self::assertStringContainsString('SiteBlockConfig::link', $adSrc);
        self::assertStringContainsString('SiteBlockConfig::link', $promoSrc);
        self::assertStringNotContainsString("LegacyMediaUrl::sanitize(\$this->getData('link'", $adSrc);
        self::assertStringNotContainsString("LegacyMediaUrl::sanitize(\$this->getData('link'", $promoSrc);
    }
}
