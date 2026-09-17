<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookTemplateTest extends TestCase
{
    public function testOrderModuleProvidesCanonicalSidebarAndContentHooks(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $sidebarTemplate = $moduleRoot . '/view/hooks/account.sidebar.group.commerce.phtml';
        $contentTemplate = $moduleRoot . '/view/hooks/account.sidebar.content.phtml';
        $headerTemplate = $moduleRoot . '/view/hooks/header-orders.phtml';
        $headerAccountLinks = $moduleRoot . '/view/hooks/header-account-links.phtml';

        $this->assertFileExists($sidebarTemplate);
        $this->assertFileExists($contentTemplate);
        $this->assertFileExists($headerTemplate);
        $this->assertFileExists($headerAccountLinks);

        $sidebar = (string) file_get_contents($sidebarTemplate);
        $content = (string) file_get_contents($contentTemplate);
        $header = (string) file_get_contents($headerTemplate);

        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-account-nav-parent="commerce"', $sidebar);
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
        $this->assertStringContainsString("assign('accountOrderTracking'", $content);
        $this->assertStringContainsString('OrderTrackingService', $content);
        $this->assertStringContainsString('data-order-tracking-resolved=', $content);
        $this->assertStringContainsString('<css>Weline_Order::css/account-orders.css</css>', $content);
        $this->assertStringContainsString('<css>Weline_Order::css/order-tracking.css</css>', $content);
        $trackingCss = $moduleRoot . '/view/statics/css/order-tracking.css';
        $this->assertFileExists($trackingCss);
        $trackingCssBody = (string) file_get_contents($trackingCss);
        $this->assertStringContainsString('[data-state="current"]', $trackingCssBody);
        $this->assertStringContainsString('--otr-primary', $trackingCssBody);
        $trackingPanel = $moduleRoot . '/view/templates/frontend/tracking/result-panel.phtml';
        $this->assertFileExists($trackingPanel);
        $trackingPanelBody = (string) file_get_contents($trackingPanel);
        $this->assertStringContainsString('order-tracking-result__stages', $trackingPanelBody);
        $this->assertStringContainsString('order-tracking-result__current-tag', $trackingPanelBody);
        $this->assertStringContainsString('aria-current="step"', $trackingPanelBody);
        $this->assertStringContainsString('fetchTagSource', $trackingPanelBody);
        $this->assertStringContainsString('<img class="order-tracking-result__icon"', $trackingPanelBody);
        $this->assertStringNotContainsString('<w:static', $trackingPanelBody);
        $this->assertStringContainsString('禁止写未编译的 w:static', $trackingPanelBody);
        $this->assertStringNotContainsString('$GLOBALS', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::orders', $content);

        $ordersCss = $moduleRoot . '/view/statics/css/account-orders.css';
        $this->assertFileExists($ordersCss);
        $css = (string) file_get_contents($ordersCss);
        $this->assertStringContainsString('[data-account-orders="true"]', $css);
        $this->assertStringContainsString('.account-orders__groups', $css);
        $this->assertStringContainsString('.account-orders__hang', $css);
        $this->assertStringContainsString('list-style: none', $css);

        $ordersPanel = $moduleRoot . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        $this->assertFileExists($ordersPanel);
        $orders = (string) file_get_contents($ordersPanel);
        $this->assertStringContainsString('data-account-layout="customer-sidebar"', $orders);
        $this->assertStringContainsString('data-group-summary="true"', $orders);
        $this->assertStringContainsString('data-account-orders-accordion="true"', $orders);
        $this->assertStringContainsString('data-testid="account-orders-accordion"', $orders);
        $this->assertStringContainsString('data-account-orders-hang="true"', $orders);
        $this->assertStringContainsString('data-testid="account-orders-hang"', $orders);
        $this->assertStringContainsString('account-orders__chevron', $orders);
        $this->assertStringContainsString('.account-orders__accordion', $css);
        $this->assertStringContainsString('data-order-status="true"', $orders);
        $this->assertStringContainsString('data-order-total="true"', $orders);
        $this->assertStringContainsString('data-partial-expanded="true"', $orders);
        $this->assertStringContainsString('data-account-order-tracking="true"', $orders);
        $this->assertStringContainsString('data-order-tracking-summary="true"', $orders);
        $this->assertStringContainsString('AccountCheckoutGroupPresenter', $orders);
        $this->assertStringNotContainsString('fetch(', $orders);
        $this->assertStringNotContainsString('axios', $orders);

        $this->assertStringContainsString('Hook: header-orders', $header);
        $this->assertStringContainsString('customer/account/index', $header);
        $this->assertStringContainsString('#orders', $header);
        $this->assertStringNotContainsString('/account/orders', $header);

        $accountLinks = (string) file_get_contents($headerAccountLinks);
        $this->assertStringContainsString('data-account-menu-auth="signed-in"', $accountLinks);
        $this->assertStringContainsString("@url{'customer/account/index'}#orders", $accountLinks);
        $this->assertStringContainsString('我的订单', $accountLinks);
        $this->assertStringNotContainsString("@url{'customer/account/orders'}", $accountLinks);
        $this->assertStringNotContainsString('customer/account/orders', $accountLinks);
    }
}
