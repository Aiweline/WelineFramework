<?php
declare(strict_types=1);

namespace Weline\Queue\Console\Queue;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Printing;
use Weline\Queue\Service\QueueResultLogCleanupService;

class CleanupResultLogs implements CommandInterface
{
    public function __construct(
        private readonly Printing $printing,
        private readonly QueueResultLogCleanupService $cleanupService,
    ) {
    }

    public function execute(array $args = [], array $data = [])
    {
        $maxBytes = QueueResultLogCleanupService::DEFAULT_MAX_BYTES;
        foreach ($args as $arg) {
            $arg = (string)$arg;
            if (str_starts_with($arg, '--max-bytes=')) {
                $maxBytes = max(256, (int)substr($arg, strlen('--max-bytes=')));
            }
        }

        $result = $this->cleanupService->cleanupOversized($maxBytes);
        $this->printing->success(
            (string)__(
                '已扫描 %{1} 条队列，截断超大 result/process %{2} 条（上限 %{3} 字节）。',
                [
                    (string)$result['scanned'],
                    (string)$result['updated'],
                    (string)$result['max_bytes'],
                ]
            ),
            '系统队列'
        );
        if ($result['ids'] !== []) {
            $preview = implode(', ', array_map('strval', array_slice($result['ids'], 0, 20)));
            if (count($result['ids']) > 20) {
                $preview .= ', …';
            }
            $this->printing->note((string)__('涉及 queue_id：%{1}', [$preview]), '系统队列');
        }
    }

    public function tip(): string
    {
        return '清理超大 queue.result / process 日志，仅保留短过程摘要';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'queue:cleanup-result-logs',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '--max-bytes=N' => '过程摘要最大字节（默认 8192）',
            ],
            [],
            []
        );
    }
}
