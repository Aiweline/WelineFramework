<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Api\Auth\CustomerIdentityProviderInterface;
use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticableInterface;

final class CustomerIdentityProvider implements CustomerIdentityProviderInterface
{
    public function find(int $customerId): ?AuthenticableInterface
    {
        if ($customerId <= 0) {
            return null;
        }

        /** @var Customer $customer */
        $customer = ObjectManager::getInstance(Customer::class, [], false);
        $customer->load($customerId);
        return $customer->getId() ? $customer : null;
    }
}
