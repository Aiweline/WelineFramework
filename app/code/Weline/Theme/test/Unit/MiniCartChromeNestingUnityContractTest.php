<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\ThemeResourceGateway;

/**
 * Architecture unity: mini-cart nested chrome three axes + theme-scoped widget packs.
 */
final class MiniCartChromeNestingUnityContractTest extends TestCase
{
    public function testResourceAxisPeersPromoteMoneySummaryToLayout(): void
    {
        $peers = SharedChromeService::CHROME_HOST_NESTED_LAYOUT_CSS_PEERS;
        self::assertArrayHasKey('mini-cart-icon', $peers);
        self::assertContains(
            'Weline_Checkout::css/widgets/storefront-money-summary.css',
            $peers['mini-cart-icon']
        );

        $collector = new ThemeLayoutEntityAssetCollector(null);
        $manifest = $collector->collectFromNodes([
            [
                'node_uid' => str_repeat('c', 32),
                'widget_module' => 'Weline_Theme',
                'widget_code' => 'mini-cart-icon',
                'widget_type' => 'header',
                'is_active' => true,
                'layout_source' => 'Weline_Theme::css/widgets/mini-cart-drawer.css',
                'source' => 'Weline_Theme::js/widgets/mini-cart-icon.js',
            ],
        ], true);

        self::assertContains(
            'Weline_Checkout::css/widgets/storefront-money-summary.css',
            $manifest['layout_css']
        );
        self::assertNotContains(
            'Weline_Checkout::css/widgets/storefront-money-summary.css',
            $manifest['source_css']
        );
    }

    public function testShellAxisMiniCartNestedSlotAndPersistChromePayload(): void
    {
        self::assertContains('footer-extras', SharedChromeService::MINI_CART_NESTED_CHROME_SLOTS);

        $slotSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/SlotRendererService.php');
        self::assertStringContainsString('function fillEmptyNestedChromeSlots(', $slotSrc);
        self::assertStringContainsString('MINI_CART_NESTED_CHROME_SLOTS', $slotSrc);

        $bakeSrc = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'
        );
        self::assertStringContainsString('$this->persistChromePayload($identity)', $bakeSrc);
        self::assertStringNotContainsString('persistChromePayloadIfEmpty', $bakeSrc);
    }

    public function testLifecycleAxisExtrasRebuildAndCompactHooks(): void
    {
        $phtml = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml'
        );
        self::assertStringContainsString('data-mini-cart-footer-compact-amount', $phtml);
        self::assertStringContainsString(
            'Weline_Checkout::css/widgets/storefront-money-summary.css',
            $phtml
        );

        $extras = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/js/widgets/mini-cart-extras-tabs.js'
        );
        self::assertStringContainsString('function rebuildExtras', $extras);
        self::assertStringContainsString('rebuild: rebuildExtras', $extras);

        $miniJs = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/js/widgets/mini-cart-icon.js'
        );
        self::assertStringContainsString('.mini-cart-drawer__footer-compact-amount', $miniJs);

        $b2b = dirname(__DIR__, 3) . '/B2B/view/statics/js/selling-mode.js';
        self::assertFileExists($b2b);
        $b2bJs = (string)file_get_contents($b2b);
        // Chrome sync calls boot(); orphan unhide rebuilds inside extras-tabs (preserves active tab).
        self::assertStringContainsString('WelineMiniCartExtras.boot', $b2bJs);
        self::assertStringContainsString('Blind rebuild tore down tabs', $b2bJs);
        self::assertStringContainsString('preferredIdentity', $extras);
    }

    public function testThemeArtifactAxisExplicitThemeAndHashSkip(): void
    {
        $gateway = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/ThemeResourceGateway.php');
        self::assertMatchesRegularExpression(
            '/function buildWidgetAssetArtifact\s*\([^)]*\?WelineTheme\s+\$theme\s*=\s*null/s',
            $gateway
        );
        self::assertStringContainsString('/Weline/Theme/widget-assets/', $gateway);
        self::assertStringContainsString('Fail-closed', $gateway);

        $publisher = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/LayoutEntity/WidgetAssetArtifactPublisher.php'
        );
        self::assertStringContainsString('?WelineTheme $theme = null', $publisher);
        self::assertStringContainsString('buildWidgetAssetArtifact(', $publisher);
        self::assertStringContainsString('if (!is_file($target[\'path\']))', $publisher);
        self::assertStringContainsString('resolvePublishTheme', $publisher);

        $optimizer = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/LayoutEntity/WidgetAssetOptimizer.php'
        );
        self::assertStringContainsString("options['_theme']", $optimizer);

        $placement = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/LayoutEntity/WidgetAssetHtmlPlacement.php'
        );
        self::assertStringContainsString("options['_theme']", $placement);
        self::assertStringContainsString('ThemeData::getCurrentTheme', $placement);

        self::assertTrue(method_exists(ThemeResourceGateway::class, 'buildWidgetAssetArtifact'));
    }
}
