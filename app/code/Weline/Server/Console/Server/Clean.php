<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:clean - 清理已无运行进程但仍残留实例文件的 WLS 实例记录
 */
class Clean extends CommandAbstract
{
    public function __construct(
        private readonly ServerInstanceManager $instanceManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): void
    {
        $this->printer->setup(__('清理未运行的 WLS 实例记录'));

        $cleanedNames = $this->instanceManager->cleanupInactiveInstances();
        $cleaned = \count($cleanedNames);

        if ($cleaned === 0) {
            $this->printer->info(__('未发现需要清理的未运行实例记录。'));
            $this->printer->note(__('可使用 server:status 或 server:listing 查看当前实例状态。'));
            return;
        }

        $this->printer->success(__('已清理 %{1} 个未运行实例记录。', [$cleaned]));
        if ($cleanedNames !== []) {
            $this->printer->note(__('已处理实例：%{1}', [\implode(', ', $cleanedNames)]));
        }
        $this->printer->note(__('可使用 server:status 或 server:listing 查看清理后的实例状态。'));
    }

    public function tip(): string
    {
        return __('清理已无运行进程但仍残留实例文件的 WLS 实例记录');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:clean',
            __('清理那些已经没有运行但仍占着实例文件的 WLS 实例记录'),
            [
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('清理未运行实例记录') => 'php bin/w server:clean',
                __('清理后查看实例状态') => 'php bin/w server:status --all',
            ]
        );
    }
}
