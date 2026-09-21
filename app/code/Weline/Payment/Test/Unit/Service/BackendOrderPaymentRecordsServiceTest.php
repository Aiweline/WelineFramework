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
