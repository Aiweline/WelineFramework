<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeOrderPageTest extends TestCase
{
    public function testOrderThemePagesWrapOrderFrontendTemplates(): void
    {
        $index = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/order/index.phtml');
        $orderList = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/order/order-list.phtml');
        $view = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/order/view.phtml');

        $this->assertIsString($index);
        $this->assertIsString($orderList);
        $this->assertIsString($view);
        $this->assertStringContainsString("WeShop_Order::templates/Frontend/Order/OrderList/index.phtml", $index);
        $this->assertStringContainsString("WeShop_Order::templates/Frontend/Order/OrderList/index.phtml", $orderList);
        $this->assertStringContainsString("WeShop_Order::templates/Frontend/Order/View/index.phtml", $view);
    }
}
