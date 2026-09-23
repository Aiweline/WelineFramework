<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductAddToCartWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsPurchaseActionsSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('product-add-to-cart', $widgets);
        $widget = $widgets['product-add-to-cart'];
        self::assertSame('product-purchase-actions', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Cart::templates/frontend/widgets/product-add-to-cart.phtml',
            $widget['template'] ?? null,
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-purchase-actions', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
    }

    public function testWidgetTemplateUsesCartPurchaseActionsScript(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-add-to-cart.phtml',
        );
        self::assertStringContainsString('data-testid="product-add-to-cart"', $template);
        self::assertStringContainsString('data-action="add"', $template);
        self::assertStringContainsString('!$quoteOnly', $template);
        self::assertStringContainsString('data-quote-only', $template);
        self::assertStringContainsString('data-weline-load="cart"', $template);
        self::assertStringNotContainsString('@static(Weline_Cart::js/widgets/product-purchase-actions.js)', $template);
        self::assertStringContainsString('data-purchase-loading', $template);
        self::assertStringContainsString('data-purchase-success', $template);
        self::assertStringContainsString('data-cart-url', $template);
        self::assertStringContainsString('data-cart-link-text', $template);
        self::assertStringNotContainsString('product-native-detail__cart', $template);
        self::assertStringNotContainsString('data-purchase-mark-added', $template);
        self::assertStringContainsString('StorefrontOfferResolver::resolve', $template);
    }

    public function testPurchaseActionsScriptDispatchesCartUpdatedEvent(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );
        self::assertStringContainsString('notifyCartUpdated', $script);
        self::assertStringContainsString('weline:cart-updated', $script);
        self::assertStringContainsString('weline:cart:update', $script);
        self::assertStringContainsString('rememberSummary', $script);
        self::assertStringContainsString("load('miniCartIcon')", $script);
        self::assertStringContainsString('applyCachedSummary', $script);
    }

    public function testPurchaseActionsSubmitSelectedEavVariantValues(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );

        self::assertStringContainsString('function readEavSelection(button)', $script);
        self::assertStringContainsString("'[data-variant-option].is-selected'", $script);
        self::assertStringContainsString('selection: readEavSelection(button)', $script);
    }

    public function testPurchasePanelReopenClearsNativeDialogHidden(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );

        // Weline.UI.dialog close() sets hidden on native <dialog>; reopen must clear it
        // or the second listing Add-to-Cart looks like a no-op (invisible modal).
        self::assertStringContainsString('function revealPurchasePanel(dialog)', $script);
        self::assertStringContainsString('function closePurchasePanel(dialog)', $script);
        self::assertStringContainsString('dialog.removeAttribute(\'hidden\')', $script);
        self::assertStringContainsString('revealPurchasePanel(dialog)', $script);
        self::assertStringContainsString('closePurchasePanel(dialog)', $script);
        self::assertStringContainsString('Weline.UI.dialog', $script);
        self::assertStringContainsString(
            'product-purchase-actions.js?v=20260922-purchase-panel-binquery',
            $modules,
        );
    }

    public function testPurchasePanelFetchFailureReplacesLoadingWithError(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );

        self::assertStringContainsString('function humanizePurchaseError(error, fallback)', $script);
        self::assertStringContainsString('function showPurchasePanelError(body, message)', $script);
        self::assertStringContainsString('w-product-purchase-panel__error', $script);
        self::assertStringContainsString('failed to fetch|networkerror', $script);
        self::assertStringContainsString('worker_timeout', $script);
        self::assertStringContainsString('worker request timed out', $script);
        self::assertStringContainsString('网络异常，无法打开加购面板，请稍后重试', $script);
        self::assertStringContainsString('showPurchasePanelError(body, msg)', $script);
    }

    public function testPurchasePanelLoadsViaBinQueryProductProvider(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-card-add-to-cart.phtml',
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );

        // Storefront business I/O must be BinQuery — never native fetch to REST panel URL.
        self::assertStringContainsString('function waitForProductApi()', $script);
        self::assertStringContainsString("resource('product')", $script);
        self::assertStringContainsString('getPurchasePanel(panelParams', $script);
        self::assertStringNotContainsString('fetch(url.toString()', $script);
        self::assertStringNotContainsString('data-purchase-panel-url', $template);
        self::assertStringContainsString(
            'product-purchase-actions.js?v=20260922-purchase-panel-binquery',
            $modules,
        );
    }

    public function testAddOfferResolvesCartTypeFromPreferredModeBeforeSsrHtml(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );

        self::assertStringContainsString('function resolveAddCartType(button)', $script);
        self::assertStringContainsString('function productAllowsWholesaleAdd(button)', $script);
        self::assertStringContainsString('WelineB2BSellingMode.preferredMode', $script);
        self::assertStringNotContainsString('confirmCartTypeForAdd', $script);
        self::assertStringContainsString('weline_cart_type_explicit', $script);
        self::assertStringContainsString('cart_type: sellingMode', $script);
        self::assertStringContainsString('syncChromeAfterRetailOnlyAdd', $script);
        self::assertStringContainsString('var mode = preferred || fromButton || fromHtml || \'toc\'', $script);
        // Old FPC-first chain must stay gone.
        self::assertStringNotContainsString(
            "button.dataset.sellingMode\n            || button.dataset.cartType\n            || (document.documentElement.getAttribute('data-selling-mode') || '')\n            || (window.WelineB2BSellingMode",
            $script,
        );
    }
}
