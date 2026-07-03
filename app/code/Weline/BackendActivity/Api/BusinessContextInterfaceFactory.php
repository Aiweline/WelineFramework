<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Api;

use Weline\BackendActivity\Service\BusinessContextService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

class BusinessContextInterfaceFactory implements FactoryObjectInterface
{
    public function create(): BusinessContextInterface
    {
        return ObjectManager::getInstance(BusinessContextService::class);
    }
}
