<?php

declare(strict_types=1);

namespace Weline\HelpPay\Service;

/**
 * Storefront projection for help_pay orders: strip shipping PII for everyone including owner.
 */
final class ShippingRedactionService
{
    private const SHIPPING_KEYS = [
        'shipping_address',
        'shipping_snapshot',
        'shipping_snapshot_json',
        'shipping',
        'delivery_address',
        'recipient',
        'recipient_name',
        'recipient_phone',
        'recipient_email',
        'ship_to',
        'address',
        'line1',
        'line2',
        'city',
        'region',
        'postcode',
        'postal',
        'phone',
        'firstname',
        'lastname',
        'street',
    ];

    /**
     * @param array<string,mixed> $projection
     * @return array<string,mixed>
     */
    public function redactStorefront(array $projection, bool $isHelpPay = true): array
    {
        if (!$isHelpPay) {
            return $projection;
        }

        foreach (self::SHIPPING_KEYS as $key) {
            unset($projection[$key]);
        }
        if (isset($projection['addresses']) && is_array($projection['addresses'])) {
            unset($projection['addresses']['shipping'], $projection['addresses']['delivery']);
        }
        $projection['shipping_redacted'] = true;
        $projection['shipping_visible'] = false;
        $projection['help_pay'] = true;

        return $projection;
    }

    /**
     * @param array<string,mixed> $address
     */
    public function assertCompleteShipping(array $address): void
    {
        $name = trim((string) ($address['name'] ?? $address['firstname'] ?? ''));
        $line1 = trim((string) ($address['line1'] ?? $address['street'] ?? ''));
        $phone = trim((string) ($address['phone'] ?? ''));
        $country = trim((string) ($address['country'] ?? $address['country_code'] ?? ''));
        if ($name === '' || $line1 === '' || $phone === '' || $country === '') {
            throw new \InvalidArgumentException('helppay_shipping_incomplete');
        }
    }
}
