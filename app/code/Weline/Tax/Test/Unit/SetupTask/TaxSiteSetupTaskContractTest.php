<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\SetupTask;

use PHPUnit\Framework\TestCase;

final class TaxSiteSetupTaskContractTest extends TestCase
{
    public function testProviderRegistersAndDeclaresTaxSwitchTasks(): void
    {
        $taxRoot = dirname(__DIR__, 3);
        $extends = (string)file_get_contents($taxRoot . '/extends.php');
        $providerSrc = (string)file_get_contents(
            $taxRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTask/TaxSetupTaskProvider.php',
        );

        self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
        self::assertStringContainsString('TaxSetupTaskProvider::class', $extends);
        self::assertStringContainsString('tax_policy_guide', $providerSrc);
        self::assertStringContainsString('tax_engine_enabled', $providerSrc);
        self::assertStringContainsString('tax_prices_include', $providerSrc);
        self::assertStringContainsString('tax_collect_sales', $providerSrc);
        self::assertStringContainsString('tax_rollout', $providerSrc);
        self::assertStringContainsString('seedCollectSalesTaxCountriesCsv', $providerSrc);
        self::assertStringContainsString('IOSS', $providerSrc);
        self::assertStringContainsString('通用默认', $providerSrc);
        self::assertStringContainsString('TaxScopeConfig::KEY_ENABLED', $providerSrc);
        self::assertStringContainsString('TaxScopeConfig::KEY_COLLECT_SALES_TAX_COUNTRIES', $providerSrc);
        self::assertStringContainsString("'parent_code' => 'tax'", $providerSrc);
        self::assertStringContainsString('tax/backend/controlcenter/engine', $providerSrc);
    }
}
