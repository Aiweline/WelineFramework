<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Statistics;

use Weline\Acl\Service\MenuStatistics;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class MenuStatisticsInterfaceFactory implements FactoryObjectInterface
{
    public function create(): MenuStatisticsInterface
    {
        return ObjectManager::getInstance(MenuStatistics::class);
    }
}
