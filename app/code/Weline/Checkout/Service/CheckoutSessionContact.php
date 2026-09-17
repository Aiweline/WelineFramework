<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Resolve contact email from a checkout session payload (guest/address/customer).
 */
final class CheckoutSessionContact
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function extractEmail(array $payload): string
    {
        $candidates = [
            \trim((string)($payload['guest_email'] ?? '')),
            \trim((string)($payload['customer_email'] ?? '')),
        ];
        $address = \is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $billing = \is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [];
        $candidates[] = \trim((string)($address['email'] ?? ''));
        $candidates[] = \trim((string)($billing['email'] ?? ''));

        foreach ($candidates as $email) {
            if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        $customerId = (int)($payload['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return '';
        }
        try {
            if (!\class_exists(\Weline\Customer\Model\Customer::class)) {
                return '';
            }
            /** @var \Weline\Customer\Model\Customer $customer */
            $customer = ObjectManager::getInstance(\Weline\Customer\Model\Customer::class);
            $customer->load($customerId);
            if (!$customer->getId()) {
                return '';
            }
            $email = \trim((string)($customer->getData('email') ?? $customer->getEmail()));
            if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function extractCustomerName(array $payload): string
    {
        $address = \is_array($payload['address'] ?? null) ? $payload['address'] : [];
        foreach (['fullname_name', 'full_name', 'firstname', 'name'] as $key) {
            $v = \trim((string)($address[$key] ?? $payload[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        $first = \trim((string)($address['firstname'] ?? ''));
        $last = \trim((string)($address['lastname'] ?? ''));
        $joined = \trim($first . ' ' . $last);

        return $joined !== '' ? $joined : '';
    }
}
