<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class OrderChatAccordionContractTest extends TestCase
{
    public function testAccordionPartialDeclaresToggleAndComposer(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/partials/order-chat-accordion.phtml';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('data-testid="b2b-order-chat-accordion"', $src);
        self::assertStringContainsString('data-b2b-order-chat-toggle', $src);
        self::assertStringContainsString('b2b-order-chat__toggle-open', $src);
        self::assertStringContainsString('data-open', $src);
        self::assertStringContainsString('data-b2b-order-chat-panel', $src);
        self::assertStringContainsString('data-b2b-order-chat-form', $src);
        self::assertStringContainsString('data-chat-role', $src);
    }

    public function testHangUsesAccordionInsteadOfIdentityHashLink(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/partials/account-order-hang.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('order-chat-accordion.phtml', $src);
        self::assertStringNotContainsString('#b2b-identity', $src);
        self::assertStringContainsString('data-weline-load="b2bOrderChat"', $src);
        self::assertStringContainsString('b2b-account-hang__meta', $src);
    }

    public function testBackendOrderChatUsesWidgetDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_B2B/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey('b2b-backend-order-chat', $widgets);
        $widget = $widgets['b2b-backend-order-chat'];
        self::assertSame('backend-order-b2b-chat', $widget['slot'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('backend-order-view', $injection['layout_type'] ?? null);
        self::assertSame('backend-order-b2b-chat', $injection['slot'] ?? null);

        $tpl = dirname(__DIR__, 3) . '/view/templates/Backend/widgets/backend-order-chat.phtml';
        $src = (string) file_get_contents($tpl);
        self::assertStringContainsString("chatRole = 'merchant'", $src);
        self::assertStringContainsString('order-chat-accordion.phtml', $src);
        self::assertStringContainsString('data-testid="b2b-backend-order-chat-card"', $src);

        $hook = dirname(__DIR__, 3) . '/view/hooks/Weline_Order/backend/order/view/wholesale-chat.phtml';
        self::assertFileExists($hook);
        self::assertStringContainsString('backend-order-chat.phtml', (string) file_get_contents($hook));

        $after = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/hooks/Weline_Order/backend/order/view/after.phtml'
        );
        self::assertStringContainsString('default_injections', $after);
        self::assertStringNotContainsString('order-chat-accordion.phtml', $after);
    }

    public function testHangAdminMountsMerchantChat(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/ControlCenter/index.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('data-testid="b2b-hang-admin-chat"', $src);
        self::assertStringContainsString('data-testid="b2b-hang-order-ops"', $src);
        self::assertStringContainsString('b2b-hang-waterfall', $src);
        self::assertStringContainsString('b2b-hang-card', $src);
        self::assertStringContainsString('hang-compact1', $src);
        self::assertStringContainsString('data-density="compact"', $src);
        self::assertStringContainsString('b2b-hang-card__address-text', $src);
        self::assertStringContainsString('data-layout="inline"', $src);
        self::assertStringContainsString('$expandByDefault = false', $src);
        self::assertStringContainsString('b2b-hang-waterfall-inline', $src);
        self::assertStringContainsString('data-testid="b2b-hang-tabs"', $src);
        self::assertStringContainsString('data-testid="b2b-hang-severity"', $src);
        self::assertStringContainsString('data-testid="b2b-hang-customer"', $src);
        self::assertStringContainsString('data-testid="b2b-hang-lines"', $src);
        self::assertStringContainsString('data-testid="b2b-hang-card-chat"', $src);
        self::assertStringContainsString('$chatOpen', $src);
        self::assertStringContainsString("customer_id", $src);
        self::assertStringContainsString('商品详情', $src);
        self::assertStringNotContainsString('商品与沟通详情', $src);
        self::assertStringNotContainsString('getByOrderRef', $src);
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/css/b2b-storefront.css');
        self::assertStringContainsString('.b2b-hang-waterfall', $css);
        self::assertStringContainsString('data-layout="waterfall"', $src);
        self::assertStringContainsString('flex-flow: row wrap', $css);
        self::assertStringContainsString('data-testid="b2b-hang-status-badge"', $src);
        self::assertStringContainsString('data-testid="b2b-hang-deposit-major"', $src);
        self::assertStringNotContainsString('data-testid="b2b-hang-order-chat-row"', $src);
        self::assertStringNotContainsString("__('hang_id')", $src);
        self::assertStringNotContainsString("__('定金(分)')", $src);
        self::assertStringContainsString('order-chat-accordion.phtml', $src);
        self::assertStringContainsString("chatRole = 'merchant'", $src);
        self::assertStringContainsString('data-weline-load="b2bOrderChat"', $src);
        self::assertStringContainsString("empty(\$dataset['rows']) && \$code !== 'hang-orders'", $src);
    }

    public function testQuerySupportsMerchantOpenAndModulesRegistered(): void
    {
        $query = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/B2BQueryProvider.php'
        );
        self::assertStringContainsString('openOrCreateForMerchant', $query);
        self::assertStringContainsString('isBackendAdmin', $query);
        self::assertStringContainsString('FrontendWorkerExecutionContext', $query);
        self::assertStringContainsString('AREA_BACKEND', $query);
        self::assertStringContainsString('ROLE_MERCHANT', $query);
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'orderChat\\.open'[\\s\\S]*?'auth'\\s*=>\\s*'any'/",
            $query
        );
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'orderChat\\.send'[\\s\\S]*?'auth'\\s*=>\\s*'any'/",
            $query
        );

        $fe = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js');
        $be = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/backend/weline.modules.js');
        self::assertStringContainsString('b2bOrderChat', $fe);
        self::assertStringContainsString('order-chat-accordion.js', $fe);
        self::assertStringContainsString('b2bOrderChat', $be);
    }
}
