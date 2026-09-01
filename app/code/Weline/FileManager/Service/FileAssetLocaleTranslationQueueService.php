<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Queue\FileAssetLocaleAiTranslationQueue;
use Weline\Framework\Async\TaskStatus;

class FileAssetLocaleTranslationQueueService
{
    public function buildBizKey(): string
    {
        return 'filemanager:file_asset_locale_ai_translation';
    }

    public function enqueue(string $requestedBy = 'auto', bool $force = false): int
    {
        $bizKey = $this->buildBizKey();
        if (!$force) {
            $existing = $this->getLatestQueueByBizKey($bizKey);
            if ($existing && in_array((string)($existing['status'] ?? ''), [TaskStatus::PENDING, TaskStatus::RUNNING], true)) {
                return (int)($existing['queue_id'] ?? 0);
            }
        }

        $result = w_query('queue', 'create', [
            'class' => FileAssetLocaleAiTranslationQueue::class,
            'name' => (string)__('文件资源多语言 AI 补缺翻译'),
            'module' => 'Weline_FileManager',
            'content' => [
                'offset' => 0,
                'batch_size' => 20,
                'requested_by' => $requestedBy,
            ],
            'status' => TaskStatus::PENDING,
            'auto' => true,
            'biz_key' => $bizKey,
        ]);

        if (is_array($result)) {
            return (int)($result['queue_id'] ?? $result['id'] ?? 0);
        }
        if (is_object($result) && method_exists($result, 'getData')) {
            return (int)($result->getData('queue_id') ?? 0);
        }

        return 0;
    }

    /** @return array<string,mixed>|null */
    private function getLatestQueueByBizKey(string $bizKey): ?array
    {
        try {
            $row = w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }
}
