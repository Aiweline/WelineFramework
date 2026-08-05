<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\Data\PaymentQueryCommand;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Api\PaymentFacadeV2Interface;
use Weline\Subscription\Service\OrderFacadeSubscriptionOrderPort;
use Weline\Subscription\Service\PaymentFacadeSubscriptionPaymentPort;
use Weline\Subscription\Service\SubscriptionConflictException;

/** P4B2-E/F: production adapters use only stable Order/Payment public contracts. */
final class SubscriptionOrderPaymentAdapterTest extends TestCase
{
    public function testOrderAdapterFreezesScopeAndUsesPeriodIdempotency(): void
    {
        $facade = $this->createMock(OrderFacadeInterface::class);
        $facade->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (CreateCheckoutGroupCommand $command): bool {
                self::assertSame(0, $command->websiteId);
                self::assertSame(9, $command->storeId);
                self::assertSame('CNY', $command->currency);
                self::assertSame(42, $command->customerId);
                self::assertCount(1, $command->lines);
                self::assertSame(1200, $command->lines[0]['unit_price_minor']);
                self::assertSame('plan-adapter', $command->lines[0]['sku']);
                self::assertStringStartsWith('subscription-period-', $command->idempotencyKey);
                return true;
            }))
            ->willReturn(new CreateCheckoutGroupResult(
                checkoutGroupUuid: 'cg-subscription',
                orderUuids: ['order-subscription-period-1'],
                currency: 'CNY',
                replayed: false,
            ));

        $result = (new OrderFacadeSubscriptionOrderPort($facade))->createPeriodOrder([
            'period_key' => 'sub-a:period:1',
            'subscription_id' => 'sub-a',
            'website_id' => 0,
            'store_id' => 9,
            'customer_id' => '42',
            'plan_code' => 'plan-adapter',
            'amount_minor' => 1200,
            'currency' => 'CNY',
        ]);
        self::assertSame('order-subscription-period-1', $result['order_ref']);
        self::assertFalse($result['replayed']);
    }

    public function testPaymentAdapterStartsAndQueriesSameIntentWithoutMoneyAuthority(): void
    {
        $facade = $this->createMock(PaymentFacadeV2Interface::class);
        $facade->expects(self::once())
            ->method('start')
            ->with(self::callback(static function (PaymentStartCommand $command): bool {
                self::assertSame('weline_order', $command->getPayableType());
                self::assertSame('order-subscription-period-1', $command->getPayableId());
                self::assertSame('fake_card', $command->getMethodCode());
                self::assertSame(0, $command->getWebsiteId());
                self::assertSame(9, $command->getStoreId());
                self::assertSame('customer', $command->getActor()?->getActorType());
                self::assertSame('42', $command->getActor()?->getActorId());
                self::assertStringStartsWith('subscription-payment-', $command->getIdempotencyKey());
                return true;
            }))
            ->willReturn(PaymentOperationResult::create(
                intentCode: 'pi-subscription',
                attemptCode: 'pa-subscription',
                status: 'unknown',
                terminal: false,
                errorCode: 'provider_result_unknown',
                payableType: 'weline_order',
                payableId: 'order-subscription-period-1',
            ));
        $facade->expects(self::once())
            ->method('query')
            ->with(self::callback(static function (PaymentQueryCommand $command): bool {
                self::assertSame('pi-subscription', $command->getIntentCode());
                return true;
            }))
            ->willReturn(PaymentOperationResult::create(
                intentCode: 'pi-subscription',
                attemptCode: 'pa-subscription',
                status: 'succeeded',
                terminal: true,
                payableType: 'weline_order',
                payableId: 'order-subscription-period-1',
            ));

        $adapter = new PaymentFacadeSubscriptionPaymentPort($facade);
        $started = $adapter->startPeriodPayment([
            'period_key' => 'sub-a:period:1',
            'subscription_id' => 'sub-a',
            'order_ref' => 'order-subscription-period-1',
            'website_id' => 0,
            'store_id' => 9,
            'customer_id' => '42',
            'environment' => 'sandbox',
        ]);
        self::assertSame('unknown', $started['status']);
        self::assertSame('pi-subscription', $started['intent_code']);

        $queried = $adapter->queryPeriodPayment([
            'order_ref' => 'order-subscription-period-1',
            'customer_id' => '42',
            'intent_code' => $started['intent_code'],
        ]);
        self::assertSame('succeeded', $queried['status']);
        self::assertTrue($queried['terminal']);
        self::assertTrue($queried['replayed']);
    }

    public function testLivePaymentIsNotAuthorizedByP4b002(): void
    {
        $facade = $this->createMock(PaymentFacadeV2Interface::class);
        $facade->expects(self::never())->method('start');

        $this->expectException(SubscriptionConflictException::class);
        (new PaymentFacadeSubscriptionPaymentPort($facade))->startPeriodPayment([
            'period_key' => 'sub-live:period:1',
            'subscription_id' => 'sub-live',
            'order_ref' => 'order-live',
            'website_id' => 0,
            'store_id' => 0,
            'customer_id' => '42',
            'environment' => 'live',
        ]);
    }
}
