<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangOrderService;
use Weline\B2B\Service\B2BConflictException;
use Weline\Marketing\Api\Quote\DiscountQuote;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;
use Weline\Checkout\Service\CheckoutGroupSubmitService;
use Weline\Checkout\Service\CheckoutPaymentRecoveryStateService;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\Checkout\Model\CheckoutSession;
use Weline\B2B\Service\B2BService;
use Weline\Order\Service\OrderCalculatorGate;
use Weline\Order\Service\CommerceOrderTypeRegistry;
use Weline\Shipping\Service\ScopedShippingQuoteService;

final class TobDiscountHangMultilineContractTest extends TestCase
{
    public function testOrderCalculatorGateBansTobPriceChangingCalculators(): void
    {
        $tob = new class implements \Weline\Order\Api\CommerceOrderTypeInterface {
            public function getCode(): string { return 'tob'; }
            public function getLabel(): string { return '批发'; }
            public function getBadgeTone(): string { return 'warning'; }
            public function requiresCustomerLogin(): bool { return true; }
            public function disablesStorefrontDiscounts(): bool { return true; }
        };
        $gate = OrderCalculatorGate::forTesting(CommerceOrderTypeRegistry::forTesting([$tob]));
        self::assertFalse($gate->allowsPriceChangingCalculators('tob'));
        self::assertTrue($gate->allowsPriceChangingCalculators('toc'));
    }

    public function testTobFreezeSkipsDiscountQuoteAndComputesDeposit(): void
    {
        $discountCalls = 0;
        $discounts = new class($discountCalls) implements DiscountQuoteServiceInterface {
            public function __construct(private int &$calls) {}
            public function activeConfigVersion(): string { return '1'; }
            public function quote(DiscountQuoteRequest $request): DiscountQuote
            {
                $this->calls++;
                return new DiscountQuote(
                    discountQuoteToken: 'dqt_x',
                    amountMinor: 100,
                    currency: 'CNY',
                    currencyPrecision: 2,
                    requestHash: 'h',
                    lines: [],
                    appliedRuleIds: [1],
                    couponCode: 'SAVE',
                    actionPayloads: [],
                );
            }
            public function validateToken(DiscountQuoteRequest $request, DiscountQuote $quote): bool { return true; }
            public function redeemCoupon(string $code, array $context, DiscountQuote $quote): void {}
        };

        $quotes = ScopedShippingQuoteService::forTesting([
            'std' => ['amount_minor' => 1500, 'label' => 'Standard', 'currencies' => ['CNY']],
        ], '1');
        $svc = CheckoutGroupSubmitService::forTesting($quotes, discountQuotes: $discounts);

        $frozen = $svc->freezeAndQuote(
            lines: [[
                'name' => 'SKU-A',
                'sku' => 'SKU-A',
                'qty_minor' => 1,
                'unit_price_minor' => 10000,
                'requires_shipping' => true,
                'line_uuid' => 'line-1',
            ]],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
            cartType: 'tob',
            couponCode: 'SAVE',
        );

        self::assertSame(0, $discountCalls);
        self::assertNull($frozen['discount']);
        self::assertSame('', $frozen['coupon_code']);
        self::assertTrue($frozen['discounts_banned']);
        self::assertSame('tob', $frozen['cart_type']);
        self::assertSame(3000, $frozen['deposit']['deposit_ratio_bps']);
        // goods 10000 + tax 0 = 10000; 30% = 3000; shipping 1500 not in deposit
        self::assertSame(10000, $frozen['deposit']['goods_subtotal_taxed_minor']);
        self::assertSame(3000, $frozen['deposit']['deposit_amount_minor']);
        self::assertSame(8500, $frozen['deposit']['balance_amount_minor']); // 7000 remaining goods + 1500 ship
        self::assertFalse($frozen['deposit']['shipping_in_deposit']);
    }

    public function testMultilineQuoteSetAtomicConsumeAndDriftFailsAll(): void
    {
        $service = B2BService::forTesting();
        $service->seedGroup('g-dealer', 0, 'dealer');
        $service->assignCustomer('cust-b2b', 'g-dealer');
        $service->seedPriceList('pl-dealer', 'g-dealer', 0, 1, [
            'SKU-A' => 800,
            'SKU-B' => 900,
        ]);
        $service->enableShadow();

        $set = $service->issueQuoteSet([
            [
                'customer_id' => 'cust-b2b',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ],
            [
                'customer_id' => 'cust-b2b',
                'website_id' => 0,
                'sku' => 'SKU-B',
                'retail_amount_minor' => 1200,
            ],
        ]);
        self::assertTrue($set['ok']);
        self::assertNotSame('', $set['quote_set_id']);
        self::assertCount(2, $set['tokens']);

        $tokenIds = array_map(
            static fn (array $t): string => (string)$t['token_id'],
            $set['tokens'],
        );

        $ok = $service->submitQuoteSet($tokenIds, 'cust-b2b', 0, 'order-multi-1');
        self::assertTrue($ok['ok']);
        self::assertCount(2, $ok['snapshots']);
        self::assertSame(2, $service->checkout()->acceptedOrderCount());

        $set2 = $service->issueQuoteSet([
            [
                'customer_id' => 'cust-b2b',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ],
            [
                'customer_id' => 'cust-b2b',
                'website_id' => 0,
                'sku' => 'SKU-B',
                'retail_amount_minor' => 1200,
            ],
        ]);
        $tokenIds2 = array_map(
            static fn (array $t): string => (string)$t['token_id'],
            $set2['tokens'],
        );
        $service->seedPriceList('pl-dealer', 'g-dealer', 0, 2, [
            'SKU-A' => 780,
            'SKU-B' => 900,
        ]);
        $before = $service->checkout()->acceptedOrderCount();
        $failed = $service->submitQuoteSet($tokenIds2, 'cust-b2b', 0, 'order-multi-drift');
        self::assertFalse($failed['ok']);
        self::assertSame(\Weline\B2B\Service\B2BCheckoutRecheckService::ERROR_QUOTE_VERSION_CONFLICT, $failed['error']);
        self::assertSame($before, $service->checkout()->acceptedOrderCount());
        // tokens remain open (not partially consumed)
        self::assertSame('open', $service->checkout()->quotes()->get($tokenIds2[0])?->status());
        self::assertSame('open', $service->checkout()->quotes()->get($tokenIds2[1])?->status());
    }

    public function testHangLifecycleDepositApproveBalanceAndReject(): void
    {
        $hang = B2BHangOrderService::forTesting(clock: static fn (): int => 1_700_000_000);
        $created = $hang->createAwaitingDeposit([
            'order_ref' => 'ord-1',
            'customer_id' => 'cust-1',
            'website_id' => 0,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 1500,
            'is_shipping_owner' => true,
        ]);
        self::assertSame(B2BOrderHang::STATUS_AWAITING_DEPOSIT, $created['hang_status']);
        self::assertSame(3000, $created['deposit_amount_minor']);
        self::assertSame(8500, $created['balance_amount_minor']);

        $reserved = false;
        $afterDeposit = $hang->onDepositPaid('ord-1', 'pi_deposit_1', static function () use (&$reserved): array {
            $reserved = true;
            return [['reservation_uuid' => 'res-1']];
        });
        self::assertTrue($reserved);
        self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $afterDeposit['hang_status']);
        self::assertSame('pi_deposit_1', $afterDeposit['deposit_intent_code']);

        try {
            $hang->approve('ord-1');
            // should work
        } catch (B2BConflictException $e) {
            self::fail($e->getMessage());
        }
        $approved = $hang->getByOrderRef('ord-1');
        self::assertSame(B2BOrderHang::STATUS_AWAITING_BALANCE, $approved?->hangStatus);

        $completed = $hang->onBalancePaid('ord-1', 'pi_balance_1');
        self::assertSame(B2BOrderHang::STATUS_COMPLETED, $completed['hang_status']);

        $hang2 = B2BHangOrderService::forTesting(clock: static fn (): int => 1_700_000_100);
        $hang2->createAwaitingDeposit([
            'order_ref' => 'ord-2',
            'customer_id' => 'cust-1',
            'website_id' => 0,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 0,
            'is_shipping_owner' => false,
        ]);
        $hang2->onDepositPaid('ord-2', 'pi_d2', static fn (): array => [['reservation_uuid' => 'res-2']]);
        $rejected = $hang2->reject('ord-2', notes: 'no stock');
        self::assertSame(B2BOrderHang::STATUS_REJECTED, $rejected['hang_status']);
        self::assertNotEmpty($hang2->refunds());
        self::assertSame('awaiting_deposit', $hang2->eventPayloads()[0]['type_payload']['hang_status']);
    }

    public function testPaymentRecoveryRecordsDepositAndBalanceEntries(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_hang', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => 'order-idem-hang',
            'submitted_result' => ['order_uuids' => ['ord-h']],
        ]);
        $svc = new CheckoutPaymentRecoveryStateService($store);
        $svc->record('qt_hang', 'order-idem-hang', [
            'paid' => false,
            'outcome' => 'pending',
            'status' => 'pending',
            'purpose' => 'deposit',
        ]);
        $svc->record('qt_hang', 'order-idem-hang', [
            'paid' => true,
            'outcome' => 'paid',
            'status' => 'paid',
            'purpose' => 'balance',
        ]);
        self::assertSame('deposit', $svc->getByPurpose('qt_hang', 'order-idem-hang', 'deposit')['purpose']);
        self::assertSame('balance', $svc->getByPurpose('qt_hang', 'order-idem-hang', 'balance')['purpose']);
        self::assertSame('paid', $svc->get('qt_hang', 'order-idem-hang')['outcome']);
    }
}
