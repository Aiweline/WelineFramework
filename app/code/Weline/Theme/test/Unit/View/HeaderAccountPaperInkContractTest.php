<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Account dropdown nests under header [data-surface=inverse]; must rebind paper
 * ink locally (WO-UI-CONTRAST-INVERSE) or menu links paint cream-on-cream.
 *
 * Regression: an earlier pass seeded --amz-drawer-text from
 * var(--color-text-primary); under inverse that token is already on-dark cream,
 * so inherited design themes could not rely on the Theme widget CSS for contrast.
 *
 * Mirror: MiniCartShopifyDrawerContractTest / CheckoutDeliveryPanelPaperInkContractTest.
 */
final class HeaderAccountPaperInkContractTest extends TestCase
{
    public function testAccountDropdownRebindsPaperInkUnderInverseChrome(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/widgets/widget-header-account-default.css'
        );

        self::assertStringContainsString('.wc-theme_widget_account .account-dropdown', $css);
        self::assertStringContainsString('WO-UI-CONTRAST-INVERSE', $css);
        self::assertStringContainsString('--weline-chrome-body-text: #0f1111', $css);
        self::assertStringContainsString('--weline-chrome-body-text-secondary: #565959', $css);
        self::assertStringContainsString('--amz-drawer-text: var(--weline-chrome-body-text)', $css);
        self::assertStringContainsString('--_paper-text: var(--amz-drawer-text)', $css);
        self::assertStringContainsString('--color-text: var(--_paper-text)', $css);
        self::assertStringContainsString('--weline-theme-body-text: var(--_paper-text)', $css);
        self::assertStringContainsString('--w-surface-fg: var(--_paper-text)', $css);
        self::assertStringContainsString('color: var(--_paper-text)', $css);
        self::assertStringNotContainsString('--amz-drawer-text: var(--color-text-primary)', $css);
        self::assertStringNotContainsString('--amz-drawer-muted: var(--color-text-gray)', $css);
    }
}
