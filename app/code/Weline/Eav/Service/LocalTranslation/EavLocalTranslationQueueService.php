<?php

declare(strict_types=1);

namespace Weline\Eav\Service\LocalTranslation;

use Weline\Eav\Queue\EavLocalTranslationQueue;
use Weline\Queue\Service\IdempotentQueueAdmission;

final class EavLocalTranslationQueueService
{
    public const IDEMPOTENCY_SCOPE = 'eav_local_translation_slot';

    public function __construct(
        private readonly IdempotentQueueAdmission $admission,
    ) {
    }

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

        return $this->admission->admit([
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
            'biz_key' => $bizKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $bizKey,
            'auto' => true,
        ]);
    }

    public function enqueueContinuation(
        int $entityId,
        bool $includeOptions,
        int $offset,
        int $batchSize,
        string $requestedBy = 'queue',
    ): int {
        $entityId = max(0, $entityId);
        if ($entityId <= 0) {
            throw new \InvalidArgumentException(__('实体 ID 无效'));
        }
        $offset = max(0, $offset);
        $batchSize = max(1, min(100, $batchSize));
        $bizKey = $this->buildBizKey($entityId) . ':offset:' . $offset;

        return $this->admission->admit([
            'class' => EavLocalTranslationQueue::class,
            'name' => (string)__('EAV 属性多语言 AI 翻译（实体 #%{1}）', [$entityId]),
            'module' => 'Weline_Eav',
            'content' => [
                'entity_id' => $entityId,
                'include_options' => $includeOptions,
                'offset' => $offset,
                'batch_size' => $batchSize,
                'requested_by' => $requestedBy,
            ],
            'biz_key' => $bizKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $bizKey,
            'auto' => true,
        ]);
    }
}
