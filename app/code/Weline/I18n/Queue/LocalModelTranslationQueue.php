<?php

declare(strict_types=1);

namespace Weline\I18n\Queue;

use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationService;
use Weline\Queue\DeadWorkerRecoveryPatchQueueInterface;
use Weline\Queue\Model\Queue;

final class LocalModelTranslationQueue implements TaskConsumerInterface, DeadWorkerRecoveryPatchQueueInterface
{
    /**
     * Work-item scan batch. AI calls are grouped by locale inside processBatch
     * (AI_TEXT_CHUNK_SIZE); larger queue batches cut collect/scan overhead.
     */
    public const DEFAULT_BATCH_SIZE = 40;
    public const MAX_BATCH_SIZE = 50;
    private const MAX_DEAD_WORKER_RECOVERIES = 5;

    public function __construct(
        private readonly LocalModelTranslationService $translationService,
        private readonly LocalModelTranslationQueueService $queueService,
    ) {
    }

    public function name(): string
    {
        return 'LocalModel 多语言 AI 翻译';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return '自动发现并批量 AI 翻译所有继承 LocalModel 的业务多语言表';
    }

    public function validate(TaskContextInterface $task): bool
    {
        return true;
    }

    public function execute(TaskContextInterface $task): string
    {
        $content = $this->decodeContent($task);
        $offset = max(0, (int)($content['offset'] ?? 0));
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, (int)($content['batch_size'] ?? self::DEFAULT_BATCH_SIZE)));

        // Fetch batchSize+1 so we know whether more work remains without a full catalog scan.
        $probe = $this->translationService->collectWorkItems($offset, $batchSize + 1);
        $batch = array_slice($probe, 0, $batchSize);
        $result = $this->translationService->processBatch($batch);

        $abortedBusy = !empty($result['aborted_busy']);
        $aborted = !empty($result['aborted']);
        $skippedPersist = max(0, (int)($result['skipped_persist'] ?? 0));
        $consumed = max(0, (int)($result['consumed'] ?? (($abortedBusy || $aborted) ? 0 : count($batch))));
        $nextOffset = $offset + $consumed;
        $remainingInProbe = max(0, count($probe) - $consumed);
        $hasMore = $remainingInProbe > 0 || count($probe) > $batchSize;
        $nextQueueId = 0;
        // Busy/hard AI abort: stop this round — do not immediate-requeue; next cron continues when free.
        // Persist skips must NOT block continuation — otherwise poison rows pin offset forever.
        if ($hasMore && !$abortedBusy && !$aborted) {
            $nextQueueId = $this->queueService->enqueueContinuation(
                $nextOffset,
                $batchSize,
                (string)($content['requested_by'] ?? 'queue'),
            );
        }

        $message = (string)__(
            'LocalModel 多语言翻译批次完成：本批=%{processed}，翻译=%{translated}，剩余=%{remaining}',
            [
                'processed' => (string)($result['processed'] ?? 0),
                'translated' => (string)($result['translated'] ?? 0),
                'remaining' => ($hasMore || $abortedBusy || $aborted) ? '>' . (string)max(1, $remainingInProbe) : '0',
            ],
        );
        if ($abortedBusy) {
            $message .= PHP_EOL . (string)__('AI翻译繁忙或报错，已结束本批；不立刻续队，等待下一轮定时任务。');
        } elseif ($aborted) {
            $message .= PHP_EOL . (string)__('AI翻译失败，已结束本批；不立刻续队，等待下一轮定时任务。');
        }
        if ($skippedPersist > 0) {
            $message .= PHP_EOL . (string)__('保存失败已跳过 %{1} 条，继续后续条目。', [(string)$skippedPersist]);
        }

        if ($nextQueueId > 0) {
            $message .= PHP_EOL . (string)__('已创建下一批队列：#%{1}', [$nextQueueId]);
        }
        if (!empty($result['errors'])) {
            $message .= PHP_EOL . implode(PHP_EOL, array_map('strval', (array)$result['errors']));
        }

        return $message . PHP_EOL . 'QUEUE_DONE';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContent(TaskContextInterface $task): array
    {
        $content = $task->getContent();
        if (is_array($content)) {
            return $content;
        }

        $decoded = json_decode((string)$content, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function shouldRecoverDeadWorker(Queue $queue, int $deadPid, string $workerOutput): bool
    {
        $content = $this->decodeQueueContent($queue);
        $attempts = max(0, (int)($content['_dead_worker_retries'] ?? 0));

        return $attempts < self::MAX_DEAD_WORKER_RECOVERIES;
    }

    public function deadWorkerRecoveryPatch(Queue $queue, int $deadPid, string $workerOutput): array
    {
        if (!$this->shouldRecoverDeadWorker($queue, $deadPid, $workerOutput)) {
            return [];
        }

        $content = $this->decodeQueueContent($queue);
        $content['_dead_worker_retries'] = max(0, (int)($content['_dead_worker_retries'] ?? 0)) + 1;
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return [];
        }

        return [Queue::schema_fields_content => $encoded];
    }

    public function deadWorkerRecoveryMessage(Queue $queue, int $deadPid, string $workerOutput): string
    {
        $attempts = min(
            self::MAX_DEAD_WORKER_RECOVERIES,
            max(0, (int)($this->decodeQueueContent($queue)['_dead_worker_retries'] ?? 0)) + 1,
        );

        return (string)__('LocalModel 翻译进程异常退出，Scheduler 将自动恢复（第 %{1}/%{2} 次）。', [
            $attempts,
            self::MAX_DEAD_WORKER_RECOVERIES,
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeQueueContent(Queue $queue): array
    {
        $content = $queue->getContent();
        if (is_array($content)) {
            return $content;
        }

        $decoded = json_decode((string)$content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
