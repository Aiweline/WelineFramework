<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Extends\Module\Weline_Payment\PayableResolver\OrderPayableResolver;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PaymentQueryCommand;
use Weline\Payment\Api\Data\PaymentResumeCommand;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Service\PayableResolverRegistry;
use Weline\Payment\Service\PaymentFacadeV2;
use Weline\Payment\Service\PaymentIntentOrchestrator;

final class PaymentFacadeV2Test extends TestCase
{
    private PaymentFacadeV2 $facade;
    private OrderPayableResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = OrderPayableResolver::forTesting([
            'ord-1' => [
                'order_uuid' => 'ord-1',
                'checkout_group_uuid' => 'grp-1',
                'status' => 'pending',
                'payment_status' => 'pending',
                'currency' => 'CNY',
                'website_id' => 0,
                'store_id' => 0,
                'customer_id' => '42',
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 1000,
                    'shipping_amount_minor' => 200,
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
                'items' => [
                    [
                        'item_uuid' => 'item-1',
                        'name' => 'Demo',
                        'qty_minor' => 1,
                        'row_total_minor' => 1000,
                    ],
                ],
                'display_number' => 'DN-1',
            ],
        ]);

        $registry = new PayableResolverRegistry();
        $registry->register($this->resolver);
        $this->facade = PaymentFacadeV2::forTesting($registry);
        $this->facade->setMerchantAccount('fake', 'acct_server_fake');
    }

    public function testEntryClosedKeepsOrderUnpaid(): void
    {
        $result = $this->facade->start($this->startCommand());
        self::assertFalse($result->isOk());
        self::assertSame(PaymentFacadeV2::ERROR_ENTRY_CLOSED, $result->getErrorCode());
        self::assertNull($result->getIntentCode());
    }

    public function testStartFreezesServerSnapshotAndMerchantAccount(): void
    {
        $this->facade->setEntryEnabled(true);
        $result = $this->facade->start($this->startCommand());

        self::assertTrue($result->isOk());
        self::assertNotNull($result->getIntentCode());
        self::assertSame(1200, $result->getInt(PaymentOperationResult::FIELD_AMOUNT_MINOR));
        self::assertSame('CNY', $result->getString(PaymentOperationResult::FIELD_CURRENCY_CODE));
        self::assertSame('acct_server_fake', $result->getString(PaymentOperationResult::FIELD_MERCHANT_ACCOUNT));
        self::assertSame(0, (int) ($result->getArray(PaymentOperationResult::FIELD_SCOPE)['website_id'] ?? -1));
        self::assertSame(PaymentOperationResult::NEXT_POLL, $result->getNextActionType());
    }

    public function testCallerCannotOverrideFrozenMoneyCurrencyOrMerchantAccount(): void
    {
        $this->facade->setEntryEnabled(true);
        $command = PaymentStartCommand::fromArray([
            PaymentStartCommand::FIELD_PAYABLE_TYPE => OrderPayableResolver::PAYABLE_TYPE,
            PaymentStartCommand::FIELD_PAYABLE_ID => 'ord-1',
            PaymentStartCommand::FIELD_METHOD_CODE => 'fake',
            PaymentStartCommand::FIELD_IDEMPOTENCY_KEY => 'idem-forged-money',
            PaymentStartCommand::FIELD_REQUEST_HASH => 'hash-forged-money',
            PaymentStartCommand::FIELD_ACTOR => Actor::fromArray([
                'actor_type' => 'customer',
                'actor_id' => '42',
            ]),
            PaymentStartCommand::FIELD_WEBSITE_ID => 0,
            PaymentStartCommand::FIELD_STORE_ID => 0,
            'amount_minor' => 1,
            'currency_code' => 'USD',
            'merchant_account' => 'acct_attacker',
            'provider_reference' => 'provider-attacker',
        ]);

        $result = $this->facade->start($command);

        self::assertTrue($result->isOk());
        self::assertSame(1200, $result->getInt(PaymentOperationResult::FIELD_AMOUNT_MINOR));
        self::assertSame('CNY', $result->getString(PaymentOperationResult::FIELD_CURRENCY_CODE));
        self::assertSame('acct_server_fake', $result->getString(PaymentOperationResult::FIELD_MERCHANT_ACCOUNT));
    }

    public function testScopeMismatchFailsClosed(): void
    {
        $this->facade->setEntryEnabled(true);
        $result = $this->facade->start($this->startCommand(websiteId: 1, storeId: 2));
        self::assertSame(PaymentFacadeV2::ERROR_SCOPE_MISMATCH, $result->getErrorCode());
    }

    public function testPaidOrderNotEligible(): void
    {
        $paid = OrderPayableResolver::forTesting([
            'ord-paid' => [
                'order_uuid' => 'ord-paid',
                'status' => 'paid',
                'payment_status' => 'paid',
                'currency' => 'CNY',
                'website_id' => 0,
                'store_id' => 0,
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 100,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'grand_total_minor' => 100,
                ],
                'scope' => ['website_id' => 0, 'store_id' => 0, 'currency' => 'CNY'],
                'items' => [],
            ],
        ]);
        $registry = new PayableResolverRegistry();
        $registry->register($paid);
        $facade = PaymentFacadeV2::forTesting($registry);
        $facade->setEntryEnabled(true);

        $result = $facade->start(PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-paid',
            methodCode: 'fake',
            idempotencyKey: 'idem-paid',
            requestHash: 'hash-paid',
            actor: Actor::fromArray(['actor_type' => 'customer', 'actor_id' => '1']),
            websiteId: 0,
            storeId: 0,
        ));
        self::assertSame(PaymentFacadeV2::ERROR_NOT_ELIGIBLE, $result->getErrorCode());
    }

    public function testIdempotentReplayAndQuery(): void
    {
        $this->facade->setEntryEnabled(true);
        $first = $this->facade->start($this->startCommand());
        $second = $this->facade->start($this->startCommand());
        self::assertSame($first->getIntentCode(), $second->getIntentCode());

        $queried = $this->facade->query(PaymentQueryCommand::byPayable(
            OrderPayableResolver::PAYABLE_TYPE,
            'ord-1',
        ));
        self::assertSame($first->getIntentCode(), $queried->getIntentCode());
    }

    public function testIdempotencyConflictOnHashChange(): void
    {
        $this->facade->setEntryEnabled(true);
        $this->facade->start($this->startCommand());
        $conflict = $this->facade->start($this->startCommand(requestHash: 'other-hash'));
        self::assertSame(PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT, $conflict->getErrorCode());
    }

    public function testIdempotencyConflictWhenReturnUrlChanges(): void
    {
        $this->facade->setEntryEnabled(true);
        $allowed = [
            'https://shop.example.test/payment/complete',
            'https://shop.example.test/payment/pending',
        ];
        $first = PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-1',
            methodCode: 'fake',
            idempotencyKey: 'idem-return-url',
            requestHash: 'same-caller-hash',
            actor: Actor::fromArray(['actor_type' => 'customer', 'actor_id' => '42']),
            websiteId: 0,
            storeId: 0,
            returnUrl: $allowed[0],
            allowedReturnUrls: $allowed,
        );
        $changed = PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-1',
            methodCode: 'fake',
            idempotencyKey: 'idem-return-url',
            requestHash: 'same-caller-hash',
            actor: Actor::fromArray(['actor_type' => 'customer', 'actor_id' => '42']),
            websiteId: 0,
            storeId: 0,
            returnUrl: $allowed[1],
            allowedReturnUrls: $allowed,
        );

        self::assertTrue($this->facade->start($first)->isOk());
        self::assertSame(
            PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT,
            $this->facade->start($changed)->getErrorCode(),
        );
    }

    public function testIdempotencyConflictWhenServerSnapshotVersionChanges(): void
    {
        $ordersV1 = [
            'ord-snapshot' => [
                'order_uuid' => 'ord-snapshot',
                'status' => 'pending',
                'payment_status' => 'pending',
                'customer_id' => '7',
                'snapshot_version' => 'snapshot-v1',
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 800,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'discount_amount_minor' => 0,
                    'grand_total_minor' => 800,
                ],
                'scope' => [
                    'website_id' => 0,
                    'store_id' => 0,
                    'currency' => 'CNY',
                ],
                'items' => [],
            ],
        ];
        $ordersV2 = $ordersV1;
        $ordersV2['ord-snapshot']['snapshot_version'] = 'snapshot-v2';
        $orchestrator = PaymentIntentOrchestrator::forTesting();
        $registryV1 = new PayableResolverRegistry();
        $registryV1->register(OrderPayableResolver::forTesting($ordersV1));
        $registryV2 = new PayableResolverRegistry();
        $registryV2->register(OrderPayableResolver::forTesting($ordersV2));
        $facadeV1 = new PaymentFacadeV2($registryV1, $orchestrator);
        $facadeV2 = new PaymentFacadeV2($registryV2, $orchestrator);
        $facadeV1->setEntryEnabled(true);
        $facadeV2->setEntryEnabled(true);
        $command = PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-snapshot',
            methodCode: 'fake',
            idempotencyKey: 'idem-snapshot-version',
            requestHash: 'same-caller-hash',
            actor: Actor::fromArray(['actor_type' => 'customer', 'actor_id' => '7']),
            websiteId: 0,
            storeId: 0,
        );

        self::assertTrue($facadeV1->start($command)->isOk());
        self::assertSame(
            PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT,
            $facadeV2->start($command)->getErrorCode(),
        );
    }

    public function testProductionFacadeFailsClosedWithoutPersistentOrchestrator(): void
    {
        $registry = new PayableResolverRegistry();
        $registry->register($this->resolver);
        $facade = new PaymentFacadeV2($registry, null);
        $facade->setEntryEnabled(true);

        foreach ([
            $facade->start($this->startCommand()),
            $facade->resume(PaymentResumeCommand::create('pi_missing', 'resume-1')),
            $facade->query(PaymentQueryCommand::byIntent('pi_missing')),
        ] as $result) {
            self::assertSame(PaymentFacadeV2::ERROR_ORCHESTRATOR_UNAVAILABLE, $result->getErrorCode());
            self::assertSame(PaymentFacadeV2::STATUS_UNAVAILABLE, $result->getStatus());
            self::assertFalse($result->isTerminal());
        }
    }

    private function startCommand(int $websiteId = 0, int $storeId = 0, string $requestHash = 'hash-1'): PaymentStartCommand
    {
        return PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-1',
            methodCode: 'fake',
            idempotencyKey: 'idem-1',
            requestHash: $requestHash,
            actor: Actor::fromArray(['actor_type' => 'customer', 'actor_id' => '42']),
            websiteId: $websiteId,
            storeId: $storeId,
        );
    }
}
