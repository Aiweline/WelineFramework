<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class OrderHookDeclarationTest extends TestCase
{
    public function testOrderPageHooksAreDeclared(): void
    {
        $hooks = require __DIR__ . '/../../../hook.php';

        $this->assertIsArray($hooks);
        $this->assertArrayHasKey('WeShop_Order::frontend::pages::order::list-before', $hooks);
        $this->assertArrayHasKey('WeShop_Order::frontend::pages::order::list-after', $hooks);
        $this->assertArrayHasKey('WeShop_Order::frontend::pages::order::view-before', $hooks);
        $this->assertArrayHasKey('WeShop_Order::frontend::pages::order::view-after', $hooks);
    }
}
