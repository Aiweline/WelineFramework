<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller;
use Weline\Theme\Service\ThemeLayoutBudgetPhases;
use Weline\Theme\Service\ThemePdpBudgetPhases;

/**
 * R1/R4 rework: narrow filter heal + pdp.* / L0–L4 phase reservation & stamps.
 */
final class ThemeReworkR1R4ContractTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLifecycleTrace::clearPanelTrace();
        RequestLifecycleTrace::reset();
        try {
            \Weline\Framework\Context::leave();
        } catch (\Throwable) {
        }
        \Weline\Framework\Runtime\Runtime::resetModeCache();
        parent::tearDown();
    }

    public function testFilterPlaceholderClassifiesBeforeGenericSlotPlaceholder(): void
    {
        $html = '<div class="weline-page-wrapper products-layout">'
            . '<header class="weline-header"><nav class="header-nav">客户服务 · 语言 · 账户</nav>'
            . '<div class="header-account">acct</div></header>'
            . '<div class="products-layout__placeholder slot-placeholder"'
            . ' data-placeholder="list-filters"><span>筛选器区域</span></div>'
            . '<main class="weline-main-content">list</main></div>';

        self::assertSame(
            'filter_data_placeholder',
            ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($html)
        );
        // LayoutSlot runs before chrome Partials — narrow heal must not require header.
        self::assertTrue(ThemeLayoutEntitySlotFiller::shouldPreferNarrowFilterHeal($html));
        $bodyOnly = '<div class="products-layout__placeholder slot-placeholder"'
            . ' data-placeholder="list-filters"><span>筛选器区域</span></div>';
        self::assertTrue(ThemeLayoutEntitySlotFiller::shouldPreferNarrowFilterHeal($bodyOnly));
    }

    public function testSubstantialHeaderDoesNotSkipEmptyFooterShell(): void
    {
        $emptyFooterShell = '<div class="weline-page-wrapper products-layout">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav></header>'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<footer class="weline-footer weline-footer--shell"></footer></div>'
            . '<main class="weline-main-content">list</main></div>';

        self::assertTrue(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($emptyFooterShell)
        );
        self::assertSame(
            'missing_chrome_blank_header_or_footer',
            ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($emptyFooterShell)
        );
    }

    public function testSubstantialHeaderIgnoresBlankExclusiveHeaderRoot(): void
    {
        $html = '<div class="weline-page-wrapper product-detail-layout">'
            . '<header class="weline-header">'
            . str_repeat('x', 90)
            . '<nav class="header-nav">客户服务 · 购物车 · 账户中心入口</nav>'
            . '<div class="header-account">login</div></header>'
            . '<div class="theme-published-slot" data-slot-id="header"></div>'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<footer class="weline-footer"><div class="footer-container">Help</div></footer></div>'
            . '<main class="product-detail-layout__main">pdp</main></div>';

        self::assertTrue(ThemeLayoutEntityPublishedSlotHost::shellHasSubstantialHeaderChrome($html));
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($html),
            'blank exclusive header root must not force heal when Partial header is substantial'
        );
        self::assertSame('none', ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($html));
    }

    public function testSubstantialFooterAllowsBlankExclusiveFooterRoot(): void
    {
        $html = '<div class="weline-page-wrapper products-layout">'
            . '<header class="weline-header">'
            . str_repeat('x', 90)
            . '<nav class="header-nav">客户服务 · 购物车 · 账户中心入口</nav>'
            . '<div class="header-account">login</div></header>'
            . '<div class="theme-published-slot" data-slot-id="footer">'
            . '<footer class="weline-footer weline-footer--shell"></footer></div>'
            . '<footer class="weline-footer"><div class="footer-container">'
            . str_repeat('帮助中心与页脚链接 ', 8)
            . '</div></footer>'
            . '<main class="weline-main-content">list</main></div>';

        self::assertTrue(ThemeLayoutEntityPublishedSlotHost::shellHasSubstantialHeaderChrome($html));
        self::assertTrue(ThemeLayoutEntityPublishedSlotHost::shellHasSubstantialFooterChrome($html));
        self::assertFalse(
            ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($html),
            'Partial footer body present → blank exclusive footer--shell is not 缺壳'
        );
    }

    public function testStampSectionAttributesAddsMissingPdpBudgetPhases(): void
    {
        $html = '<main class="product-detail-layout__main" id="product-detail-main" data-layout="product-detail">'
            . 'main</main>'
            . '<section class="product-detail-layout__related">rel</section>'
            . '<section class="product-detail-layout__personalization">pers</section>';

        $stamped = ThemePdpBudgetPhases::stampSectionAttributes($html);
        self::assertStringContainsString('data-pdp-budget-phase="pdp.main"', $stamped);
        self::assertStringContainsString('data-pdp-budget-phase="pdp.related_stack"', $stamped);
        self::assertStringContainsString('data-pdp-budget-phase="pdp.personalization"', $stamped);
        // Idempotent
        self::assertSame($stamped, ThemePdpBudgetPhases::stampSectionAttributes($stamped));
    }

    public function testReserveTraceBucketsSurvivesPhaseCap(): void
    {
        \Weline\Framework\Runtime\Runtime::setMode(\Weline\Framework\Runtime\RuntimeInterface::MODE_WLS);
        \Weline\Framework\Context::enter(new \Weline\Framework\Context([
            'input' => ['uri' => '/product/test'],
            'runtime' => ['request_context' => ['initialized' => true, 'request_id' => 'theme-r1r4-reserve']],
        ]));
        self::assertTrue(\Weline\Framework\Runtime\RequestContext::isInitialized());
        RequestLifecycleTrace::installPanelTraceOn();
        self::assertTrue(RequestLifecycleTrace::isEnabled());

        ThemePdpBudgetPhases::reserveTraceBuckets();
        self::assertArrayHasKey('pdp.main', RequestLifecycleTrace::getAggregateSummary()['phases'] ?? []);

        for ($i = 0; $i < 60; $i++) {
            RequestLifecycleTrace::measurePhase('noise.phase.' . $i, static fn(): int => $i);
        }
        RequestLifecycleTrace::measurePhase('pdp.main', static function (): string {
            $x = 0;
            for ($i = 0; $i < 2000; $i++) {
                $x += $i;
            }

            return 'main-body-' . $x;
        });
        RequestLifecycleTrace::measurePhase('pdp.personalization', static fn(): string => 'pers');

        $phases = RequestLifecycleTrace::getAggregateSummary()['phases'] ?? [];
        self::assertArrayHasKey('pdp.main', $phases);
        self::assertArrayHasKey('pdp.personalization', $phases);
        self::assertArrayHasKey(ThemeLayoutBudgetPhases::L3_SLOTS, $phases);
        self::assertGreaterThanOrEqual(1, (int)($phases['pdp.main']['calls'] ?? 0));
    }

    public function testWidgetCodeAndSlotMapsCoverUcPdpCold(): void
    {
        self::assertSame(ThemePdpBudgetPhases::MAIN, ThemePdpBudgetPhases::forWidgetCode('product-info'));
        self::assertSame(ThemePdpBudgetPhases::RELATED_STACK, ThemePdpBudgetPhases::forWidgetCode('cross-sell'));
        self::assertSame(ThemePdpBudgetPhases::PERSONALIZATION, ThemePdpBudgetPhases::forWidgetCode('you-may-like'));
        self::assertSame(ThemePdpBudgetPhases::PERSONALIZATION, ThemePdpBudgetPhases::forSlot('product-recently-viewed'));
    }

    public function testLayoutSlotRendererWiresNarrowFilterHeal(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php');
        self::assertStringContainsString('shouldPreferNarrowFilterHeal', $src);
        self::assertStringContainsString('healNarrowFilterPlaceholders', $src);
        self::assertStringContainsString('ThemePdpBudgetPhases::stampSectionAttributes', $src);
        self::assertStringContainsString('+narrow_filter_heal', $src);
        // Empty footer--shell also contains "weline-footer"; chrome_partial_skip must require body.
        self::assertStringContainsString('shellHasSubstantialFooterChrome', $src);
        self::assertStringContainsString('+chrome_partial_skip', $src);
        self::assertSame(
            0,
            preg_match_all(
                '/chrome_partial_skip[\s\S]{0,400}?\\\\str_contains\(\$html,\s*[\'"]weline-footer[\'"]\)/',
                $src,
            ),
            'chrome_partial_skip must not gate on bare str_contains(weline-footer)',
        );
        // Both skip sites use substantial footer (narrow-filter path + listing/PDP path).
        self::assertGreaterThanOrEqual(
            2,
            substr_count($src, 'shellHasSubstantialFooterChrome'),
        );
    }

    public function testSlotFillerExposesNarrowFilterHealApi(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        self::assertStringContainsString('function healNarrowFilterPlaceholders', $src);
        self::assertStringContainsString('theme.layout_slot.narrow_filter_heal', $src);
        self::assertStringContainsString('theme.layout_slot.safety_net_heal', $src);
        // N1: bake splice before Overlay allowlist
        self::assertStringContainsString('splicePublishedFilterSlotsFromBake', $src);
    }
}
