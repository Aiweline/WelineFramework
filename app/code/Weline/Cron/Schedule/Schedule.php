<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/27 20:35:14
 */

namespace Weline\Cron\Schedule;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

class Schedule implements ScheduleInterface
{
    const cron_config_key = 'CRON_SCHEDULE_NAME';
    const cron_flag = '[Weline_Cron]';

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
     * CLI 模式下同时输出到控制台
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $shouldLog = $this->isDevMode() 
            || in_array($level, ['error', 'warning', 'critical', 'alert', 'emergency'], true);
        
        if ($shouldLog) {
            w_log($level, $message, $context, self::LOG_CHANNEL);
            
            // CLI 模式下输出到控制台
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

    public function create(string $name): array
    {
        $this->logDev('info', '创建定时任务开始: {name}', ['name' => $name, 'os' => PHP_OS]);
        
        try {
            $result = $this->getScheduler()->create($name);
            
            if ($result['status']) {
                $this->logDev('info', '定时任务创建成功: {name}', ['name' => $name, 'result' => $result]);
            } else {
                $this->log('warning', '定时任务创建失败或已存在: {name}', ['name' => $name, 'result' => $result]);
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->log('error', '定时任务创建异常: {name}, 错误: {error}', [
                'name' => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function run(string $name): array
    {
        $this->logDev('info', '运行定时任务: {name}', ['name' => $name]);
        
        try {
            $result = $this->getScheduler()->run($name);
            
            if ($result['status']) {
                $this->logDev('info', '定时任务运行成功: {name}', ['name' => $name]);
            } else {
                $this->log('warning', '定时任务运行失败: {name}, 消息: {msg}', [
                    'name' => $name,
                    'msg' => $result['msg'] ?? ''
                ]);
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->log('error', '定时任务运行异常: {name}, 错误: {error}', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function remove(string $name): array
    {
        $this->logDev('info', '移除定时任务: {name}', ['name' => $name]);
        
        try {
            $result = $this->getScheduler()->remove($name);
            
            $this->logDev('info', '定时任务移除完成: {name}, 状态: {status}', [
                'name' => $name,
                'status' => $result['status'] ? 'success' : 'failed'
            ]);
            
            return $result;
        } catch (\Throwable $e) {
            $this->log('error', '定时任务移除异常: {name}, 错误: {error}', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getScheduler(): ScheduleInterface
    {
        $cron_class = IS_WIN ? 'Windows\Schtasks' : 'Linux\Crontab';
        $schedulerClass = "Weline\Cron\\Schedule\\$cron_class";
        
        $this->logDev('debug', '获取调度器实例: {class}, 平台: {platform}', [
            'class' => $schedulerClass,
            'platform' => IS_WIN ? 'Windows' : 'Linux/Unix'
        ]);
        
        return ObjectManager::getInstance($schedulerClass);
    }

    public function exist(string $name): bool
    {
        $this->logDev('debug', '检查定时任务是否存在: {name}', ['name' => $name]);
        
        try {
            $exists = $this->getScheduler()->exist($name);
            
            $this->logDev('debug', '定时任务存在检查结果: {name}, 存在: {exists}', [
                'name' => $name,
                'exists' => $exists ? 'yes' : 'no'
            ]);
            
            return $exists;
        } catch (\Throwable $e) {
            $this->log('error', '检查定时任务存在时异常: {name}, 错误: {error}', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getJobs(): array
    {
        $this->logDev('debug', '获取所有定时任务列表');
        
        try {
            $jobs = $this->getScheduler()->getJobs();
            
            $this->logDev('debug', '获取到 {count} 个定时任务', ['count' => count($jobs)]);
            
            return $jobs;
        } catch (\Throwable $e) {
            $this->log('error', '获取定时任务列表异常: {error}', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
