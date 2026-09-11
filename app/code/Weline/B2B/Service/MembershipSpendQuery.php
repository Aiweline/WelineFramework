<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

/**
 * Read-only tob paid spend ledger for membership auto-upgrade.
 * Amounts are accumulated in the website default (benchmark) currency;
 * other order currencies are FX-converted before summing.
 */
final class MembershipSpendQuery
{
    /** @var list<array{customer_id:string,website_id:int,order_type:string,payment_status:string,status:string,currency?:string,money_snapshot_json?:string,subtotal?:float|int|string,tax_amount?:float|int|string}>|null */
    private ?array $testingRows = null;

    public function __construct(
        private ?B2BBaseCurrencyResolver $currency = null,
    ) {
    }

    public static function forTesting(array $rows = [], ?B2BBaseCurrencyResolver $currency = null): self
    {
        $q = new self($currency);
        $q->testingRows = $rows;

        return $q;
    }

    /** Sum of paid tob goods (taxed) for customer+website in website-default-currency minor units. */
    public function sumPaidTobGoodsMinor(string $customerId, int $websiteId): int
    {
        $customerId = trim($customerId);
        if ($customerId === '' || $websiteId < 0) {
            return 0;
        }

        $total = 0;
        $resolver = $this->currency();
        foreach ($this->paidTobRows($customerId, $websiteId) as $row) {
            $sourceMinor = $this->goodsTaxedMinorFromRow($row);
            $sourceCurrency = $this->currencyCodeFromRow($row, $resolver->forWebsite($websiteId));
            $converted = $resolver->convertMinorToWebsiteDefault($sourceMinor, $sourceCurrency, $websiteId);
            if ($converted === null) {
                continue;
            }
            $total += $converted;
        }

        return max(0, $total);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function paidTobRows(string $customerId, int $websiteId): array
    {
        if ($this->testingRows !== null) {
            $out = [];
            foreach ($this->testingRows as $row) {
                if ((string)($row['customer_id'] ?? '') !== $customerId) {
                    continue;
                }
                if ((int)($row['website_id'] ?? -1) !== $websiteId) {
                    continue;
                }
                if (strtolower(trim((string)($row['order_type'] ?? ''))) !== 'tob') {
                    continue;
                }
                if (!$this->isPaidRow($row)) {
                    continue;
                }
                $out[] = $row;
            }

            return $out;
        }

        try {
            /** @var Order $order */
            $order = ObjectManager::getInstance(Order::class);
            $rows = $order->clear()
                ->where(Order::schema_fields_CUSTOMER_ID, $customerId)
                ->where(Order::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Order::schema_fields_ORDER_TYPE, 'tob')
                ->where(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PAID)
                ->select()
                ->fetchArray();
            $out = [];
            foreach ($rows as $row) {
                if (is_array($row) && $this->isPaidRow($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $row */
    private function isPaidRow(array $row): bool
    {
        $payment = strtolower(trim((string)($row[Order::schema_fields_PAYMENT_STATUS]
            ?? $row['payment_status']
            ?? '')));
        if ($payment !== Order::PAYMENT_STATUS_PAID) {
            return false;
        }
        $status = strtolower(trim((string)($row[Order::schema_fields_STATUS] ?? $row['status'] ?? '')));
        if ($status === Order::STATUS_CANCELLED || $status === Order::STATUS_REFUNDED) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $row */
    private function goodsTaxedMinorFromRow(array $row): int
    {
        $json = (string)($row[Order::schema_fields_MONEY_SNAPSHOT_JSON]
            ?? $row['money_snapshot_json']
            ?? '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ([
                    'goods_subtotal_taxed_minor',
                    'goods_subtotal_minor',
                    'subtotal_taxed_minor',
                    'subtotal_minor',
                ] as $key) {
                    if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                        return max(0, (int)$decoded[$key]);
                    }
                }
                if (isset($decoded['grand_total_minor']) && is_numeric($decoded['grand_total_minor'])) {
                    $shipping = (int)($decoded['shipping_amount_minor'] ?? 0);
                    return max(0, (int)$decoded['grand_total_minor'] - max(0, $shipping));
                }
            }
        }

        $subtotal = (float)($row[Order::schema_fields_SUBTOTAL] ?? $row['subtotal'] ?? 0);
        $tax = (float)($row[Order::schema_fields_TAX_AMOUNT] ?? $row['tax_amount'] ?? 0);

        return max(0, (int)round(($subtotal + $tax) * 100));
    }

    /** @param array<string,mixed> $row */
    private function currencyCodeFromRow(array $row, string $fallback): string
    {
        $json = (string)($row[Order::schema_fields_MONEY_SNAPSHOT_JSON]
            ?? $row['money_snapshot_json']
            ?? '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach (['currency_code', 'currency', 'base_currency'] as $key) {
                    $code = strtoupper(trim((string)($decoded[$key] ?? '')));
                    if ($code !== '') {
                        return $code;
                    }
                }
            }
        }
        $fromOrder = strtoupper(trim((string)($row[Order::schema_fields_CURRENCY]
            ?? $row['currency']
            ?? $row['currency_code']
            ?? '')));
        if ($fromOrder !== '') {
            return $fromOrder;
        }

        return strtoupper(trim($fallback)) ?: 'CNY';
    }

    private function currency(): B2BBaseCurrencyResolver
    {
        return $this->currency ??= new B2BBaseCurrencyResolver();
    }
}
