<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class OrderAccountOrdersCardsHookTest extends TestCase
{
    public function testCurrentAccountOrdersCardsHookTemplateLinksToOrderRoutes(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("w_query('order', 'getCustomerDashboardOrders'", $template);
        $this->assertStringContainsString('/customer/account/index#orders', $template);
        $this->assertStringContainsString('return_anchor=', $template);
        $this->assertStringContainsString('orderCount', $template);
        $this->assertStringContainsString('unpaidCount', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::orders::cards', $template);
    }
}
