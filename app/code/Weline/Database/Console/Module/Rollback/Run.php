<?php

declare(strict_types=1);

namespace Weline\Database\Console\Module\Rollback;

use Weline\Database\Service\ModuleRollbackExecutor;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

final class Run implements CommandInterface
{
    public function __construct(
        private readonly ModuleRollbackExecutor $executor,
        private readonly Printing $printing,
    ) {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $operationId = trim((string)($args['operation-id'] ?? ''));
        if ($operationId === '') {
            throw new \InvalidArgumentException(__('请指定 --operation-id'));
        }
        $result = !empty($args['recover'])
            ? $this->executor->recover($operationId)
            : $this->executor->execute($operationId);
        $message = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->printing->info((string)$message);
        return (string)$message;
    }

    public function tip(): string
    {
        return __('执行持久化模块代码与数据库联动回滚任务');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('module:rollback:run', $this->tip(), [
            '--operation-id' => __('回滚任务 ID'),
            '--recover' => __('重试人工恢复补偿'),
        ], [
            'php bin/w module:rollback:run --operation-id=rollback-20260713-xxxx',
            'php bin/w module:rollback:run --operation-id=rollback-20260713-xxxx --recover',
        ], []);
    }
}
