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
     * 日志通道名
     */
    private const LOG_CHANNEL = 'cron';

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

    /**
     * 判断是否为开发模式
     */
    private function isDevMode(): bool
    {
        try {
            return Env::system('deploy') === 'dev';
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * 判断是否为 CLI 模式
     */
    private function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * 格式化日志消息（替换占位符）
     */
    private function formatMessage(string $message, array $context = []): string
    {
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $message = str_replace('{' . $key . '}', (string) $value, $message);
        }
        return $message;
    }

    /**
     * 输出到控制台（CLI 模式）
     */
    private function consoleOutput(string $level, string $message, array $context = []): void
    {
        if (!$this->isCli()) {
            return;
        }

        $formattedMessage = $this->formatMessage($message, $context);
        
        // 颜色映射
        $colors = [
            'debug' => "\033[36m",     // 青色
            'info' => "\033[32m",      // 绿色
            'notice' => "\033[34m",    // 蓝色
            'warning' => "\033[33m",   // 黄色
            'error' => "\033[31m",     // 红色
            'critical' => "\033[35m",  // 紫色
            'alert' => "\033[35m",     // 紫色
            'emergency' => "\033[31m", // 红色
        ];
        
        $reset = "\033[0m";
        $color = $colors[$level] ?? '';
        $levelUpper = strtoupper($level);
        
        // 输出格式：[时间] [级别] 消息
        $timestamp = date('H:i:s');
        $output = "{$color}[{$timestamp}] [{$levelUpper}]{$reset} {$formattedMessage}\n";
        
        fwrite(STDOUT, $output);
    }

    /**
     * 智能日志：开发模式记录所有，生产模式仅记录 error/warning
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $shouldLog = $this->isDevMode() 
            || in_array($level, ['error', 'warning', 'critical', 'alert', 'emergency'], true);
        
        if ($shouldLog) {
            w_log($level, $message, $context, self::LOG_CHANNEL);
            if ($this->isDevMode()) {
                $this->consoleOutput($level, $message, $context);
            }
        }
    }

    /**
     * 仅开发模式日志
     */
    private function logDev(string $level, string $message, array $context = []): void
    {
        if ($this->isDevMode()) {
            w_log($level, $message, $context, self::LOG_CHANNEL);
            $this->consoleOutput($level, $message, $context);
        }
    }

    /**
     * 记录命令执行结果
     */
    private function logCommand(string $command, array $data): void
    {
        $this->logDev('debug', '[Windows/Schtasks] 命令: {command}, 返回码: {code}, 输出: {output}', [
            'command' => $command,
            'code' => $data['return_vars'] ?? -1,
            'output' => implode("\n", $data['output'] ?? [])
        ]);
    }

    /**
     * 获取任务详情（用于判断是否为 Weline Cron 任务）
     */
    private function getTaskDetail(string $name): ?array
    {
        $command = "schtasks /query /tn \"$name\" /FO LIST /V";
        $data = $this->system->win_exec($command);
        
        if (($data['return_vars'] ?? -1) !== 0) {
            return null;
        }
        
        return $data;
    }

    /**
     * 判断任务是否为 Weline Cron 任务
     * 通过检查任务运行的脚本路径是否在 generated 目录下
     */
    private function isWelineCronTask(string $name): bool
    {
        $data = $this->getTaskDetail($name);
        if ($data === null) {
            return false;
        }
        
        $output = implode("\n", $data['output'] ?? []);
        
        // 检查任务是否运行我们的 VBS 脚本（在 generated 目录下）
        $generatedPath = str_replace('\\', '/', Env::path_framework_generated);
        
        // 也检查是否有 cron_flag 标记
        $hasFlag = str_contains($output, Schedule::cron_flag);
        $runsGeneratedScript = str_contains($output, '-cron.vbs');
        
        return $hasFlag || $runsGeneratedScript;
    }

    public function create(string $name): array
    {
        $this->log('info', '[Windows/Schtasks] 开始创建定时任务: {name}', ['name' => $name]);
        
        $current_user = Env::user();
        
        $this->logDev('debug', '[Windows/Schtasks] 当前用户: {user}', ['user' => $current_user]);
        
        if (!$this->exist($name)) {
            $php_binary = PHP_BINARY;
            # 获取盘符
            $log = BP . 'var/cron.log';
            if (!file_exists($log)) {
                if (!is_dir(dirname($log))) {
                    $created = mkdir(dirname($log), 0777, true);
                    $this->logDev('debug', '[Windows/Schtasks] 创建日志目录: {dir}, 结果: {result}', [
                        'dir' => dirname($log),
                        'result' => $created ? 'success' : 'failed'
                    ]);
                }
                file_put_contents($log, '');
            }
            
            $bp_command_file = BP . 'bin\w';
            $command = "$php_binary $bp_command_file cron:task:run";
            
            $this->logDev('debug', '[Windows/Schtasks] 命令: {command}', ['command' => $command]);
            
            // 在脚本中添加注释标记，用于标识 Weline Cron 任务
            $script_string = <<<SCRIPT
' {flag}
Dim command
command = "$command"
Dim shell
Set shell = WScript.CreateObject("WScript.Shell")
shell.Run command, 0, True
Set shell = Nothing

SCRIPT;
            // 替换标记
            $script_string = str_replace('{flag}', Schedule::cron_flag, $script_string);
            
            $script_file = Env::path_framework_generated . $name . '-cron.vbs';
            
            $this->logDev('debug', '[Windows/Schtasks] VBS 脚本路径: {path}', ['path' => $script_file]);

            $script_string = Win::command_convert_gbk($script_string);
            file_put_contents($script_file, $script_string);
            
            // 创建计划任务（不使用 /D 参数，因为那是用于指定星期几的）
            $create_task = "SCHTASKS /Create /TN \"$name\" /TR \"$script_file\" /SC MINUTE /RU \"$current_user\"";
            
            $this->logDev('debug', '[Windows/Schtasks] 创建任务命令: {command}', ['command' => $create_task]);
            
            $data = $this->system->win_exec($create_task);
            
            $this->logCommand($create_task, $data);
            
            if ($data['return_vars'] === 1) {
                $permissionHelp = <<<'HELP'
你可能需要添加用户权限！（当前用户：%{1}）
打开本地安全策略：

按 Win + R 键，输入 secpol.msc，然后按回车。
导航到任务计划程序权限：

在本地安全策略编辑器中，展开「本地策略」->「用户权限分配」。
找到「作为批处理作业登录」和「计划任务」这两项权限。
添加用户：

双击「作为批处理作业登录」或「计划任务」，然后点击「添加用户或组」。
输入要赋予权限的用户账户名，然后点击「检查名称」以确保账户名正确无误。
点击「确定」以添加用户，并再次点击「确定」以保存更改。
HELP;
                $msg = __($permissionHelp, $current_user);
                $msg .= PHP_EOL . PHP_EOL . '[' . PHP_OS . ']' . __('系统定时任务安装失败：%{1} 遇到权限问题，请按照上述步骤添加权限！或者使用管理员运行：php bin/w cron:install', $name);
                
                $this->log('error', '[Windows/Schtasks] 定时任务创建失败（权限问题）: {name}, 用户: {user}', [
                    'name' => $name,
                    'user' => $current_user
                ]);

                return ['status' => false, 'msg' => $msg, 'result' => $data];
            }
            
            $this->log('info', '[Windows/Schtasks] 定时任务创建成功: {name}', ['name' => $name]);
            return ['status' => true, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务安装成功：%{1}', $name), 'result' => $data];
        }
        
        $this->log('warning', '[Windows/Schtasks] 定时任务已存在: {name}', ['name' => $name]);
        return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务已存在：%{1}', $name), 'result' => []];
    }

    public function run(string $name): array
    {
        $this->logDev('info', '[Windows/Schtasks] 手动运行定时任务: {name}', ['name' => $name]);
        
        $command = "schtasks /Run /tn \"$name\"";
        $data = $this->system->win_exec($command);
        
        $this->logCommand($command, $data);
        
        if (count($data['output']) === 1) {
            $this->logDev('info', '[Windows/Schtasks] 定时任务运行成功: {name}', ['name' => $name]);
            return ['status' => true, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%{1} ,成功运行!', $name), 'result' => $data];
        } else {
            if ($this->exist($name)) {
                $msg = '[' . PHP_OS . '] ' . __('系统计划任务：%{1} ,运行失败!任务正在运行!稍后重试~！使用php bin/w cron:listing 检查！', $name);
                $this->log('warning', '[Windows/Schtasks] 任务正在运行中: {name}', ['name' => $name]);
            } else {
                $msg = '[' . PHP_OS . '] ' . __('系统计划任务：%{1} ,运行失败!任务未安装!请执行：php bin/w cron:install 安装计划任务后重试！', $name);
                $this->log('warning', '[Windows/Schtasks] 任务未安装: {name}', ['name' => $name]);
            }
            return ['status' => false, 'msg' => $msg, 'result' => $data];
        }
    }

    public function remove(string $name): array
    {
        $this->log('info', '[Windows/Schtasks] 开始移除定时任务: {name}', ['name' => $name]);
        
        // 删除脚本
        $scriptPath = Env::path_framework_generated . $name . '-cron.vbs';
        if (file_exists($scriptPath)) {
            unlink($scriptPath);
            $this->logDev('debug', '[Windows/Schtasks] 已删除脚本文件: {path}', ['path' => $scriptPath]);
        }
        
        if ($this->exist($name)) {
            $command = "schtasks /Delete /tn \"$name\" /F";
            $data = $this->system->win_exec($command);
            
            $this->logCommand($command, $data);
            
            if (count($data['output']) === 1) {
                $this->log('info', '[Windows/Schtasks] 定时任务移除成功: {name}', ['name' => $name]);
                return ['status' => true, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%{1} ,成功移除!', $name), 'result' => $data];
            }
            
            $this->log('warning', '[Windows/Schtasks] 定时任务移除失败（可能权限不足）: {name}', ['name' => $name]);
            return ['status' => false, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务 %{1} 移除失败！可能权限不足，考虑使用管理员运行！php bin/w cron:remove', $name), 'result' => $data];
        }
        
        $this->log('warning', '[Windows/Schtasks] 定时任务不存在，无需移除: {name}', ['name' => $name]);
        return ['status' => false, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务 %{1} 尚未安装！请执行：php bin/w cron:install 安装计划任务！', $name), 'result' => []];
    }

    public function exist(string $name, bool $return_output = false): bool
    {
        $this->logDev('debug', '[Windows/Schtasks] 检查任务是否存在: {name}', ['name' => $name]);
        
        $command = "schtasks /query /tn \"$name\" /FO LIST /V";
        $data = $this->system->win_exec($command);
        
        // 如果命令执行失败（返回码非0），任务不存在
        if (($data['return_vars'] ?? -1) !== 0) {
            $this->logDev('debug', '[Windows/Schtasks] 任务不存在（查询失败）: {name}', ['name' => $name]);
            return false;
        }
        
        $output = implode("\n", $data['output'] ?? []);
        
        // 检查是否为 Weline Cron 任务
        $isWelineTask = $this->isWelineCronTask($name);
        
        $this->logDev('debug', '[Windows/Schtasks] 任务存在检查: {name}, 是否 Weline Cron 任务: {is_weline}', [
            'name' => $name,
            'is_weline' => $isWelineTask ? 'yes' : 'no'
        ]);
        
        return $isWelineTask;
    }

    public function getJobs(): array
    {
        $this->logDev('debug', '[Windows/Schtasks] 获取所有定时任务列表');
        
        $data = $this->system->win_exec("schtasks /query /FO LIST /V");
        $output = implode("\n", $data['output'] ?? []);
        
        // 按任务分割
        $tasks = explode("\n任务名:", $output);
        
        $welineTasks = [];
        foreach ($tasks as $task) {
            // 检查是否包含 cron_flag 或运行 generated 目录下的脚本
            if (str_contains($task, Schedule::cron_flag) || str_contains($task, '-cron.vbs')) {
                // 提取任务名
                if (preg_match('/^\s*(.+)/m', $task, $matches)) {
                    $welineTasks[] = trim($matches[1]);
                }
            }
        }
        
        $this->logDev('debug', '[Windows/Schtasks] 过滤后 Weline Cron 任务数: {count}', ['count' => count($welineTasks)]);
        
        return $welineTasks;
    }
}
