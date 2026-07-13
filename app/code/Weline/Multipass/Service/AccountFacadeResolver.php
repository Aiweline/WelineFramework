<?php

declare(strict_types=1);

namespace Weline\Multipass\Service;

use Weline\Backend\Api\Auth\BackendAccountFacadeInterface;
use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Frontend\Api\Auth\FrontendAccountFacadeInterface;

/** Resolves the three required account providers once per process service instance. */
final class AccountFacadeResolver
{
    private ?BackendAccountFacadeInterface $backend = null;
    private ?FrontendAccountFacadeInterface $frontend = null;
    private ?CustomerAccountFacadeInterface $customer = null;

    public function __construct(
        private readonly RuntimeProviderResolver $providers,
    ) {
    }

    public function backend(): BackendAccountFacadeInterface
    {
        if ($this->backend !== null) {
            return $this->backend;
        }

        $provider = $this->providers->resolve(BackendAccountFacadeInterface::class);
        if (!$provider instanceof BackendAccountFacadeInterface) {
            throw new \LogicException('Required Backend account facade is unavailable.');
        }

        return $this->backend = $provider;
    }

    public function frontend(): FrontendAccountFacadeInterface
    {
        if ($this->frontend !== null) {
            return $this->frontend;
        }

        $provider = $this->providers->resolve(FrontendAccountFacadeInterface::class);
        if (!$provider instanceof FrontendAccountFacadeInterface) {
            throw new \LogicException('Required Frontend account facade is unavailable.');
        }

        return $this->frontend = $provider;
    }

    public function customer(): CustomerAccountFacadeInterface
    {
        if ($this->customer !== null) {
            return $this->customer;
        }

        $provider = $this->providers->resolve(CustomerAccountFacadeInterface::class);
        if (!$provider instanceof CustomerAccountFacadeInterface) {
            throw new \LogicException('Required Customer account facade is unavailable.');
        }

        return $this->customer = $provider;
    }
}
