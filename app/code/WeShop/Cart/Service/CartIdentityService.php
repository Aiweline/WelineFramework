<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Session\Auth\AuthenticableInterface;

class CartIdentityService
{
    private const GUEST_CART_CUSTOMER_ID_KEY = 'weshop_guest_cart_customer_id';
    public const GUEST_CART_MIN_ID = 1000000000;
    public const GUEST_CART_MAX_ID = 1999999999;

    public function __construct(
        private readonly CustomerSession $customerSession
    ) {
    }

    public function getCartCustomerId(bool $createGuest = true): int
    {
        $authenticatedId = $this->getAuthenticatedCustomerId();
        if ($authenticatedId > 0) {
            return $authenticatedId;
        }

        if (!$createGuest) {
            return 0;
        }

        return $this->getGuestCartCustomerId();
    }

    public function isGuest(): bool
    {
        return $this->getAuthenticatedCustomerId() <= 0;
    }

    public static function isGuestCartCustomerId(int $customerId): bool
    {
        return $customerId >= self::GUEST_CART_MIN_ID && $customerId <= self::GUEST_CART_MAX_ID;
    }

    public function getCustomer(): ?AuthenticableInterface
    {
        $customer = $this->customerSession->getCustomer();
        return $customer instanceof AuthenticableInterface ? $customer : null;
    }

    public function getAuthenticatedCustomerId(): int
    {
        $customer = $this->customerSession->getCustomer();
        if (!$customer) {
            return 0;
        }

        if (method_exists($customer, 'getAuthIdentifier')) {
            $id = (int) $customer->getAuthIdentifier();
            if ($id > 0) {
                return $id;
            }
        }

        if (method_exists($customer, 'getId')) {
            return max(0, (int) $customer->getId());
        }

        return 0;
    }

    public function getGuestCartCustomerId(): int
    {
        $guestId = (int) ($this->customerSession->get(self::GUEST_CART_CUSTOMER_ID_KEY) ?? 0);
        if ($guestId >= self::GUEST_CART_MIN_ID && $guestId <= self::GUEST_CART_MAX_ID) {
            return $guestId;
        }

        $guestId = random_int(self::GUEST_CART_MIN_ID, self::GUEST_CART_MAX_ID);
        $this->customerSession->set(self::GUEST_CART_CUSTOMER_ID_KEY, $guestId);
        $this->customerSession->getSession()->save();

        return $guestId;
    }
}
