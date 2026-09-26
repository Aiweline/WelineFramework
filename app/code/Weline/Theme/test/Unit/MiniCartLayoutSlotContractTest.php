<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Theme\Service\SlotRendererService;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';

final class MiniCartLayoutSlotContractTest extends TestCase
{
    public function testMiniCartIconDeclaresLayoutScopedFooterExtrasSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="footer-extras"', $source);
        self::assertStringContainsString('layout="mini-cart"', $source);
        self::assertStringContainsString('mini-cart-drawer__extras', $source);
    }

    public function testCartModuleProvidesMiniCartThemeLayout(): void
    {
        $path = dirname(__DIR__, 4) . '/Weline/Cart/view/theme/frontend/layouts/mini-cart/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('data-layout="mini-cart"', $source);
        self::assertStringContainsString('<w:slot id="footer-extras"', $source);
        self::assertStringContainsString('mini-cart-coupon', $source);
        self::assertStringContainsString('order-notice', $source);
    }

    public function testMiniCartDefaultLayoutJsonSeedsFooterExtrasWidgets(): void
    {
        $path = dirname(__DIR__, 4) . '/Weline/Cart/view/theme/frontend/layouts/mini-cart/default.layout.json';
        self::assertFileExists($path);
        $decoded = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($decoded);
        $widgets = $decoded['widgets'] ?? [];
        self::assertCount(2, $widgets);
        self::assertSame('mini-cart-coupon', $widgets[0]['widget_code'] ?? null);
        self::assertSame('order-notice', $widgets[1]['widget_code'] ?? null);
        self::assertSame('footer-extras', $widgets[0]['slot_id'] ?? null);
    }

    public function testSlotRendererMergesLayoutScopedWidgets(): void
    {
        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $extract = new ReflectionMethod(SlotRendererService::class, 'extractSlotLayoutBindingsFromHtml');
        $extract->setAccessible(true);

        $html = '<div data-wslot="footer-extras" data-wslot-layout="mini-cart"></div>';
        self::assertSame(['footer-extras' => 'mini-cart'], $extract->invoke($service, $html));

        // Published outbound keeps layout on durable data-slot-layout after reactive strip.
        $published = '<div class="theme-published-slot" data-slot-id="footer-extras" data-slot-layout="mini-cart"></div>';
        self::assertSame(['footer-extras' => 'mini-cart'], $extract->invoke($service, $published));
    }

    public function testStripPromotesWslotLayoutToDurableSlotLayout(): void
    {
        $html = '<div data-wslot="footer-extras" data-wslot-layout="mini-cart" class="mini-cart-drawer__extras"></div>';
        $stripped = \Weline\Theme\Service\SlotBoundaryMarkers::strip($html);
        self::assertStringNotContainsString('data-wslot=', $stripped);
        self::assertStringNotContainsString('data-wslot-layout=', $stripped);
        self::assertStringContainsString('data-slot-id="footer-extras"', $stripped);
        self::assertStringContainsString('data-slot-layout="mini-cart"', $stripped);
        self::assertStringContainsString('theme-published-slot', $stripped);
    }

    public function testEmptyFooterExtrasForcesSafetyNetWhenChromePresent(): void
    {
        $shell = '<div class="weline-page-wrapper">'
            . '<header class="weline-header"><nav class="header-nav">nav</nav>'
            . '<div class="header-account">acct</div></header>'
            . '<div class="theme-published-slot mini-cart-drawer__extras" data-slot-id="footer-extras"></div>'
            . '<main class="weline-main-content homepage-main">ok</main></div>';
        self::assertFalse(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::shellMissingStorefrontChromeSignals($shell)
        );
        self::assertTrue(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::shellHasEmptyCriticalPublishedSlots($shell)
        );
        self::assertSame(
            'empty_critical_footer_extras',
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::shellSafetyNetFillReason($shell)
        );
        self::assertTrue(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($shell)
        );
    }

    public function testSlotRendererFillsUnmarkedLayoutScopedSlotsAfterBoundaryPass(): void
    {
        $path = dirname(__DIR__, 2) . '/Service/SlotRendererService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('fillRemainingUnmarkedSlotWidgets', $source);
        self::assertStringContainsString('layout-scoped widgets (e.g. mini-cart footer-extras)', $source);

        $methodPos = strpos($source, 'function processSlotFragmentWithBoundaries');
        self::assertNotFalse($methodPos);
        $fragment = substr($source, $methodPos, 8000);
        $restorePos = strpos($fragment, '$parker->restore($html)');
        $fillPos = strpos($fragment, '$this->fillRemainingUnmarkedSlotWidgets($html, $slotWidgets)');
        self::assertNotFalse($restorePos);
        self::assertNotFalse($fillPos);
        self::assertLessThan(
            $fillPos,
            $restorePos,
            'Nested mini-cart footer-extras is parked inside widget-wrappers; restore must run before fillRemaining'
        );
    }

    public function testOpaqueParkerHidesNestedFooterExtrasInsideWidgetWrapper(): void
    {
        $html = <<<'HTML'
<div class="widget-wrapper" data-widget-code="mini-cart-icon">
<!--@weline-slot:footer-extras--><div data-wslot="footer-extras" data-wslot-layout="mini-cart" class="mini-cart-drawer__extras"></div><!--@/weline-slot:footer-extras-->
</div>
HTML;
        $parker = new \Weline\Theme\Service\SlotHtmlOpaqueParker();
        $parked = $parker->park($html);
        self::assertStringNotContainsString('data-wslot="footer-extras"', $parked);
        $restored = $parker->restore($parked);
        self::assertStringContainsString('data-wslot="footer-extras"', $restored);
        self::assertStringContainsString('data-wslot-layout="mini-cart"', $restored);
    }
}
