<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderPaymentMethodCatalogInterface;
use Weline\Order\Api\OrderShippingMethodCatalogInterface;
use Weline\Order\Model\Order;

/**
 * Admin customer-adjust chrome for payment/shipping method accordions.
 *
 * Empty payment_method must never render as a blank “broken” field: show
 * 「未指定」 plus payment_status (and hang hint when present).
 * Shipping summary prefers catalog service_name over raw service_code.
 */
final class BackendOrderPaymentChromePresenter
{
    public function __construct(
        private readonly ?OrderPaymentMethodCatalogInterface $catalog = null,
        private readonly ?OrderShippingMethodCatalogInterface $shippingCatalog = null,
    ) {
    }

    /**
     * @param Order|array<string, mixed> $order
     * @return array{
     *     payment_method: string,
     *     payment_method_label: string,
     *     payment_status: string,
     *     payment_status_label: string,
     *     payment_summary: string,
     *     payment_hint: string,
     *     payment_options: list<array{code: string, label: string}>,
     *     shipping_method: string,
     *     shipping_method_label: string,
     *     shipping_amount: float,
     *     shipping_options: list<array{code: string, label: string}>,
     *     shipping_summary: string
     * }
     */
    public function present(Order|array $order): array
    {
        $data = $order instanceof Order ? $order->getData() : $order;
        if (!\is_array($data)) {
            $data = [];
        }

        $websiteId = (int)($data[Order::schema_fields_WEBSITE_ID] ?? $data['website_id'] ?? 0);
        $storeId = (int)($data[Order::schema_fields_STORE_ID] ?? $data['store_id'] ?? 0);
        $method = trim((string)($data[Order::schema_fields_PAYMENT_METHOD] ?? ''));
        $status = strtolower(trim((string)($data[Order::schema_fields_PAYMENT_STATUS] ?? '')));
        $shipping = trim((string)($data[Order::schema_fields_SHIPPING_METHOD] ?? ''));
        $currency = trim((string)($data[Order::schema_fields_CURRENCY] ?? 'CNY'));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $catalog = $this->catalog();
        $options = $catalog !== null ? $catalog->listActiveOptions($websiteId, $storeId) : [];
        $methodLabel = $method !== ''
            ? ($catalog !== null ? $catalog->resolveLabel($method, $websiteId, $storeId) : $method)
            : $this->phrase('未指定');
        if ($method !== '' && $methodLabel === '') {
            $methodLabel = $method;
        }

        $statusLabel = $this->paymentStatusLabel($status);
        $summaryParts = [$methodLabel];
        if ($statusLabel !== '') {
            $summaryParts[] = $statusLabel;
        }
        $hint = $this->hangHint($data);

        $shippingCatalog = $this->shippingCatalog();
        $shippingOptions = $shippingCatalog !== null
            ? $shippingCatalog->listActiveOptions($websiteId, $storeId)
            : [];
        $shippingLabel = $shipping !== ''
            ? ($shippingCatalog !== null
                ? $shippingCatalog->resolveLabel($shipping, $websiteId, $storeId)
                : $shipping)
            : $this->phrase('未指定配送方式');
        if ($shipping !== '' && $shippingLabel === '') {
            $shippingLabel = $shipping;
        }
        $shippingAmount = $this->shippingAmountMajor($data);
        $shippingSummary = $shipping === ''
            ? $shippingLabel
            : ($shippingLabel . ' · ' . $this->phrase('运费') . ' ' . $currency . ' ' . number_format($shippingAmount, 2));

        return [
            'payment_method' => $method,
            'payment_method_label' => $methodLabel,
            'payment_status' => $status,
            'payment_status_label' => $statusLabel,
            'payment_summary' => implode(' · ', $summaryParts),
            'payment_hint' => $hint,
            'payment_options' => $options,
            'shipping_method' => $shipping,
            'shipping_method_label' => $shippingLabel,
            'shipping_amount' => $shippingAmount,
            'shipping_options' => $shippingOptions,
            'shipping_summary' => $shippingSummary,
        ];
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => $this->phrase('待支付'),
            'paid' => $this->phrase('已支付'),
            'partial' => $this->phrase('部分支付'),
            'refunded' => $this->phrase('已退款'),
            'failed' => $this->phrase('支付失败'),
            'cancelled', 'canceled' => $this->phrase('已取消'),
            default => $status !== '' ? $status : '',
        };
    }

    /** @param array<string, mixed> $data */
    private function hangHint(array $data): string
    {
        $payload = $data[Order::schema_fields_TYPE_PAYLOAD_JSON] ?? null;
        if (\is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($payload)) {
            return '';
        }
        $hang = strtolower(trim((string)($payload['hang_status'] ?? '')));
        if ($hang === '') {
            return '';
        }
        if (str_contains($hang, 'awaiting')) {
            return $this->phrase('批发挂账订单：结账时可能尚未选定最终支付方式，可在此指定或待客户支付链路回写。');
        }

        return '';
    }

    /** @param array<string, mixed> $data */
    private function shippingAmountMajor(array $data): float
    {
        $money = $data[Order::schema_fields_MONEY_SNAPSHOT_JSON] ?? null;
        if (\is_string($money) && $money !== '') {
            $decoded = json_decode($money, true);
            $money = \is_array($decoded) ? $decoded : [];
        }
        if (\is_array($money) && isset($money['shipping_amount_minor']) && is_numeric($money['shipping_amount_minor'])) {
            return ((int)$money['shipping_amount_minor']) / 100;
        }

        $snapshot = $data[Order::schema_fields_SHIPPING_SNAPSHOT_JSON] ?? null;
        if (\is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            $snapshot = \is_array($decoded) ? $decoded : [];
        }
        if (\is_array($snapshot) && isset($snapshot['amount_minor']) && is_numeric($snapshot['amount_minor'])) {
            return ((int)$snapshot['amount_minor']) / 100;
        }

        $major = $data[Order::schema_fields_SHIPPING_AMOUNT] ?? null;
        if ($major !== null && $major !== '' && is_numeric($major)) {
            return (float)$major;
        }

        return 0.0;
    }

    private function phrase(string $text): string
    {
        try {
            return (string)__($text);
        } catch (\Throwable) {
            return $text;
        }
    }

    private function catalog(): ?OrderPaymentMethodCatalogInterface
    {
        if ($this->catalog instanceof OrderPaymentMethodCatalogInterface) {
            return $this->catalog;
        }
        try {
            $candidate = ObjectManager::getInstance(OrderPaymentMethodCatalogInterface::class);

            return $candidate instanceof OrderPaymentMethodCatalogInterface ? $candidate : null;
        } catch (\Throwable) {
            return null;
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
