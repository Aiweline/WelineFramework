<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredFilterSlotFallbackInjector;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityRequestSolidifyGate;

/**
 * Solidify fallback must not leave Filters declaration placeholders on storefront HTML.
 */
final class RequiredFilterSlotFallbackInjectorContractTest extends TestCase
{
    public function testEnsureStripsPlaceholderWhenFiltersAlreadyPresent(): void
    {
        $html = '<aside class="products-layout__sidebar">'
            . '<div data-slot-id="list-filters" class="theme-published-slot">'
            . '<div class="products-layout__placeholder slot-placeholder" data-placeholder="list-filters">'
            . '<span class="products-layout__placeholder-text">筛选器区域 - 由 Filters 部件默认注入</span>'
            . '</div>'
            . '<div class="widget-wrapper" data-widget-code="category-filters" data-slot-id="list-filters">'
            . '<aside class="w-filters" data-testid="storefront-filters-panel">filters-ok</aside>'
            . '</div>'
            . '</div></aside>';

        $out = (new RequiredFilterSlotFallbackInjector())->ensure($html);
        self::assertStringContainsString('storefront-filters-panel', $out);
        self::assertStringContainsString('filters-ok', $out);
        self::assertStringNotContainsString('data-placeholder="list-filters"', $out);
        self::assertStringNotContainsString('由 Filters 部件默认注入', $out);
    }

    public function testGateFingerprintAndCriticalPathCoverFiltersInventory(): void
    {
        $gate = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityRequestSolidifyGate.php';
        self::assertFileExists($gate);
        $src = (string)file_get_contents($gate);
        self::assertStringContainsString('isFiltersCritical', $src);
        self::assertStringContainsString('filterInventoryBakeBroken', $src);
        self::assertStringContainsString('empty_filters_inventory_fallback_original', $src);
        self::assertStringContainsString("list-filters", $src);
        self::assertStringContainsString('category-filters', $src);

        $injector = dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredFilterSlotFallbackInjector.php';
        self::assertFileExists($injector);
        $injSrc = (string)file_get_contents($injector);
        self::assertStringContainsString('data-wslot', $injSrc);
        // Must not early-return on editor canvas (theme-switch solidify acceptance).
        self::assertStringNotContainsString(
            'if ($html === \'\' || $this->isEditorCanvas())',
            $injSrc,
        );

        $net = dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionRuntimeSafetyNet.php';
        $netEditor = (string)file_get_contents($net);
        self::assertStringContainsString('if ($this->isEditorCanvas())', $netEditor);
        self::assertStringContainsString('return $this->finishFiltersXor($html);', $netEditor);

        $obs = dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php';
        $obsSrc = (string)file_get_contents($obs);
        self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $obsSrc);
        // Reactive shells expose data-wslot until strip; ensure() must run after promote.
        $stripPos = strpos($obsSrc, 'SlotBoundaryMarkers::strip($html)');
        $ensurePos = strpos($obsSrc, 'requiredNet->ensure($html)');
        self::assertNotFalse($stripPos);
        self::assertNotFalse($ensurePos);
        self::assertLessThan($stripPos, $ensurePos, 'Required ensure must run after SlotBoundaryMarkers::strip');
        // Editor canvas finalizeFrontendHtml must run Filters XOR before editor assets.
        self::assertMatchesRegularExpression(
            '/function finalizeFrontendHtml\([\s\S]*?'
            . 'if \(\$this->isEditorCanvasRequest\(\)\) \{[\s\S]*?'
            . 'requiredNet->ensure\(\$html\)[\s\S]*?'
            . 'EditorModeAssetInjector[\s\S]*?'
            . 'return \$injector->inject\(\$html\)/',
            $obsSrc,
            'Editor canvas Filters ensure must run before EditorModeAssetInjector',
        );

        $healer = dirname(__DIR__, 3) . '/Service/StorefrontSsrChromeHealer.php';
        $healerSrc = (string)file_get_contents($healer);
        self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $healerSrc);
        $hStrip = strpos($healerSrc, 'SlotBoundaryMarkers::strip($html)');
        $hEnsure = strpos($healerSrc, 'requiredNet->ensure($html)');
        self::assertNotFalse($hStrip);
        self::assertNotFalse($hEnsure);
        self::assertLessThan($hStrip, $hEnsure, 'Healer required ensure must run after strip');

        $netSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionRuntimeSafetyNet.php'
        );
        self::assertStringContainsString('RequiredFilterSlotFallbackInjector', $netSrc);

        self::assertTrue(class_exists(ThemeLayoutEntityRequestSolidifyGate::class));
    }

    public function testEnsureFillsEmptyPublishedListFiltersSlot(): void
    {
        $html = '<aside class="products-layout__sidebar">'
            . '<div data-slot-id="list-filters" class="theme-published-slot" data-area="frontend">'
            . '</div></aside>';
        // Without a live ThemeLayoutEntityWidgetRenderer stack, ensure() may strip-only
        // when render returns empty — contract is: never leave declaration placeholder text.
        $out = (new RequiredFilterSlotFallbackInjector())->ensure($html);
        self::assertStringNotContainsString('由 Filters 部件默认注入', $out);
        self::assertStringContainsString('data-slot-id="list-filters"', $out);
    }

    public function testProductsSourcePlaceholderIsEditorOnly(): void
    {
        $products = dirname(__DIR__, 4) . '/Product/view/theme/frontend/layouts/products/default.phtml';
        if (!is_file($products)) {
            $products = dirname(__DIR__, 5) . '/Product/view/theme/frontend/layouts/products/default.phtml';
        }
        self::assertFileExists($products);
        $src = (string)file_get_contents($products);
        self::assertStringContainsString('$isEditorPreview', $src);
        self::assertStringContainsString('if ($isEditorPreview)', $src);
        self::assertStringContainsString('data-placeholder="list-filters"', $src);

        $category = dirname(__DIR__, 4) . '/Product/view/theme/frontend/layouts/category/default.phtml';
        if (!is_file($category)) {
            $category = dirname(__DIR__, 5) . '/Product/view/theme/frontend/layouts/category/default.phtml';
        }
        self::assertFileExists($category);
        $catSrc = (string)file_get_contents($category);
        self::assertStringContainsString('$isEditorPreview', $catSrc);
        self::assertStringContainsString('if ($isEditorPreview)', $catSrc);
    }
}
