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

use Weline\Cron\Schedule\Schedule;
use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\System\OS\Win;

class Schtasks implements \Weline\Cron\Schedule\ScheduleInterface
{
    /**
     * @var \Weline\Framework\App\System
     */
    private System $system;

    public function __construct(
        System $system
    )
    {
        $this->system = $system;
    }

    public function create(string $name): array
    {
        $current_user = Env::user();
        if (!$this->exist($name)) {
            $php_binary = PHP_BINARY;
            # 获取盘符
            $log = BP . 'var/cron.log';
            if (!file_exists($log)) {
                if (!is_dir(dirname($log))) {
                    mkdir(dirname($log), 0777, true);
                }
                file_put_contents($log, '');
            }
            # 2>> \"\"{$log}\"\" 2>&1
            $bp_command_file = BP . 'bin\w';
//            $command = "$php_binary $bp_command_file cron:task:run 2>> {$log} 2>&1";
            $command = "$php_binary $bp_command_file cron:task:run";
//            dd($command);
            $script_string = <<<SCRIPT
Dim command
command = "$command"
Dim shell
Set shell = WScript.CreateObject("WScript.Shell")
shell.Run command, 0, True
Set shell = Nothing

SCRIPT;
            $script_file = Env::path_framework_generated . $name . '-cron.vbs';

            $script_string = Win::command_convert_gbk($script_string);
            file_put_contents($script_file, $script_string);
            // 创建计划任务
            $create_task = "SCHTASKS /Create /TN \"$name\" /TR \"$script_file\" /SC MINUTE /RU \"$current_user\"";
            $data = $this->system->win_exec($create_task);
            if ($data['return_vars'] === 1) {
                return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务安装失败：%1', $name), 'result' => $data];
            }
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
            if ($this->exist($name)) {
                $msg = '[' . PHP_OS . '] ' . __('系统计划任务：%1 ,运行失败!任务正在运行!稍后重试~！使用php bin/w cron:listing 检查！', $name);
            } else {
                $msg = '[' . PHP_OS . '] ' . __('系统计划任务：%1 ,运行失败!任务未安装!请执行：php bin/w cron:install 安装计划任务后重试！', $name);
            }
            return ['status' => false, 'msg' => $msg, 'result' => $data];
        }
    }

    public function remove(string $name): array
    {
        // 删除脚本
        if (Env::path_framework_generated . $name . '-cron.vbs') {
            unlink(Env::path_framework_generated . $name . '-cron.vbs');
        }
        if ($this->exist($name)) {
            $data = $this->system->win_exec("schtasks /Delete /tn $name /F");
            if (count($data['output']) === 1) {
                return ['status' => true, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%1 ,成功移除!', $name), 'result' => $data];
            }
            return ['status' => false, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务 %1 移除失败！', $name), 'result' => $data];
        }
        return ['status' => false, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务 %1 尚未安装！请执行：php bin/w cron:install 安装计划任务！', $name), 'result' => []];
    }

    public function exist(string $name, bool $return_output = false): bool
    {
        $data = $this->system->win_exec("schtasks /query /tn $name /FO LIST");
        $output = implode("\n", $data['output']);
        if (str_contains($output, Schedule::cron_flag)) {
            if (str_contains($output, $name)) {
                return true;
            }
            return false;
        }
        return false;
    }

    public function getJobs(): array
    {
        $data = $this->system->win_exec("schtasks /query");
        $output = implode("\n", $data['output']);
        $output = explode("\n", $output);
        $output = array_filter($output, function ($item) {
            return str_contains($item, Schedule::cron_flag);
        });
        return $output;
    }
}
