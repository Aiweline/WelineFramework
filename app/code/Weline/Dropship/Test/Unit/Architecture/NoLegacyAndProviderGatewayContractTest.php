<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Extends\Module\Weline_Dropship\DropshipProvider\FakeDropshipProvider;
use Weline\Dropship\Service\DropshipProviderControllerDispatcher;
use Weline\Dropship\Service\DropshipProviderControllerRouteScanner;
use Weline\Dropship\Service\DropshipWarehouseMapService;

/**
 * No legacy cj_* fallback; Provider controller* via shell Gateway.
 */
final class NoLegacyAndProviderGatewayContractTest extends TestCase
{
    public function testWarehouseMapServiceHasNoCjFallback(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DropshipWarehouseMapService.php');
        self::assertStringNotContainsString('cj_country_code', $src);
        self::assertStringNotContainsString('cj_storage_id', $src);
        self::assertSame('', DropshipWarehouseMapService::remoteCountryCode([]));
        self::assertSame('US', DropshipWarehouseMapService::remoteCountryCode(['remote_country_code' => 'US']));
    }

    public function testGatewayForbiddenActionsAndFakeControllerPing(): void
    {
        self::assertContains('notify', DropshipProviderControllerDispatcher::FORBIDDEN_ACTIONS);
        self::assertContains('controllerNotify', DropshipProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);

        $fake = new FakeDropshipProvider();
        self::assertTrue(method_exists($fake, 'controllerPing'));
        $ping = $fake->controllerPing([]);
        self::assertTrue($ping['ok']);
        self::assertSame('fake', $ping['provider']);
    }

    public function testShellHasProviderGatewayControllers(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/Controller/Frontend/ProviderGateway.php');
        self::assertFileExists($root . '/Controller/Backend/ProviderGateway.php');
        $front = (string)file_get_contents($root . '/Controller/Frontend/ProviderGateway.php');
        self::assertStringContainsString('DropshipProviderControllerDispatcher', $front);
        self::assertStringContainsString('provider_code', $front);
    }

    public function testUpgradeMigratesAndDropsLegacyColumns(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('remote_country_code', $src);
        self::assertStringContainsString('cj_country_code', $src);
        self::assertStringContainsString('DROP COLUMN IF EXISTS', $src);
        self::assertStringContainsString('cj_storage_id', $src);
    }
}
