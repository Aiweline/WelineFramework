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
