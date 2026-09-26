<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\BackendOrderPaymentRecordsService;

final class BackendOrderPaymentRecordsServiceTest extends TestCase
{
    public function testMergeKeepsFailedTransactionAlongsideSuccessfulAttempt(): void
    {
        $attempts = [
            [
                'payment_method' => 'paypal',
                'amount' => 100.0,
                'currency' => 'CNY',
                'transaction_id' => 'txn-success-2',
                'status' => 'paid',
                'paid_at' => '2026-03-21 12:00:00',
                'source' => 'weline_payment_attempt',
            ],
        ];
        $transactions = [
            [
                'payment_method' => 'paypal',
                'amount' => 100.0,
                'currency' => 'CNY',
                'transaction_id' => 'txn-failed-1',
                'status' => 'failed',
                'paid_at' => '2026-03-21 11:00:00',
                'source' => 'weline_payment_transaction',
            ],
            [
                'payment_method' => 'paypal',
                'amount' => 100.0,
                'currency' => 'CNY',
                'transaction_id' => 'txn-success-2',
                'status' => 'paid',
                'paid_at' => '2026-03-21 12:00:00',
                'source' => 'weline_payment_transaction',
            ],
        ];

        $merged = BackendOrderPaymentRecordsService::mergeAttemptAndTransactionRows(
            $attempts,
            $transactions
        );

        self::assertCount(2, $merged);
        $statuses = array_column($merged, 'status');
        self::assertContains('failed', $statuses);
        self::assertContains('paid', $statuses);

        $byTxn = [];
        foreach ($merged as $row) {
            $byTxn[(string)$row['transaction_id']] = $row;
        }
        self::assertSame('weline_payment_attempt', $byTxn['txn-success-2']['source']);
        self::assertSame('weline_payment_transaction', $byTxn['txn-failed-1']['source']);

        // paid_at DESC, then transaction_id DESC
        self::assertSame('txn-success-2', $merged[0]['transaction_id']);
        self::assertSame('txn-failed-1', $merged[1]['transaction_id']);
    }

    /**
     * 回归：Attempt 与 Transaction 使用「不同 id 命名空间」时也必须折叠成一行。
     *
     * 上面两个用例都用相同 id 空间（transaction_id 直接相等）构造，属假护栏：
     * 真实链路里 Attempt.transaction_id = provider_reference（支付商 capture id），
     * Transaction.transaction_id = transaction_no（内部 PAY2026…），两组永不相等，
     * 于是同一笔成功支付被展示成两行 + 一行失败（订单 200/201/202/203 实测如此）。
     */
    public function testMergeCollapsesSamePaymentAcrossDifferentIdSpaces(): void
    {
        $attempts = [
            [
                'payment_method' => 'paypal',
                'amount' => 608.48,
                'currency' => 'USD',
                'transaction_id' => '3PK91601X3311762N',
                'provider_reference' => '3PK91601X3311762N',
                'status' => 'paid',
                'paid_at' => '2026-09-26 04:51:42',
                'source' => 'weline_payment_attempt',
            ],
        ];
        $transactions = [
            [
                // 被取消的那次尝试：capture id 不同 ⇒ 属独立事件，必须保留
                'payment_method' => 'paypal',
                'amount' => 608.48,
                'currency' => 'USD',
                'transaction_id' => 'PAY20260926045047502016',
                'provider_reference' => '7VC57649DB993892L',
                'status' => 'failed',
                'paid_at' => '',
                'source' => 'weline_payment_transaction',
            ],
            [
                // 同一笔成功支付：内部号与 capture id 不同名空间，但 provider_reference 命中 ⇒ 必须折叠
                'payment_method' => 'paypal',
                'amount' => 608.48,
                'currency' => 'USD',
                'transaction_id' => 'PAY20260926045132889543',
                'provider_reference' => '3PK91601X3311762N',
                'status' => 'paid',
                'paid_at' => '2026-09-26 04:51:42',
                'source' => 'weline_payment_transaction',
            ],
        ];

        $merged = BackendOrderPaymentRecordsService::mergeAttemptAndTransactionRows(
            $attempts,
            $transactions
        );

        self::assertCount(2, $merged);
        self::assertSame(
            ['weline_payment_attempt', 'weline_payment_transaction'],
            array_column($merged, 'source')
        );
        // 保留 Attempt 行（携带支付商 capture id），而不是内部 PAY 号
        self::assertSame('3PK91601X3311762N', $merged[0]['transaction_id']);
        self::assertSame('failed', $merged[1]['status']);
    }

    /**
     * 两套 id 空间必须真的被采集：Transaction 侧 provider_reference 取自 response_data。
     */
    public function testTransactionRowsExposeProviderReferenceFromResponseData(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BackendOrderPaymentRecordsService.php'
        );
        self::assertStringContainsString('schema_fields_RESPONSE_DATA', $src);
        self::assertStringContainsString("'provider_reference' => trim((string)(\$responseData['provider_reference']", $src);
        self::assertStringContainsString("'provider_reference' => \$providerReference", $src);
        self::assertStringContainsString('paymentIdentityKeys', $src);
        self::assertStringContainsString('hasSeenIdentityKey', $src);
    }

    public function testMergePrefersAttemptWhenTransactionIdMatches(): void
    {
        $attempts = [
            [
                'transaction_id' => 'txn-dup',
                'status' => 'paid',
                'paid_at' => '2026-03-21 12:00:00',
                'source' => 'weline_payment_attempt',
            ],
        ];
        $transactions = [
            [
                'transaction_id' => 'txn-dup',
                'status' => 'paid',
                'paid_at' => '2026-03-21 12:00:00',
                'source' => 'weline_payment_transaction',
            ],
        ];

        $merged = BackendOrderPaymentRecordsService::mergeAttemptAndTransactionRows(
            $attempts,
            $transactions
        );

        self::assertCount(1, $merged);
        self::assertSame('weline_payment_attempt', $merged[0]['source']);
    }

    public function testListForPayableSourceAlwaysMergesAttemptsAndTransactions(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BackendOrderPaymentRecordsService.php'
        );
        self::assertStringContainsString('mergeAttemptAndTransactionRows', $src);
        self::assertStringContainsString('transactionsForPayable', $src);
        self::assertStringNotContainsString(
            "if (\$rows !== []) {\n            return \$rows;\n        }",
            $src
        );
    }
}
