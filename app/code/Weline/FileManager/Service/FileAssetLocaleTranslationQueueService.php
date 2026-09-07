<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Queue\FileAssetLocaleAiTranslationQueue;
use Weline\Framework\Async\TaskStatus;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;
use Weline\Queue\Service\IdempotentQueueAdmission;

class FileAssetLocaleTranslationQueueService
{
    public const IDEMPOTENCY_SCOPE = 'filemanager_file_asset_locale_translation_slot';

    public function __construct(
        private readonly IdempotentQueueAdmission $admission,
    ) {
    }

    public function buildBizKey(): string
    {
        return 'filemanager:file_asset_locale_ai_translation';
    }

    public function enqueue(string $requestedBy = 'auto', bool $force = false): int
    {
        $bizKey = $this->buildBizKey();
        if (!$force) {
            // Continuation jobs use biz_key "base:offset:N". Exact getByBizKey(base)
            // misses them, so cron used to spawn a parallel offset=0 idle chain.
            $activeId = $this->findActiveFamilyQueueId($bizKey);
            if ($activeId > 0) {
                return $activeId;
            }
        }

        return $this->admission->admit([
            'class' => FileAssetLocaleAiTranslationQueue::class,
            'name' => (string)__('文件资源多语言 AI 补缺翻译'),
            'module' => 'Weline_FileManager',
            'content' => [
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

    public function enqueueContinuation(int $offset, int $batchSize = 20, string $requestedBy = 'continuation'): int
    {
        $offset = max(0, $offset);
        $batchSize = max(1, min(100, $batchSize));
        $bizKey = $this->buildBizKey() . ':offset:' . $offset;

        return $this->admission->admit([
            'class' => FileAssetLocaleAiTranslationQueue::class,
            'name' => (string)__('文件资源多语言 AI 补缺翻译'),
            'module' => 'Weline_FileManager',
            'content' => [
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

    /**
     * Any pending/running job in the base or offset continuation family.
     */
    public function findActiveFamilyQueueId(string $baseBizKey = ''): int
    {
        $baseBizKey = trim($baseBizKey !== '' ? $baseBizKey : $this->buildBizKey());
        if ($baseBizKey === '') {
            return 0;
        }

        $exact = $this->getLatestQueueByBizKey($baseBizKey);
        if ($exact && in_array((string)($exact['status'] ?? ''), [TaskStatus::PENDING, TaskStatus::RUNNING], true)) {
            return (int)($exact['queue_id'] ?? 0);
        }

        try {
            /** @var Queue $queue */
            $queue = ObjectManager::getInstance(Queue::class);
            $rows = $queue->clearData()->reset()
                ->where(Queue::schema_fields_BIZ_KEY, $baseBizKey . ':offset:%', 'LIKE')
                ->where(Queue::schema_fields_status, [TaskStatus::PENDING, TaskStatus::RUNNING], 'IN')
                ->order(Queue::schema_fields_ID, 'DESC')
                ->limit(1)
                ->select()
                ->fetchArray();
            $row = is_array($rows[0] ?? null) ? $rows[0] : [];

            return (int)($row[Queue::schema_fields_ID] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
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
