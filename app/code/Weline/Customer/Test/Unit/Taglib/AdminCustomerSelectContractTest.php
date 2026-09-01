<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class AdminCustomerSelectContractTest extends TestCase
{
    public function testCustomerAdminSelectUsesThemePopoverAndCustomerAdminResource(): void
    {
        $root = dirname(__DIR__, 3);
        $taglibPath = $root . '/Taglib/AdminCustomerSelect.php';
        $rendererPath = $root . '/Taglib/SearchableCustomerSelect.php';
        $scriptPath = $root . '/view/statics/ui/components/weline-customer-select.js';

        self::assertFileExists($taglibPath);
        self::assertFileExists($rendererPath);
        self::assertFileExists($scriptPath);

        $taglib = (string) file_get_contents($taglibPath);
        $renderer = (string) file_get_contents($rendererPath);
        $script = (string) file_get_contents($scriptPath);

        self::assertSame('customer:admin:select', \Weline\Customer\Taglib\AdminCustomerSelect::name());
        self::assertStringContainsString('SearchableCustomerSelect::render', $taglib);
        self::assertStringContainsString('customer_admin', $script);
        self::assertStringContainsString("resource('customer_admin')", $script);
        self::assertStringContainsString('inlineStyles', $renderer);
        self::assertStringContainsString('weline-customer-select.css', $renderer);
        self::assertStringContainsString('inlineScript', $renderer);
        self::assertStringContainsString('data-w-component="customer-select"', $renderer);
        self::assertStringContainsString('data-w-customer-select-panel', $renderer);
        self::assertStringNotContainsString('w-field__control', $renderer);
        self::assertStringContainsString("UI.define('customer-select'", $script);
        self::assertStringContainsString('floating.portal', $script);
    }
}
