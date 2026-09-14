<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** 费用模板写入强制站点基础货币；报价按汇率换算。 */
final class RateTemplateBaseCurrencyContractTest extends TestCase
{
    public function testAdminServiceForcesBaseCurrencyAndSupportsUpdate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingConfigurationAdminService.php'
        );
        self::assertStringContainsString('function createRateTemplate', $src);
        self::assertStringContainsString('function updateRateTemplate', $src);
        self::assertStringContainsString('CurrencyRateService', $src);
        self::assertStringContainsString('getBaseCurrency', $src);
        self::assertDoesNotMatchRegularExpression(
            '/CURRENCY_CODE\s*=>\s*strtoupper\(trim\(\(string\)\(\$data\[[\'"]currency_code[\'"]/',
            $src
        );
    }

    public function testControllerSaveBranchesCreateAndUpdate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/RateTemplate.php'
        );
        self::assertStringContainsString('updateRateTemplate', $src);
        self::assertStringContainsString('template_id', $src);
        self::assertStringContainsString('editing_template', $src);
    }

    public function testQuoteRatesConvertsViaCurrencyRateService(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php'
        );
        self::assertStringContainsString('tryConvert', $src);
        self::assertStringContainsString('CurrencyRateService', $src);
        self::assertStringNotContainsString(
            'if ($templateCurrency === \'\' || $templateCurrency !== $currency)',
            $src
        );
        self::assertStringContainsString('convertShippingMinor', $src);
    }

    public function testModuleRequiresCurrency(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertArrayHasKey('Weline_Currency', $module['requires'] ?? []);
    }
}
