<?php

declare(strict_types=1);

namespace Weline\Cron\Api\Task;

interface CronTaskCatalogInterface
{
    /** @return list<CronTaskRecord> */
    public function listByNameContains(string $needle): array;
}
