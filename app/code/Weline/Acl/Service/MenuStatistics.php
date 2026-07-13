<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Statistics\MenuStatisticsInterface;
use Weline\Acl\Model\Acl;

final class MenuStatistics implements MenuStatisticsInterface
{
    public function __construct(private readonly Acl $acl)
    {
    }

    public function countActiveBackendMenus(): int
    {
        return (int)$this->acl->reset()
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_IS_BACKEND, 1)
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->count(Acl::schema_fields_SOURCE_ID);
    }
}
