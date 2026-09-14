<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderShippingMethodCatalogInterface;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

/**
 * Read-only money / line accumulation for Magento-like order manage cards.
 */
final class BackendOrderTotalsPresenter
{
    public function __construct(
        private readonly ?OrderShippingMethodCatalogInterface $shippingCatalog = null,
    ) {
    }

    /**
     * @param Order|array<string, mixed> $order
     * @param list<array<string, mixed>> $displayLines
     * @return array{
     *     currency: string,
     *     line_count: int,
     *     lines_qty: float,
     *     lines_subtotal: float,
     *     subtotal: float,
     *     shipping_amount: float,
     *     shipping_method: string,
     *     shipping_method_label: string,
     *     tax_amount: float,
     *     discount_amount: float,
     *     discount_items: list<array{label: string, amount: float, source: string}>,
     *     grand_total: float,
     *     money_snapshot: array<string, mixed>,
     *     type_payload: array<string, mixed>,
     *     lines_match_subtotal: bool,
     *     rows: list<array{label: string, amount: float, tone: string}>
     * }
     */
    public function present(Order|array $order, array $displayLines = []): array
    {
        $data = $order instanceof Order ? $order->getData() : $order;
        if (!\is_array($data)) {
            $data = [];
        }

        $currency = trim((string)($data[Order::schema_fields_CURRENCY] ?? 'CNY'));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $money = $this->decodeMap($data[Order::schema_fields_MONEY_SNAPSHOT_JSON] ?? null);
        $typePayload = $this->decodeMap($data[Order::schema_fields_TYPE_PAYLOAD_JSON] ?? null);

        $linesQty = 0.0;
        $linesSubtotal = 0.0;
        foreach ($displayLines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $linesQty += (float)($line[OrderItem::schema_fields_QTY_ORDERED] ?? $line['qty'] ?? 0);
            $linesSubtotal += (float)($line[OrderItem::schema_fields_ROW_TOTAL] ?? $line['row_total'] ?? 0);
        }

        $subtotal = $this->moneyMajor(
            $data[Order::schema_fields_SUBTOTAL] ?? null,
            $money['subtotal_minor'] ?? null,
            $linesSubtotal,
        );
        $shipping = $this->moneyMajor(
            $data[Order::schema_fields_SHIPPING_AMOUNT] ?? null,
            $money['shipping_amount_minor'] ?? null,
            0.0,
        );
        $tax = $this->moneyMajor(
            $data[Order::schema_fields_TAX_AMOUNT] ?? null,
            $money['tax_amount_minor'] ?? null,
            0.0,
        );
        $discount = $this->moneyMajor(
            $data[Order::schema_fields_DISCOUNT_AMOUNT] ?? null,
            $money['discount_amount_minor'] ?? null,
            0.0,
        );
        $grand = $this->moneyMajor(
            $data[Order::schema_fields_GRAND_TOTAL] ?? null,
            $money['grand_total_minor'] ?? null,
            $subtotal + $shipping + $tax - $discount + ((int)($money['cod_fee_amount_minor'] ?? 0)) / 100,
        );

        $shippingMethod = trim((string)($data[Order::schema_fields_SHIPPING_METHOD] ?? ''));
        $websiteId = (int)($data[Order::schema_fields_WEBSITE_ID] ?? $data['website_id'] ?? 0);
        $storeId = (int)($data[Order::schema_fields_STORE_ID] ?? $data['store_id'] ?? 0);
        $shippingLabel = $this->resolveShippingLabel($shippingMethod, $websiteId, $storeId);

        $discountItems = $this->buildDiscountItems($discount, $typePayload, $displayLines);

        $rows = [
            ['label' => '商品行累计', 'amount' => $linesSubtotal, 'tone' => 'muted'],
            ['label' => '商品小计', 'amount' => $subtotal, 'tone' => 'default'],
            ['label' => '运费', 'amount' => $shipping, 'tone' => 'default'],
            ['label' => '税费', 'amount' => $tax, 'tone' => 'default'],
            ['label' => '折扣', 'amount' => $discount, 'tone' => 'default'],
        ];
        $codFee = $this->moneyMajor(
            null,
            $money['cod_fee_amount_minor'] ?? null,
            0.0,
        );
        if ($codFee > 0.0001) {
            $rows[] = ['label' => '货到付款手续费', 'amount' => $codFee, 'tone' => 'default'];
        }
        $rows[] = ['label' => '订单总额', 'amount' => $grand, 'tone' => 'strong'];

        $deposit = (int)($typePayload['deposit_amount_minor'] ?? 0);
        $balance = (int)($typePayload['balance_amount_minor'] ?? 0);
        if ($deposit > 0 || $balance > 0) {
            $rows[] = ['label' => '定金（快照）', 'amount' => $deposit / 100, 'tone' => 'muted'];
            $rows[] = ['label' => '尾款（快照）', 'amount' => $balance / 100, 'tone' => 'muted'];
        }

        $match = abs($linesSubtotal - $subtotal) < 0.015 || $displayLines === [];

        return [
            'currency' => $currency,
            'line_count' => count($displayLines),
            'lines_qty' => $linesQty,
            'lines_subtotal' => $linesSubtotal,
            'subtotal' => $subtotal,
            'shipping_amount' => $shipping,
            'shipping_method' => $shippingMethod,
            'shipping_method_label' => $shippingLabel,
            'tax_amount' => $tax,
            'discount_amount' => $discount,
            'discount_items' => $discountItems,
            'grand_total' => $grand,
            'money_snapshot' => $money,
            'type_payload' => $typePayload,
            'lines_match_subtotal' => $match,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $typePayload
     * @param list<array<string, mixed>> $displayLines
     * @return list<array{label: string, amount: float, source: string}>
     */
    private function buildDiscountItems(float $orderDiscount, array $typePayload, array $displayLines): array
    {
        $items = [];
        $lineDiscountSum = 0.0;

        foreach ($displayLines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $amount = $this->lineDiscountMajor($line);
            if ($amount <= 0.0001) {
                continue;
            }
            $lineDiscountSum += $amount;
            $label = trim((string)($line['campaign_label'] ?? ''));
            if ($label === '') {
                $name = trim((string)($line[OrderItem::schema_fields_PRODUCT_NAME] ?? $line['product_name'] ?? ''));
                $label = $name !== ''
                    ? ($this->phrase('行优惠') . ' · ' . $name)
                    : $this->phrase('行优惠');
            }
            $items[] = [
                'label' => $label,
                'amount' => $amount,
                'source' => 'line',
            ];
        }

        $remainder = max(0.0, $orderDiscount - $lineDiscountSum);
        if ($remainder > 0.0001) {
            $kind = strtolower(trim((string)($typePayload['discount_kind'] ?? '')));
            $orderLabel = match ($kind) {
                'asset_b2b_credit' => $this->phrase('批发信用优惠'),
                default => $this->phrase('订单折扣'),
            };
            $items[] = [
                'label' => $orderLabel,
                'amount' => $remainder,
                'source' => 'order',
            ];
        } elseif ($orderDiscount > 0.0001 && $items === []) {
            $kind = strtolower(trim((string)($typePayload['discount_kind'] ?? '')));
            $orderLabel = match ($kind) {
                'asset_b2b_credit' => $this->phrase('批发信用优惠'),
                default => $this->phrase('订单折扣'),
            };
            $items[] = [
                'label' => $orderLabel,
                'amount' => $orderDiscount,
                'source' => 'order',
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $line */
    private function lineDiscountMajor(array $line): float
    {
        $minor = (int)($line['line_discount_minor'] ?? 0);
        if ($minor > 0) {
            return $minor / 100;
        }

        return $this->moneyMajor(
            $line[OrderItem::schema_fields_DISCOUNT_AMOUNT] ?? $line['discount_amount'] ?? null,
            null,
            0.0,
        );
    }

    private function resolveShippingLabel(string $code, int $websiteId, int $storeId): string
    {
        if ($code === '') {
            return $this->phrase('未指定配送方式');
        }
        $catalog = $this->shippingCatalog();
        if ($catalog === null) {
            return $code;
        }
        $label = trim($catalog->resolveLabel($code, $websiteId, $storeId));

        return $label !== '' ? $label : $code;
    }

    private function moneyMajor(mixed $major, mixed $minor, float $fallback): float
    {
        if ($minor !== null && $minor !== '' && is_numeric($minor)) {
            return ((int)$minor) / 100;
        }
        if ($major !== null && $major !== '' && is_numeric($major)) {
            return (float)$major;
        }

        return $fallback;
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function phrase(string $text): string
    {
        try {
            return (string)__($text);
        } catch (\Throwable) {
            return $text;
        }
    }

    private function shippingCatalog(): ?OrderShippingMethodCatalogInterface
    {
        if ($this->shippingCatalog instanceof OrderShippingMethodCatalogInterface) {
            return $this->shippingCatalog;
        }
        try {
            $candidate = ObjectManager::getInstance(OrderShippingMethodCatalogInterface::class);

            return $candidate instanceof OrderShippingMethodCatalogInterface ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
