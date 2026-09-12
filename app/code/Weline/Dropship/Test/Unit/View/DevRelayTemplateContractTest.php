<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class DevRelayTemplateContractTest extends TestCase
{
    public function testTemplateAndMenuExist(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = file_get_contents($root . '/view/templates/Backend/DevRelay/index.phtml');
        $ctrl = file_get_contents($root . '/Controller/Backend/DevRelay.php');
        $menu = file_get_contents($root . '/etc/backend/menu.xml');
        self::assertIsString($tpl);
        self::assertIsString($ctrl);
        self::assertIsString($menu);
        self::assertStringContainsString('payment/backend/dev-relay', $ctrl);
        self::assertStringContainsString('cj.sandbox.default', $ctrl);
        self::assertStringContainsString('打开支付 DevRelay 控制台', $tpl);
        self::assertStringContainsString('dropship/backend/dev-relay', $menu);
    }
}
