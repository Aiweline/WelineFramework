<?php

declare(strict_types=1);

namespace WeShop\Customer\Api;

use WeShop\Customer\Service\CustomerContext;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

class CustomerContextInterfaceFactory implements FactoryObjectInterface
{
    public function create(): CustomerContextInterface
    {
        return ObjectManager::getInstance(CustomerContext::class);
    }
}
