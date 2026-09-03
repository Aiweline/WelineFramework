<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentReturnPresentationService;

final class PaymentReturnPresentationServiceTest extends TestCase
{
    public function testBuildTotalsFromOrderMoneyAndMarksPaidStatus(): void
    {
        $transaction = $this->transaction([
            PaymentTransaction::schema_fields_STATUS => PaymentTransaction::STATUS_SUCCESS,
            PaymentTransaction::schema_fields_CURRENCY => 'CNY',
            PaymentTransaction::schema_fields_AMOUNT => 107.4,
        ]);
        $order = new OrderReadResult(
            orderUuid: 'order-1',
            checkoutGroupUuid: 'group-1',
            status: 'pending',
            currency: 'CNY',
            websiteId: 0,
            storeId: 0,
            money: [
                'subtotal_minor' => 9750,
                'shipping_amount_minor' => 990,
                'tax_amount_minor' => 0,
                'discount_amount_minor' => 0,
                'grand_total_minor' => 10740,
            ],
            shipping: ['method' => 'r43store_c1d7adba892e'],
        );

        $presentation = (new PaymentReturnPresentationService())->build($transaction, $order, null);

        self::assertSame('已支付', $presentation['status_label']);
        self::assertSame('标准配送', $presentation['shipping_method_label']);
        self::assertCount(4, $presentation['totals_lines']);
        self::assertSame('subtotal', $presentation['totals_lines'][0]['key']);
        self::assertSame(9750, $presentation['totals_lines'][0]['amount_minor']);
        self::assertSame('shipping', $presentation['totals_lines'][1]['key']);
        self::assertSame(990, $presentation['totals_lines'][1]['amount_minor']);
        self::assertSame('grand_total', $presentation['totals_lines'][3]['key']);
        self::assertTrue($presentation['totals_lines'][3]['emphasis'] ?? false);
    }

    public function testBuildDiscountLineFromCouponCodeInRequestData(): void
    {
        $transaction = $this->transaction([
            PaymentTransaction::schema_fields_STATUS => PaymentTransaction::STATUS_SUCCESS,
            PaymentTransaction::schema_fields_REQUEST_DATA => json_encode([
                'totals' => [
                    'subtotal_minor' => 10000,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'discount_amount_minor' => 1000,
                    'grand_total_minor' => 9000,
                ],
                'coupon_code' => 'SAVE10',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $presentation = (new PaymentReturnPresentationService())->build($transaction, null, null);
        $discountLine = null;
        foreach ($presentation['totals_lines'] as $line) {
            if (($line['key'] ?? '') === 'discount') {
                $discountLine = $line;
                break;
            }
        }

        self::assertNotNull($discountLine);
        self::assertSame(-1000, $discountLine['amount_minor']);
        self::assertSame('discount', $discountLine['key']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function transaction(array $data): PaymentTransaction
    {
        $transaction = new PaymentTransaction();
        foreach ($data as $field => $value) {
            $transaction->setData($field, $value);
        }

        return $transaction;
    }
}
