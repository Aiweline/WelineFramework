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

class Start extends CommandAbstract
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
        return '启动同步watcher';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'async:start',
            $this->tip(),
            [
                '--host' => '主机ID（可选，启动指定主机的所有映射）',
                '--mapping' => '映射ID（可选，启动指定映射）',
            ],
            [],
            [
                '启动所有watcher' => 'php bin/w async:start',
                '启动指定主机的watcher' => 'php bin/w async:start --host=1',
                '启动指定映射的watcher' => 'php bin/w async:start --mapping=1',
            ]
        );
    }

    public function execute(array $args = [], array $data = []): void
    {
        $hostId = $data['host'] ?? null;
        $mappingId = $data['mapping'] ?? null;

        $this->printer->note('正在启动watcher...');
        $successCount = 0;
        $failCount = 0;

        // 先启动项目配置的watcher（如果存在）
        $configService = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Async\Service\ConfigService::class);
        if ($configService->hasProjectConfig()) {
            $this->printer->note('检测到项目配置文件 weline-async.json，启动项目同步...');
            $result = $this->watcherService->startProjectWatcher();
            
            if ($result['success']) {
                $this->printer->success("项目配置启动成功 (PID: {$result['pid']})");
                $successCount++;
            } else {
                $this->printer->error("项目配置启动失败: {$result['message']}");
                $failCount++;
            }
        }

        // 启动后台配置的映射
        $query = $this->syncMapping->clear()
            ->where(SyncMapping::schema_fields_STATUS, 1); // 只启动状态为开启的映射

        if ($mappingId) {
            $query->where(SyncMapping::schema_fields_MAPPING_ID, $mappingId);
        } elseif ($hostId) {
            $query->where(SyncMapping::schema_fields_HOST_ID, $hostId);
        }

        $mappings = $query->select()->fetch()->getItems();

        foreach ($mappings as $mapping) {
            $mappingId = $mapping->getId();
            $result = $this->watcherService->startWatcher($mappingId);
            
            if ($result['success']) {
                $this->printer->success("映射 #{$mappingId} 启动成功 (PID: {$result['pid']})");
                $successCount++;
            } else {
                $this->printer->error("映射 #{$mappingId} 启动失败: {$result['message']}");
                $failCount++;
            }
        }

        if ($successCount === 0 && $failCount === 0 && !$configService->hasProjectConfig()) {
            $this->printer->warning('没有找到需要启动的映射');
            return;
        }

        $this->printer->note("启动完成: 成功 {$successCount} 个, 失败 {$failCount} 个");
    }
}
