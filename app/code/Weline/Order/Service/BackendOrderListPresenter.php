<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Database\AbstractModel;
use Weline\Order\Model\Order;

/**
 * Backend order-list row projection.
 *
 * Live checkout-created rows often leave customer_* / created_at empty while
 * shipping_address and framework create_time are populated.
 */
final class BackendOrderListPresenter
{
    /**
     * @param Order|array<string, mixed> $order
     * @return array{
     *     customer_name: string,
     *     customer_email: string,
     *     customer_phone: string,
     *     is_anonymous: bool,
     *     created_at: string
     * }
     */
    public function present(Order|array $order): array
    {
        $data = $order instanceof Order ? $order->getData() : $order;
        if (!\is_array($data)) {
            $data = [];
        }

        $shipping = $this->decodeMap($data[Order::schema_fields_SHIPPING_ADDRESS] ?? null);
        $customerName = $this->firstNonEmpty(
            (string)($data[Order::schema_fields_CUSTOMER_NAME] ?? ''),
            $this->shippingDisplayName($shipping)
        );
        $customerEmail = $this->firstNonEmpty(
            (string)($data[Order::schema_fields_CUSTOMER_EMAIL] ?? ''),
            (string)($shipping['email'] ?? '')
        );
        $customerPhone = $this->firstNonEmpty(
            (string)($data[Order::schema_fields_CUSTOMER_PHONE] ?? ''),
            (string)($shipping['telephone'] ?? ''),
            (string)($shipping['phone'] ?? ''),
            (string)($shipping['mobile'] ?? '')
        );

        return [
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'is_anonymous' => $this->isAnonymous($data),
            'created_at' => $this->formatDateTime(
                $this->firstNonEmpty(
                    (string)($data[AbstractModel::schema_fields_CREATE_TIME] ?? ''),
                    (string)($data[Order::schema_fields_CREATED_AT] ?? '')
                )
            ),
        ];
    }

    /** @param array<string, mixed> $data */
    private function isAnonymous(array $data): bool
    {
        $customerId = $data[Order::schema_fields_CUSTOMER_ID] ?? null;
        if ($customerId === null || $customerId === '') {
            return true;
        }

        return (int)$customerId <= 0;
    }

    /** @param array<string, mixed> $shipping */
    private function shippingDisplayName(array $shipping): string
    {
        $full = $this->firstNonEmpty(
            (string)($shipping['name'] ?? ''),
            (string)($shipping['fullname_name'] ?? ''),
            (string)($shipping['fullname'] ?? ''),
            (string)($shipping['fullnameName'] ?? ''),
            (string)($shipping['company'] ?? '')
        );
        if ($full !== '') {
            return $full;
        }

        $first = trim((string)($shipping['firstname'] ?? $shipping['first_name'] ?? ''));
        $last = trim((string)($shipping['lastname'] ?? $shipping['last_name'] ?? ''));
        $combined = trim($first . ' ' . $last);

        return $combined;
    }

    private function formatDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }

        return $raw;
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

    private function firstNonEmpty(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }
}
