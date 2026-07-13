<?php

declare(strict_types=1);

namespace Weline\Cron\Api\Task;

/** Immutable scheduled-task projection for cross-module dashboards. */
final readonly class CronTaskRecord
{
    public function __construct(
        public string $executeName,
        public string $name,
        public string $cronTime,
        public string $tip,
        public string $previousRunDate,
    ) {
    }
}
