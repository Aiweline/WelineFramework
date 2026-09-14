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
     *     created_at: string,
     *     checkout_group_display: string,
     *     shipping_address_lines: list<string>,
     *     billing_address_lines: list<string>,
     *     notes: string,
     *     payment_method: string,
     *     shipping_method: string
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
            'checkout_group_display' => OrderListKeywordNormalizer::groupDisplayNumber(
                (string)($data[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? '')
            ),
            'shipping_address_lines' => $this->formatAddressLines($shipping),
            'billing_address_lines' => $this->formatAddressLines(
                $this->decodeMap($data[Order::schema_fields_BILLING_ADDRESS] ?? null)
            ),
            'notes' => trim((string)($data[Order::schema_fields_NOTES] ?? '')),
            'payment_method' => trim((string)($data[Order::schema_fields_PAYMENT_METHOD] ?? '')),
            'shipping_method' => trim((string)($data[Order::schema_fields_SHIPPING_METHOD] ?? '')),
        ];
    }

    /**
     * Flatten checkout shipping/billing JSON into display lines (Magento/Shopify-style cards).
     *
     * @param array<string, mixed> $address
     * @return list<string>
     */
    public function formatAddressLines(array $address): array
    {
        if ($address === []) {
            return [];
        }

        $lines = [];
        $name = $this->firstNonEmpty(
            (string)($address['name'] ?? ''),
            (string)($address['fullname_name'] ?? ''),
            trim($this->firstNonEmpty(
                (string)($address['firstname'] ?? $address['first_name'] ?? ''),
                ''
            ) . ' ' . $this->firstNonEmpty(
                (string)($address['lastname'] ?? $address['last_name'] ?? ''),
                ''
            ))
        );
        if ($name !== '') {
            $lines[] = $name;
        }

        $company = trim((string)($address['company'] ?? ''));
        if ($company !== '') {
            $lines[] = $company;
        }

        $street = '';
        if (isset($address['street']) && \is_array($address['street'])) {
            $street = trim(implode(' ', array_map('strval', $address['street'])));
        }
        if ($street === '') {
            $street = $this->firstNonEmpty(
                (string)($address['street'] ?? ''),
                (string)($address['street1'] ?? ''),
                (string)($address['address'] ?? ''),
                (string)($address['address1'] ?? ''),
                (string)($address['detail'] ?? ''),
                (string)($address['detailed_address'] ?? '')
            );
        }
        if ($street !== '') {
            $lines[] = $street;
        }

        $regionLine = trim(implode(' ', array_filter([
            $this->firstNonEmpty(
                (string)($address['region'] ?? ''),
                (string)($address['province'] ?? ''),
                (string)($address['state'] ?? '')
            ),
            $this->firstNonEmpty(
                (string)($address['city'] ?? ''),
                (string)($address['city_name'] ?? '')
            ),
            $this->firstNonEmpty(
                (string)($address['district'] ?? ''),
                (string)($address['area'] ?? ''),
                (string)($address['county'] ?? '')
            ),
        ], static fn(string $part): bool => $part !== '')));
        if ($regionLine !== '') {
            $lines[] = $regionLine;
        }

        $postcode = trim((string)($address['postcode'] ?? $address['zip'] ?? $address['postal_code'] ?? ''));
        if ($postcode !== '') {
            $lines[] = $postcode;
        }

        $country = trim((string)($address['country'] ?? $address['country_id'] ?? $address['country_code'] ?? ''));
        if ($country !== '') {
            $lines[] = $country;
        }

        $phone = $this->firstNonEmpty(
            (string)($address['telephone'] ?? ''),
            (string)($address['phone'] ?? ''),
            (string)($address['mobile'] ?? '')
        );
        if ($phone !== '') {
            $lines[] = $phone;
        }

        return $lines;
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
