<?php

declare(strict_types=1);

namespace Weline\I18n\Service\LocalModelTranslation;

use Weline\Framework\Async\TaskStatus;
use Weline\I18n\Queue\LocalModelTranslationQueue;
use Weline\I18n\Service\AiTranslationConfig;

final class LocalModelTranslationQueueService
{
    public function __construct(
        private readonly AiTranslationConfig $translationConfig,
    ) {
    }

    public function buildBizKey(): string
    {
        return 'i18n:local_model_translation';
    }

    public function enqueue(string $requestedBy = 'auto', bool $force = false): int
    {
        if (!$this->translationConfig->isEnabled()) {
            return 0;
        }

        $bizKey = $this->buildBizKey();
        if (!$force) {
            $existing = $this->getLatestQueueByBizKey($bizKey);
            if ($existing && in_array((string)($existing['status'] ?? ''), [TaskStatus::PENDING, TaskStatus::RUNNING], true)) {
                return (int)($existing['queue_id'] ?? 0);
            }
        }

        $result = w_query('queue', 'create', [
            'class' => LocalModelTranslationQueue::class,
            'name' => (string)__('LocalModel 多语言 AI 翻译'),
            'module' => 'Weline_I18n',
            'content' => [
                'offset' => 0,
                'batch_size' => max(1, min(100, $this->translationConfig->getBatchSize())),
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
