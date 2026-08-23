<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\SessionFactory;

final class ReviewCurrentCustomerResolver
{
    public function __construct(private readonly ?SessionFactory $sessionFactory = null)
    {
    }

    public function currentCustomerId(): ?int
    {
        try {
            $session = ($this->sessionFactory ?? SessionFactory::getInstance())->createFrontendSession();
            $customerId = (int)($session->getUserId() ?? 0);
            if ($session->isLoggedIn() && $customerId > 0) {
                return $customerId;
            }
        } catch (\Throwable) {
        }

        if (!interface_exists(CustomerAccountFacadeInterface::class)) {
            return null;
        }
        try {
            $facade = ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve(CustomerAccountFacadeInterface::class);
            $customerId = $facade instanceof CustomerAccountFacadeInterface ? (int)($facade->current()?->getId() ?? 0) : 0;
            return $customerId > 0 ? $customerId : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
