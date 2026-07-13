<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Options;

use Weline\Eav\Service\EavOptionsQuery;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class EavOptionsQueryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): EavOptionsQueryInterface
    {
        return ObjectManager::getInstance(EavOptionsQuery::class);
    }
}
