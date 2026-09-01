<?php

declare(strict_types=1);

namespace Weline\I18n\Queue;

use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationService;

final class LocalModelTranslationQueue implements TaskConsumerInterface
{
    private const DEFAULT_BATCH_SIZE = 20;

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
        $batchSize = max(1, min(100, (int)($content['batch_size'] ?? self::DEFAULT_BATCH_SIZE)));

        $allItems = $this->translationService->collectWorkItems();
        $batch = array_slice($allItems, $offset, $batchSize);
        $result = $this->translationService->processBatch($batch);

        $nextOffset = $offset + count($batch);
        $remaining = max(0, count($allItems) - $nextOffset);
        $nextQueueId = 0;
        if ($remaining > 0) {
            $nextQueueId = $this->enqueueContinuation($nextOffset, $batchSize, $content);
        }

        $message = (string)__(
            'LocalModel 多语言翻译批次完成：本批=%{processed}，翻译=%{translated}，剩余=%{remaining}',
            [
                'processed' => (string)($result['processed'] ?? 0),
                'translated' => (string)($result['translated'] ?? 0),
                'remaining' => (string)$remaining,
            ],
        );

        if ($nextQueueId > 0) {
            $message .= PHP_EOL . (string)__('已创建下一批队列：#%{1}', [$nextQueueId]);
        }
        if (!empty($result['errors'])) {
            $message .= PHP_EOL . implode(PHP_EOL, array_map('strval', (array)$result['errors']));
        }

        return $message . PHP_EOL . 'QUEUE_DONE';
    }

    /**
     * @param array<string, mixed> $content
     */
    private function enqueueContinuation(int $offset, int $batchSize, array $content): int
    {
        $result = w_query('queue', 'create', [
            'class' => self::class,
            'name' => (string)__('LocalModel 多语言 AI 翻译'),
            'module' => 'Weline_I18n',
            'content' => [
                'offset' => $offset,
                'batch_size' => $batchSize,
                'requested_by' => (string)($content['requested_by'] ?? 'queue'),
            ],
            'status' => 'pending',
            'auto' => true,
            'biz_key' => $this->queueService->buildBizKey() . ':offset:' . $offset,
        ]);

        if (is_array($result)) {
            return (int)($result['queue_id'] ?? $result['id'] ?? 0);
        }
        if (is_object($result) && method_exists($result, 'getData')) {
            return (int)($result->getData('queue_id') ?? 0);
        }

        return 0;
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
}
