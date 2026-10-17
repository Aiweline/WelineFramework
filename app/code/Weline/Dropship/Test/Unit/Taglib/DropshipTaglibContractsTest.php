<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Taglib\ProviderSelect;
use Weline\Dropship\Taglib\RemoteWarehouseSelect;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllDropshipTaglibContractsPass().
 */
final class DropshipTaglibContractsTest extends TestCase
{
    public function testAllDropshipTaglibContractsPass(): void
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
            'ProviderSelect::testTagNameAndMultipleDefaults' => function () {
            $this->assert_ProviderSelect_testTagNameAndMultipleDefaults();
        },
            'RemoteWarehouseSelect::testTagNameAndRequiredId' => function () {
            $this->assert_RemoteWarehouseSelect_testTagNameAndRequiredId();
        },
        ];
    }

private function assert_ProviderSelect_testTagNameAndMultipleDefaults(): void
{
    self::assertSame('dropship:provider:select', ProviderSelect::name());
    self::assertTrue(ProviderSelect::attr()['id']);
    self::assertFalse(ProviderSelect::attr()['multiple']);
    self::assertFalse(ProviderSelect::attr()['auto-submit']);
    self::assertFalse(ProviderSelect::attr()['options-json']);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Taglib/ProviderSelect.php');
    self::assertStringContainsString('SearchSelect::class', $src);
    self::assertStringContainsString('::buildMarkup([', $src);
    self::assertStringContainsString("'multiple' =>", $src);
    self::assertStringContainsString('auto-submit', $src);
    self::assertStringContainsString('留空=全部', $src);
}

private function assert_RemoteWarehouseSelect_testTagNameAndRequiredId(): void
{
    self::assertSame('dropship:remote-warehouse:select', RemoteWarehouseSelect::name());
    self::assertTrue(RemoteWarehouseSelect::attr()['id']);
    self::assertFalse(RemoteWarehouseSelect::attr()['name']);
    self::assertFalse(RemoteWarehouseSelect::attr()['provider-select']);
    self::assertFalse(RemoteWarehouseSelect::attr()['country-input']);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Taglib/RemoteWarehouseSelect.php');
    self::assertStringContainsString('country_code=', $src);
    self::assertStringContainsString('fillCountryFromLabel', $src);
}
}
