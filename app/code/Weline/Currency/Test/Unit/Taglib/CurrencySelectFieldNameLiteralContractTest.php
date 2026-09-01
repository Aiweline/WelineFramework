<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Taglib\CurrencySelect;

final class CurrencySelectFieldNameLiteralContractTest extends TestCase
{
    public function testFormNameIsCompiledAsHtmlLiteral(): void
    {
        $compiled = (CurrencySelect::callback())(
            'w:currency:select',
            [],
            [],
            ['id' => 'website-default-currency', 'name' => 'default_currency'],
        );

        self::assertStringContainsString("\$Taglib__name = 'default_currency';", $compiled);
        self::assertStringContainsString('$Taglib__multiple = false;', $compiled);
        self::assertStringContainsString('data-w-component="currency-select"', $compiled);
        self::assertStringContainsString('name="<?= htmlspecialchars($__wcs_name, ENT_QUOTES, \'UTF-8\') ?>"', $compiled);
    }

    public function testMultipleModeRendersMultiSelectField(): void
    {
        $compiled = (CurrencySelect::callback())(
            'w:currency:select',
            [],
            [],
            [
                'id' => 'website-related-currencies',
                'name' => 'currency_codes[]',
                'multiple' => 'true',
            ],
        );

        self::assertStringContainsString("\$Taglib__name = 'currency_codes[]';", $compiled);
        self::assertStringContainsString('<?= $__wcs_multiple ? \'multiple\' : \'\' ?>', $compiled);
        self::assertStringContainsString('data-w-multiple="<?= $__wcs_multiple ? \'true\' : \'false\' ?>"', $compiled);
    }
}
