<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HookContractsTest extends TestCase
{
    public function testRmaModuleProvidesExternalHostTemplateWithoutCrossModuleDeclarations(): void
    {
        $hooks = require __DIR__ . '/../../../hook.php';
        $this->assertIsArray($hooks);
        $this->assertSame([], $hooks);

        $this->assertFileExists(
            BP . '/app/code/WeShop/RMA/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml'
        );
    }
}
