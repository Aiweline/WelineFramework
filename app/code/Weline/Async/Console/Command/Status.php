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

class Status extends CommandAbstract
{
    private WatcherService $watcherService;

    public function __construct()
    {
        $this->watcherService = ObjectManager::getInstance(WatcherService::class);
    }

    public function tip(): string
    {
        return '查看同步watcher状态';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'async:status',
            $this->tip(),
            [],
            [],
            [
                '查看所有watcher状态' => 'php bin/w async:status',
            ]
        );
    }

    public function execute(array $args = [], array $data = []): void
    {
        $status = $this->watcherService->getAllWatchersStatus();

        if (empty($status)) {
            $this->printer->warning('没有配置任何映射');
            return;
        }

        $this->printer->note('同步watcher状态:');
        $this->printer->print('');

        foreach ($status as $item) {
            $statusText = $item['status'] === 1 ? '开启' : '关闭';
            $runningText = $item['is_running'] ? '运行中' : '未运行';
            $pidText = $item['pid'] ? "(PID: {$item['pid']})" : '';

            $this->printer->print("映射 #{$item['mapping_id']}:");
            $this->printer->print("  本地路径: {$item['local_path']}");
            $this->printer->print("  远程路径: {$item['remote_path']}");
            $this->printer->print("  状态: {$statusText}");
            $this->printer->print("  运行状态: {$runningText} {$pidText}");
            $this->printer->print('');
        }
    }
}
