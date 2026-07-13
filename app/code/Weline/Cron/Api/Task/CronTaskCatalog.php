<?php

declare(strict_types=1);

namespace Weline\Cron\Api\Task;

use Weline\Cron\Model\CronTask;

final class CronTaskCatalog implements CronTaskCatalogInterface
{
    public function __construct(
        private readonly CronTask $cronTask,
    ) {
    }

    public function listByNameContains(string $needle): array
    {
        $needle = \trim($needle);
        $query = $this->cronTask->reset();
        if ($needle !== '') {
            $query->where(CronTask::schema_fields_NAME, '%' . $needle . '%', 'like');
        }

        $rows = $query
            ->order(CronTask::schema_fields_PRE_RUN_DATE, 'DESC')
            ->select()
            ->fetchArray();

        $records = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $records[] = new CronTaskRecord(
                executeName: (string)($row[CronTask::schema_fields_EXECUTE_NAME] ?? ''),
                name: (string)($row[CronTask::schema_fields_NAME] ?? ''),
                cronTime: (string)($row[CronTask::schema_fields_CRON_TIME] ?? ''),
                tip: (string)($row[CronTask::schema_fields_TIP] ?? ''),
                previousRunDate: (string)($row[CronTask::schema_fields_PRE_RUN_DATE] ?? ''),
            );
        }

        return $records;
    }
}
