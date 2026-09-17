<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * Project notify-able contact fields from shipping address + checkout options.
 */
final class OrderCheckoutContactFields
{
    /**
     * @param array<string, mixed> $shippingAddress
     * @param array<string, mixed> $options
     * @return array{email:string,name:string,phone:string,shipping_address:array<string,mixed>}
     */
    public static function project(array $shippingAddress, array $options = []): array
    {
        $shipping = $shippingAddress;
        $email = self::firstValidEmail([
            (string)($options['guest_email'] ?? ''),
            (string)($options['customer_email'] ?? ''),
            (string)($shipping['email'] ?? ''),
            (string)((is_array($options['billing_address'] ?? null) ? $options['billing_address'] : [])['email'] ?? ''),
            (string)($options['payer_email'] ?? ''),
            (string)($options['payment_email'] ?? ''),
        ]);
        if ($email !== '' && trim((string)($shipping['email'] ?? '')) === '') {
            $shipping['email'] = $email;
        }

        $name = self::firstNonEmpty([
            (string)($options['customer_name'] ?? ''),
            (string)($shipping['name'] ?? ''),
            (string)($shipping['fullname_name'] ?? ''),
            (string)($shipping['contact_name'] ?? ''),
            trim(
                trim((string)($shipping['firstname'] ?? $shipping['first_name'] ?? ''))
                . ' '
                . trim((string)($shipping['lastname'] ?? $shipping['last_name'] ?? ''))
            ),
        ]);

        $phone = self::firstNonEmpty([
            (string)($options['customer_phone'] ?? ''),
            (string)($shipping['phone'] ?? ''),
            (string)($shipping['telephone'] ?? ''),
            (string)($shipping['mobile'] ?? ''),
            (string)($shipping['contact_phone'] ?? ''),
        ]);

        return [
            'email' => $email,
            'name' => $name,
            'phone' => $phone,
            'shipping_address' => $shipping,
        ];
    }

    /**
     * @param list<string> $candidates
     */
    public static function firstValidEmail(array $candidates): string
    {
        foreach ($candidates as $raw) {
            $email = strtolower(trim($raw));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }

    /**
     * @param list<string> $candidates
     */
    private static function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $raw) {
            $value = trim($raw);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
