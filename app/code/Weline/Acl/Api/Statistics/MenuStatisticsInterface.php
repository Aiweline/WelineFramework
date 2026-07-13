<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Statistics;

interface MenuStatisticsInterface
{
    public function countActiveBackendMenus(): int;
}
