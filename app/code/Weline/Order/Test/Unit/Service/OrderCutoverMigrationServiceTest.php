<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\OrderCompatibilityReader;
use Weline\Order\Service\OrderCutoverGate;
use Weline\Order\Service\OrderCutoverMigrationService;
use Weline\Order\Service\OrderFacadeConflictException;
use Weline\Order\Service\OrderWriterGuard;

/**
 * TEST-MIG-P2-01, TEST-MIG-P2-02, TEST-MIG-P2-03 and TEST-MIG-P2-07：Checkout→Order 单写切流。
 *
 * TEST-MIG-P2-04/05/06 由本任务的 Product migration regression 文件逐项验证，
 * 不再用“此前任务已绿”替代当前 MIG 证据。
 */
final class OrderCutoverMigrationServiceTest extends TestCase
{
    private OrderCutoverMigrationService $svc;
    private string $journalDir;

    protected function setUp(): void
    {
        $this->journalDir = sys_get_temp_dir() . '/mig_p2_order_test_' . bin2hex(random_bytes(6));
        $this->svc = OrderCutoverMigrationService::forTesting($this->journalDir);
        $this->svc->setProductShardReady(true);
        $this->svc->setProductionOnToken('explicit-unit-token');
        $this->svc->seedLegacyOrder('LEGACY-OLD-1', ['status' => 'paid', 'paid' => true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->journalDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->journalDir);
    }

    private function isolatedDb(string $suffix): array
    {
        return [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p2ord_' . $suffix,
            'username' => 'weline',
        ];
    }

    public function testMigP201LegacyReadableReadonlyAfterCutover(): void
    {
        $apply = $this->svc->apply($this->isolatedDb('01'));
        self::assertTrue($apply['ok']);
        self::assertFalse($apply['legacy_writable']);

        $unified = $this->svc->reader()->readUnified('LEGACY-OLD-1');
        self::assertNotNull($unified);
        self::assertSame(OrderCompatibilityReader::SOURCE_LEGACY, $unified['source']);
        self::assertSame('paid', $unified['order']['status'] ?? null);

        try {
            $this->svc->attemptLegacyMutation('LEGACY-OLD-1');
            self::fail('legacy mutation must be blocked');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderWriterGuard::ERROR_LEGACY_BLOCKED, $e->errorCode());
        }
        self::assertSame(0, $this->svc->legacyMutationCount());
        self::assertSame('paid', $this->svc->legacyOrders()['LEGACY-OLD-1']['status']);
    }

    public function testMigP202NewOrderOnlyWritesWelineOrder(): void
    {
        $this->svc->apply($this->isolatedDb('02'));
        $created = $this->svc->createSandboxNewOrder('mig-new-02');
        self::assertNotSame('', $created['order_uuid']);
        self::assertFalse($created['legacy_table_has_new_fact']);
        self::assertGreaterThan(0, $created['write_count']);

        // Legacy table still only the seeded old order.
        self::assertCount(1, $this->svc->legacyOrders());
        self::assertArrayHasKey('LEGACY-OLD-1', $this->svc->legacyOrders());
        self::assertArrayNotHasKey($created['order_uuid'], $this->svc->legacyOrders());

        $new = $this->svc->reader()->readUnified($created['order_uuid']);
        self::assertNotNull($new);
        self::assertSame(OrderCompatibilityReader::SOURCE_NEW, $new['source']);
    }

    public function testMigP203UnsafeRollbackRejectedCompatReaderKeepsBoth(): void
    {
        $this->svc->apply($this->isolatedDb('03'));
        $created = $this->svc->createSandboxNewOrder('mig-new-03');

        $rbOff = $this->svc->rollbackUi(OrderCutoverGate::MODE_OFF);
        self::assertFalse($rbOff['ok']);
        self::assertSame(OrderCutoverGate::ERROR_ROLLBACK_HIDES_NEW, $rbOff['error']);
        self::assertFalse($rbOff['legacy_writable']);

        $rbShadow = $this->svc->rollbackUi(OrderCutoverGate::MODE_SHADOW);
        self::assertTrue($rbShadow['ok']);
        self::assertSame(OrderCutoverGate::MODE_SHADOW, $rbShadow['mode']);
        self::assertFalse($rbShadow['legacy_writable']);
        self::assertFalse($rbShadow['new_writable']);
        self::assertTrue($rbShadow['forbid_legacy_writer_restored']);

        $legacy = $this->svc->reader()->readUnified('LEGACY-OLD-1');
        $new = $this->svc->reader()->readUnified($created['order_uuid']);
        self::assertNotNull($legacy);
        self::assertNotNull($new);
        self::assertSame(OrderCompatibilityReader::SOURCE_LEGACY, $legacy['source']);
        self::assertSame(OrderCompatibilityReader::SOURCE_NEW, $new['source']);

        try {
            $this->svc->gate()->forbidLegacyWriterRollback();
            self::fail('must forbid legacy writer restore');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderCutoverGate::ERROR_ROLLBACK_LEGACY, $e->errorCode());
        }
    }

    public function testMigP207NewOrderRemainsSoleNewFactAfterControlledRollback(): void
    {
        $this->svc->apply($this->isolatedDb('07'));
        $created = $this->svc->createSandboxNewOrder('mig-paid-07');
        self::assertFalse($created['legacy_table_has_new_fact']);

        $rb = $this->svc->rollbackUi(OrderCutoverGate::MODE_SHADOW);
        self::assertTrue($rb['ok']);
        self::assertTrue($rb['continue_forward']);
        self::assertFalse($this->svc->gate()->legacyWritable());

        $verifyMode = $this->svc->gate()->mode();
        self::assertSame(OrderCutoverGate::MODE_SHADOW, $verifyMode);

        $new = $this->svc->reader()->readUnified($created['order_uuid']);
        self::assertNotNull($new);
        self::assertSame(OrderCompatibilityReader::SOURCE_NEW, $new['source']);

        $legacy = $this->svc->reader()->readUnified('LEGACY-OLD-1');
        self::assertNotNull($legacy);
        self::assertSame(OrderCompatibilityReader::SOURCE_LEGACY, $legacy['source']);
    }

    public function testSharedDbAndShardGate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(OrderCutoverMigrationService::ERROR_SHARED_DB);
        $this->svc->apply(null);
    }

    public function testSharedWelineNameRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_db_denied');
        $this->svc->apply([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline',
            'username' => 'weline',
        ]);
    }

    public function testShardNotReadyBlocksApply(): void
    {
        $this->svc->setProductShardReady(false);
        $blocked = $this->svc->apply($this->isolatedDb('shard'));
        self::assertFalse($blocked['ok']);
        self::assertSame(OrderCutoverMigrationService::ERROR_SHARD_NOT_READY, $blocked['error']);
    }

    public function testVerifyAfterApply(): void
    {
        $db = $this->isolatedDb('verify');
        $apply = $this->svc->apply($db);
        self::assertTrue($apply['ok']);
        self::assertStringNotContainsString(
            'explicit-unit-token',
            json_encode($apply, JSON_THROW_ON_ERROR),
        );
        $journal = file_get_contents(
            $this->journalDir . '/' . (string) $apply['checkpoint_id'] . '.json',
        );
        self::assertNotFalse($journal);
        self::assertStringNotContainsString('explicit-unit-token', $journal);

        // 模拟全新的 CLI/PHP 进程：仅共享持久化 journal，不共享 gate 内存。
        $fresh = OrderCutoverMigrationService::forTesting($this->journalDir);
        $fresh->setProductShardReady(true);
        $fresh->seedLegacyOrder('LEGACY-OLD-1', ['status' => 'paid', 'paid' => true]);
        $verify = $fresh->verify($db, (string) $apply['checkpoint_id']);
        self::assertTrue($verify['ok'], json_encode($verify['diffs'] ?? []));
        self::assertTrue($verify['fresh_journal']['ok']);
        self::assertFalse($verify['legacy_writable']);
        self::assertTrue($verify['new_writable']);
    }

    public function testApplyRequiresExplicitTokenBeforeCheckpointOrCutover(): void
    {
        $service = OrderCutoverMigrationService::forTesting($this->journalDir . '-missing-token');
        $service->setProductShardReady(true);
        $blocked = $service->apply($this->isolatedDb('missing-token'));

        self::assertFalse($blocked['ok']);
        self::assertSame(OrderCutoverMigrationService::ERROR_TOKEN, $blocked['error']);
        self::assertFalse($service->gate()->isCutoverApplied());

        foreach (glob($this->journalDir . '-missing-token/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->journalDir . '-missing-token');
    }
}
