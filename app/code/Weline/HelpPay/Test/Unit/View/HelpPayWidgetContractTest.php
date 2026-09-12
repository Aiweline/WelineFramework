<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HelpPayWidgetContractTest extends TestCase
{
    public function testShareResultHasDualDeliveryAndNoStatic(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/help-pay-share-result.phtml'
        );
        self::assertStringContainsString('data-testid="help-pay-share-result"', $tpl);
        self::assertStringContainsString('data-testid="help-pay-copy-url"', $tpl);
        self::assertStringContainsString('data-testid="help-pay-copy-qr"', $tpl);
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
        self::assertStringNotContainsString('@static', $tpl);
    }

    public function testCartCheckoutCtasUseModuleLoadWithoutStatic(): void
    {
        foreach (['cart-summary-help-pay', 'checkout-summary-help-pay'] as $name) {
            $tpl = (string) file_get_contents(
                dirname(__DIR__, 3) . '/view/templates/frontend/widgets/' . $name . '.phtml'
            );
            self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
            self::assertStringNotContainsString('@static', $tpl);
        }
    }

    public function testPayerTemplateKeepsRulesAndNoCouponCopy(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/pay/payer.phtml'
        );
        self::assertStringContainsString('优惠券与积分不可用于帮我付', $tpl);
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
        self::assertStringNotContainsString('@static', $tpl);
    }

    public function testQuickPayUsesLayoutSafeStructure(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/pay/quick.phtml'
        );
        self::assertStringContainsString('data-testid="quick-pay-self"', $tpl);
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
        self::assertStringContainsString('w-helppay-panel', $tpl);
        self::assertStringNotContainsString('@static', $tpl);
    }

    public function testFrontendJsKeepsRulesGate(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/js/helppay-share.js'
        );
        self::assertStringContainsString('help-pay-rules-link', $js);
        self::assertStringContainsString('/faq/help-pay-rules', $js);
        self::assertStringContainsString('help-pay-rules-accepted', $js);
        self::assertStringContainsString('w-helppay-share-result__grid', $js);
    }

    public function testModulesRegistryExists(): void
    {
        $base = dirname(__DIR__, 3);
        $mod = (string) file_get_contents($base . '/view/statics/frontend/weline.modules.js');
        self::assertStringContainsString('helpPayShare', $mod);
        self::assertStringContainsString('helppay-share.js', $mod);
        self::assertStringNotContainsString('helppay-share.css', $mod);
        self::assertFileExists($base . '/view/statics/js/helppay-share.js');
        self::assertFileExists($base . '/view/statics/css/helppay-share.css');
        $js = (string) file_get_contents($base . '/view/statics/js/helppay-share.js');
        self::assertStringContainsString('data-helppay-share-css', $js);
        self::assertStringContainsString('/Weline/HelpPay/view/statics/css/helppay-share.css', $js);
    }
}
