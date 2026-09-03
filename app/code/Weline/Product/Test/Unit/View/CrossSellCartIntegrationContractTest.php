<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CrossSellCartIntegrationContractTest extends TestCase
{
    public function testCrossSellTemplateExposesCartFeedbackMessages(): void
    {
        $template = $this->template();

        self::assertStringContainsString('data-msg-loading', $template);
        self::assertStringContainsString('data-msg-error', $template);
        self::assertStringContainsString('data-msg-success', $template);
        self::assertStringContainsString('data-cart-url', $template);
        self::assertStringContainsString('data-qty="1"', $template);
        self::assertStringContainsString('data-global-offer-uuid', $template);
    }

    public function testCrossSellScriptUsesCartBinQueryAndDispatchesCartUpdated(): void
    {
        $script = $this->script();

        self::assertStringContainsString("resource('cart')", $script);
        self::assertStringContainsString('add', $script);
        self::assertStringContainsString('issueGuestToken', $script);
        self::assertStringContainsString('weline:cart-updated', $script);
        self::assertStringContainsString('weline:cart:update', $script);
        self::assertStringContainsString('weshop:cart:updated', $script);
        self::assertStringContainsString('global_offer_uuid', $script);
        self::assertStringContainsString('legacy_product_id', $script);
        self::assertStringContainsString('showCartAddedSuccess', $script);
        self::assertStringContainsString('showCartAddedNotice', $script);
    }

    public function testCrossSellScriptDoesNotFakeSuccessWithoutCartMutation(): void
    {
        $script = $this->script();

        self::assertStringContainsString('addSelectedItems', $script);
        self::assertStringContainsString('notifyCartUpdated', $script);
        self::assertStringNotContainsString("notice(msgAdded, 'success');\n        addAll.setAttribute('data-state', 'saved');", $script);
    }

    private function template(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cross-sell.phtml',
        );
    }

    private function script(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/cross-sell.js',
        );
    }
}
