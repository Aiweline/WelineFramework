<?php

declare(strict_types=1);

namespace Weline\Vendor\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Service\CommerceRolloutGate;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorPayoutRecord;
use Weline\Vendor\Model\VendorRecord;
use Weline\Vendor\Model\VendorRefundReversalRecord;
use Weline\Vendor\Model\VendorSplitRuleRecord;
use Weline\Vendor\Model\VendorSplitSnapshotRecord;
use Weline\Vendor\Model\VendorStoreAccountBindingRecord;
use Weline\Vendor\Model\VendorWebsiteAuthorizationRecord;
use Weline\Vendor\Service\VendorAclGuard;
use Weline\Vendor\Service\VendorAuthorizationService;
use Weline\Vendor\Service\VendorConflictException;
use Weline\Vendor\Service\VendorEligibilityService;
use Weline\Vendor\Service\VendorPayoutLedger;
use Weline\Vendor\Service\VendorProductBindingService;
use Weline\Vendor\Service\VendorRefundReversalService;
use Weline\Vendor\Service\VendorRegistryStore;
use Weline\Vendor\Service\VendorService;
use Weline\Vendor\Service\VendorSettlementService;
use Weline\Vendor\Service\VendorSplitRuleStore;
use Weline\Vendor\Service\VendorSplitSnapshotStore;
use Weline\Vendor\Service\VendorStoreAccountBindingService;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * TASK-P4A-002: durable settlement, atomic reversal and mode-off obligations.
 */
final class VendorDurableSettlementIntegrationTest extends TestCase
{
    public function testSettlementSurvivesFreshInstancesAndFailedReversalRollsBack(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        foreach ([
            VendorRecord::class,
            VendorWebsiteAuthorizationRecord::class,
            VendorStoreAccountBindingRecord::class,
            VendorSplitRuleRecord::class,
            VendorSplitSnapshotRecord::class,
            VendorPayoutRecord::class,
            VendorRefundReversalRecord::class,
        ] as $modelClass) {
            self::assertNotNull((new SchemaParser())->parse($modelClass));
        }

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p4a002_vendor_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connectionFactory = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connectionFactory->getConnector();
        $this->createTables($connector);
        $transactions = new DatabaseTransactionRunner(new TransactionCoordinator());
        $stores = $this->storeCatalog();

        try {
            $gate = new CommerceRolloutGate();
            $gate->setMode(
                VendorSettlementService::CAPABILITY,
                CommerceRolloutGateInterface::MODE_ALLOWLIST,
                ['website:0'],
            );
            $runtime = $this->runtime($connectionFactory, $transactions, $stores, $gate);

            $vendor = $runtime['registry']->register([
                'code' => 'durable_settlement',
                'legal_name' => 'Durable Settlement Ltd',
                'environment' => VendorIdentity::ENV_SANDBOX,
            ]);
            $vendorId = (string) $vendor['vendor_id'];
            $runtime['authorization']->authorizeWebsite($vendorId, 0);
            $runtime['accounts']->bind([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'store_id' => 721,
                'environment' => VendorIdentity::ENV_SANDBOX,
                'account_ref' => 'sandbox:settlement-v1',
            ]);
            $runtime['rules']->upsert([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'commission_bps' => 1000,
                'currency' => 'CNY',
                'legal_entity' => 'Durable Legal V1',
            ]);

            $snapshot = $runtime['settlement']->captureSnapshot([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'store_id' => 721,
                'checkout_group_ref' => 'group-durable',
                'order_ref' => 'order-durable-a',
                'payment_ref' => 'payment-durable',
                'gross_minor' => 10000,
                'currency' => 'CNY',
                'required_environment' => VendorIdentity::ENV_SANDBOX,
            ]);
            $pendingSnapshot = $runtime['settlement']->captureSnapshot([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'store_id' => 721,
                'checkout_group_ref' => 'group-durable',
                'order_ref' => 'order-durable-b',
                'payment_ref' => 'payment-durable',
                'gross_minor' => 5000,
                'currency' => 'CNY',
            ]);
            self::assertSame(1000, $snapshot['commission_bps']);
            self::assertSame('sandbox:settlement-v1', $snapshot['account']['account_ref']);
            self::assertSame(9000, $snapshot['vendor_share_minor']);

            $payout = $runtime['settlement']->schedulePayout($snapshot['snapshot_id'], 'settle-a');
            $replayedPayout = $runtime['settlement']->schedulePayout(
                $snapshot['snapshot_id'],
                'settle-a',
            );
            self::assertSame($payout['payout_id'], $replayedPayout['payout_id']);
            try {
                $runtime['settlement']->schedulePayout($snapshot['snapshot_id'], 'settle-conflict');
                self::fail('expected payout idempotency conflict');
            } catch (VendorConflictException $e) {
                self::assertSame(VendorPayoutLedger::ERROR_IDEMPOTENCY, $e->errorCode);
            }

            $failingFactory = function () use ($connectionFactory): VendorRefundReversalRecord {
                $model = new class extends VendorRefundReversalRecord {
                    public function save_before(): void
                    {
                        throw new \RuntimeException('forced reversal journal failure');
                    }
                };
                $model->setConnection($connectionFactory);
                $model->__init();
                return $model;
            };
            $failingReversal = new VendorRefundReversalService(
                $runtime['payouts'],
                $runtime['snapshots'],
                $transactions,
                $failingFactory,
            );
            try {
                $failingReversal->reverse([
                    'payout_id' => $payout['payout_id'],
                    'refund_ref' => 'refund-atomic-fail',
                    'amount_minor' => 50,
                ]);
                self::fail('expected forced journal failure');
            } catch (\RuntimeException $e) {
                self::assertSame('forced reversal journal failure', $e->getMessage());
            }
            self::assertSame(9000, $runtime['payouts']->get($payout['payout_id'])['net_minor']);
            self::assertSame([], $runtime['reversals']->all());

            $reversal = $runtime['settlement']->reverseRefund([
                'payout_id' => $payout['payout_id'],
                'refund_ref' => 'refund-durable',
                'amount_minor' => 2000,
                'reason' => 'partial',
            ]);
            self::assertFalse($reversal['replayed']);
            self::assertSame(7000, $reversal['payout']['net_minor']);
            $replayedReversal = $runtime['settlement']->reverseRefund([
                'payout_id' => $payout['payout_id'],
                'refund_ref' => 'refund-durable',
                'amount_minor' => 2000,
                'reason' => 'partial',
            ]);
            self::assertTrue($replayedReversal['replayed']);
            try {
                $runtime['settlement']->reverseRefund([
                    'payout_id' => $payout['payout_id'],
                    'refund_ref' => 'refund-durable',
                    'amount_minor' => 2001,
                    'reason' => 'partial',
                ]);
                self::fail('expected reversal idempotency conflict');
            } catch (VendorConflictException $e) {
                self::assertSame(VendorRefundReversalService::ERROR_IDEMPOTENCY, $e->errorCode);
            }

            $runtime['rules']->upsert([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'commission_bps' => 2000,
                'currency' => 'CNY',
                'legal_entity' => 'Durable Legal V2',
            ]);
            $runtime['accounts']->revoke($vendorId, 0, 721);
            $runtime['accounts']->bind([
                'vendor_id' => $vendorId,
                'website_id' => 0,
                'store_id' => 721,
                'environment' => VendorIdentity::ENV_SANDBOX,
                'account_ref' => 'sandbox:settlement-v2',
            ]);

            $offGate = new CommerceRolloutGate();
            $offGate->setMode(
                VendorSettlementService::CAPABILITY,
                CommerceRolloutGateInterface::MODE_OFF,
            );
            $fresh = $this->runtime($connectionFactory, $transactions, $stores, $offGate);
            $old = $fresh['snapshots']->get($snapshot['snapshot_id']);
            self::assertSame($snapshot['payload_hash'], $old['payload_hash']);
            self::assertSame(1000, $old['commission_bps']);
            self::assertSame('Durable Legal V1', $old['legal']['legal_entity']);
            self::assertSame('sandbox:settlement-v1', $old['account']['account_ref']);
            self::assertSame(2000, $fresh['rules']->get($vendorId, 0)['commission_bps']);
            self::assertSame(
                'sandbox:settlement-v2',
                $fresh['accounts']->assertBound($vendorId, 0, 721)['account_ref'],
            );
            self::assertSame(7000, $fresh['payouts']->get($payout['payout_id'])['net_minor']);
            self::assertCount(1, $fresh['reversals']->all());

            $pendingPayout = $fresh['settlement']->schedulePayout(
                $pendingSnapshot['snapshot_id'],
                'settle-mode-off',
            );
            self::assertSame(4500, $pendingPayout['net_minor']);
            $fullReversal = $fresh['settlement']->reverseRefund([
                'payout_id' => $pendingPayout['payout_id'],
                'refund_ref' => 'refund-mode-off',
            ]);
            self::assertSame(0, $fullReversal['payout']['net_minor']);
            try {
                $fresh['settlement']->captureSnapshot([
                    'vendor_id' => $vendorId,
                    'website_id' => 0,
                    'store_id' => 721,
                    'order_ref' => 'order-blocked',
                    'payment_ref' => 'payment-blocked',
                    'gross_minor' => 1000,
                ]);
                self::fail('expected mode-off split block');
            } catch (VendorConflictException $e) {
                self::assertSame(VendorSettlementService::ERROR_MODE_OFF_NEW_SPLIT, $e->errorCode);
            }

            $report = $fresh['settlement']->reconcileReport(
                environment: VendorIdentity::ENV_SANDBOX,
                storeMode: 'test',
            );
            self::assertTrue($report['conserved']);
            self::assertSame(2, $report['payout_count']);
            self::assertSame(2, $report['reversal_count']);
            self::assertSame(2, $report['snapshot_count']);
            self::assertNotSame('', $report['report_hash']);
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
        self::assertFileDoesNotExist($dbPath);
    }

    /**
     * @return array{
     *   registry:VendorRegistryStore,
     *   authorization:VendorAuthorizationService,
     *   accounts:VendorStoreAccountBindingService,
     *   rules:VendorSplitRuleStore,
     *   snapshots:VendorSplitSnapshotStore,
     *   payouts:VendorPayoutLedger,
     *   reversals:VendorRefundReversalService,
     *   settlement:VendorSettlementService
     * }
     */
    private function runtime(
        ConnectionFactory $connectionFactory,
        DatabaseTransactionRunner $transactions,
        StoreCatalogInterface $stores,
        CommerceRolloutGate $gate,
    ): array {
        $registry = new VendorRegistryStore($this->factory($connectionFactory, VendorRecord::class));
        $authorization = new VendorAuthorizationService(
            $this->factory($connectionFactory, VendorWebsiteAuthorizationRecord::class),
        );
        $accounts = new VendorStoreAccountBindingService(
            $registry,
            $authorization,
            $stores,
            $this->factory($connectionFactory, VendorStoreAccountBindingRecord::class),
        );
        $eligibility = new VendorEligibilityService($registry, $authorization, $accounts);
        $vendors = new VendorService(
            $registry,
            $authorization,
            $eligibility,
            VendorProductBindingService::forTesting($eligibility),
            VendorAclGuard::forTesting(),
            $gate,
            $accounts,
        );
        $rules = new VendorSplitRuleStore(
            $this->factory($connectionFactory, VendorSplitRuleRecord::class),
        );
        $snapshots = new VendorSplitSnapshotStore(
            $eligibility,
            $rules,
            $registry,
            $this->factory($connectionFactory, VendorSplitSnapshotRecord::class),
        );
        $payouts = new VendorPayoutLedger(
            $snapshots,
            $this->factory($connectionFactory, VendorPayoutRecord::class),
        );
        $reversals = new VendorRefundReversalService(
            $payouts,
            $snapshots,
            $transactions,
            $this->factory($connectionFactory, VendorRefundReversalRecord::class),
        );
        return [
            'registry' => $registry,
            'authorization' => $authorization,
            'accounts' => $accounts,
            'rules' => $rules,
            'snapshots' => $snapshots,
            'payouts' => $payouts,
            'reversals' => $reversals,
            'settlement' => new VendorSettlementService(
                $vendors,
                $rules,
                $snapshots,
                $payouts,
                $reversals,
                $gate,
            ),
        ];
    }

    /**
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return \Closure(): T
     */
    private function factory(ConnectionFactory $connectionFactory, string $modelClass): \Closure
    {
        return static function () use ($connectionFactory, $modelClass): Model {
            $model = new $modelClass();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
    }

    private function storeCatalog(): StoreCatalogInterface
    {
        $store = new StoreSummary(
            721,
            0,
            'durable-settlement-test',
            'Durable Settlement Test',
            'test',
            false,
            true,
            'active',
            null,
        );
        return new class($store) implements StoreCatalogInterface {
            public function __construct(private readonly StoreSummary $store)
            {
            }

            public function byWebsite(int $websiteId): array
            {
                return $websiteId === $this->store->websiteId ? [$this->store] : [];
            }

            public function byCode(int $websiteId, string $storeCode): ?StoreSummary
            {
                return $websiteId === $this->store->websiteId && $storeCode === $this->store->code
                    ? $this->store
                    : null;
            }

            public function byId(int $storeId): ?StoreSummary
            {
                return $storeId === $this->store->id ? $this->store : null;
            }

            public function defaultStore(int $websiteId): ?StoreSummary
            {
                return $websiteId === $this->store->websiteId ? $this->store : null;
            }

            public function all(): array
            {
                return [$this->store];
            }
        };
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $queries = [
            'CREATE TABLE weline_vendor_identity ('
                . 'identity_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'code VARCHAR(64) NOT NULL, legal_name VARCHAR(255) NOT NULL, environment VARCHAR(16) NOT NULL, '
                . "status VARCHAR(16) NOT NULL, account_ref VARCHAR(255) NOT NULL DEFAULT '', "
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(code, environment))',
            'CREATE TABLE weline_vendor_website_authorization ('
                . 'authorization_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, status VARCHAR(16) NOT NULL, grant_version INTEGER NOT NULL DEFAULT 1, '
                . 'authorized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at DATETIME NULL, '
                . 'UNIQUE(vendor_id, website_id))',
            'CREATE TABLE weline_vendor_store_account_binding ('
                . 'binding_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, store_mode_snapshot VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, account_ref VARCHAR(255) NOT NULL, '
                . 'account_ref_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, '
                . 'binding_version INTEGER NOT NULL DEFAULT 1, bound_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'revoked_at DATETIME NULL, UNIQUE(vendor_id, website_id, store_id))',
            'CREATE TABLE weline_vendor_split_rule ('
                . 'rule_id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, commission_bps INTEGER NOT NULL, currency VARCHAR(8) NOT NULL, '
                . "legal_entity VARCHAR(255) NOT NULL DEFAULT '', rule_version INTEGER NOT NULL DEFAULT 1, "
                . 'cas_token VARCHAR(64) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE(vendor_id, website_id))',
            'CREATE TABLE weline_vendor_split_snapshot ('
                . 'snapshot_row_id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'schema_version VARCHAR(32) NOT NULL, vendor_id VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, '
                . 'store_id INTEGER NOT NULL, store_mode_snapshot VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, checkout_group_ref VARCHAR(64) NOT NULL, '
                . 'order_ref VARCHAR(64) NOT NULL, payment_ref VARCHAR(64) NOT NULL, currency VARCHAR(8) NOT NULL, '
                . 'gross_minor INTEGER NOT NULL, vendor_share_minor INTEGER NOT NULL, '
                . 'platform_share_minor INTEGER NOT NULL, commission_bps INTEGER NOT NULL, '
                . 'legal_json TEXT NOT NULL, account_json TEXT NOT NULL, commission_json TEXT NOT NULL, '
                . 'payload_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE(vendor_id, store_id, order_ref, payment_ref))',
            'CREATE TABLE weline_vendor_payout ('
                . 'payout_row_id INTEGER PRIMARY KEY AUTOINCREMENT, payout_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'snapshot_id VARCHAR(64) NOT NULL UNIQUE, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, store_mode_snapshot VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, currency VARCHAR(8) NOT NULL, amount_minor INTEGER NOT NULL, '
                . 'reversed_minor INTEGER NOT NULL DEFAULT 0, net_minor INTEGER NOT NULL, status VARCHAR(32) NOT NULL, '
                . 'account_ref VARCHAR(255) NOT NULL, legal_entity VARCHAR(255) NOT NULL, '
                . "idempotency_key VARCHAR(128) NOT NULL DEFAULT '', request_hash VARCHAR(64) NOT NULL, "
                . 'ledger_version INTEGER NOT NULL DEFAULT 1, cas_token VARCHAR(64) NOT NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE weline_vendor_refund_reversal ('
                . 'reversal_row_id INTEGER PRIMARY KEY AUTOINCREMENT, reversal_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'payout_id VARCHAR(64) NOT NULL, snapshot_id VARCHAR(64) NOT NULL, vendor_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, store_mode_snapshot VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, refund_ref VARCHAR(128) NOT NULL, '
                . 'amount_minor INTEGER NOT NULL, currency VARCHAR(8) NOT NULL, reason VARCHAR(255) NOT NULL, '
                . 'payout_net_after_minor INTEGER NOT NULL, request_hash VARCHAR(64) NOT NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(payout_id, refund_ref))',
        ];
        foreach ($queries as $query) {
            $connector->query($query)->fetch();
        }
    }
}
