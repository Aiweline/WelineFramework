<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Api\Deal;

use PHPUnit\Framework\TestCase;

final class ExternalDealDiscountProviderContractTest extends TestCase
{
    public function testProviderInterfaceIsPublishedInModuleProvides(): void
    {
        $module = dirname(__DIR__, 4) . '/etc/module.php';
        $interface = dirname(__DIR__, 4) . '/Api/Deal/ExternalDealDiscountProviderInterface.php';
        $service = dirname(__DIR__, 4) . '/Service/ExternalDealDiscountProvider.php';
        self::assertFileExists($module);
        self::assertFileExists($interface);
        self::assertFileExists($service);

        $config = include $module;
        self::assertIsArray($config);
        $provides = $config['provides'] ?? [];
        self::assertArrayHasKey(
            \Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface::class,
            $provides,
        );
        self::assertSame(
            \Weline\Marketing\Service\ExternalDealDiscountProvider::class,
            $provides[\Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface::class],
        );

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
