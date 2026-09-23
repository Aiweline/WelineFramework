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

    public function testLayoutSlotSkipsFillWhenSolidifiedElseSafetyNet(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );

        self::assertStringContainsString('shouldForcePublishedZeroRuntimeFill', $src);
        self::assertStringContainsString('wave8-8s5', $src);
        self::assertStringContainsString('CTX_ZERO_FILL_APPLIED', $src);
        self::assertStringContainsString('theme.layout_slot.zero_runtime_fill', $src);
        self::assertStringContainsString('+skip_fill_solidified', $src);
        self::assertStringContainsString('+safety_net_fill', $src);
        self::assertStringContainsString('shellSafetyNetFillReason', $src);
        self::assertStringContainsString('prefillPublishedChromeFromRenderedSnapshot', $src);
        self::assertStringContainsString('healPublishedPlaceholderShell', $src);
        self::assertStringContainsString('resolveSafetyNetPageType', $src);
        self::assertStringContainsString("'skipped_fill' => true", $src);
        self::assertStringContainsString("'skipped_fill' => false", $src);
        self::assertStringContainsString("editor_mode", $src);
        self::assertStringContainsString('isPreviewMode()', $src);

        // 布局固化与默认注入.md §3–§4: complete skip_fill → strip only (bake owns required).
        $skipPos = \strpos($src, "\$reason .= '+skip_fill_solidified'");
        self::assertNotFalse($skipPos);
        $stripAfterSkip = \strpos($src, 'SlotBoundaryMarkers::strip', $skipPos);
        self::assertNotFalse($stripAfterSkip);
        $between = \substr($src, $skipPos, $stripAfterSkip - $skipPos);
        self::assertStringNotContainsString('fillRequiredDefaultsOnShell', $between);
        self::assertStringContainsString('布局固化与默认注入', $between);
        // Incomplete shells still self-heal with Overlay on safety-net path.
        self::assertStringContainsString('+safety_net_fill', $src);
        $safetyPos = \strpos($src, '+safety_net_fill');
        self::assertNotFalse($safetyPos);
        self::assertStringContainsString('fillRequiredDefaultsOnShell', \substr($src, $safetyPos, 2500));
    }

    public function testHostDoesNotStickyMissOnTransientIncludeError(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPublishedSlotHost.php'
        );

        self::assertStringContainsString('CTX_PRIME_TRANSIENT_ERROR', $src);
        self::assertStringContainsString('DO NOT sticky-miss', $src);
        self::assertStringContainsString('wave8-8s4', $src);
        self::assertStringContainsString('shellNeedsRuntimeSafetyNetFill', $src);
        self::assertStringContainsString('shellSafetyNetFillReason', $src);
        self::assertStringContainsString('list-filters|category-filters', $src);
        self::assertStringContainsString('shellHasEmptyCriticalPublishedSlots', $src);
        self::assertStringContainsString('shellMissingStorefrontChromeSignals', $src);
        self::assertStringContainsString('shellHasBlankExclusiveChromeRoots', $src);
        self::assertStringContainsString('weline-header\\b(?!-)', $src);
    }

    public function testShellNeedsSafetyNetWhenListFiltersPlaceholderPresent(): void
    {
        $shellWithPlaceholder = '<!--@weline-slot:list-filters-->'
            . '<div data-wslot="list-filters" class="products-layout__sidebar-content">'
            . '<div class="products-layout__placeholder slot-placeholder"'
            . ' data-placeholder="list-filters">'
            . '<span>筛选器区域 - 由 Filters 部件默认注入</span>'
            . '</div></div>'
            . '<!--@/weline-slot:list-filters-->';

        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($shellWithPlaceholder)
        );

        $strippedOnly = SlotBoundaryMarkers::strip($shellWithPlaceholder);
        self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $strippedOnly));
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($strippedOnly)
        );
        self::assertStringContainsString('data-placeholder="list-filters"', $strippedOnly);
        self::assertStringNotContainsString('w-filters', $strippedOnly);

        // Empty list-grid placeholder alone must NOT force safety-net (narrow gate).
        $gridOnly = '<div class="products-layout__placeholder" data-placeholder="list-grid">none</div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($gridOnly)
        );

        $healed = '<!--@weline-slot:list-filters-->'
            . '<div class="theme-layout-entity-slot" data-slot-id="list-filters">'
            . '<div class="w-filters storefront-filters-panel" data-widget-code="category-filters">'
            . 'filters-ok</div></div>'
            . '<!--@/weline-slot:list-filters-->';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($healed)
        );
        $outbound = SlotBoundaryMarkers::strip($healed);
        self::assertStringContainsString('w-filters', $outbound);
        self::assertStringContainsString('storefront-filters-panel', $outbound);
        self::assertStringNotContainsString('data-placeholder="list-filters"', $outbound);
    }

    public function testShellNeedsSafetyNetWhenChromeOrHeaderMissing(): void
    {
        $emptyNav = '<div class="weline-page-wrapper">'
            . '<div class="theme-published-slot" data-slot-id="header-nav-extensions"></div>'
            . '<main class="weline-main-content homepage-main">body</main>'
            . '</div>';
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($emptyNav)
        );
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellHasEmptyCriticalPublishedSlots($emptyNav)
        );

        $missingHeader = '<div class="weline-page-wrapper">'
            . '<main class="weline-main-content homepage-main">body only</main>'
            . '</div>';
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($missingHeader)
        );
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($missingHeader)
        );

        // Complete shell: header signals present → may skip fill.
        $complete = '<div class="weline-page-wrapper">'
            . '<header class="weline-header"><nav class="header-nav">客户服务</nav>'
            . '<div class="header-account">acct</div></header>'
            . '<main class="weline-main-content homepage-main">'
            . '<div class="theme-published-slot" data-slot-id="homepage-hero">hero-ok</div>'
            . '</main></div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($complete)
        );
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($complete)
        );
        $outbound = SlotBoundaryMarkers::strip($complete);
        self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $outbound));
        self::assertStringContainsString('weline-header', $outbound);
        self::assertStringContainsString('客户服务', $outbound);

        // wave9-9s3: Hanfu / published chrome markers without weline-header class
        // must not false-negative the completeness gate.
        $hanfuChrome = '<div class="weline-page-wrapper">'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<nav>帮助中心</nav></div>'
            . '<div class="theme-published-slot" data-slot-id="header-nav-extensions">'
            . '<a href="/blog">博客</a></div>'
            . '<main class="weline-main-content homepage-main">ok</main></div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($hanfuChrome)
        );
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($hanfuChrome)
        );

        $atelier = '<div class="weline-page-wrapper">'
            . '<header class="hanfu-atelier-chrome">顶栏</header>'
            . '<main class="homepage-main">ok</main></div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($atelier)
        );
    }

    /**
     * wave9-9s5/9s6: complete chrome shells must skip heal even with empty delivery /
     * nested same-tag HTML that used to false-positive blank via naive (.*?).
     * Empty footer--shell is 缺壳 (missing_chrome_blank_header_or_footer), not
     * emptyCrit-while-chromePresent (that false-forced ~2s heal every request).
     */
    public function testCompleteChromeShellSkipsDespiteEmptyDeliveryOrNestedTags(): void
    {
        $withEmptyDelivery = '<div class="weline-page-wrapper products-layout">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav></header>'
            . '<div class="theme-published-slot" data-slot-id="header">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav></header></div>'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<footer class="weline-footer"><div class="footer-container">Help</div></footer></div>'
            . '<div class="theme-published-slot" data-slot-id="delivery"></div>'
            . '<div class="theme-published-slot" data-slot-id="list-filters">'
            . '<div class="w-filters">filters-ok</div></div>'
            . '<main class="weline-main-content">list</main></div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($withEmptyDelivery)
        );
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellHasEmptyCriticalPublishedSlots($withEmptyDelivery),
            'chrome complete → empty delivery alone must not force heal'
        );
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($withEmptyDelivery)
        );
        self::assertSame(
            'none',
            ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($withEmptyDelivery)
        );

        // Header present + empty footer shell = 缺壳 (not emptyCrit chromePresent branch).
        $emptyFooterShell = '<div class="weline-page-wrapper products-layout">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav></header>'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<footer class="weline-footer weline-footer--shell"></footer></div>'
            . '<main class="weline-main-content">list</main></div>';
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($emptyFooterShell),
            'empty footer--shell is 缺壳 even with header signal'
        );
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellHasEmptyCriticalPublishedSlots($emptyFooterShell)
                && !ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($emptyFooterShell),
            'must not classify empty footer as emptyCrit-while-chromePresent'
        );
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($emptyFooterShell)
        );
        self::assertSame(
            'missing_chrome_blank_header_or_footer',
            ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($emptyFooterShell)
        );

        // Nested empty <div></div> before real content — naive regex captured blank.
        $nestedFooter = '<div class="weline-page-wrapper">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav></header>'
            . '<div class="theme-published-slot" data-slot-id="header">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav></header></div>'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<div></div><footer class="weline-footer">帮助中心</footer></div>'
            . '<main class="homepage-main">ok</main></div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($nestedFooter)
        );

        // Empty filters still heal even when chrome is complete.
        $emptyFilters = '<div class="weline-page-wrapper products-layout">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav></header>'
            . '<div class="theme-published-slot" data-slot-id="header">'
            . '<header class="weline-header">h</header></div>'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<footer class="weline-footer">f</footer></div>'
            . '<div class="theme-published-slot" data-slot-id="list-filters"></div>'
            . '<main>list</main></div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($emptyFilters)
        );
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellHasEmptyCriticalPublishedSlots($emptyFilters)
        );
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($emptyFilters)
        );
        self::assertSame(
            'empty_critical_filters',
            ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($emptyFilters)
        );
    }

    public function testWelineHeaderSlotClassIsNotChromeSignalAlone(): void
    {
        // Substring trap: class weline-header-slot must not false-pass completeness.
        $slotOnly = '<div class="weline-page-wrapper products-layout">'
            . '<div class="theme-published-slot weline-header-slot" data-slot-id="header"></div>'
            . '<div class="theme-published-slot weline-footer-slot" data-slot-id="footer">'
            . '<footer class="weline-footer weline-footer--shell"></footer></div>'
            . '<main class="weline-main-content">list</main></div>';
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellHasStorefrontHeaderSignal($slotOnly)
        );
        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($slotOnly)
        );
        self::assertSame(
            'missing_chrome_header_signals',
            ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($slotOnly)
        );
    }

    public function testLayoutSlotSkipPathGatesBeforePrime(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );
        self::assertStringContainsString('wave9-9s5', $src);
        self::assertStringContainsString('wave9-9s6', $src);
        self::assertStringContainsString('gate FIRST', $src);
        self::assertStringContainsString('chrome_snapshot_prefill', $src);
        self::assertStringContainsString('prefillPublishedChromeFromRenderedSnapshot', $src);
        self::assertStringContainsString('shellSafetyNetFillReason', $src);
        self::assertStringNotContainsString('/tmp/weline_zero_fill_debug.log', $src);
        $forcePos = \strpos($src, 'shouldForcePublishedZeroRuntimeFill');
        self::assertNotFalse($forcePos);
        // Limit to the published zero-runtime-fill branch (avoid earlier/later primes).
        $block = \substr($src, (int)$forcePos, 12000);
        $gatePos = \strpos($block, 'shellSafetyNetFillReason($html)');
        $prefillPos = \strpos($block, 'prefillPublishedChromeFromRenderedSnapshot');
        $primePos = \strpos($block, 'primeStorefront(');
        $healPos = \strpos($block, 'healPublishedPlaceholderShell');
        self::assertNotFalse($gatePos);
        self::assertNotFalse($prefillPos);
        self::assertNotFalse($primePos);
        self::assertNotFalse($healPos);
        self::assertLessThan($prefillPos, $gatePos, 'gate before snapshot prefill');
        self::assertLessThan($primePos, $prefillPos, 'snapshot prefill before prime');
        self::assertLessThan($healPos, $primePos, 'prime before heal');
    }

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

    public function testUseReactiveMarkersFalseWhenFragmentsCached(): void
    {
        RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_THEME_ID, 1);
        RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_LAYOUT_TYPE, 'homepage');
        RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS, [
            'page_html' => '<!--@weline-slot:content--><div class="theme-layout-entity-slot" data-slot-id="content">ok</div><!--@/weline-slot:content-->',
            'chrome_by_slot' => [],
        ]);
        RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE, false);

        self::assertFalse(ThemeLayoutEntityPublishedSlotHost::useReactiveMarkers());
        self::assertFalse(RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE));
    }

    public function testSlotFillerAllowsPlaceholderSafetyNetOnPublished(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );

        self::assertStringContainsString('wave8-8s5', $src);
        self::assertStringContainsString('healPublishedPlaceholderShell', $src);
        self::assertStringContainsString('shellNeedsRuntimeSafetyNetFill', $src);
        self::assertStringContainsString('narrow safety-net exception', $src);
        self::assertStringContainsString('spliceChromeSlotsFromBake', $src);
        self::assertStringContainsString('wave9-9s2', $src);
        // wave9-9s4: page-only shell must still load finalized chrome (renderCurrent / chrome.rendered).
        self::assertStringContainsString('wave9-9s4', $src);
        self::assertStringContainsString('loadPublishedChromeBakeHtmlDirect', $src);
        self::assertStringContainsString('renderCurrent', $src);
        self::assertStringNotContainsString(
            'return $this->includeEntityPhtml($path);',
            \substr(
                $src,
                (int)\strpos($src, 'function loadPublishedChromeBakeHtmlDirect'),
                800,
            ),
            'chrome graft must not raw-include chrome.phtml injectors',
        );
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($src, 'loadPublishedChromeBakeHtmlDirect('),
            'shell empty-chrome fallback + heal empty chromeBySlot must both load chrome bake',
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
