<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Service\WarehouseMigrationService;

/**
 * TEST-P3A-01：P2 多状态库存样本 → 默认逻辑仓全流程守恒。
 */
final class WarehouseMigrationServiceTest extends TestCase
{
    private WarehouseMigrationService $svc;

    protected function setUp(): void
    {
        $this->svc = WarehouseMigrationService::forTesting();
        // multi-state sample: on-hand / reserved / deducted / available
        $this->svc->seedStock(0, 0, 5001, onHandMinor: 100, reservedMinor: 20, deductedMinor: 5);
        $this->svc->seedStock(0, 0, 5002, onHandMinor: 40, reservedMinor: 0, deductedMinor: 10);
        $this->svc->seedStock(0, 1, 6001, onHandMinor: 15, reservedMinor: 3, deductedMinor: 0, storeMode: Warehouse::MODE_TEST);
        $this->svc->seedReservation('rsv-1', 0, 0, 5001, 20);
        $this->svc->seedReservation('rsv-test', 0, 1, 6001, 3, Warehouse::MODE_TEST);
    }

    public function testMigP3a01PreflightAndSharedDbRejected(): void
    {
        $pre = $this->svc->preflight();
        self::assertTrue($pre['ok']);
        self::assertSame(3, $pre['stock_count']);
        self::assertSame(2, $pre['reservation_count']);
        self::assertTrue($pre['shared_db_apply_forbidden']);
        self::assertArrayHasKey('0:0:5001', $pre['offer_totals']);
        self::assertSame(100, $pre['offer_totals']['0:0:5001']['on_hand_minor']);
        self::assertSame(20, $pre['offer_totals']['0:0:5001']['reserved_minor']);
        self::assertSame(5, $pre['offer_totals']['0:0:5001']['deducted_minor']);
        self::assertSame(80, $pre['offer_totals']['0:0:5001']['available_minor']);

        $rejected = $this->svc->apply(null);
        self::assertFalse($rejected['ok']);
        self::assertStringContainsString(
            WarehouseMigrationService::ERROR_SHARED_DB,
            (string) $rejected['error'],
        );
    }

    public function testMigP3a01ApplyVerifyConservePerOfferAndIsolateModes(): void
    {
        $db = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p3awh_unit',
            'username' => 'weline',
        ];
        $first = $this->svc->apply($db);
        self::assertTrue($first['ok']);
        self::assertSame(3, $first['mapped']);
        self::assertSame(2, $first['reservations_mapped']);
        self::assertSame('off', $first['mode']);
        self::assertNotSame('', $first['checkpoint_id']);

        $verify = $this->svc->verify($db, $first['checkpoint_id']);
        self::assertTrue($verify['ok'], json_encode($verify['diffs'] ?? []));
        self::assertSame(0, $verify['diff_count']);
        self::assertTrue($verify['history_retained']);
        self::assertSame(
            $preflight = $this->svc->preflight()['offer_totals']['0:0:5001'],
            $verify['conservation']['0:0:5001'],
        );

        $allowlist = $this->svc->allowlist($db, $first['checkpoint_id'], [0]);
        self::assertTrue($allowlist['ok']);
        self::assertSame('allowlist', $allowlist['mode']);
        self::assertSame([0], $allowlist['allowlist']);

        $second = $this->svc->apply($db);
        self::assertTrue($second['ok']);
        self::assertSame(0, $second['mapped']);
        self::assertSame(3, $second['already']);
        self::assertSame(0, $second['reservations_mapped']);
        self::assertSame(2, $second['reservations_already']);

        $ws = $this->svc->warehouseStocks()['0:0:5001'];
        self::assertSame(100, (int) $ws['warehouse_id']);
        $testWs = $this->svc->warehouseStocks()['0:1:6001'];
        self::assertSame(200, (int) $testWs['warehouse_id']);
        self::assertNotSame($ws['warehouse_id'], $testWs['warehouse_id']);

        $mappedRsv = $this->svc->mappedReservations()['rsv-1'];
        self::assertSame(100, (int) $mappedRsv['warehouse_id']);
        self::assertSame(20, (int) $mappedRsv['qty_minor']);
        $mappedTest = $this->svc->mappedReservations()['rsv-test'];
        self::assertSame(200, (int) $mappedTest['warehouse_id']);
    }

    public function testRollbackModeOffKeepsFactsAndBlocksNewApply(): void
    {
        $db = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p3awh_rb',
            'username' => 'weline',
        ];
        $apply = $this->svc->apply($db);
        $rb = $this->svc->rollbackToModeOff($db, $apply['checkpoint_id']);
        self::assertTrue($rb['ok']);
        self::assertSame('off', $rb['mode']);
        self::assertSame(3, $rb['stock_count']);
        self::assertSame(3, $rb['quota_count']);
        self::assertTrue($rb['mapping_retained']);

        $blocked = $this->svc->apply($db);
        self::assertFalse($blocked['ok']);
        self::assertSame(WarehouseMigrationService::ERROR_MODE_OFF, $blocked['error']);
    }

    public function testSharedWelineNameRejectedOnApply(): void
    {
        $rejected = $this->svc->apply([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline',
            'username' => 'weline',
        ]);
        self::assertFalse($rejected['ok']);
        self::assertStringContainsString('migration_db_denied', (string) $rejected['error']);
    }
}
