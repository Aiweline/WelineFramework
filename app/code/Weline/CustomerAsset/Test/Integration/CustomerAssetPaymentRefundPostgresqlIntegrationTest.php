<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\OrderAssetAllocationSnapshot;
use Weline\Order\Model\RefundCase;
use Weline\Order\Model\RefundOutbox;
use Weline\Order\Service\OrderAssetAllocationSnapshotService;
use Weline\Order\Service\OrderRefundCoordinator;
use Weline\Order\Service\PaymentRefundAssetReturnCapability;
use Weline\Payment\Model\PaymentAllocation;
use Weline\Payment\Service\AssetPaymentService;
use Weline\SystemConfig\Service\CommerceRolloutGate;

/**
 * PostgreSQL acceptance for the CustomerAsset → Payment → Order refund chain.
 */
final class CustomerAssetPaymentRefundPostgresqlIntegrationTest extends TestCase
{
    private string $customerId = '';
    private string $orderUuid = '';
    private string $allocationCode = '';
    private string $refundCaseUuid = '';
    private string $providerOutboxCode = '';
    private string $assetOutboxCode = '';
    private CustomerAssetService $customerAssets;

    public static function setUpBeforeClass(): void
    {
        $database = trim((string) getenv('WELINE_CUSTOMER_ASSET_TEST_DATABASE'));
        if ($database === '') {
            self::markTestSkipped(
                'WELINE_CUSTOMER_ASSET_TEST_DATABASE must identify a registered mig_clone_* PostgreSQL database',
            );
        }

        $env = include BP . '/app/etc/env.php';
        $db = is_array($env) ? ($env['db']['master'] ?? $env['db'] ?? []) : [];
        if (!is_array($db)) {
            self::fail('master database config is unavailable');
        }
        $db['database'] = $database;

        ObjectManager::clearInstances();
        $binding = ObjectManager::getInstance(MigrationTargetBinder::class)->bindIsolated($db);
        self::assertSame($database, $binding['database']);
        self::assertNotSame('', $binding['fingerprint']);
    }

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(12));
        $this->customerId = 'p4d2_' . substr($suffix, 0, 16);
        $this->orderUuid = 'ord-' . $suffix;
        $this->allocationCode = 'allocation-' . $suffix;
        $this->refundCaseUuid = 'rfc-' . $suffix;
        $this->providerOutboxCode = 'provider-' . $suffix;
        $this->assetOutboxCode = 'asset-' . $suffix;
        $this->customerAssets = new CustomerAssetService(
            rolloutGate: $this->enabledGate(),
        );

        self::assertSame(
            'pgsql',
            strtolower((string) $this->allocationModel()
                ->getConnection()
                ->getConnector()
                ->getConfigProvider()
                ->getDbType()),
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testMixedRefundRetriesAssetReturnWithoutRepeatingCashProvider(): void
    {
        $credit = $this->customerAssets->credit([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 1000,
            'event_id' => $this->customerId . ':credit',
        ]);
        self::assertFalse($credit['idempotent']);
        $reserved = $this->customerAssets->reserve([
            'customer_id' => $this->customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 300,
            'event_id' => $this->customerId . ':reserve',
        ]);
        $reservationId = (string) $reserved['reservation']['reservation_id'];
        $this->customerAssets->commit(
            $reservationId,
            $this->customerId . ':commit',
        );
        self::assertSame(700, $this->customerAssets->getBalance(
            $this->customerId,
            0,
            'credit',
        )['available_minor']);

        $intentCode = 'intent-' . substr(hash('sha256', $this->orderUuid), 0, 24);
        $attemptCode = 'attempt-' . substr(hash('sha256', $intentCode), 0, 24);
        $now = gmdate('Y-m-d H:i:s');
        $allocation = [
            PaymentAllocation::schema_fields_ALLOCATION_CODE => $this->allocationCode,
            PaymentAllocation::schema_fields_ENVIRONMENT => 'live',
            PaymentAllocation::schema_fields_PAYABLE_TYPE => 'order',
            PaymentAllocation::schema_fields_PAYABLE_ID => $this->orderUuid,
            PaymentAllocation::schema_fields_CHECKOUT_SESSION_CODE => null,
            PaymentAllocation::schema_fields_INTENT_CODE => $intentCode,
            PaymentAllocation::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentAllocation::schema_fields_TRANSACTION_CODE => 'txn-' . substr($attemptCode, 8),
            PaymentAllocation::schema_fields_REFUND_CODE => null,
            PaymentAllocation::schema_fields_SOURCE_TYPE => PaymentAllocation::SOURCE_ASSET,
            PaymentAllocation::schema_fields_SOURCE_CODE => 'credit',
            PaymentAllocation::schema_fields_ASSET_CODE => 'credit',
            PaymentAllocation::schema_fields_CUSTOMER_ID => $this->customerId,
            PaymentAllocation::schema_fields_WEBSITE_ID => 0,
            PaymentAllocation::schema_fields_NAMESPACE => AssetAccount::NS_LIVE,
            PaymentAllocation::schema_fields_RESERVATION_ID => $reservationId,
            PaymentAllocation::schema_fields_RESERVE_EVENT_ID =>
                $this->customerId . ':reserve',
            PaymentAllocation::schema_fields_ROLE => PaymentAllocation::ROLE_PAYMENT,
            PaymentAllocation::schema_fields_AMOUNT_MINOR => 300,
            PaymentAllocation::schema_fields_ASSET_AMOUNT_MINOR => 300,
            PaymentAllocation::schema_fields_CURRENCY_CODE => 'CNY',
            PaymentAllocation::schema_fields_PRECISION => 2,
            PaymentAllocation::schema_fields_RESERVED_AMOUNT_MINOR => 300,
            PaymentAllocation::schema_fields_COMMITTED_AMOUNT_MINOR => 300,
            PaymentAllocation::schema_fields_RELEASED_AMOUNT_MINOR => 0,
            PaymentAllocation::schema_fields_REFUNDED_AMOUNT_MINOR => 0,
            PaymentAllocation::schema_fields_STATUS => PaymentAllocation::STATUS_COMMITTED,
            PaymentAllocation::schema_fields_REQUEST_HASH => hash(
                'sha256',
                $this->allocationCode,
            ),
            PaymentAllocation::schema_fields_VERSION => 1,
            PaymentAllocation::schema_fields_CAS_TOKEN => bin2hex(random_bytes(32)),
            PaymentAllocation::schema_fields_ALLOCATION_SNAPSHOT => '{}',
            PaymentAllocation::schema_fields_METADATA_JSON => '{}',
            PaymentAllocation::schema_fields_CREATED_AT => $now,
            PaymentAllocation::schema_fields_UPDATED_AT => $now,
        ];
        $this->allocationModel()->setData($allocation)->save();

        $snapshots = new OrderAssetAllocationSnapshotService();
        $recorded = $snapshots->recordCommittedAllocations(
            'order',
            $this->orderUuid,
            $intentCode,
            $attemptCode,
            [$allocation],
            'payment:' . $intentCode . ':asset:commit:v1',
        );
        self::assertTrue($recorded['ok']);
        self::assertCount(1, $recorded['snapshots']);

        $split = $snapshots->allocateRefund(
            $this->orderUuid,
            1000,
            200,
        );
        self::assertSame(140, $split['cash_amount_minor']);
        self::assertSame(60, $split['asset_amount_minor']);
        self::assertCount(1, $split['asset_allocations']);

        $providerEffect = 'refund:' . $this->refundCaseUuid . ':provider:refund:v1';
        $assetEffect = 'refund:' . $this->refundCaseUuid . ':asset:return:v1';
        $this->refundCaseModel()->setData([
            RefundCase::schema_fields_REFUND_CASE_UUID => $this->refundCaseUuid,
            RefundCase::schema_fields_ORDER_UUID => $this->orderUuid,
            RefundCase::schema_fields_PAYMENT_REFUND_CODE => 'payment-refund-' . $this->orderUuid,
            RefundCase::schema_fields_IDEMPOTENCY_KEY => 'idem-' . $this->orderUuid,
            RefundCase::schema_fields_REQUEST_HASH => hash('sha256', $this->refundCaseUuid),
            RefundCase::schema_fields_AMOUNT_MINOR => 200,
            RefundCase::schema_fields_CASH_AMOUNT_MINOR => 140,
            RefundCase::schema_fields_ASSET_AMOUNT_MINOR => 60,
            RefundCase::schema_fields_ASSET_ALLOCATIONS_JSON => $this->json(
                $split['asset_allocations'],
            ),
            RefundCase::schema_fields_CURRENCY => 'CNY',
            RefundCase::schema_fields_ITEMS_JSON => '[]',
            RefundCase::schema_fields_SHIPPING_REFUND_MINOR => 0,
            RefundCase::schema_fields_STATUS => RefundCase::STATUS_SUCCEEDED,
            RefundCase::schema_fields_CUSTOMER_VIEW =>
                OrderRefundCoordinator::CUSTOMER_VIEW_SUCCEEDED,
            RefundCase::schema_fields_VERSION => 1,
            RefundCase::schema_fields_REASON => 'PostgreSQL acceptance',
            RefundCase::schema_fields_STEPS_JSON => $this->json([
                $providerEffect => ['status' => RefundOutbox::STATUS_DONE],
                $assetEffect => ['status' => RefundOutbox::STATUS_PENDING],
            ]),
            RefundCase::schema_fields_CREATED_AT => $now,
            RefundCase::schema_fields_UPDATED_AT => $now,
        ])->save();
        $this->refundOutboxModel()->setData([
            RefundOutbox::schema_fields_OUTBOX_CODE => $this->providerOutboxCode,
            RefundOutbox::schema_fields_EFFECT_KEY => $providerEffect,
            RefundOutbox::schema_fields_REFUND_CASE_UUID => $this->refundCaseUuid,
            RefundOutbox::schema_fields_REFUND_CODE => 'payment-refund-' . $this->orderUuid,
            RefundOutbox::schema_fields_OPERATION =>
                RefundOutbox::OPERATION_PROVIDER_REFUND,
            RefundOutbox::schema_fields_PROVIDER_REQUEST_KEY =>
                'provider-request-' . $this->refundCaseUuid,
            RefundOutbox::schema_fields_STATUS => RefundOutbox::STATUS_DONE,
            RefundOutbox::schema_fields_PAYLOAD_JSON => $this->json([
                'amount_minor' => 140,
                'currency_code' => 'CNY',
            ]),
            RefundOutbox::schema_fields_RESULT_JSON => '{"provider_refund_id":"accepted"}',
            RefundOutbox::schema_fields_ERROR_CODE => null,
            RefundOutbox::schema_fields_ATTEMPT_COUNT => 1,
            RefundOutbox::schema_fields_CLAIM_TOKEN => '',
            RefundOutbox::schema_fields_CLAIMED_AT => null,
            RefundOutbox::schema_fields_CREATED_AT => $now,
            RefundOutbox::schema_fields_PROCESSED_AT => $now,
        ])->save();
        $this->refundOutboxModel()->setData([
            RefundOutbox::schema_fields_OUTBOX_CODE => $this->assetOutboxCode,
            RefundOutbox::schema_fields_EFFECT_KEY => $assetEffect,
            RefundOutbox::schema_fields_REFUND_CASE_UUID => $this->refundCaseUuid,
            RefundOutbox::schema_fields_REFUND_CODE => 'payment-refund-' . $this->orderUuid,
            RefundOutbox::schema_fields_OPERATION => RefundOutbox::OPERATION_ASSET_RETURN,
            RefundOutbox::schema_fields_PROVIDER_REQUEST_KEY => null,
            RefundOutbox::schema_fields_STATUS => RefundOutbox::STATUS_PENDING,
            RefundOutbox::schema_fields_PAYLOAD_JSON => $this->json([
                'allocations' => $split['asset_allocations'],
            ]),
            RefundOutbox::schema_fields_RESULT_JSON => null,
            RefundOutbox::schema_fields_ERROR_CODE => null,
            RefundOutbox::schema_fields_ATTEMPT_COUNT => 0,
            RefundOutbox::schema_fields_CLAIM_TOKEN => '',
            RefundOutbox::schema_fields_CLAIMED_AT => null,
            RefundOutbox::schema_fields_CREATED_AT => $now,
            RefundOutbox::schema_fields_PROCESSED_AT => null,
        ])->save();

        $paymentAssets = new AssetPaymentService(
            transactionCoordinator: new TransactionCoordinator(),
            customerAssets: $this->customerAssets,
        );
        $paymentAssets->failNextTerminalEffect('asset:return:v1');
        $coordinator = new OrderRefundCoordinator(
            transactions: new TransactionCoordinator(),
            assetReturns: new PaymentRefundAssetReturnCapability($paymentAssets),
        );

        $failed = $coordinator->processOneOutbox($this->assetOutboxCode);
        self::assertFalse($failed['ok']);
        self::assertSame(
            'payment_asset_effect_controlled_failure',
            $failed['error_code'],
        );
        self::assertSame(
            RefundOutbox::STATUS_PENDING,
            $this->outbox($this->assetOutboxCode)['status'],
        );
        self::assertSame(1, $this->outbox($this->assetOutboxCode)['attempt_count']);
        self::assertProviderOutboxUnchanged($this->outbox($this->providerOutboxCode));

        $succeeded = $coordinator->processOneOutbox($this->assetOutboxCode);
        $replayed = $coordinator->processOneOutbox($this->assetOutboxCode);
        self::assertTrue($succeeded['ok']);
        self::assertTrue($replayed['ok']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(
            RefundOutbox::STATUS_DONE,
            $this->outbox($this->assetOutboxCode)['status'],
        );
        self::assertSame(2, $this->outbox($this->assetOutboxCode)['attempt_count']);
        self::assertProviderOutboxUnchanged($this->outbox($this->providerOutboxCode));

        $storedAllocation = $this->allocationModel()->clear()
            ->where(
                PaymentAllocation::schema_fields_ALLOCATION_CODE,
                $this->allocationCode,
            )
            ->find()
            ->fetch();
        self::assertSame(
            PaymentAllocation::STATUS_PARTIALLY_REFUNDED,
            $storedAllocation->getData(PaymentAllocation::schema_fields_STATUS),
        );
        self::assertSame(
            60,
            (int) $storedAllocation->getData(
                PaymentAllocation::schema_fields_REFUNDED_AMOUNT_MINOR,
            ),
        );
        self::assertSame(
            $this->refundCaseUuid,
            $storedAllocation->getData(PaymentAllocation::schema_fields_REFUND_CODE),
        );

        $balance = $this->customerAssets->getBalance(
            $this->customerId,
            0,
            'credit',
        );
        self::assertSame(760, $balance['available_minor']);
        self::assertSame(0, $balance['reserved_minor']);
        self::assertSame(
            60,
            (int) $this->reservationModel()->clear()
                ->where(
                    AssetReservation::schema_fields_RESERVATION_ID,
                    $reservationId,
                )
                ->find()
                ->fetch()
                ->getData(AssetReservation::schema_fields_RETURNED_AMOUNT_MINOR),
        );
        self::assertSame(
            1,
            count($this->ledgerModel()->clear()
                ->where(AssetLedger::schema_fields_CUSTOMER_ID, $this->customerId)
                ->where(AssetLedger::schema_fields_EVENT_TYPE, AssetLedger::TYPE_RETURN)
                ->select()
                ->fetchArray()),
        );
        self::assertCount(1, $snapshots->listForOrder($this->orderUuid));

        $case = $this->refundCaseModel()->clear()
            ->where(
                RefundCase::schema_fields_REFUND_CASE_UUID,
                $this->refundCaseUuid,
            )
            ->find()
            ->fetch();
        self::assertSame(140, (int) $case->getData(
            RefundCase::schema_fields_CASH_AMOUNT_MINOR,
        ));
        self::assertSame(60, (int) $case->getData(
            RefundCase::schema_fields_ASSET_AMOUNT_MINOR,
        ));
    }

    /** @param array<string, mixed> $outbox */
    private static function assertProviderOutboxUnchanged(array $outbox): void
    {
        self::assertSame(RefundOutbox::STATUS_DONE, $outbox['status']);
        self::assertSame(1, $outbox['attempt_count']);
        self::assertSame(
            RefundOutbox::OPERATION_PROVIDER_REFUND,
            $outbox['operation'],
        );
    }

    /** @return array{status:string,attempt_count:int,operation:string} */
    private function outbox(string $code): array
    {
        $row = $this->refundOutboxModel()->clear()
            ->where(RefundOutbox::schema_fields_OUTBOX_CODE, $code)
            ->find()
            ->fetch();

        return [
            'status' => (string) $row->getData(RefundOutbox::schema_fields_STATUS),
            'attempt_count' => (int) $row->getData(
                RefundOutbox::schema_fields_ATTEMPT_COUNT,
            ),
            'operation' => (string) $row->getData(
                RefundOutbox::schema_fields_OPERATION,
            ),
        ];
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function enabledGate(): CommerceRolloutGate
    {
        $gate = new CommerceRolloutGate();
        $gate->setMode(
            CustomerAssetService::CAPABILITY,
            CommerceRolloutGate::MODE_ALLOWLIST,
            ['website:0'],
        );

        return $gate;
    }

    private function cleanup(): void
    {
        if ($this->refundCaseUuid !== '') {
            $this->refundOutboxModel()->clear()
                ->where(
                    RefundOutbox::schema_fields_REFUND_CASE_UUID,
                    $this->refundCaseUuid,
                )
                ->delete()
                ->fetch();
            $this->refundCaseModel()->clear()
                ->where(
                    RefundCase::schema_fields_REFUND_CASE_UUID,
                    $this->refundCaseUuid,
                )
                ->delete()
                ->fetch();
        }
        if ($this->allocationCode !== '') {
            $this->snapshotModel()->clear()
                ->where(
                    OrderAssetAllocationSnapshot::schema_fields_ALLOCATION_CODE,
                    $this->allocationCode,
                )
                ->delete()
                ->fetch();
            $this->allocationModel()->clear()
                ->where(
                    PaymentAllocation::schema_fields_ALLOCATION_CODE,
                    $this->allocationCode,
                )
                ->delete()
                ->fetch();
        }
        if ($this->customerId === '') {
            return;
        }
        $this->ledgerModel()->clear()
            ->where(AssetLedger::schema_fields_CUSTOMER_ID, $this->customerId)
            ->delete()
            ->fetch();
        $this->reservationModel()->clear()
            ->where(AssetReservation::schema_fields_CUSTOMER_ID, $this->customerId)
            ->delete()
            ->fetch();
        $this->accountModel()->clear()
            ->where(AssetAccount::schema_fields_CUSTOMER_ID, $this->customerId)
            ->delete()
            ->fetch();
    }

    private function accountModel(): AssetAccount
    {
        return ObjectManager::create(AssetAccount::class, [], false);
    }

    private function ledgerModel(): AssetLedger
    {
        return ObjectManager::create(AssetLedger::class, [], false);
    }

    private function reservationModel(): AssetReservation
    {
        return ObjectManager::create(AssetReservation::class, [], false);
    }

    private function allocationModel(): PaymentAllocation
    {
        return ObjectManager::create(PaymentAllocation::class, [], false);
    }

    private function snapshotModel(): OrderAssetAllocationSnapshot
    {
        return ObjectManager::create(OrderAssetAllocationSnapshot::class, [], false);
    }

    private function refundCaseModel(): RefundCase
    {
        return ObjectManager::create(RefundCase::class, [], false);
    }

    private function refundOutboxModel(): RefundOutbox
    {
        return ObjectManager::create(RefundOutbox::class, [], false);
    }
}
