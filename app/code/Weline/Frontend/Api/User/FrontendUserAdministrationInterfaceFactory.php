<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\User;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Service\FrontendUserAdministration;

final class FrontendUserAdministrationInterfaceFactory implements FactoryObjectInterface
{
    public function create(): FrontendUserAdministrationInterface
    {
        return ObjectManager::getInstance(FrontendUserAdministration::class);
    }
}
