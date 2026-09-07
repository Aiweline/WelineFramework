<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\QueueStatus;
use Weline\Queue\Model\Queue;
use Weline\Queue\Service\QueueDispatchService;
use Weline\Search\Api\SearchProjectionQueueAdmissionInterface;
use Weline\Search\Queue\SearchIndexIncrementalQueue;

/**
 * Coalesces projection events onto one Queue slot per target+scope.
 *
 * Many ResourceChange events for the same product/store_product collapse into
 * a single pending row that always carries the newest event_seq. A single
 * follow-up slot covers the rare "primary already running" window.
 */
final class SearchProjectionQueueAdmission implements SearchProjectionQueueAdmissionInterface
{
    public const IDEMPOTENCY_SCOPE = 'search_product_projection_slot';

    public function __construct(
        private readonly QueueDispatchService $dispatch,
    ) {
    }

    public function admit(array $payload, ScopeIdentity $scope): void
    {
        $this->assertPayload($payload);
        $slotKey = $this->slotKey(
            $scope,
            (string)$payload['target_type'],
            (int)$payload['target_id'],
        );
        $this->admitSlot($slotKey, $payload, $scope, false);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function admitSlot(
        string $slotKey,
        array $payload,
        ScopeIdentity $scope,
        bool $isFollowUp,
    ): void {
        $created = \w_query('queue', 'createIfAbsent', [
            'class' => SearchIndexIncrementalQueue::class,
            'name' => $this->queueName($payload, $isFollowUp),
            'module' => 'Weline_Search',
            'content' => $payload,
            'status' => QueueStatus::PENDING,
            'auto' => true,
            'biz_key' => $slotKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $slotKey,
            'scope_envelope' => ScopeEnvelope::of($scope)->toArray(),
        ], 'backend');
        if (!\is_array($created)
            || empty($created['success'])
            || (int)($created['queue_id'] ?? 0) < 1
        ) {
            throw new \RuntimeException('search_incremental_queue_admission_failed');
        }
        if (!empty($created['created'])) {
            return;
        }

        $queueId = (int)$created['queue_id'];
        $status = (string)($created['status'] ?? '');
        $row = \is_array($created['data'] ?? null) ? $created['data'] : [];

        if ($status === Queue::status_pending) {
            $this->refreshPendingSlot($queueId, $payload, $row, $isFollowUp, $scope);
            return;
        }
        if ($status === Queue::status_running) {
            if ($isFollowUp) {
                // Class-level single-flight should keep follow-ups pending.
                // If a follow-up is somehow running, newest seq is already in flight.
                return;
            }
            $this->admitSlot($slotKey . ':followup', $payload, $scope, true);
            return;
        }

        $this->reopenTerminalSlot($queueId, $payload, $isFollowUp);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $row
     */
    private function refreshPendingSlot(
        int $queueId,
        array $payload,
        array $row,
        bool $isFollowUp,
        ScopeIdentity $scope,
    ): void {
        $existingSeq = $this->contentEventSeq($row[Queue::schema_fields_content] ?? null);
        $incomingSeq = (int)$payload['event_seq'];
        if ($existingSeq > $incomingSeq) {
            return;
        }

        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'content' => $payload,
            'name' => $this->queueName($payload, $isFollowUp),
        ], 'backend');
        if (\is_array($updated) && !empty($updated['success'])) {
            return;
        }

        $errorCode = \is_array($updated) ? (string)($updated['error_code'] ?? '') : '';
        if ($errorCode === 'queue_edit_active' || $errorCode === 'queue_state_changed') {
            if ($isFollowUp) {
                return;
            }
            $slotKey = (string)($row[Queue::schema_fields_BIZ_KEY] ?? '');
            if ($slotKey === '') {
                $slotKey = $this->slotKey(
                    $scope,
                    (string)$payload['target_type'],
                    (int)$payload['target_id'],
                );
            }
            $this->admitSlot($slotKey . ':followup', $payload, $scope, true);
            return;
        }

        throw new \RuntimeException('search_incremental_queue_coalesce_update_failed');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function reopenTerminalSlot(int $queueId, array $payload, bool $isFollowUp): void
    {
        $requeued = $this->dispatch->requeueQueueSafely($queueId);
        if (empty($requeued['confirmed'])) {
            throw new \RuntimeException(
                'search_incremental_queue_reopen_failed:'
                . (string)($requeued['error_code'] ?? 'unknown'),
            );
        }

        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'content' => $payload,
            'name' => $this->queueName($payload, $isFollowUp),
        ], 'backend');
        if (!\is_array($updated) || empty($updated['success'])) {
            throw new \RuntimeException('search_incremental_queue_reopen_update_failed');
        }

        $queue = ObjectManager::getInstance(Queue::class);
        $queue->clearData()->setData(\is_array($updated['data'] ?? null) ? $updated['data'] : []);
        if ((int)$queue->getId() < 1) {
            $queue->clearData()->clearQuery()
                ->where(Queue::schema_fields_ID, $queueId)
                ->find()
                ->fetch();
        }
        $this->dispatch->dispatchQueueIfEligible($queue);
    }

    public function slotKey(ScopeIdentity $scope, string $targetType, int $targetId): string
    {
        return 'slot:'
            . $targetType
            . ':'
            . $targetId
            . ':'
            . $scope->scopeKind
            . ':'
            . (string)$scope->websiteId
            . ':'
            . (string)$scope->websiteCode
            . ':'
            . (string)($scope->storeCode ?? '')
            . ':'
            . (string)($scope->storeMode ?? '');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function queueName(array $payload, bool $isFollowUp): string
    {
        $label = (string)__(
            'Search Product 投影 %{1}#%{2}',
            [(string)$payload['target_type'], (string)(int)$payload['target_id']],
        );

        return $isFollowUp ? $label . ' / followup' : $label;
    }

    private function contentEventSeq(mixed $rawContent): int
    {
        if (\is_array($rawContent)) {
            return (int)($rawContent['event_seq'] ?? 0);
        }
        if (!\is_string($rawContent) || $rawContent === '') {
            return 0;
        }
        try {
            $decoded = \json_decode($rawContent, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return 0;
        }

        return \is_array($decoded) ? (int)($decoded['event_seq'] ?? 0) : 0;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertPayload(array $payload): void
    {
        if (($payload['contract'] ?? null) !== SearchIndexIncrementalQueue::CONTRACT
            || \preg_match('/^[a-f0-9]{32}$/D', (string)($payload['event_id'] ?? '')) !== 1
            || (int)($payload['event_seq'] ?? 0) < 1
            || (int)($payload['target_id'] ?? 0) < 1
            || !\in_array((string)($payload['target_type'] ?? ''), ['product', 'store_product'], true)
        ) {
            throw new \InvalidArgumentException('search_projection_admission_payload_invalid');
        }
    }
}
