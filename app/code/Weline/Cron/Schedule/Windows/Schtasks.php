<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/27 21:05:17
 */

namespace Weline\Cron\Schedule\Windows;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;

class Schtasks implements \Weline\Cron\Schedule\ScheduleInterface
{
    /**
     * @var \Weline\Framework\App\System
     */
    private System $system;

    public function __construct(
        System $system
    ) {
        $this->system = $system;
    }

    public function create(string $name): array
    {
        if (!$this->exist($name)) {
            $base_project_dir       = BP;
            $base_project_disk_name = substr($base_project_dir, 0, 2);
            # FIXME bat弹窗问题 有vbs版本可以解决此问题，先不处理
            $php_binary = PHP_BINARY;
            $bat_string = "
@echo off
Rem WelineFramework框架 Window计划任务脚本
$base_project_disk_name && cd $base_project_dir && $php_binary bin/m cron:task:run
";
            $bat_file   = Env::path_framework_generated . $name . '-cron.bat';
            file_put_contents($bat_file, $bat_string);
            $create_command = "SCHTASKS /Create /TN $name /TR $bat_file /SC MINUTE";
            $data           = $this->system->win_exec($create_command);
            return ['status' => true, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务安装成功：%1', $name), 'result' => $data];
        }
        return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务已存在：%1', $name), 'result' => []];
    }

    public function run(string $name): array
    {
        $data = $this->system->win_exec("schtasks /Run /tn $name");
        if (count($data['output']) === 1) {
            return ['status' => true, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%1 ,成功运行!', $name), 'result' => $data];
        } else {
            return ['status' => false, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%1 ,运行失败!任务可能未安装！请执行：php bin/m cron:install 安装计划任务！', $name), 'result' => $data];
        }
    }

    public function remove(string $name): array
    {
        # 删除脚本
        if (Env::path_framework_generated . $name . '-cron.bat') {
            unlink(Env::path_framework_generated . $name . '-cron.bat');
        }
        if ($this->exist($name)) {
            $data = $this->system->win_exec("schtasks /Delete /tn $name /F");
            if (count($data['output']) === 1) {
                return ['status' => true, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%1 ,成功移除!', $name), 'result' => $data];
            }
            return ['status' => false, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务 %1 移除失败！', $name), 'result' => $data];
        }
        return ['status' => false, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务 %1 尚未安装！请执行：php bin/m cron:install 安装计划任务！', $name), 'result' => []];
    }

    public function exist(string $name): bool
    {
        $data = $this->system->win_exec("schtasks /query /tn $name");
        if (count($data['output']) === 5) {
            return true;
        }
        return false;
    }
}
