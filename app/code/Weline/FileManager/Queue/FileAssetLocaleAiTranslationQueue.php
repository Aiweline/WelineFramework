<?php

declare(strict_types=1);

namespace Weline\FileManager\Queue;

use Weline\FileManager\Api\FileAssetLocaleTranslationInterface;
use Weline\FileManager\Service\FileAssetLocaleTranslationQueueService;
use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;

final class FileAssetLocaleAiTranslationQueue implements TaskConsumerInterface
{
    private const DEFAULT_BATCH_SIZE = 20;

    public function __construct(
        private readonly FileAssetLocaleTranslationInterface $translations,
        private readonly FileAssetLocaleTranslationQueueService $queueService,
    ) {
    }

    public function name(): string
    {
        return '文件资源多语言 AI 补缺翻译';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return '按批为 FileAssetLocale 补缺翻译；已有文案的语言跳过。';
    }

    public function validate(TaskContextInterface $task): bool
    {
        return true;
    }

    public function execute(TaskContextInterface $task): string
    {
        $content = $task->getContent();
        if (!is_array($content)) {
            $content = [];
        }
        $offset = max(0, (int)($content['offset'] ?? 0));
        $batchSize = max(1, min(100, (int)($content['batch_size'] ?? self::DEFAULT_BATCH_SIZE)));
        $result = $this->translations->processPendingBatch($offset, $batchSize);
        $lines = [
            (string)__('processed=%{1}', [$result['processed']]),
            (string)__('filled=%{1}', [$result['filled']]),
            (string)__('skipped=%{1}', [$result['skipped']]),
        ];
        if ($result['errors'] !== []) {
            $lines[] = (string)__('errors=%{1}', [implode('; ', array_slice($result['errors'], 0, 5))]);
        }
        if (!empty($result['continuation'])) {
            $this->queueService->enqueue('continuation', true);
            $lines[] = (string)__('已续队下一批');
        }

        return implode(', ', $lines);
    }
}
