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
    /**
     * 日志通道名
     */
    private const LOG_CHANNEL = 'cron';

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
     * 记录命令执行结果
     */
    private function logCommand(string $command, array $output, int $returnCode): void
    {
        $this->logDev('debug', '命令执行: {command}', [
            'command' => $command,
            'output' => implode("\n", $output),
            'return_code' => $returnCode
        ]);
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

    public function create(string $name): array
    {
        $this->log('info', '[Linux/Crontab] 开始创建定时任务: {name}', ['name' => $name]);
        
        #生成shell脚本
        $base_project_dir = BP;
        $cron_shell_file_path = Env::path_framework_generated . $name . '-cron.sh';
        $php_binary = PHP_BINARY;
        $log = BP . 'var/cron.log';
        
        $this->logDev('debug', '[Linux/Crontab] 脚本路径: {script_path}, PHP: {php_binary}, 日志: {log_path}', [
            'script_path' => $cron_shell_file_path,
            'php_binary' => $php_binary,
            'log_path' => $log
        ]);
        
        if (!file_exists($log)) {
            if (!is_dir(dirname($log))) {
                $created = mkdir(dirname($log), 0777, true);
                $this->logDev('debug', '[Linux/Crontab] 创建日志目录: {dir}, 结果: {result}', [
                    'dir' => dirname($log),
                    'result' => $created ? 'success' : 'failed'
                ]);
            }
            file_put_contents($log, '');
        }
        
        $shell_string = "
#!/bin/sh
cd $base_project_dir &&
$php_binary bin/w cron:task:run 2>&1 >> $log";
        file_put_contents($cron_shell_file_path, $shell_string);
        
        $this->logDev('debug', '[Linux/Crontab] Shell 脚本已写入: {path}', ['path' => $cron_shell_file_path]);
        
        $this->removeLegacyProjectCronEntries($name);

        if (is_string($name) && !empty($name) && $this->exist($name) === false) {
            // 获取现有的crontab内容
            $existing_crontab = [];
            exec('crontab -l 2>/dev/null', $existing_crontab, $listReturnCode);
            
            $this->logDev('debug', '[Linux/Crontab] 获取现有 crontab, 返回码: {code}, 条目数: {count}', [
                'code' => $listReturnCode,
                'count' => count($existing_crontab)
            ]);
            
            // 添加新的定时任务，包含 cron_flag 标识
            $new_cron_job = "*/1 * * * * sh " . $cron_shell_file_path . " # " . Schedule::cron_flag;
            $existing_crontab[] = $new_cron_job;
            
            $this->logDev('debug', '[Linux/Crontab] 新增 cron 条目: {entry}', ['entry' => $new_cron_job]);
            
            // 创建临时文件
            $temp_file = tempnam(sys_get_temp_dir(), 'crontab_');
            file_put_contents($temp_file, implode("\n", $existing_crontab) . "\n");
            
            $this->logDev('debug', '[Linux/Crontab] 临时文件: {temp_file}', ['temp_file' => $temp_file]);
            
            // 安装新的crontab
            $installCommand = "crontab " . $temp_file;
            exec($installCommand, $output, $return_code);
            
            $this->logCommand($installCommand, $output, $return_code);
            
            // 清理临时文件
            unlink($temp_file);
            
            if ($return_code === 0) {
                $this->log('info', '[Linux/Crontab] 定时任务安装成功: {name}', ['name' => $name]);
                return ['status' => true, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务安装成功：%{1}', $name), 'result' => $output];
            } else {
                $this->log('error', '[Linux/Crontab] 定时任务安装失败: {name}, 返回码: {code}', [
                    'name' => $name,
                    'code' => $return_code,
                    'output' => $output
                ]);
                return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务安装失败：%{1}', $name), 'result' => $output];
            }
        }
        
        $this->log('warning', '[Linux/Crontab] 定时任务已存在或名称无效: {name}', ['name' => $name]);
        return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务已存在：%{1}', $name), 'result' => ''];
    }

    private function removeLegacyProjectCronEntries(string $name): void
    {
        $jobs = $this->getAllJobs();
        if (!$jobs) {
            return;
        }

        $generatedDir = Env::path_framework_generated;
        $filtered = [];
        $changed = false;
        foreach ($jobs as $job) {
            if (
                str_contains($job, Schedule::cron_flag)
                && str_contains($job, $generatedDir)
                && !str_contains($job, $name)
            ) {
                $changed = true;
                continue;
            }
            $filtered[] = $job;
        }

        if (!$changed) {
            return;
        }

        $temp_file = tempnam(sys_get_temp_dir(), 'crontab_');
        file_put_contents($temp_file, implode("\n", $filtered) . "\n");
        exec("crontab " . $temp_file, $output, $returnCode);
        unlink($temp_file);

        $this->logDev('debug', '[Linux/Crontab] Removed legacy project cron entries, return code: {code}', [
            'code' => $returnCode,
        ]);
    }

    public function run(string $name): array
    {
        $this->logDev('info', '[Linux/Crontab] 手动运行定时任务: {name}', ['name' => $name]);
        
        $base_project_dir = BP;
        $php_binary = PHP_BINARY;
        $command = "cd $base_project_dir && $php_binary bin/w cron:task:run";
        
        $this->logDev('debug', '[Linux/Crontab] 执行命令: {command}', ['command' => $command]);
        
        exec($command, $output, $returnCode);
        
        $this->logCommand($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->logDev('info', '[Linux/Crontab] 定时任务运行成功: {name}', ['name' => $name]);
        } else {
            $this->log('warning', '[Linux/Crontab] 定时任务运行返回非零: {name}, 返回码: {code}', [
                'name' => $name,
                'code' => $returnCode
            ]);
        }
        
        return ['status' => true, 'msg' => '[' . PHP_OS . '] ' . __('系统计划任务：%{1} ,成功运行!', $name), 'result' => $output];
    }

    public function remove(string $name): array
    {
        $this->log('info', '[Linux/Crontab] 开始移除定时任务: {name}', ['name' => $name]);
        
        $jobs = $this->getJobs();
        $originalCount = count($jobs);
        
        $this->logDev('debug', '[Linux/Crontab] 当前任务数: {count}', ['count' => $originalCount]);
        
        foreach ($jobs as $key => $job) {
            if (str_contains($job, $name)) {
                $this->logDev('debug', '[Linux/Crontab] 找到匹配任务，移除: {job}', ['job' => $job]);
                unset($jobs[$key]);
            }
        }
        
        $jobs_string = implode(PHP_EOL, $jobs);
        $command = "echo -e \"$jobs_string\" | crontab -";
        
        $this->logDev('debug', '[Linux/Crontab] 执行移除命令');
        
        exec($command, $output, $returnCode);
        
        $this->logDev('debug', '[Linux/Crontab] 移除命令返回码: {code}', ['code' => $returnCode]);
        
        # 删除脚本
        $scriptPath = Env::path_framework_generated . $name . '-cron.sh';
        if (is_file($scriptPath)) {
            unlink($scriptPath);
            $this->logDev('debug', '[Linux/Crontab] 已删除脚本文件: {path}', ['path' => $scriptPath]);
        }
        
        $this->log('info', '[Linux/Crontab] 定时任务已移除: {name}', ['name' => $name]);
        
        return ['status' => false, 'msg' => '[' . PHP_OS . ']' . __('系统定时任务已移除：%{1}', $name), 'result' => ''];
    }

    public function exist(string $name): bool
    {
        $this->logDev('debug', '[Linux/Crontab] 检查任务是否存在: {name}', ['name' => $name]);
        
        $crontab = $this->getJobs();
        
        foreach ($crontab as $job) {
            // 检查任务名是否在 cron 条目中（脚本文件名包含任务名）
            if (str_contains($job, $name)) {
                $this->logDev('debug', '[Linux/Crontab] 任务存在: {name}', ['name' => $name]);
                return true;
            }
        }
        
        $this->logDev('debug', '[Linux/Crontab] 任务不存在: {name}', ['name' => $name]);
        return false;
    }

    public function getJobs(): array
    {
        $this->logDev('debug', '[Linux/Crontab] 获取 crontab 任务列表');
        
        // 先获取所有 crontab 条目
        exec('crontab -l 2>/dev/null', $crontab, $returnCode);
        
        $this->logDev('debug', '[Linux/Crontab] crontab -l 返回码: {code}, 条目数: {count}', [
            'code' => $returnCode,
            'count' => count($crontab)
        ]);
        
        if ($returnCode !== 0) {
            $this->logDev('debug', '[Linux/Crontab] 无 crontab 条目或命令执行失败');
            return [];
        }
        
        // 过滤出包含 cron_flag 的条目（由本模块创建的任务）
        $weline_crons = array_filter($crontab, function ($item) {
            return str_contains($item, Schedule::cron_flag);
        });
        
        $this->logDev('debug', '[Linux/Crontab] 过滤后 Weline Cron 任务数: {count}', ['count' => count($weline_crons)]);
        
        return $weline_crons;
    }
    
    /**
     * 获取所有 crontab 条目（不过滤）
     * 用于调试目的
     */
    public function getAllJobs(): array
    {
        $this->logDev('debug', '[Linux/Crontab] 获取所有 crontab 条目（不过滤）');
        
        exec('crontab -l 2>/dev/null', $crontab, $returnCode);
        
        $this->logDev('debug', '[Linux/Crontab] 返回码: {code}, 条目数: {count}', [
            'code' => $returnCode,
            'count' => count($crontab)
        ]);
        
        return $returnCode === 0 ? $crontab : [];
    }
}
