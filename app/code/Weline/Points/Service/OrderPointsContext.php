<?php

declare(strict_types=1);

namespace Weline\Points\Service;

/**
 * Reads only order event payloads. No ORM access here so the money rules stay
 * unit-testable; asset tender lookup is injected by PointsService.
 */
final class OrderPointsContext
{
    public const NS_LIVE = 'live';
    public const NS_SANDBOX = 'sandbox';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function fromPaidEvent(array $data, int $assetTenderMinor = 0): array
    {
        $order = $data['order'] ?? null;
        $orderId = (int) ($data['order_id'] ?? $this->get($order, 'order_id', 0));
        $customerId = (int) ($data['customer_id'] ?? $this->get($order, 'customer_id', 0));
        $websiteId = max(0, (int) ($data['website_id'] ?? $this->get($order, 'website_id', 0)));
        $currency = strtoupper((string) ($data['currency_code'] ?? $this->get($order, 'currency', 'CNY')));
        $checkoutEntry = strtolower((string) ($data['checkout_entry'] ?? $this->get($order, 'checkout_entry', 'checkout')));
        $namespace = $this->namespace($data, $order);
        [$totalMinor, $precision] = $this->totalMinor($order, $data);
        $assetTenderMinor = max(0, $assetTenderMinor);
        $cashMinor = max(0, $totalMinor - min($assetTenderMinor, $totalMinor));

        return [
            'order_id' => $orderId,
            'order_uuid' => trim((string) ($data['order_uuid'] ?? $this->get($order, 'order_uuid', ''))),
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'namespace' => $namespace,
            'checkout_entry' => $checkoutEntry === '' ? 'checkout' : $checkoutEntry,
            'currency' => $currency === '' ? 'CNY' : $currency,
            'precision' => $precision,
            'total_minor' => $totalMinor,
            'asset_tender_minor' => $assetTenderMinor,
            'cash_minor' => $cashMinor,
            'payment_key' => $this->paymentKey($data, $order, $orderId),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $paidContext
     * @return array<string, mixed>
     */
    public function fromRefundEvent(array $data, array $paidContext): array
    {
        $refund = $data['refund'] ?? null;
        $order = $data['order'] ?? null;
        $precision = (int) ($paidContext['precision'] ?? 2);
        $scale = 10 ** max(0, min(6, $precision));
        $amount = $data['refund_amount']
            ?? $this->get($refund, 'amount', 0);
        $refundMinor = (int) round(max(0.0, (float) $amount) * $scale);
        $refundKey = $this->refundKey($data, $refund, $this->get($order, 'order_id', 0));

        return $paidContext + [
            'refund_key' => $refundKey,
            'refund_amount_minor' => $refundMinor,
        ];
    }

    private function namespace(mixed $data, mixed $order): string
    {
        $explicit = strtolower(trim((string) (($data['namespace'] ?? null) ?? $this->get($order, 'namespace', ''))));
        if ($explicit === self::NS_SANDBOX) {
            return self::NS_SANDBOX;
        }
        if ((int) $this->get($order, 'is_sandbox', 0) === 1) {
            return self::NS_SANDBOX;
        }

        return self::NS_LIVE;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function totalMinor(mixed $order, array $data): array
    {
        $snapshot = [];
        $rawSnapshot = $this->get($order, 'money_snapshot_json', null);
        if (\is_string($rawSnapshot) && trim($rawSnapshot) !== '') {
            $decoded = json_decode($rawSnapshot, true);
            if (\is_array($decoded)) {
                $snapshot = $decoded;
            }
        } elseif (\is_array($rawSnapshot)) {
            $snapshot = $rawSnapshot;
        } elseif (\is_array($data['money_snapshot'] ?? null)) {
            $snapshot = $data['money_snapshot'];
        }

        $currency = strtoupper((string) ($this->get($order, 'currency', $data['currency_code'] ?? 'CNY')));
        $precision = (int) ($snapshot['precision'] ?? $data['precision'] ?? self::precisionForCurrency($currency));
        $precision = max(0, min(6, $precision));
        $totalMinor = (int) ($snapshot['grand_total_minor'] ?? 0);
        if ($totalMinor <= 0) {
            $grandTotal = (float) ($this->get($order, 'grand_total', 0));
            $totalMinor = (int) round(max(0.0, $grandTotal) * (10 ** $precision));
        }

        return [max(0, $totalMinor), $precision];
    }

    private function paymentKey(array $data, mixed $order, int $orderId): string
    {
        $payment = $data['payment'] ?? null;
        $candidates = [
            $data['payment_id'] ?? null,
            $this->get($payment, 'payment_id', null),
            $this->get($payment, 'getId', null),
            $data['intent_code'] ?? null,
            $this->get($order, 'order_uuid', null),
            $orderId > 0 ? (string) $orderId : null,
        ];
        foreach ($candidates as $candidate) {
            if (\is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return 'order';
    }

    private function refundKey(array $data, mixed $refund, mixed $orderId): string
    {
        $candidates = [
            $data['refund_id'] ?? null,
            $this->get($refund, 'payment_id', null),
            $this->get($refund, 'getId', null),
            $data['transaction_id'] ?? null,
            $this->get($refund, 'transaction_id', null),
        ];
        foreach ($candidates as $candidate) {
            if (\is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return 'refund-' . (string) $orderId;
    }

    public static function precisionForCurrency(string $currency): int
    {
        $currency = strtoupper(trim($currency));
        if (\in_array($currency, [
            'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG',
            'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ], true)) {
            return 0;
        }
        if (\in_array($currency, [
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
        ], true)) {
            return 3;
        }

        return 2;
    }

    private function get(mixed $source, string $key, mixed $default): mixed
    {
        if (\is_array($source)) {
            return $source[$key] ?? $default;
        }
        if (\is_object($source) && method_exists($source, 'getData')) {
            $value = $source->getData($key);

            return $value ?? $default;
        }
        if (\is_object($source) && method_exists($source, $key)) {
            return $source->{$key}();
        }

        return $default;
    }
}
