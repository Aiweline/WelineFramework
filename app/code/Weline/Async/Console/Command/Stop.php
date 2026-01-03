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

class Stop extends CommandAbstract
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
        return '停止同步watcher';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'async:stop',
            $this->tip(),
            [
                '--host' => '主机ID（可选，停止指定主机的所有watcher）',
                '--mapping' => '映射ID（可选，停止指定映射的watcher）',
            ],
            [],
            [
                '停止所有watcher' => 'php bin/w async:stop',
                '停止指定主机的watcher' => 'php bin/w async:stop --host=1',
                '停止指定映射的watcher' => 'php bin/w async:stop --mapping=1',
            ]
        );
    }

    public function execute(array $args = [], array $data = []): void
    {
        $hostId = $data['host'] ?? null;
        $mappingId = $data['mapping'] ?? null;

        $this->printer->note('正在停止watcher...');
        $successCount = 0;
        $failCount = 0;

        // 先停止项目配置的watcher（如果存在且运行中）
        $configService = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Async\Service\ConfigService::class);
        if ($configService->hasProjectConfig() && $this->watcherService->isWatcherRunning('project')) {
            $this->printer->note('停止项目配置watcher...');
            $result = $this->watcherService->stopProjectWatcher();
            
            if ($result['success']) {
                $this->printer->success("项目配置已停止");
                $successCount++;
            } else {
                $this->printer->error("项目配置停止失败: {$result['message']}");
                $failCount++;
            }
        }

        // 停止后台配置的映射
        $query = $this->syncMapping->clear();

        if ($mappingId) {
            $query->where(SyncMapping::fields_MAPPING_ID, $mappingId);
        } elseif ($hostId) {
            $query->where(SyncMapping::fields_HOST_ID, $hostId);
        }

        $mappings = $query->select()->fetch()->getItems();

        foreach ($mappings as $mapping) {
            $mappingId = $mapping->getId();
            
            if (!$this->watcherService->isWatcherRunning($mappingId)) {
                $this->printer->note("映射 #{$mappingId} 未运行，跳过");
                continue;
            }

            $result = $this->watcherService->stopWatcher($mappingId);
            
            if ($result['success']) {
                $this->printer->success("映射 #{$mappingId} 已停止");
                $successCount++;
            } else {
                $this->printer->error("映射 #{$mappingId} 停止失败: {$result['message']}");
                $failCount++;
            }
        }

        if ($successCount === 0 && $failCount === 0) {
            $this->printer->warning('没有找到需要停止的watcher');
            return;
        }

        $this->printer->note("停止完成: 成功 {$successCount} 个, 失败 {$failCount} 个");
    }
}
