<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Taglib\CurrencySelect;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCurrencyTaglibContractsPass().
 */
final class CurrencyTaglibContractsTest extends TestCase
{
    public function testAllCurrencyTaglibContractsPass(): void
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
            'CurrencySelectFieldNameLiteral::testFormNameIsCompiledAsHtmlLiteral' => function () {
            $this->assert_CurrencySelectFieldNameLiteral_testFormNameIsCompiledAsHtmlLiteral();
        },
            'CurrencySelectFieldNameLiteral::testMultipleModeRendersMultiSelectField' => function () {
            $this->assert_CurrencySelectFieldNameLiteral_testMultipleModeRendersMultiSelectField();
        },
        ];
    }

private function assert_CurrencySelectFieldNameLiteral_testFormNameIsCompiledAsHtmlLiteral(): void
{
    $compiled = CurrencySelect::callback()('w:currency:select', [], [], ['id' => 'website-default-currency', 'name' => 'default_currency']);
    self::assertStringContainsString("\$Taglib__name = 'default_currency';", $compiled);
    self::assertStringContainsString('$Taglib__multiple = false;', $compiled);
    self::assertStringContainsString('data-w-component="currency-select"', $compiled);
    self::assertStringContainsString('name="<?= htmlspecialchars($__wcs_name, ENT_QUOTES, \'UTF-8\') ?>"', $compiled);
}

private function assert_CurrencySelectFieldNameLiteral_testMultipleModeRendersMultiSelectField(): void
{
    $compiled = CurrencySelect::callback()('w:currency:select', [], [], ['id' => 'website-related-currencies', 'name' => 'currency_codes[]', 'multiple' => 'true']);
    self::assertStringContainsString("\$Taglib__name = 'currency_codes[]';", $compiled);
    self::assertStringContainsString('<?= $__wcs_multiple ? \'multiple\' : \'\' ?>', $compiled);
    self::assertStringContainsString('data-w-multiple="<?= $__wcs_multiple ? \'true\' : \'false\' ?>"', $compiled);
}
}
