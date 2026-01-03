<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Console\Command;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Async\Service\WatcherService;
use Weline\Async\Model\SyncMapping;

class Daemon extends CommandAbstract
{
    private WatcherService $watcherService;
    private SyncMapping $syncMapping;
    private bool $running = true;

    public function __construct()
    {
        $this->watcherService = ObjectManager::getInstance(WatcherService::class);
        $this->syncMapping = ObjectManager::getInstance(SyncMapping::class);
    }

    public function tip(): string
    {
        return '后台守护模式，自动监控和重启watcher';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'async:daemon',
            $this->tip(),
            [
                '--interval' => '检查间隔（秒，默认60）',
            ],
            [],
            [
                '启动守护进程' => 'php bin/w async:daemon',
                '指定检查间隔' => 'php bin/w async:daemon --interval=30',
            ]
        );
    }

    public function execute(array $args = [], array $data = []): void
    {
        $interval = (int)($data['interval'] ?? 60);
        
        $this->printer->success('守护进程已启动，按 Ctrl+C 退出');
        $this->printer->note("检查间隔: {$interval} 秒");

        // 注册信号处理
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        while ($this->running) {
            $this->checkAndRestartWatchers();
            
            // 等待指定时间
            if (function_exists('pcntl_signal_dispatch')) {
                for ($i = 0; $i < $interval && $this->running; $i++) {
                    pcntl_signal_dispatch();
                    sleep(1);
                }
            } else {
                sleep($interval);
            }
        }

        $this->printer->note('守护进程已停止');
    }

    /**
     * 检查并重启watcher
     */
    private function checkAndRestartWatchers(): void
    {
        // 检查项目配置的watcher
        $configService = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Async\Service\ConfigService::class);
        if ($configService->hasProjectConfig()) {
            if (!$this->watcherService->isWatcherRunning('project')) {
                $this->printer->warning("检测到项目配置watcher未运行，正在重启...");
                $result = $this->watcherService->startProjectWatcher();
                
                if ($result['success']) {
                    $this->printer->success("项目配置重启成功 (PID: {$result['pid']})");
                } else {
                    $this->printer->error("项目配置重启失败: {$result['message']}");
                }
            }
        }

        // 检查后台配置的映射
        $mappings = $this->syncMapping->clear()
            ->where(SyncMapping::fields_STATUS, 1)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($mappings as $mapping) {
            $mappingId = $mapping->getId();
            
            if (!$this->watcherService->isWatcherRunning($mappingId)) {
                $this->printer->warning("检测到映射 #{$mappingId} 未运行，正在重启...");
                $result = $this->watcherService->startWatcher($mappingId);
                
                if ($result['success']) {
                    $this->printer->success("映射 #{$mappingId} 重启成功 (PID: {$result['pid']})");
                } else {
                    $this->printer->error("映射 #{$mappingId} 重启失败: {$result['message']}");
                }
            }
        }
    }

    /**
     * 信号处理
     */
    public function handleSignal(int $signal): void
    {
        $this->running = false;
    }
}
