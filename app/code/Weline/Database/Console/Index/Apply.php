<?php

declare(strict_types=1);

namespace Weline\Database\Console\Index;

use Weline\Database\Service\Index\IndexApplier;
use Weline\Database\Service\Index\SchemaIndexJobService;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

final class Apply implements CommandInterface
{
    public function __construct(
        private readonly IndexApplier $applier,
        private readonly SchemaIndexJobService $jobService,
        private readonly Printing $printing,
    ) {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $jobId = (int)($args['job'] ?? 0);
        if ($jobId < 1 && !empty($args['pending'])) {
            $job = $this->jobService->nextPendingJob();
            $jobId = (int)($job['job_id'] ?? 0);
        }
        if ($jobId < 1) {
            throw new \InvalidArgumentException(__('请指定 --job=<job_id> 或 --pending'));
        }

        $result = $this->applier->apply($jobId);
        $message = (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->printing->info($message);

        return $message;
    }

    public function tip(): string
    {
        return __('执行异步 schema 索引任务（默认 CREATE INDEX CONCURRENTLY）。');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('index:apply', $this->tip(), [
            '--job' => __('索引任务 ID'),
            '--pending' => __('取下一条待索引任务执行'),
        ], [
            'php bin/w index:apply --job=123',
            'php bin/w index:apply --pending',
        ], []);
    }
}
