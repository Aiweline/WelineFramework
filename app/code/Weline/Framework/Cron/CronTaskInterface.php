<?php

declare(strict_types=1);

namespace Weline\Framework\Cron;

interface CronTaskInterface
{
    public function name(): string;
    public function execute_name(): string;
    public function tip(): string;
    public function cron_time(): string;
    public function execute(): string;
    public function unlock_timeout(int $minute = 30): int;
}
