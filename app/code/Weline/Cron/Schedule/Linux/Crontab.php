<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/27 21:04:49
 */

namespace Weline\Cron\Schedule\Linux;

use Weline\Cron\Schedule\Schedule;
use Weline\Framework\App\Env;

class Crontab implements \Weline\Cron\Schedule\ScheduleInterface
{

    public function create(string $name): array
    {
        #生成shell脚本
        $base_project_dir = BP;
        $cron_shell_file_path = Env::path_framework_generated . $name . '-cron.sh';
        $php_binary = PHP_BINARY;
        $log = BP . 'var/cron.log';
        if (!file_exists($log)) {
            if (!is_dir(dirname($log))) {
                mkdir(dirname($log), 0777, true);
            }
            file_put_contents($log, '');
        }
        $shell_string = "
#!/bin/sh
cd $base_project_dir &&
$php_binary bin/w cron:task:run 2>&1 >> $log";
        file_put_contents($cron_shell_file_path, $shell_string);
        if (is_string($name) && !empty($name) && $this->exist($name) === false) {
            exec(
                'echo -e "`crontab -l` ' . PHP_EOL . ' */1 * * * * sh ' . $cron_shell_file_path . '" | crontab -',
                $output
            );
            return ['status' => true, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务安装成功：%1', $name), 'result' => $output];
        }
        return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务已存在：%1', $name), 'result' => ''];
    }

    public function run(string $name): array
    {
        $base_project_dir = BP;
        $php_binary = PHP_BINARY;
        exec("cd $base_project_dir && $php_binary bin/w cron:task:run", $output);
        return ['status' => true, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%1 ,成功运行!', $name), 'result' => $output];
    }

    public function remove(string $name): array
    {
        $jobs = $this->getJobs();
        foreach ($jobs as $key => $job) {
            if (str_contains($job, $name)) {
                unset($jobs[$key]);
            }
        }
        $jobs_string = implode(PHP_EOL, $jobs);
        exec("echo -e \"$jobs_string\" | crontab -");
        # 删除脚本
        if (is_file(Env::path_framework_generated . $name . '-cron.sh')) {
            unlink(Env::path_framework_generated . $name . '-cron.sh');
        }
        return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务已移除：%1', $name), 'result' => ''];
    }

    public function exist(string $name): bool
    {
        $crontab = $this->getJobs();
        foreach ($crontab as $job) {
            if (str_contains($job, $name)) {
                return true;
            }
        }
        return false;
    }

    public function getJobs(): array
    {
        exec('crontab -l', $crontab);
        $weline_crons = array_filter($crontab, function ($item) {
            return str_contains($item, Schedule::cron_flag);
        });
        return $weline_crons;
    }
}
