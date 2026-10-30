<?php

declare(strict_types=1);

namespace Weline\Product\Queue;

use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;
use Weline\Product\Service\Ai\ProductAiRunner;

/**
 * Executes one Product AI optimization job per durable queue slot.
 *
 * The queue row only carries the job id; all job state lives in
 * `weline_product_ai_job`, and the runner already records failures on that row
 * instead of throwing, so a provider error never leaves the queue stuck.
 */
final class ProductAiOptimizeConsumer implements TaskConsumerInterface
{
    private const JOB_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly ProductAiRunner $runner,
    ) {
    }

    public function name(): string
    {
        return (string)__('商品 AI 优化');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('执行商品 AI 优化任务，生成待审核的文案或主图草稿');
    }

    public function validate(TaskContextInterface $task): bool
    {
        if ($this->jobId($task) === '') {
            $task->setResult((string)__('验证失败：缺少有效的 job_id。'));

            return false;
        }

        return true;
    }

    public function execute(TaskContextInterface $task): string
    {
        $jobId = $this->jobId($task);
        if ($jobId === '') {
            return (string)__('缺少有效的 job_id，任务未执行。') . PHP_EOL . 'QUEUE_DONE';
        }

        $result = $this->runner->run($jobId);
        $status = (string)($result['status'] ?? '');
        if (!empty($result['skipped'])) {
            return (string)__(
                '任务状态已变化，跳过执行：任务=%{1}，状态=%{2}',
                [$jobId, $status],
            ) . PHP_EOL . 'QUEUE_DONE';
        }
        if ($status !== 'succeeded') {
            return (string)__(
                '商品 AI 优化失败：任务=%{1}，错误码=%{2}',
                [$jobId, (string)($result['error_code'] ?? 'unknown')],
            ) . PHP_EOL . 'QUEUE_DONE';
        }

        return (string)__(
            '商品 AI 优化完成：任务=%{1}，草稿=%{2}',
            [$jobId, (string)(int)($result['drafts'] ?? 0)],
        ) . PHP_EOL . 'QUEUE_DONE';
    }

    private function jobId(TaskContextInterface $task): string
    {
        $content = $task->getContent();
        $decoded = \is_array($content) ? $content : \json_decode((string)$content, true);
        if (!\is_array($decoded)) {
            return '';
        }
        $jobId = \strtolower(\trim((string)($decoded['job_id'] ?? '')));

        return \preg_match(self::JOB_ID_PATTERN, $jobId) === 1 ? $jobId : '';
    }
}
