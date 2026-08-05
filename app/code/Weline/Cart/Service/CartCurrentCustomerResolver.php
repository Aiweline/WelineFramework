<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * Resolves the server-owned storefront customer identity for Cart APIs.
 *
 * Weline_Customer remains optional: Cart depends only on its public facade when
 * that provider is declared by the runtime.
 */
final class CartCurrentCustomerResolver
{
    public const ERROR_AUTH_REQUIRED = 'cart_customer_auth_required';

    /** @var (\Closure(): (?int))|null */
    private readonly ?\Closure $resolver;

    /** @param (callable(): (?int))|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    public function currentCustomerId(): ?int
    {
        if ($this->resolver !== null) {
            return $this->normalize(($this->resolver)());
        }
        if (!interface_exists(CustomerAccountFacadeInterface::class)) {
            return null;
        }

        try {
            $accounts = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(CustomerAccountFacadeInterface::class);
            if (!$accounts instanceof CustomerAccountFacadeInterface) {
                return null;
            }
            return $this->normalize($accounts->current()?->getId());
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalize(mixed $customerId): ?int
    {
        $customerId = (int)$customerId;
        return $customerId > 0 ? $customerId : null;
    }
}
