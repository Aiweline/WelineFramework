<?php

declare(strict_types=1);

namespace Weline\Database\Queue;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Service\Index\IndexApplier;
use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Queue consumer for one schema-index job. Long CREATE INDEX CONCURRENTLY
 * work belongs here, never inside the setup request or the cron scheduler.
 */
final class SchemaIndexApplyQueue implements TaskConsumerInterface
{
    public function __construct(
        private readonly IndexApplier $applier,
    ) {
    }

    public function name(): string
    {
        return '异步索引应用队列';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return '执行 schema 索引任务（CREATE INDEX CONCURRENTLY）。';
    }

    public function validate(TaskContextInterface $task): bool
    {
        $content = $this->decodeContent($task);
        $jobId = (int)($content['job_id'] ?? 0);
        if ($jobId < 1) {
            $task->setResult((string)__('验证失败：缺少 job_id。'));

            return false;
        }

        $job = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->find()
            ->fetch();
        if (!$job->getId()) {
            $task->setResult((string)__('验证失败：索引任务 #%{1} 不存在。', [$jobId]));

            return false;
        }
        if (in_array((string)$job->getData(SchemaIndexJob::schema_fields_STATUS), [
            SchemaIndexJob::STATUS_DONE,
            SchemaIndexJob::STATUS_CANCELED,
            SchemaIndexJob::STATUS_SKIPPED,
        ], true)) {
            $task->setResult((string)__('索引任务 #%{1} 已结束，跳过。', [$jobId]));

            return false;
        }

        return true;
    }

    public function execute(TaskContextInterface $queue): string
    {
        $content = $this->decodeContent($queue);
        $jobId = (int)($content['job_id'] ?? 0);
        $result = $this->applier->apply($jobId);

        $message = (string)__(
            '索引任务 #%{1}：%{2}（%{3}ms）%{4}',
            [
                (string)$jobId,
                (string)$result['status'],
                (string)$result['duration_ms'],
                (string)$result['message'],
            ],
        );

        return $message . PHP_EOL . 'QUEUE_DONE';
    }

    /** @return array<string,mixed> */
    private function decodeContent(TaskContextInterface $task): array
    {
        $content = $task->getContent();
        if (is_array($content)) {
            return $content;
        }
        $decoded = json_decode((string)$content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
