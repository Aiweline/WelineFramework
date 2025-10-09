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

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'cron:listing',
            '查看系统中已安装的所有定时计划任务',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '查看所有定时任务' => 'php bin/w cron:listing',
            ]
        );
    }
}