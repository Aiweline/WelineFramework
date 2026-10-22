<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\QueueStatus;
use Weline\Queue\Model\Queue;
use Weline\Queue\Service\QueueDispatchService;
use Weline\Search\Queue\SearchProviderIndexQueue;

/**
 * One pending slot per indexer + website + entity. Newer revision replaces the slot.
 */
final class SearchProviderIndexAdmission
{
    public const IDEMPOTENCY_SCOPE = 'search_provider_index_slot';

    public function __construct(
        private readonly QueueDispatchService $dispatch,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function admit(array $payload): void
    {
        $this->assertPayload($payload);
        $slotKey = $this->slotKey($payload);
        $this->admitSlot($slotKey, $payload, false);
    }

    /** @param array<string,mixed> $payload */
    private function admitSlot(string $slotKey, array $payload, bool $isFollowUp, int $attempt = 0): void
    {
        $websiteId = max(0, (int)$payload['website_id']);
        $created = \w_query('queue', 'createIfAbsent', [
            'class' => SearchProviderIndexQueue::class,
            'name' => $this->queueName($payload, $isFollowUp),
            'module' => 'Weline_Search',
            'content' => $payload,
            'status' => QueueStatus::PENDING,
            'auto' => true,
            'biz_key' => $slotKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $slotKey,
            'scope_envelope' => ScopeEnvelope::of(ScopeIdentity::websiteById($websiteId))->toArray(),
        ], 'backend');
        if (!is_array($created) || empty($created['success']) || (int)($created['queue_id'] ?? 0) < 1) {
            throw new \RuntimeException('provider_index_queue_admission_failed');
        }
        if (!empty($created['created'])) {
            return;
        }

        $queueId = (int)$created['queue_id'];
        $status = (string)($created['status'] ?? '');
        $row = is_array($created['data'] ?? null) ? $created['data'] : [];
        if ($status === Queue::status_pending) {
            $this->refreshPending($queueId, $payload, $row, $isFollowUp, $attempt);
            return;
        }
        if ($status === Queue::status_running) {
            if (!$isFollowUp) {
                $this->admitSlot($slotKey . ':followup', $payload, true);
            }
            return;
        }
        if (in_array($status, [Queue::status_done, Queue::status_error, Queue::status_stop], true)) {
            $this->reopen($queueId, $payload, $isFollowUp);
        }
    }

    /** @param array<string,mixed> $payload
     *  @param array<string,mixed> $row
     */
    private function refreshPending(int $queueId, array $payload, array $row, bool $isFollowUp, int $attempt): void
    {
        $raw = $row[Queue::schema_fields_content] ?? null;
        $previous = is_array($raw) ? $raw : json_decode((string)$raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($previous)) {
            throw new \RuntimeException('provider_index_slot_content_invalid');
        }
        if ((int)($previous['revision'] ?? 0) > (int)$payload['revision']) {
            return;
        }
        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'content' => $payload,
                'name' => $this->queueName($payload, $isFollowUp),
            ],
            'expected_content' => is_array($raw)
                ? (string)json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : (string)$raw,
        ], 'backend');
        if (is_array($updated) && !empty($updated['success'])) {
            return;
        }
        $errorCode = is_array($updated) ? (string)($updated['error_code'] ?? '') : '';
        if ($errorCode === 'queue_content_changed' && $attempt < 7) {
            $this->admitSlot((string)($row[Queue::schema_fields_BIZ_KEY] ?? ''), $payload, $isFollowUp, $attempt + 1);
            return;
        }
        if (in_array($errorCode, ['queue_edit_active', 'queue_state_changed'], true)) {
            return;
        }
        throw new \RuntimeException('provider_index_queue_coalesce_failed');
    }

    /** @param array<string,mixed> $payload */
    private function reopen(int $queueId, array $payload, bool $isFollowUp): void
    {
        $requeued = $this->dispatch->requeueQueueSafely($queueId);
        if (empty($requeued['confirmed'])) {
            throw new \RuntimeException('provider_index_queue_reopen_failed');
        }
        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'content' => $payload,
            'name' => $this->queueName($payload, $isFollowUp),
        ], 'backend');
        if (!is_array($updated) || empty($updated['success'])) {
            throw new \RuntimeException('provider_index_queue_reopen_update_failed');
        }
        \w_query('queue', 'dispatch', ['queue_id' => $queueId], 'backend');
    }

    /** @param array<string,mixed> $payload */
    private function slotKey(array $payload): string
    {
        $entity = (string)($payload['op'] === 'rebuild' ? '*' : $payload['entity_id']);

        return 'provider:'
            . (string)$payload['indexer']
            . ':'
            . (string)(int)$payload['website_id']
            . ':'
            . $entity;
    }

    /** @param array<string,mixed> $payload */
    private function queueName(array $payload, bool $isFollowUp): string
    {
        $label = (string)__(
            'Search 内容索引 %{1} %{2}',
            [(string)$payload['indexer'], (string)($payload['entity_id'] ?? '*')],
        );

        return $isFollowUp ? $label . ' / followup' : $label;
    }

    /** @param array<string,mixed> $payload */
    private function assertPayload(array $payload): void
    {
        if (($payload['contract'] ?? null) !== SearchProviderIndexQueue::CONTRACT) {
            throw new \InvalidArgumentException('provider_index_admission_payload_invalid');
        }
        if (!in_array((string)($payload['op'] ?? ''), ['upsert', 'delete', 'rebuild'], true)) {
            throw new \InvalidArgumentException('provider_index_admission_payload_invalid');
        }
        if (trim((string)($payload['indexer'] ?? '')) === '') {
            throw new \InvalidArgumentException('provider_index_admission_payload_invalid');
        }
    }
}
