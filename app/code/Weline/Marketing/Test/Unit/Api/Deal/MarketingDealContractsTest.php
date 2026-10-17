<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Api\Deal;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingDealContractsPass().
 */
final class MarketingDealContractsTest extends TestCase
{
    public function testAllMarketingDealContractsPass(): void
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
            'ExternalDealDiscountProvider::testProviderInterfaceIsPublishedInModuleProvides' => function () {
            $this->assert_ExternalDealDiscountProvider_testProviderInterfaceIsPublishedInModuleProvides();
        },
        ];
    }

private function assert_ExternalDealDiscountProvider_testProviderInterfaceIsPublishedInModuleProvides(): void
{
    $module = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/etc/module.php';
    $interface = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Api/Deal/ExternalDealDiscountProviderInterface.php';
    $service = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Service/ExternalDealDiscountProvider.php';
    self::assertFileExists($module);
    self::assertFileExists($interface);
    self::assertFileExists($service);
    $config = include $module;
    self::assertIsArray($config);
    $provides = $config['provides'] ?? [];
    self::assertArrayHasKey(\Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface::class, $provides);
    self::assertSame(\Weline\Marketing\Service\ExternalDealDiscountProvider::class, $provides[\Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface::class]);
    $iface = (string) file_get_contents($interface);
    self::assertStringContainsString('function upsert(ExternalDealDiscountRequest $request): ExternalDealDiscountResult', $iface);
    $svc = (string) file_get_contents($service);
    self::assertStringContainsString('implements ExternalDealDiscountProviderInterface', $svc);
    self::assertStringContainsString("RULE_TYPE_AUTOMATIC", $svc);
    self::assertStringContainsString('matched_products', $svc);
    self::assertStringContainsString("'external_managed' => 1", $svc);
    self::assertStringContainsString('external_managed=1', $svc);
}
}
