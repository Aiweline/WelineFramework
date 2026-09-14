<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentCaptureReaderEnsureService;

final class PaymentCaptureReaderEnsureServiceTest extends TestCase
{
    public function testMapFromSuccessTransactionUsesCaptureIdAndCanonicalPayableType(): void
    {
        $mapped = PaymentCaptureReaderEnsureService::mapFromTransactionArray([
            'transaction_no' => 'PAY20260914071423135688',
            'order_id' => '225e77d6-33e2-4d2e-9ceb-18b55f63e018',
            'method_code' => 'paypal',
            'amount' => '193.70',
            'currency' => 'CNY',
            'status' => PaymentTransaction::STATUS_SUCCESS,
            'scope' => 'default.default.default',
            'request_data' => [
                'payable_type' => 'weline_order',
                'payable_id' => '225e77d6-33e2-4d2e-9ceb-18b55f63e018',
                'amount_minor' => 19370,
                'currency_code' => 'CNY',
                'environment' => 'sandbox',
                'method_code' => 'paypal',
            ],
            'response_data' => [
                'status' => 'paid',
                'intent_code' => 'PAY20260914071423135688',
                'attempt_code' => 'PAY20260914071423135688-1',
                'provider_reference' => '6H324144C1729631Y',
                'payload' => ['capture_id' => '6H324144C1729631Y'],
            ],
        ]);
        self::assertIsArray($mapped);
        self::assertSame(
            PaymentCaptureReaderEnsureService::CANONICAL_PAYABLE_TYPE,
            $mapped['intent'][PaymentIntent::schema_fields_PAYABLE_TYPE],
        );
        self::assertSame(PaymentIntent::STATUS_PAID, $mapped['intent'][PaymentIntent::schema_fields_STATUS]);
        self::assertSame(19370, $mapped['intent'][PaymentIntent::schema_fields_AMOUNT_MINOR]);
        self::assertSame('6H324144C1729631Y', $mapped['attempt'][PaymentAttempt::schema_fields_PROVIDER_REFERENCE]);
        self::assertSame(PaymentAttempt::STATUS_SUCCEEDED, $mapped['attempt'][PaymentAttempt::schema_fields_STATUS]);
    }

    public function testPendingTransactionDoesNotMap(): void
    {
        self::assertNull(PaymentCaptureReaderEnsureService::mapFromTransactionArray([
            'transaction_no' => 'PAY-PENDING',
            'order_id' => 'ord-1',
            'status' => PaymentTransaction::STATUS_PENDING,
            'amount' => '10.00',
            'currency' => 'CNY',
        ]));
    }

    public function testPayableTypeAliasesIncludeWelineOrder(): void
    {
        self::assertSame(
            ['order', 'weline_order'],
            PaymentCaptureReaderEnsureService::payableTypeAliases('order'),
        );
    }
}
