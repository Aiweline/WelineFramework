<?php

namespace Weline\Cron\Console\Cron;

class Exist extends BaseCommand
{

    public function execute(array $args = [], array $data = []): void
    {
        $cron_name = $this->getCronName($data['module']);
        $result = $this->schedule->exist($cron_name);
        if ($result) {
            $this->printing->success(__('系统定时任务：%1 ,已安装！', $cron_name));
        } else {
            $this->printing->error(__('系统定时任务：%1 ,未安装！', $cron_name));
        }
        # 存在，但名称不匹配，解析存在的计划任务
        $jobs = $this->schedule->getJobs();
        $other_jobs = array_filter($jobs, function ($item) use ($cron_name) {
            return !str_contains($item, $cron_name);
        });
        if ($other_jobs) {
            $this->printing->note(__('系统-其他定时任务： ') . PHP_EOL . implode("\n", $other_jobs));
        }
    }

    public function tip(): string
    {
        return '查看系统定时任务是否存在。';
    }
}