<?php

namespace Weline\Cron\Console\Cron;

use Weline\Cron\Schedule\Schedule;

class Listing extends BaseCommand
{

    public function execute(array $args = [], array $data = []): void
    {
        # 存在，但名称不匹配，解析存在的计划任务
        $jobs = $this->schedule->getJobs();
        if($jobs){
            $this->printing->note(__('系统-定时计划任务： ') . PHP_EOL . implode("\n", $jobs));
        }
    }

    public function tip(): string
    {
        return '查看系统定时任务是否存在。';
    }
}