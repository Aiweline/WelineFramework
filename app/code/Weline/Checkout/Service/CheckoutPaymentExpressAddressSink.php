<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Payment\Api\PaymentExpressAddressSinkInterface;
use Weline\Shipping\Service\AddressFormatter;
use Weline\Shipping\Service\AddressValidationService;
use Weline\Shipping\Service\DeliveryAddressService;

/**
 * Checkout-side sink: persist PayPal (etc.) express shipping into delivery context.
 */
final class CheckoutPaymentExpressAddressSink implements PaymentExpressAddressSinkInterface
{
    public function __construct(
        private readonly ?CheckoutDeliveryContextService $delivery = null,
    ) {
    }

    public function applyExpressAddress(array $payload): array
    {
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        if ($profile === []) {
            return ['applied' => false, 'message' => 'empty_profile'];
        }

        $address = [
            'contact_name' => trim((string) ($profile['contact_name'] ?? $profile['name'] ?? '')),
            'contact_phone' => trim((string) ($profile['contact_phone'] ?? $profile['phone'] ?? '')),
            'email' => trim((string) ($profile['email'] ?? '')),
            'country_code' => strtoupper(trim((string) ($profile['country_code'] ?? ''))),
            'province' => trim((string) ($profile['province'] ?? '')),
            'city' => trim((string) ($profile['city'] ?? '')),
            'district' => trim((string) ($profile['district'] ?? '')),
            'street' => trim((string) ($profile['street'] ?? $profile['address1'] ?? '')),
            'address1' => trim((string) ($profile['address1'] ?? $profile['street'] ?? '')),
            'postal_code' => trim((string) ($profile['postal_code'] ?? '')),
        ];
        if ($address['street'] === '' && $address['address1'] !== '') {
            $address['street'] = $address['address1'];
        }
        if ($address['contact_name'] === '' || $address['street'] === '') {
            return ['applied' => false, 'message' => 'incomplete_profile'];
        }
        // Do not invent a fake phone — gap fields are filled on express-review.

        try {
            $this->delivery()->saveAddress(['address' => $address]);
        } catch (\Throwable $e) {
            return ['applied' => false, 'message' => $e->getMessage()];
        }

        return ['applied' => true];
    }

    private function delivery(): CheckoutDeliveryContextService
    {
        if ($this->delivery instanceof CheckoutDeliveryContextService) {
            return $this->delivery;
        }
        try {
            $svc = ObjectManager::getInstance(CheckoutDeliveryContextService::class);
            if ($svc instanceof CheckoutDeliveryContextService) {
                return $svc;
            }
        } catch (\Throwable) {
        }

        return new CheckoutDeliveryContextService(
            ObjectManager::getInstance(SessionFactory::class),
            ObjectManager::getInstance(DeliveryAddressService::class),
            ObjectManager::getInstance(AddressFormatter::class),
            ObjectManager::getInstance(AddressValidationService::class),
        );
    }
}
