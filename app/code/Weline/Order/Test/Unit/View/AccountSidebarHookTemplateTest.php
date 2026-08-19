<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookTemplateTest extends TestCase
{
    public function testOrderModuleProvidesCanonicalSidebarAndContentHooks(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $sidebarTemplate = $moduleRoot . '/view/hooks/account.sidebar.phtml';
        $contentTemplate = $moduleRoot . '/view/hooks/account.sidebar.content.phtml';
        $headerTemplate = $moduleRoot . '/view/hooks/header-orders.phtml';

        $this->assertFileExists($sidebarTemplate);
        $this->assertFileExists($contentTemplate);
        $this->assertFileExists($headerTemplate);

        $sidebar = (string) file_get_contents($sidebarTemplate);
        $content = (string) file_get_contents($contentTemplate);
        $header = (string) file_get_contents($headerTemplate);

        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-section="orders"', $sidebar);
        $this->assertStringContainsString('#orders', $sidebar);
        $this->assertStringContainsString('account-hook-nav-link', $sidebar);

        $this->assertStringContainsString('data-account-section="orders"', $content);
        $this->assertStringContainsString('id="orders-section"', $content);
        $this->assertStringContainsString('Weline_Order::frontend::account::index::orders', $content);
        $this->assertStringContainsString('AccountCheckoutGroupLoader', $content);
        $this->assertStringContainsString('AccountOrderDetailResolver', $content);
        $this->assertStringContainsString('AccountSidebarContentGate::requestParam(', $content);
        $this->assertStringContainsString("'order_uuid',", $content);
        $this->assertStringContainsString("getParam('order_uuid'", $content);
        $this->assertStringContainsString('data-requested-order-uuid=', $content);
        $this->assertStringContainsString('data-order-detail-resolved=', $content);
        $this->assertStringContainsString('AccountSidebarProjectionProviderInterface', $content);
        $this->assertStringContainsString("assign('accountCheckoutGroups'", $content);
        $this->assertStringContainsString("assign('accountOrderDetail'", $content);
        $this->assertStringNotContainsString('$GLOBALS', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::orders', $content);

        $ordersPanel = $moduleRoot . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        $this->assertFileExists($ordersPanel);
        $orders = (string) file_get_contents($ordersPanel);
        $this->assertStringContainsString('data-account-layout="customer-sidebar"', $orders);
        $this->assertStringContainsString('data-group-summary="true"', $orders);
        $this->assertStringContainsString('data-order-status="true"', $orders);
        $this->assertStringContainsString('data-order-total="true"', $orders);
        $this->assertStringContainsString('data-partial-expanded="true"', $orders);
        $this->assertStringContainsString('AccountCheckoutGroupPresenter', $orders);
        $this->assertStringNotContainsString('fetch(', $orders);
        $this->assertStringNotContainsString('axios', $orders);

        $this->assertStringContainsString('Hook: header-orders', $header);
        $this->assertStringContainsString('customer/account/index', $header);
        $this->assertStringContainsString('#orders', $header);
        $this->assertStringNotContainsString('/account/orders', $header);
    }
}
