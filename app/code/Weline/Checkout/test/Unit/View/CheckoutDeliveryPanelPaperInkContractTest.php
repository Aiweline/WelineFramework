<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Delivery panel nests under header [data-surface=inverse]; must rebind paper
 * ink locally (WO-UI-CONTRAST-INVERSE) or title/address rows paint cream-on-cream.
 */
final class CheckoutDeliveryPanelPaperInkContractTest extends TestCase
{
    public function testDeliveryPanelRebindsPaperInkTokens(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/widgets/widget-header-checkout-delivery-context-default.css'
        );

        self::assertStringContainsString('.wc-theme_widget_checkout_delivery_context .delivery-panel', $css);
        self::assertStringContainsString('WO-UI-CONTRAST-INVERSE', $css);
        self::assertStringContainsString('--_paper-text: var(--amz-drawer-text)', $css);
        self::assertStringContainsString('--color-text: var(--_paper-text)', $css);
        self::assertStringContainsString('--weline-theme-body-text: var(--_paper-text)', $css);
        self::assertStringContainsString('--w-surface-fg: var(--_paper-text)', $css);
        self::assertStringContainsString('color: var(--_paper-text)', $css);
    }
}
