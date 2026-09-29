<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Service\SlotBoundaryMarkers;

/**
 * wave8-8s4/8s5+safety / wave9-9s2: strip without CTX===false gate; skip fill when
 * solidified AND complete; placeholders / empty chrome / missing header → heal path.
 */
final class PublishedStorefrontForcedZeroFillContractTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE);
        RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS);
        RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED);
        RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON);
        RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_PRIME_TRANSIENT_ERROR);
        parent::tearDown();
    }



    /**
     * 2026-09-26 用户纠偏（严格档「有固化就完全不注」）：
     * `publishedSolidifiedArtifactLoaded()` 以 `CTX_FRAGMENTS.page_html` 非空为唯一判据。
     */








    /**
     * wave9-9s5/9s6: complete chrome shells must skip heal even with empty delivery /
     * nested same-tag HTML that used to false-positive blank via naive (.*?).
     * Empty footer--shell is 缺壳 (missing_chrome_blank_header_or_footer), not
     * emptyCrit-while-chromePresent (that false-forced ~2s heal every request).
     */






    public function testStripProducesZeroDataWslotOnSimulatedPublishedHtml(): void
    {
        $html = '<!--@weline-slot:content-->'
            . '<div data-wslot="content" data-wslot-name="主内容" class="x">'
            . '<div data-wslot="widget-hero" data-wslot-exclusive="true">hero</div>'
            . '</div>'
            . '<!--@/weline-slot:content-->'
            . '<div data-wslot="logo">L</div>';

        RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED, true);
        RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON, 'forced_ctx_unset+skip_fill_solidified');
        RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE, true);

        self::assertFalse(ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html));

        $out = SlotBoundaryMarkers::strip($html);

        self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $out));
        self::assertStringNotContainsString('@weline-slot', $out);
        self::assertStringContainsString('hero', $out);
        self::assertTrue(RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED) === true);
        self::assertSame(
            'forced_ctx_unset+skip_fill_solidified',
            RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON)
        );
    }





    public function testProductsListingLayoutDefaultsChromeOn(): void
    {
        $productLayout = \dirname(__DIR__, 4) . '/Product/view/theme/frontend/layouts/products/default.phtml';
        if (!\is_file($productLayout)) {
            $productLayout = \dirname(__DIR__, 5) . '/Product/view/theme/frontend/layouts/products/default.phtml';
        }
        self::assertFileExists($productLayout);
        $src = (string)\file_get_contents($productLayout);
        self::assertStringContainsString('wave9-9s4', $src);
        self::assertStringContainsString("\$meta['showHeader'] = \$showHeader", $src);
        self::assertStringContainsString("\$meta['showFooter'] = \$showFooter", $src);
        self::assertStringContainsString("\$showHeader = \$meta['showHeader'] ?? \$this->getData('showHeader') ?? true", $src);
    }
}
