<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HookContractsTest extends TestCase
{
    public function testCompareModuleProvidesProductAndAccountHookTemplates(): void
    {
        $hooks = require __DIR__ . '/../../../hook.php';
        $this->assertIsArray($hooks);
        $this->assertSame([], $hooks);

        $this->assertFileExists(
            BP . '/app/code/WeShop/Compare/view/hooks/WeShop_Product/frontend/product/detail/after-add-to-cart.phtml'
        );
        $this->assertFileExists(
            BP . '/app/code/WeShop/Compare/view/hooks/WeShop_Customer/frontend/account/discovery/cards.phtml'
        );
    }
}
