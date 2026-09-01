<?php

declare(strict_types=1);

namespace Weline\Eav\Queue;

use Weline\Eav\Service\LocalTranslation\EavLocalTranslationQueueService;
use Weline\Eav\Service\LocalTranslation\EavLocalTranslationService;
use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;

final class EavLocalTranslationQueue implements TaskConsumerInterface
{
    private const DEFAULT_BATCH_SIZE = 20;

    public function __construct(
        private readonly EavLocalTranslationService $translationService,
        private readonly EavLocalTranslationQueueService $queueService,
    ) {
    }

    public function name(): string
    {
        return 'EAV 属性多语言 AI 翻译';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return '批量 AI 翻译 EAV 实体下的属性集/组/属性/选项 LocalDescription';
    }

    public function validate(TaskContextInterface $task): bool
    {
        $content = $this->decodeContent($task);
        if ((int)($content['entity_id'] ?? 0) <= 0) {
            $task->setResult((string)__('验证失败：缺少 entity_id。'));
            return false;
        }

        return true;
    }

    public function execute(TaskContextInterface $task): string
    {
        $content = $this->decodeContent($task);
        $entityId = (int)($content['entity_id'] ?? 0);
        $includeOptions = !array_key_exists('include_options', $content) || !empty($content['include_options']);
        $offset = max(0, (int)($content['offset'] ?? 0));
        $batchSize = max(1, min(100, (int)($content['batch_size'] ?? self::DEFAULT_BATCH_SIZE)));

        $allItems = $this->translationService->collectEntityWorkItems($entityId, $includeOptions);
        $batch = array_slice($allItems, $offset, $batchSize);
        $result = $this->translationService->processBatch($batch);

        $nextOffset = $offset + count($batch);
        $remaining = max(0, count($allItems) - $nextOffset);
        $nextQueueId = 0;
        if ($remaining > 0) {
            $nextQueueId = $this->enqueueContinuation($entityId, $includeOptions, $nextOffset, $batchSize, $content);
        }

        $message = (string)__(
            'EAV 多语言翻译批次完成：实体=%{entity}，本批=%{processed}，翻译=%{translated}，剩余=%{remaining}',
            [
                'entity' => (string)$entityId,
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
    private function enqueueContinuation(
        int $entityId,
        bool $includeOptions,
        int $offset,
        int $batchSize,
        array $content,
    ): int {
        $result = w_query('queue', 'create', [
            'class' => self::class,
            'name' => (string)__('EAV 属性多语言 AI 翻译（实体 #%{1}）', [$entityId]),
            'module' => 'Weline_Eav',
            'content' => [
                'entity_id' => $entityId,
                'include_options' => $includeOptions,
                'offset' => $offset,
                'batch_size' => $batchSize,
                'requested_by' => (string)($content['requested_by'] ?? 'queue'),
            ],
            'status' => 'pending',
            'auto' => true,
            'biz_key' => $this->queueService->buildBizKey($entityId) . ':offset:' . $offset,
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
