<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Session\CustomerSession;
use Weline\Customer\Model\Customer as AuthCustomer;

class CustomerContext implements CustomerContextInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerProfileService $customerProfileService
    ) {
    }

    public function getAuthUser(): ?AuthCustomer
    {
        $user = $this->customerSession->getUser();
        return $user instanceof AuthCustomer ? $user : null;
    }

    public function getProfile(): ?CustomerProfile
    {
        $authUser = $this->getAuthUser();
        if (!$authUser) {
            return null;
        }

        return $this->customerProfileService->getByUserId((int) $authUser->getId());
    }

    public function getUserId(): ?int
    {
        $authUser = $this->getAuthUser();
        return $authUser ? (int) $authUser->getId() : null;
    }

    public function getEmail(): ?string
    {
        $authUser = $this->getAuthUser();
        return $authUser ? (string) $authUser->getUsername() : null;
    }
}
