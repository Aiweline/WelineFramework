<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Runtime\Task;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerInvocation;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeTaskRunner;

/** Detached CLI entry point invoked only by ResumableTaskRunnerLauncher. */
final class Run extends CommandAbstract
{
    public function __construct(private readonly RuntimeTaskRunner $runner)
    {
    }

    public function execute(array $args = [], array $data = []): void
    {
        try {
            $invocation = RuntimeRunnerInvocation::fromArgs($args)->withCurrentProcessIdentity();
            $result = $this->runner->run($invocation);
            if ($result->status === 'failed') {
                $this->printer->error(__('可恢复任务 Runner 失败：%{1}', [$result->errorMessage]));
            }
        } catch (\Throwable $throwable) {
            $this->printer->error(__('可恢复任务 Runner 无法启动：%{1}', [mb_substr($throwable->getMessage(), 0, 512)]));
        }
    }

    public function tip(): string
    {
        return __('执行一个已预占 fencing 的可恢复后台任务 Runner');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'runtime:task:run --task-id=<id> --generation=<n> --runner-id=<id> --name=<name> --launch-id=<id>',
            $this->tip(),
            [],
            [],
            [],
        );
    }
}
