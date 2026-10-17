<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerTaglibContractsPass().
 */
final class CustomerTaglibContractsTest extends TestCase
{
    public function testAllCustomerTaglibContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AdminCustomerSelect::testCustomerAdminSelectUsesThemePopoverAndCustomerAdminResource' => function () {
            $this->assert_AdminCustomerSelect_testCustomerAdminSelectUsesThemePopoverAndCustomerAdminResource();
        },
        ];
    }

private function assert_AdminCustomerSelect_testCustomerAdminSelectUsesThemePopoverAndCustomerAdminResource(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
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
    self::assertStringContainsString('email:', $script);
    self::assertStringContainsString('getEmail:', $script);
    self::assertStringContainsString('dataset.wCustomerEmail', $script);
}
}
