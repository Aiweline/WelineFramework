<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * Maps PayPal Orders v2 payer/shipping into Checkout form-shaped address fields.
 */
final class PayPalExpressProfileMapper
{
    /**
     * @param array<string, mixed> $orderOrCapture PayPal order or capture response
     * @return array<string, mixed>|null
     */
    public function fromOrderPayload(array $orderOrCapture): ?array
    {
        $shipping = $this->firstShipping($orderOrCapture);
        $payer = is_array($orderOrCapture['payer'] ?? null) ? $orderOrCapture['payer'] : [];
        $name = $this->resolveName($shipping, $payer);
        $address = is_array($shipping['address'] ?? null) ? $shipping['address'] : [];
        $email = trim((string) ($payer['email_address'] ?? $orderOrCapture['payment_source']['paypal']['email_address'] ?? ''));
        $phone = $this->resolvePhone($shipping, $payer);

        $line1 = trim((string) ($address['address_line_1'] ?? ''));
        $country = strtoupper(trim((string) ($address['country_code'] ?? '')));
        if ($line1 === '' && $country === '' && $name === '' && $email === '') {
            return null;
        }

        $adminArea1 = trim((string) ($address['admin_area_1'] ?? ''));
        $adminArea2 = trim((string) ($address['admin_area_2'] ?? ''));
        $postal = trim((string) ($address['postal_code'] ?? ''));
        $line2 = trim((string) ($address['address_line_2'] ?? ''));

        return array_filter([
            'name' => $name,
            'contact_name' => $name,
            'phone' => $phone,
            'contact_phone' => $phone,
            'email' => $email,
            'country_code' => $country,
            'province' => $adminArea1,
            'city' => $adminArea2,
            'address1' => $line1,
            'street' => $line1,
            'address2' => $line2,
            'postal_code' => $postal,
            'source' => 'paypal_express',
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * @param array<string, mixed> $orderOrCapture
     * @return array<string, mixed>
     */
    private function firstShipping(array $orderOrCapture): array
    {
        $units = $orderOrCapture['purchase_units'] ?? null;
        if (!is_array($units)) {
            return [];
        }
        foreach ($units as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $shipping = $unit['shipping'] ?? null;
            if (is_array($shipping) && $shipping !== []) {
                return $shipping;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $shipping
     * @param array<string, mixed> $payer
     */
    private function resolveName(array $shipping, array $payer): string
    {
        $shipName = is_array($shipping['name'] ?? null) ? $shipping['name'] : [];
        $full = trim((string) ($shipName['full_name'] ?? ''));
        if ($full !== '') {
            return $full;
        }
        $payerName = is_array($payer['name'] ?? null) ? $payer['name'] : [];
        $given = trim((string) ($payerName['given_name'] ?? ''));
        $surname = trim((string) ($payerName['surname'] ?? ''));

        return trim($given . ' ' . $surname);
    }

    /**
     * @param array<string, mixed> $shipping
     * @param array<string, mixed> $payer
     */
    private function resolvePhone(array $shipping, array $payer): string
    {
        $shipPhone = is_array($shipping['phone_number'] ?? null) ? $shipping['phone_number'] : [];
        $national = trim((string) ($shipPhone['national_number'] ?? ''));
        if ($national !== '') {
            return $national;
        }
        $payerPhone = is_array($payer['phone']['phone_number'] ?? null)
            ? $payer['phone']['phone_number']
            : (is_array($payer['phone'] ?? null) ? $payer['phone'] : []);

        return trim((string) ($payerPhone['national_number'] ?? $payerPhone['number'] ?? ''));
    }
}
