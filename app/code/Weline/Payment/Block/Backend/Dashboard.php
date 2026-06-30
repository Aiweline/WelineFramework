<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Block\Backend;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentTransaction;

class Dashboard extends Block
{
    private const MAX_ROWS = 1000;

    /**
     * @return array<string, mixed>
     */
    public function getDashboardData(): array
    {
        $transactions = $this->loadRows(PaymentTransaction::class, PaymentTransaction::schema_fields_CREATED_AT);
        $intents = $this->loadRows(PaymentIntent::class, PaymentIntent::schema_fields_CREATED_AT);
        $attempts = $this->loadRows(PaymentAttempt::class, PaymentAttempt::schema_fields_CREATED_AT);

        $transactionStats = $this->buildTransactionStats($transactions['rows']);
        $intentStats = $this->buildPaymentObjectStats(
            $intents['rows'],
            PaymentIntent::schema_fields_METHOD_CODE,
            PaymentIntent::schema_fields_PROVIDER_CODE,
            PaymentIntent::schema_fields_SCOPE,
            PaymentIntent::schema_fields_CURRENCY_CODE,
            PaymentIntent::schema_fields_STATUS,
            PaymentIntent::schema_fields_AMOUNT_MINOR,
            PaymentIntent::schema_fields_PRECISION,
            [
                PaymentIntent::STATUS_PENDING,
                PaymentIntent::STATUS_REDIRECT_PENDING,
                PaymentIntent::STATUS_QR_PENDING,
                PaymentIntent::STATUS_AUTHENTICATION_REQUIRED,
                PaymentIntent::STATUS_REQUIRES_ACTION,
                PaymentIntent::STATUS_PROCESSING,
                PaymentIntent::STATUS_RETRYABLE_FAILED,
                PaymentIntent::STATUS_REVIEW_REQUIRED,
            ],
            [
                PaymentIntent::STATUS_FAILED,
                PaymentIntent::STATUS_RETRYABLE_FAILED,
                PaymentIntent::STATUS_EXPIRED,
                PaymentIntent::STATUS_CANCELLED,
            ],
            [
                PaymentIntent::STATUS_REFUNDING,
                PaymentIntent::STATUS_PARTIALLY_REFUNDED,
                PaymentIntent::STATUS_REFUNDED,
            ]
        );
        $attemptStats = $this->buildPaymentObjectStats(
            $attempts['rows'],
            PaymentAttempt::schema_fields_METHOD_CODE,
            PaymentAttempt::schema_fields_PROVIDER_CODE,
            PaymentAttempt::schema_fields_SCOPE,
            PaymentAttempt::schema_fields_PAYMENT_CURRENCY_CODE,
            PaymentAttempt::schema_fields_STATUS,
            PaymentAttempt::schema_fields_AMOUNT_MINOR,
            PaymentAttempt::schema_fields_PRECISION,
            [
                PaymentAttempt::STATUS_CREATED,
                PaymentAttempt::STATUS_PROVIDER_PENDING,
                PaymentAttempt::STATUS_REQUIRES_ACTION,
                PaymentAttempt::STATUS_PROCESSING,
            ],
            [
                PaymentAttempt::STATUS_FAILED,
                PaymentAttempt::STATUS_CANCELLED,
                PaymentAttempt::STATUS_ABANDONED,
                PaymentAttempt::STATUS_LATE_SUCCESS_REVIEW,
            ],
            []
        );

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'sample_limit' => self::MAX_ROWS,
            'sources' => [
                'transactions' => $transactions + ['label' => 'weline_payment_transaction'],
                'intents' => $intents + ['label' => 'weline_payment_intent'],
                'attempts' => $attempts + ['label' => 'weline_payment_attempt'],
            ],
            'transactions' => $transactionStats,
            'intents' => $intentStats,
            'attempts' => $attemptStats,
            'summary' => $this->buildSummary($transactionStats, $intentStats, $attemptStats),
            'gaps' => $this->buildGaps($transactions, $intents, $attempts),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, error: string, limited: bool, count: int}
     */
    private function loadRows(string $modelClass, string $orderField): array
    {
        try {
            $model = ObjectManager::getInstance($modelClass);
            $rows = $model->reset()
                ->order($orderField, 'DESC')
                ->limit(self::MAX_ROWS)
                ->select()
                ->fetchArray();

            if (!is_array($rows)) {
                $rows = [];
            }

            return [
                'rows' => $rows,
                'error' => '',
                'limited' => count($rows) >= self::MAX_ROWS,
                'count' => count($rows),
            ];
        } catch (Throwable $throwable) {
            return [
                'rows' => [],
                'error' => $throwable->getMessage(),
                'limited' => false,
                'count' => 0,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildTransactionStats(array $rows): array
    {
        $stats = $this->emptyStats();

        foreach ($rows as $row) {
            $method = $this->stringValue($row[PaymentTransaction::schema_fields_METHOD_CODE] ?? '');
            $currency = $this->stringValue($row[PaymentTransaction::schema_fields_CURRENCY] ?? '');
            $status = $this->stringValue($row[PaymentTransaction::schema_fields_STATUS] ?? '');
            $amount = (float)($row[PaymentTransaction::schema_fields_AMOUNT] ?? 0);

            $stats['total']++;
            $this->increment($stats['by_method'], $method);
            $this->increment($stats['by_currency'], $currency);
            $this->increment($stats['by_status'], $status);
            $this->addAmount($stats['amount_by_currency'], $currency, $amount);

            if ($status === PaymentTransaction::STATUS_SUCCESS) {
                $stats['success_count']++;
                $this->addAmount($stats['success_amount_by_currency'], $currency, $amount);
            }
            if ($status === PaymentTransaction::STATUS_FAILED) {
                $stats['failed_count']++;
            }
            if (in_array($status, [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_PROCESSING], true)) {
                $stats['incomplete_count']++;
            }
            if ($status === PaymentTransaction::STATUS_REFUNDED) {
                $stats['refund_count']++;
                $this->addAmount($stats['refund_amount_by_currency'], $currency, $amount);
            }
        }

        $stats['failure_rate'] = $this->rate($stats['failed_count'], $stats['total']);
        $stats['refund_rate'] = $this->rate($stats['refund_count'], $stats['total']);
        $stats['by_method'] = $this->sortDimension($stats['by_method']);
        $stats['by_currency'] = $this->sortDimension($stats['by_currency']);
        $stats['by_status'] = $this->sortDimension($stats['by_status']);

        return $stats;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string> $incompleteStatuses
     * @param list<string> $failedStatuses
     * @param list<string> $refundStatuses
     * @return array<string, mixed>
     */
    private function buildPaymentObjectStats(
        array $rows,
        string $methodField,
        string $providerField,
        string $scopeField,
        string $currencyField,
        string $statusField,
        string $amountField,
        string $precisionField,
        array $incompleteStatuses,
        array $failedStatuses,
        array $refundStatuses
    ): array {
        $stats = $this->emptyStats();
        $stats['by_provider'] = [];
        $stats['by_scope'] = [];

        foreach ($rows as $row) {
            $method = $this->stringValue($row[$methodField] ?? '');
            $provider = $this->stringValue($row[$providerField] ?? '');
            $scope = $this->stringValue($row[$scopeField] ?? '');
            $currency = $this->stringValue($row[$currencyField] ?? '');
            $status = $this->stringValue($row[$statusField] ?? '');
            $amount = $this->minorAmount($row[$amountField] ?? 0, $row[$precisionField] ?? 2);

            $stats['total']++;
            $this->increment($stats['by_method'], $method);
            $this->increment($stats['by_provider'], $provider);
            $this->increment($stats['by_scope'], $scope);
            $this->increment($stats['by_currency'], $currency);
            $this->increment($stats['by_status'], $status);
            $this->addAmount($stats['amount_by_currency'], $currency, $amount);

            if (in_array($status, $incompleteStatuses, true)) {
                $stats['incomplete_count']++;
            }
            if (in_array($status, $failedStatuses, true)) {
                $stats['failed_count']++;
            }
            if (in_array($status, $refundStatuses, true)) {
                $stats['refund_count']++;
                $this->addAmount($stats['refund_amount_by_currency'], $currency, $amount);
            }
        }

        $stats['failure_rate'] = $this->rate($stats['failed_count'], $stats['total']);
        $stats['refund_rate'] = $this->rate($stats['refund_count'], $stats['total']);
        $stats['by_method'] = $this->sortDimension($stats['by_method']);
        $stats['by_provider'] = $this->sortDimension($stats['by_provider']);
        $stats['by_scope'] = $this->sortDimension($stats['by_scope']);
        $stats['by_currency'] = $this->sortDimension($stats['by_currency']);
        $stats['by_status'] = $this->sortDimension($stats['by_status']);

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(): array
    {
        return [
            'total' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'incomplete_count' => 0,
            'refund_count' => 0,
            'failure_rate' => 0.0,
            'refund_rate' => 0.0,
            'by_method' => [],
            'by_currency' => [],
            'by_status' => [],
            'amount_by_currency' => [],
            'success_amount_by_currency' => [],
            'refund_amount_by_currency' => [],
        ];
    }

    /**
     * @param array<string, mixed> $transactionStats
     * @param array<string, mixed> $intentStats
     * @param array<string, mixed> $attemptStats
     * @return array<string, mixed>
     */
    private function buildSummary(array $transactionStats, array $intentStats, array $attemptStats): array
    {
        $failureBase = $attemptStats['total'] > 0 ? $attemptStats : $transactionStats;
        $incompleteBase = $intentStats['total'] > 0 ? $intentStats : $transactionStats;

        return [
            'failure_rate' => (float)$failureBase['failure_rate'],
            'failure_count' => (int)$failureBase['failed_count'],
            'incomplete_count' => (int)$incompleteBase['incomplete_count'],
            'refund_count' => (int)$transactionStats['refund_count'] + (int)$intentStats['refund_count'],
            'transaction_total' => (int)$transactionStats['total'],
            'intent_total' => (int)$intentStats['total'],
            'attempt_total' => (int)$attemptStats['total'],
        ];
    }

    /**
     * @param array<string, mixed> $transactions
     * @param array<string, mixed> $intents
     * @param array<string, mixed> $attempts
     * @return array<int, array<string, string>>
     */
    private function buildGaps(array $transactions, array $intents, array $attempts): array
    {
        $gaps = [];

        foreach ([
            'weline_payment_transaction' => $transactions,
            'weline_payment_intent' => $intents,
            'weline_payment_attempt' => $attempts,
        ] as $label => $source) {
            if (!empty($source['error'])) {
                $gaps[] = [
                    'title' => $label,
                    'detail' => (string)$source['error'],
                ];
            }
        }

        $gaps[] = [
            'title' => 'payment_refund',
            'detail' => '独立退款模型尚未进入当前最小统计口径，页面暂以 transaction/refund status 和 intent refund status 标记退款入口。',
        ];
        $gaps[] = [
            'title' => 'payment_ledger / reconciliation_difference',
            'detail' => '缺少账本 checksum 与对账差异模型，无法证明退款金额、净收款和对账异常的财务一致性。',
        ];
        $gaps[] = [
            'title' => 'backend menu',
            'detail' => '本轮写入范围未包含 etc/backend/menu.xml，Dashboard 可通过路由直达，侧边栏菜单仍需后续接入。',
        ];

        return $gaps;
    }

    /**
     * @param array<string, int> $bucket
     */
    private function increment(array &$bucket, string $key): void
    {
        $key = $key !== '' ? $key : 'unknown';
        $bucket[$key] = ($bucket[$key] ?? 0) + 1;
    }

    /**
     * @param array<string, float> $bucket
     */
    private function addAmount(array &$bucket, string $currency, float $amount): void
    {
        $currency = $currency !== '' ? $currency : 'unknown';
        $bucket[$currency] = ($bucket[$currency] ?? 0.0) + $amount;
    }

    /**
     * @param array<string, int> $bucket
     * @return array<int, array{key: string, count: int}>
     */
    private function sortDimension(array $bucket): array
    {
        arsort($bucket);
        $rows = [];

        foreach ($bucket as $key => $count) {
            $rows[] = [
                'key' => (string)$key,
                'count' => (int)$count,
            ];
        }

        return $rows;
    }

    private function rate(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 2);
    }

    private function minorAmount(mixed $amount, mixed $precision): float
    {
        $precision = max(0, (int)$precision);

        return ((float)$amount) / (10 ** $precision);
    }

    private function stringValue(mixed $value): string
    {
        return trim((string)$value);
    }
}
