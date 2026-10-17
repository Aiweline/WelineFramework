<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\TestCore;
use Weline\Shipping\Model\ShippingService;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllShippingUnitContractsPass().
 */
final class ShippingUnitContractsTest extends TestCase
{
    public function testAllShippingUnitContractsPass(): void
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
            'ZoneRemoval::testZoneDomainFilesRemoved' => function () {
            $this->assert_ZoneRemoval_testZoneDomainFilesRemoved();
        },
            'ZoneRemoval::testShippingServiceHasNoZoneIdField' => function () {
            $this->assert_ZoneRemoval_testShippingServiceHasNoZoneIdField();
        },
            'ZoneRemoval::testMenuAndManagerDropZone' => function () {
            $this->assert_ZoneRemoval_testMenuAndManagerDropZone();
        },
        ];
    }

private function assert_ZoneRemoval_testZoneDomainFilesRemoved(): void
{
    $base = BP . 'app/code/Weline/Shipping/';
    self::assertFileDoesNotExist($base . 'Model/Zone.php');
    self::assertFileDoesNotExist($base . 'Model/ZoneRegion.php');
    self::assertFileDoesNotExist($base . 'Service/ZoneService.php');
    self::assertFileDoesNotExist($base . 'Controller/Backend/Zone.php');
    self::assertFileDoesNotExist($base . 'view/templates/backend/Zone/index.phtml');
}

private function assert_ZoneRemoval_testShippingServiceHasNoZoneIdField(): void
{
    $ref = new \ReflectionClass(ShippingService::class);
    self::assertFalse($ref->hasConstant('schema_fields_ZONE_ID'));
    $src = (string) file_get_contents($ref->getFileName());
    self::assertStringNotContainsString("'zone_id'", $src);
}

private function assert_ZoneRemoval_testMenuAndManagerDropZone(): void
{
    $menu = (string) file_get_contents(BP . 'app/code/Weline/Shipping/etc/backend/menu.xml');
    self::assertStringNotContainsString('Weline_Shipping::zone', $menu);
    self::assertStringNotContainsString('shipping/backend/zone', $menu);
    $manager = (string) file_get_contents(BP . 'app/code/Weline/Shipping/Controller/Backend/Manager.php');
    self::assertStringNotContainsString("'zone'", $manager);
    $ssm = (string) file_get_contents(BP . 'app/code/Weline/Shipping/Service/ShippingServiceManager.php');
    self::assertStringNotContainsString('ZoneService', $ssm);
    self::assertStringNotContainsString('matchZoneByAddress', $ssm);
}
}
