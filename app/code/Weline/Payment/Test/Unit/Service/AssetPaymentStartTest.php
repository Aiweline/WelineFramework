<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\Order\Extends\Module\Weline_Payment\PayableResolver\OrderPayableResolver;
use Weline\Order\Service\OrderAssetAllocationSnapshotService;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Api\PaymentEffectOutboxProcessorInterface;
use Weline\Payment\Model\PaymentWebhookInbox;
use Weline\Payment\Queue\PaymentInboxConsumer;
use Weline\Payment\Service\AssetAllocationService;
use Weline\Payment\Service\AssetPaymentService;
use Weline\Payment\Service\PayableResolverRegistry;
use Weline\Payment\Service\PaymentCallbackReceiver;
use Weline\Payment\Service\PaymentFacadeV2;
use Weline\Payment\Service\PaymentIntentOrchestrator;
use Weline\Payment\Service\PaymentAssetEffectConsumer;
use Weline\Payment\Service\WebhookEndpointDirectory;
use Weline\SystemConfig\Service\CommerceRolloutGate;

/**
 * TEST-P4D-02 and TEST-P4D-03 plus zero-cash start coverage on explicit memory seams.
 *
 * PostgreSQL transaction/concurrency acceptance is intentionally separate.
 */
final class AssetPaymentStartTest extends TestCase
{
    private CustomerAssetService $customerAssets;
    private AssetPaymentService $assetPayments;
    private PaymentIntentOrchestrator $orchestrator;
    private PaymentFacadeV2 $facade;

    protected function setUp(): void
    {
        $this->customerAssets = CustomerAssetService::forTesting(
            new CommerceRolloutGate(),
        );
        $this->customerAssets->enableAllowlist(['website:0']);
        $this->customerAssets->credit([
            'customer_id' => '42',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_LIVE,
            'amount_minor' => 1200,
            'event_id' => 'asset-start-seed',
        ]);
        $this->assetPayments = new AssetPaymentService(
            customerAssets: $this->customerAssets,
            snapshotSink: OrderAssetAllocationSnapshotService::forTesting(),
            policyOverride: $this->policy('1'),
            useMemory: true,
        );
        $this->orchestrator = PaymentIntentOrchestrator::forTesting();
        $registry = new PayableResolverRegistry();
        $registry->register(OrderPayableResolver::forTesting([
            'ord-asset' => [
                'order_uuid' => 'ord-asset',
                'checkout_group_uuid' => 'grp-asset',
                'status' => 'pending',
                'payment_status' => 'pending',
                'currency' => 'CNY',
                'website_id' => 0,
                'store_id' => 0,
                'customer_id' => '42',
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 1200,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'discount_amount_minor' => 0,
                    'grand_total_minor' => 1200,
                ],
                'scope' => [
                    'website_id' => 0,
                    'store_id' => 0,
                    'currency' => 'CNY',
                    'locale' => 'zh_Hans_CN',
                ],
                'items' => [],
            ],
        ]));
        $this->facade = PaymentFacadeV2::forTesting(
            $registry,
            $this->orchestrator,
            $this->assetPayments,
        );
        $this->facade->setEntryEnabled(true);
        $this->facade->setMerchantAccount('fake', 'acct_fake');
    }

    public function testMixedPaymentReservesBeforeCashAttemptAndUsesCashTail(): void
    {
        $result = $this->facade->start($this->command(300));

        self::assertTrue($result->isOk());
        self::assertSame(900, $result->getInt(PaymentOperationResult::FIELD_AMOUNT_MINOR));
        self::assertNotNull($result->getAttemptCode());
        $attempt = $this->orchestrator->getAttempt((string) $result->getAttemptCode());
        self::assertSame(900, $attempt['amount_minor']);
        self::assertSame(300, $this->balance()['reserved_minor']);
        $allocations = $this->assetPayments->listByIntent(
            (string) $result->getIntentCode(),
        );
        self::assertCount(1, $allocations);
        self::assertSame(300, $allocations[0]['asset_amount_minor']);
        self::assertSame(300, $allocations[0]['amount_minor']);
        self::assertSame('reserved', $allocations[0]['status']);
    }

    public function testFullAssetCreatesZeroAmountIntentWithoutAttemptOrProviderCommand(): void
    {
        $result = $this->facade->start($this->command(1200));

        self::assertTrue($result->isOk());
        self::assertSame('zero_amount_ready', $result->getStatus());
        self::assertNull($result->getAttemptCode());
        self::assertSame(0, $result->getInt(PaymentOperationResult::FIELD_AMOUNT_MINOR));
        self::assertSame([], $this->orchestrator->pendingOutbox());
        self::assertSame([], $this->orchestrator->providerCalls());
        self::assertCount(1, $this->orchestrator->effectOutbox());
        self::assertSame(1200, $this->balance()['reserved_minor']);
        self::assertCount(
            1,
            $this->assetPayments->listByIntent((string) $result->getIntentCode()),
        );
    }

    public function testReserveFailureCreatesNoIntentAttemptOrProviderCall(): void
    {
        $result = $this->facade->start($this->command(1201));

        self::assertFalse($result->isOk());
        self::assertSame(
            AssetPaymentService::ERROR_ALLOCATION_EXCEEDS,
            $result->getErrorCode(),
        );
        self::assertNull($result->getIntentCode());
        self::assertSame([], $this->orchestrator->providerCalls());
        self::assertSame([], $this->orchestrator->pendingOutbox());
        self::assertSame(0, $this->balance()['reserved_minor']);
    }

    public function testInsufficientBalanceCreatesNoCashAttempt(): void
    {
        $this->customerAssets->reserve([
            'customer_id' => '42',
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 1,
            'event_id' => 'occupy-one',
        ]);

        $result = $this->facade->start($this->command(1200));

        self::assertFalse($result->isOk());
        self::assertSame(
            CustomerAssetService::ERROR_INSUFFICIENT,
            $result->getErrorCode(),
        );
        self::assertNull($result->getIntentCode());
        self::assertSame([], $this->orchestrator->pendingOutbox());
        self::assertSame([], $this->orchestrator->providerCalls());
        self::assertSame(1, $this->balance()['reserved_minor']);
    }

    public function testReplayKeepsOneReservationAndChangedAllocationConflicts(): void
    {
        $first = $this->facade->start($this->command(300));
        $replay = $this->facade->start($this->command(300));
        self::assertSame($first->getIntentCode(), $replay->getIntentCode());
        self::assertSame(300, $this->balance()['reserved_minor']);
        self::assertCount(2, $this->customerAssets->listLedger('42', 0, 'credit'));

        $changed = $this->facade->start($this->command(301));
        self::assertSame(
            PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT,
            $changed->getErrorCode(),
        );
        self::assertSame(300, $this->balance()['reserved_minor']);
    }

    public function testExactDecimalRatioAndCallerConversionMismatchFailClosed(): void
    {
        $assetPayments = new AssetPaymentService(
            customerAssets: $this->customerAssets,
            snapshotSink: OrderAssetAllocationSnapshotService::forTesting(),
            policyOverride: $this->policy('0.5'),
            useMemory: true,
        );
        $registry = new PayableResolverRegistry();
        $registry->register(OrderPayableResolver::forTesting([
            'ord-ratio' => [
                'order_uuid' => 'ord-ratio',
                'status' => 'pending',
                'payment_status' => 'pending',
                'customer_id' => '42',
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 1200,
                    'grand_total_minor' => 1200,
                ],
                'scope' => [
                    'website_id' => 0,
                    'store_id' => 0,
                    'currency' => 'CNY',
                ],
                'items' => [],
            ],
        ]));
        $facade = PaymentFacadeV2::forTesting(
            $registry,
            PaymentIntentOrchestrator::forTesting(),
            $assetPayments,
        );
        $facade->setEntryEnabled(true);
        $command = PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-ratio',
            methodCode: 'fake',
            idempotencyKey: 'ratio-idem',
            requestHash: 'ratio-hash',
            actor: Actor::fromArray([
                'actor_type' => 'customer',
                'actor_id' => '42',
            ]),
            websiteId: 0,
            storeId: 0,
            assetRequests: [[
                'asset_code' => 'credit',
                'role' => AssetAllocationService::ROLE_PAYMENT,
                'asset_amount_minor' => 600,
                'amount_minor' => 301,
            ]],
        );

        $result = $facade->start($command);
        self::assertSame(
            AssetPaymentService::ERROR_CONVERSION_MISMATCH,
            $result->getErrorCode(),
        );
        self::assertSame(0, $this->balance()['reserved_minor']);
    }

    public function testCashSuccessAssetCommitRetriesWithoutChargingProviderTwice(): void
    {
        $start = $this->facade->start($this->command(300));
        self::assertTrue($start->isOk());
        $processed = $this->orchestrator->processPendingOutbox();
        self::assertTrue($processed[0]['ok'] ?? false);
        self::assertCount(1, $this->orchestrator->providerCalls());

        $effect = $this->assetEffect('asset:commit:v1');
        $this->assetPayments->failNextTerminalEffect('asset:commit:v1');
        try {
            $this->assetPayments->applyTerminalEffect($effect);
            self::fail('Expected controlled asset effect failure');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'payment_asset_effect_controlled_failure',
                $exception->getMessage(),
            );
        }
        self::assertSame(1200, $this->balance()['available_minor']);
        self::assertSame(300, $this->balance()['reserved_minor']);

        $retried = $this->assetPayments->applyTerminalEffect($effect);
        $replayed = $this->assetPayments->applyTerminalEffect($effect);
        self::assertTrue($retried['ok']);
        self::assertTrue($replayed['ok']);
        self::assertSame(900, $this->balance()['available_minor']);
        self::assertSame(0, $this->balance()['reserved_minor']);
        self::assertCount(1, $this->orchestrator->providerCalls());
        self::assertSame(
            1,
            count(array_filter(
                $this->customerAssets->listLedger('42', 0, 'credit'),
                static fn (array $row): bool =>
                    ($row['event_type'] ?? '') === 'commit',
            )),
        );
        $allocation = $this->assetPayments->listByIntent(
            (string) $start->getIntentCode(),
        )[0];
        self::assertSame('committed', $allocation['status']);
        self::assertSame(300, $allocation['committed_amount_minor']);
    }

    public function testCashFailureReleasesAssetWhileModeIsOff(): void
    {
        $this->orchestrator->setProviderHandler(
            static fn (): array => [
                'status' => PaymentIntentOrchestrator::STATUS_FAILED,
                'error_code' => 'declined',
            ],
        );
        $start = $this->facade->start($this->command(300));
        $this->customerAssets->modeOff();
        $processed = $this->orchestrator->processPendingOutbox();
        self::assertTrue($processed[0]['ok'] ?? false);

        $result = $this->assetPayments->applyTerminalEffect(
            $this->assetEffect('asset:release:v1'),
        );
        self::assertTrue($result['ok']);
        self::assertSame(1200, $this->balance()['available_minor']);
        self::assertSame(0, $this->balance()['reserved_minor']);
        self::assertCount(1, $this->orchestrator->providerCalls());
        $allocation = $this->assetPayments->listByIntent(
            (string) $start->getIntentCode(),
        )[0];
        self::assertSame('released', $allocation['status']);
        self::assertSame(300, $allocation['released_amount_minor']);
    }

    public function testCommittedAllocationReturnIsCumulativeAndIdempotent(): void
    {
        $start = $this->facade->start($this->command(300));
        $this->orchestrator->processPendingOutbox();
        $this->assetPayments->applyTerminalEffect(
            $this->assetEffect('asset:commit:v1'),
        );
        $allocation = $this->assetPayments->listByIntent(
            (string) $start->getIntentCode(),
        )[0];
        $request = [[
            'allocation_code' => $allocation['allocation_code'],
            'reservation_id' => $allocation['reservation_id'],
            'payment_refund_amount_minor' => 100,
            'asset_return_amount_minor' => 100,
            'cumulative_payment_refunded_minor' => 100,
        ]];

        $this->assetPayments->failNextTerminalEffect('asset:return:v1');
        try {
            $this->assetPayments->returnCommittedAllocations(
                'refund-case-asset-1',
                $request,
                'refund:refund-case-asset-1:asset:return:v1',
            );
            self::fail('Expected controlled asset return failure');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'payment_asset_effect_controlled_failure',
                $exception->getMessage(),
            );
        }
        self::assertSame(900, $this->balance()['available_minor']);
        self::assertCount(1, $this->orchestrator->providerCalls());

        $first = $this->assetPayments->returnCommittedAllocations(
            'refund-case-asset-1',
            $request,
            'refund:refund-case-asset-1:asset:return:v1',
        );
        $replay = $this->assetPayments->returnCommittedAllocations(
            'refund-case-asset-1',
            $request,
            'refund:refund-case-asset-1:asset:return:v1',
        );

        self::assertTrue($first['ok']);
        self::assertFalse($first['allocations'][0]['replayed']);
        self::assertTrue($replay['allocations'][0]['replayed']);
        self::assertSame(1000, $this->balance()['available_minor']);
        self::assertSame(
            1,
            count(array_filter(
                $this->customerAssets->listLedger('42', 0, 'credit'),
                static fn (array $row): bool =>
                    ($row['event_type'] ?? '') === 'return',
            )),
        );
        $stored = $this->assetPayments->listByIntent(
            (string) $start->getIntentCode(),
        )[0];
        self::assertSame('partially_refunded', $stored['status']);
        self::assertSame(100, $stored['refunded_amount_minor']);
        self::assertCount(1, $this->orchestrator->providerCalls());
    }

    public function testWebhookSuccessEnqueuesCanonicalAssetCommitEffect(): void
    {
        $start = $this->facade->start($this->command(300));
        $receiver = PaymentCallbackReceiver::forTesting(
            WebhookEndpointDirectory::forTesting(),
        );
        $inboxCode = $receiver->seedInbox([
            'endpoint_code' => 'asset-test-endpoint',
            'provider_event_id' => 'asset-paid-event',
            'provider_code' => 'fake',
            'merchant_account' => 'acct_fake',
            'environment' => 'sandbox',
            'schema_version' => '1',
            'payload_hash' => hash('sha256', 'asset-paid-event'),
            'intent_code' => (string) $start->getIntentCode(),
            'attempt_code' => (string) $start->getAttemptCode(),
            'status_transition' => 'paid',
            'status' => PaymentWebhookInbox::STATUS_RECEIVED,
        ]);
        $consumer = PaymentInboxConsumer::forTesting(
            $receiver,
            $this->orchestrator,
        );

        $applied = $consumer->applyOne($inboxCode);
        self::assertTrue($applied['ok']);
        $assetEffects = array_values(array_filter(
            $consumer->effectOutbox(),
            static fn (array $row): bool =>
                ($row['effect_type'] ?? '') === 'asset:commit:v1',
        ));
        self::assertCount(1, $assetEffects);
        self::assertSame(
            'attempt:' . $start->getAttemptCode() . ':asset:commit:v1',
            $assetEffects[0]['effect_key'],
        );
        self::assertCount(4, $consumer->effectOutbox());
    }

    public function testAssetEffectOutboxStaysPendingOnFailureThenCompletesOnce(): void
    {
        $this->facade->start($this->command(300));
        $this->orchestrator->processPendingOutbox();
        $effect = $this->assetEffect('asset:commit:v1');
        $processor = new class($effect) implements PaymentEffectOutboxProcessorInterface {
            public bool $done = false;

            public function __construct(
                private readonly PaymentEffectRecord $record,
            ) {
            }

            public function pendingCodes(array $effectTypes, int $limit = 20): array
            {
                return !$this->done && in_array(
                    $this->record->effectType,
                    $effectTypes,
                    true,
                ) ? [$this->record->outboxCode] : [];
            }

            public function process(string $outboxCode, callable $handler): array
            {
                if ($this->done) {
                    return [
                        'ok' => true,
                        'replayed' => true,
                        'effect' => $this->record->toArray(),
                    ];
                }
                $result = $handler($this->record);
                $this->done = true;
                return [
                    'ok' => true,
                    'replayed' => false,
                    'effect' => $this->record->toArray(),
                    'result' => $result,
                ];
            }
        };
        $consumer = new PaymentAssetEffectConsumer(
            $processor,
            $this->assetPayments,
        );
        $this->assetPayments->failNextTerminalEffect('asset:commit:v1');

        try {
            $consumer->processPending();
            self::fail('Expected terminal effect failure');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'payment_asset_effect_controlled_failure',
                $exception->getMessage(),
            );
        }
        self::assertFalse($processor->done);
        self::assertSame(300, $this->balance()['reserved_minor']);

        $completed = $consumer->processPending();
        self::assertTrue($processor->done);
        self::assertFalse($completed[0]['replayed']);
        self::assertSame(0, $this->balance()['reserved_minor']);
        self::assertTrue(
            $consumer->processOne($effect->outboxCode)['replayed'],
        );
        self::assertCount(1, $this->orchestrator->providerCalls());
    }

    private function command(int $assetAmountMinor): PaymentStartCommand
    {
        return PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-asset',
            methodCode: 'fake',
            idempotencyKey: 'asset-start-idem',
            requestHash: 'asset-start-hash',
            actor: Actor::fromArray([
                'actor_type' => 'customer',
                'actor_id' => '42',
            ]),
            websiteId: 0,
            storeId: 0,
            assetRequests: [[
                'asset_code' => 'credit',
                'role' => AssetAllocationService::ROLE_PAYMENT,
                'asset_amount_minor' => $assetAmountMinor,
                'amount_minor' => $assetAmountMinor,
            ]],
        );
    }

    private function assetEffect(string $effectType): PaymentEffectRecord
    {
        $row = null;
        foreach ($this->orchestrator->effectOutbox() as $candidate) {
            if (($candidate['effect_type'] ?? '') === $effectType) {
                $row = $candidate;
                break;
            }
        }
        self::assertIsArray($row, 'Expected terminal asset effect');

        return new PaymentEffectRecord(
            outboxCode: (string) $row['outbox_code'],
            effectKey: (string) $row['effect_key'],
            intentCode: (string) $row['intent_code'],
            attemptCode: (string) ($row['attempt_code'] ?? ''),
            effectType: (string) $row['effect_type'],
            payableType: (string) $row['payable_type'],
            payableId: (string) $row['payable_id'],
            schemaVersion: (string) $row['schema_version'],
        );
    }

    /** @return array<string, mixed> */
    private function policy(string $ratio): array
    {
        return [
            'credit' => [
                'enabled' => true,
                'roles' => [
                    AssetAllocationService::ROLE_PAYMENT => true,
                    AssetAllocationService::ROLE_DISCOUNT => false,
                ],
                'exchange_ratio' => $ratio,
                'max_discount_ratio' => '1',
                'allowed_payable_types' => [OrderPayableResolver::PAYABLE_TYPE],
                'refund_strategy' => 'allocation',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function balance(): array
    {
        return $this->customerAssets->getBalance('42', 0, 'credit');
    }
}
