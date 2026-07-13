<?php

declare(strict_types=1);

namespace Weline\Customer\Api\Auth;

use Weline\Customer\Service\CustomerAccountFacade;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class CustomerAccountFacadeInterfaceFactory implements FactoryObjectInterface
{
    public function create(): CustomerAccountFacadeInterface
    {
        return ObjectManager::getInstance(CustomerAccountFacade::class);
    }
}
