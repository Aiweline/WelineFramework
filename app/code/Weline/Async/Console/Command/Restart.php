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

class Restart extends CommandAbstract
{
    private WatcherService $watcherService;
    private SyncMapping $syncMapping;

    public function __construct()
    {
        $this->watcherService = ObjectManager::getInstance(WatcherService::class);
        $this->syncMapping = ObjectManager::getInstance(SyncMapping::class);
    }

    public function tip(): string
    {
        return '重启同步watcher';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'async:restart',
            $this->tip(),
            [
                '--host' => '主机ID（可选，重启指定主机的所有watcher）',
                '--mapping' => '映射ID（可选，重启指定映射的watcher）',
            ],
            [],
            [
                '重启所有watcher' => 'php bin/w async:restart',
                '重启指定主机的watcher' => 'php bin/w async:restart --host=1',
                '重启指定映射的watcher' => 'php bin/w async:restart --mapping=1',
            ]
        );
    }

    public function execute(array $args = [], array $data = []): void
    {
        $hostId = $data['host'] ?? null;
        $mappingId = $data['mapping'] ?? null;

        $this->printer->note('正在重启watcher...');

        // 先重启项目配置的watcher（如果存在）
        $configService = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Async\Service\ConfigService::class);
        if ($configService->hasProjectConfig()) {
            $this->printer->note('重启项目配置watcher...');
            
            // 先停止
            if ($this->watcherService->isWatcherRunning('project')) {
                $this->watcherService->stopProjectWatcher();
                sleep(1); // 等待进程完全停止
            }
            
            // 再启动
            $result = $this->watcherService->startProjectWatcher();
            
            if ($result['success']) {
                $this->printer->success("项目配置重启成功 (PID: {$result['pid']})");
            } else {
                $this->printer->error("项目配置重启失败: {$result['message']}");
            }
        }

        // 重启后台配置的映射
        $query = $this->syncMapping->clear()
            ->where(SyncMapping::fields_STATUS, 1); // 只重启状态为开启的映射

        if ($mappingId) {
            $query->where(SyncMapping::fields_MAPPING_ID, $mappingId);
        } elseif ($hostId) {
            $query->where(SyncMapping::fields_HOST_ID, $hostId);
        }

        $mappings = $query->select()->fetch()->getItems();

        foreach ($mappings as $mapping) {
            $mappingId = $mapping->getId();
            
            // 先停止
            if ($this->watcherService->isWatcherRunning($mappingId)) {
                $this->watcherService->stopWatcher($mappingId);
                sleep(1); // 等待进程完全停止
            }
            
            // 再启动
            $result = $this->watcherService->startWatcher($mappingId);
            
            if ($result['success']) {
                $this->printer->success("映射 #{$mappingId} 重启成功 (PID: {$result['pid']})");
            } else {
                $this->printer->error("映射 #{$mappingId} 重启失败: {$result['message']}");
            }
        }

        $this->printer->note('重启完成');
    }
}
