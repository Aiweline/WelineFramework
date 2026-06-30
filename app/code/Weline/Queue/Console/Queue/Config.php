<?php

declare(strict_types=1);

namespace Weline\Queue\Console\Queue;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Queue\Service\QueueDispatchService;

class Config implements CommandInterface
{
    public function __construct(
        private readonly QueueDispatchService $queueDispatchService,
        private readonly Printing $printing
    ) {
    }

    public function execute(array $args = [], array $data = [])
    {
        $value = $this->resolveMaxConcurrentArgument($args, $data);
        if ($value === null) {
            $this->printCurrentConfig();
            return;
        }

        $maxConcurrent = $this->normalizeMaxConcurrent($value);
        if ($maxConcurrent === null) {
            $this->printing->error(__('队列并发数必须是大于 0 的整数。'));
            return;
        }

        Env::set('queue.cron.max_concurrent', $maxConcurrent);
        $this->printing->success(__('队列并发数已更新为 %{1}', [$maxConcurrent]));
        $this->printCurrentConfig();
    }

    public function tip(): string
    {
        return '查看或设置系统队列调度并发数。';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'queue:config',
            $this->tip(),
            [
                '--max-concurrent <n>' => '设置系统调度最多同时运行的自动队列数',
                '-m <n>' => '同 --max-concurrent',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '查看当前配置' => 'php bin/w queue:config',
                '设置并发数' => 'php bin/w queue:config --max-concurrent=4',
            ]
        );
    }

    private function printCurrentConfig(): void
    {
        $this->printing->note('queue.cron.max_concurrent = ' . $this->queueDispatchService->getMaxConcurrent());
        $this->printing->note('queue.worker.memory_limit = ' . $this->queueDispatchService->getWorkerMemoryLimit());
    }

    private function resolveMaxConcurrentArgument(array $args, array $data): mixed
    {
        foreach (['max-concurrent', 'max_concurrent', 'm'] as $key) {
            if (isset($data[$key])) {
                return $data[$key];
            }
            if (isset($args[$key])) {
                return $args[$key];
            }
        }

        foreach ($args as $index => $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if (\preg_match('/^--max(?:-|_)concurrent=(.+)$/', $arg, $matches)) {
                return $matches[1];
            }
            if (($arg === '--max-concurrent' || $arg === '--max_concurrent' || $arg === '-m') && isset($args[$index + 1])) {
                return $args[$index + 1];
            }
        }

        return null;
    }

    private function normalizeMaxConcurrent(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }
        $value = \trim((string)$value);
        if (!\preg_match('/^[1-9]\d*$/', $value)) {
            return null;
        }

        return (int)$value;
    }
}
