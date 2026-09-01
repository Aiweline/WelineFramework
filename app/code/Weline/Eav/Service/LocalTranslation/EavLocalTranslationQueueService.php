<?php

declare(strict_types=1);

namespace Weline\Eav\Service\LocalTranslation;

use Weline\Eav\Queue\EavLocalTranslationQueue;
use Weline\Framework\Async\TaskStatus;

final class EavLocalTranslationQueueService
{
    public function buildBizKey(int $entityId): string
    {
        return 'eav.local_translation:' . max(0, $entityId);
    }

    public function enqueue(int $entityId, bool $includeOptions = true, string $requestedBy = 'manual'): int
    {
        $entityId = max(0, $entityId);
        if ($entityId <= 0) {
            throw new \InvalidArgumentException(__('实体 ID 无效'));
        }

        $bizKey = $this->buildBizKey($entityId);
        $existing = $this->getLatestQueueByBizKey($bizKey);
        if ($existing && in_array((string)($existing['status'] ?? ''), [TaskStatus::PENDING, TaskStatus::RUNNING], true)) {
            return (int)($existing['queue_id'] ?? 0);
        }

        $result = w_query('queue', 'create', [
            'class' => EavLocalTranslationQueue::class,
            'name' => (string)__('EAV 属性多语言 AI 翻译（实体 #%{1}）', [$entityId]),
            'module' => 'Weline_Eav',
            'content' => [
                'entity_id' => $entityId,
                'include_options' => $includeOptions,
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

    /**
     * @return array<string, mixed>|null
     */
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
