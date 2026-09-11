<?php

declare(strict_types=1);

namespace Weline\I18n\Service\LocalModelTranslation;

use Weline\Framework\Async\TaskStatus;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Queue\LocalModelTranslationQueue;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\Queue\Model\Queue;
use Weline\Queue\Service\IdempotentQueueAdmission;

final class LocalModelTranslationQueueService
{
    public const IDEMPOTENCY_SCOPE = 'i18n_local_model_translation_slot';

    public function __construct(
        private readonly AiTranslationConfig $translationConfig,
        private readonly IdempotentQueueAdmission $admission,
        private readonly LocalModelTranslationService $localModelTranslationService,
    ) {
    }

    public function buildBizKey(): string
    {
        return 'i18n:local_model_translation';
    }

    public function enqueue(string $requestedBy = 'auto', bool $force = false): int
    {
        if (!$this->translationConfig->isEnabled()
            || $this->translationConfig->getEnabledLocaleCodes() === []
        ) {
            return 0;
        }

        $bizKey = $this->buildBizKey();
        if (!$force) {
            // Continuations use "base:offset:N". Exact getByBizKey(base)
            // must not miss them or cron will spawn parallel offset=0 chains.
            // Check active family before collectWorkItems to avoid expensive LocalModel scans.
            $activeId = $this->findActiveFamilyQueueId($bizKey);
            if ($activeId > 0) {
                return $activeId;
            }
        }

        // Anti-starve: do not occupy a LocalModel queue/worker slot when nothing is pending.
        // An empty runner must not burn the model channel while dictionary/Meta still have work.
        if ($this->localModelTranslationService->collectWorkItems(0, 1) === []) {
            return 0;
        }

        return $this->admission->admit([
            'class' => LocalModelTranslationQueue::class,
            'name' => 'LocalModel 多语言 AI 翻译',
            'module' => 'Weline_I18n',
            'content' => [
                'offset' => 0,
                'batch_size' => LocalModelTranslationQueue::DEFAULT_BATCH_SIZE,
                'requested_by' => $requestedBy,
            ],
            'biz_key' => $bizKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $bizKey,
            'auto' => true,
        ]);
    }

    public function enqueueContinuation(int $offset, int $batchSize, string $requestedBy = 'continuation'): int
    {
        $offset = max(0, $offset);
        $batchSize = max(1, min(LocalModelTranslationQueue::MAX_BATCH_SIZE, $batchSize));
        $bizKey = $this->buildBizKey() . ':offset:' . $offset;

        return $this->admission->admit([
            'class' => LocalModelTranslationQueue::class,
            'name' => 'LocalModel 多语言 AI 翻译',
            'module' => 'Weline_I18n',
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

    public function findActiveFamilyQueueId(string $baseBizKey = ''): int
    {
        $baseBizKey = trim($baseBizKey !== '' ? $baseBizKey : $this->buildBizKey());
        if ($baseBizKey === '') {
            return 0;
        }

        $exact = $this->getLatestQueueByBizKey($baseBizKey);
        if ($exact && $this->isLiveActiveQueueRow($exact)) {
            return (int)($exact['queue_id'] ?? 0);
        }

        try {
            /** @var Queue $queue */
            $queue = ObjectManager::getInstance(Queue::class);
            $rows = $queue->clearData()->reset()
                ->where(Queue::schema_fields_BIZ_KEY, $baseBizKey . ':offset:%', 'LIKE')
                ->where(Queue::schema_fields_status, [TaskStatus::PENDING, TaskStatus::RUNNING], 'IN')
                ->order(Queue::schema_fields_ID, 'DESC')
                ->limit(5)
                ->select()
                ->fetchArray();
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row) || !$this->isLiveActiveQueueRow($row)) {
                    continue;
                }

                return (int)($row[Queue::schema_fields_ID] ?? 0);
            }

            return 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Pending is always active. Running counts only while the worker PID is still alive;
     * dead-PID "running" rows must not block cron/EAV re-enqueue (reconcile may lag).
     *
     * @param array<string, mixed> $row
     */
    private function isLiveActiveQueueRow(array $row): bool
    {
        $status = (string)($row['status'] ?? '');
        if ($status === TaskStatus::PENDING) {
            return true;
        }
        if ($status !== TaskStatus::RUNNING) {
            return false;
        }

        $pid = (int)($row[Queue::schema_fields_pid] ?? $row['pid'] ?? 0);
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }

        // Best-effort fallback when posix is unavailable.
        $out = [];
        $code = 1;
        @\exec('ps -p ' . $pid . ' -o pid=', $out, $code);

        return $code === 0 && $out !== [];
    }

    /**
     * Latest LocalModel queue row for admin progress (base or newest offset continuation).
     *
     * @return array<string, mixed>|null
     */
    public function getLatestFamilyQueue(string $baseBizKey = ''): ?array
    {
        $baseBizKey = trim($baseBizKey !== '' ? $baseBizKey : $this->buildBizKey());
        if ($baseBizKey === '') {
            return null;
        }

        $exact = $this->getLatestQueueByBizKey($baseBizKey);
        $best = $exact;
        $bestId = is_array($exact) ? (int)($exact['queue_id'] ?? $exact['id'] ?? 0) : 0;

        try {
            /** @var Queue $queue */
            $queue = ObjectManager::getInstance(Queue::class);
            $rows = $queue->clearData()->reset()
                ->where(Queue::schema_fields_BIZ_KEY, $baseBizKey . '%', 'LIKE')
                ->order(Queue::schema_fields_ID, 'DESC')
                ->limit(8)
                ->select()
                ->fetchArray();
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row[Queue::schema_fields_ID] ?? 0);
                if ($id > $bestId) {
                    $bestId = $id;
                    $best = $row;
                    $best['queue_id'] = $id;
                }
            }
        } catch (\Throwable) {
        }

        return is_array($best) ? $best : null;
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
