<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookTemplateTest extends TestCase
{
    public function testOrderModuleBridgesSidebarAndContentThroughCanonicalHooks(): void
    {
        $contentTemplate = __DIR__ . '/../../../view/hooks/Weline_Order/frontend/account/index/orders.phtml';

        $this->assertFileExists($contentTemplate);

        $content = (string) file_get_contents($contentTemplate);

        $this->assertStringContainsString('Hook: Weline_Order::frontend::account::index::orders', $content);
        $this->assertStringContainsString('weshop-account-orders', $content);
        $this->assertStringContainsString('data-order-status-filter', $content);
        $this->assertStringContainsString('data-weshop-orders-body', $content);
        $this->assertStringContainsString('data-orders-deferred="1"', $content);
        $this->assertStringContainsString('accountListFragment', $content);
        $this->assertStringNotContainsString('list-fragment', $content);
        $this->assertStringNotContainsString('data-orders-fragment-url', $content);
        $this->assertStringNotContainsString('Hook: account.sidebar', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::orders', $content);
    }
}
