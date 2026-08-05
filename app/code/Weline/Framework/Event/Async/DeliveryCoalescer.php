<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Database\Exception\UniqueConstraintViolationDetector;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Event\Async\Exception\CoalesceConflictException;
use Weline\Framework\Model\Event\CoalesceSlot;
use Weline\Framework\Model\Event\Delivery;

final class DeliveryCoalescer
{
    private const MAX_CAS_ATTEMPTS = 8;

    public function __construct(
        private readonly CoalesceSlot $slotModel,
        private readonly DeliveryStateMachine $deliveries,
        private readonly CanonicalJson $canonicalJson,
        private readonly UniqueConstraintViolationDetector $uniqueViolation,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    /** @param array<string,mixed> $target */
    public function register(Delivery $newDelivery, array $target): void
    {
        if ((string)($target['coalesce'] ?? 'none') !== 'latest') {
            return;
        }
        $observerKey = (string)$newDelivery->getData(Delivery::schema_fields_OBSERVER_KEY);
        $coalesceKey = (string)$newDelivery->getData(Delivery::schema_fields_COALESCE_KEY);
        if ($observerKey === '' || $coalesceKey === '') {
            return;
        }
        $newId = (int)$newDelivery->getData(Delivery::schema_fields_ID);
        $observerHash = hash('sha256', $observerKey);
        $coalesceHash = hash('sha256', $coalesceKey);

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $slot = $this->findSlot($observerHash, $coalesceHash);
            if ($slot === null) {
                try {
                    $this->transactions->withSavepoint(
                        $this->slotModel->getConnection(),
                        'event_coalesce_slot_create',
                        fn(): bool => $this->createSlot(
                            $observerKey,
                            $coalesceKey,
                            $observerHash,
                            $coalesceHash,
                            $newId,
                        ),
                    );
                    return;
                } catch (\Throwable $exception) {
                    if (!$this->uniqueViolation->matches(
                        $exception,
                        'uk_event_coalesce_slot',
                        $this->slotModel->getConnection()->getConfigProvider()->getPrefix() . CoalesceSlot::schema_table,
                        CoalesceSlot::schema_fields_OBSERVER_KEY_HASH,
                    )) {
                        throw $exception;
                    }
                }
                continue;
            }
            if ((string)$slot->getData(CoalesceSlot::schema_fields_OBSERVER_KEY) !== $observerKey
                || (string)$slot->getData(CoalesceSlot::schema_fields_COALESCE_KEY) !== $coalesceKey) {
                throw new \RuntimeException(__('异步事件 coalesce SHA-256 碰撞'));
            }
            $currentNew = $this->deliveries->find($newId);
            if ($currentNew === null) {
                throw new CoalesceConflictException(__('待合并的新 Delivery 不存在'));
            }
            $newRevision = (int)$currentNew->getData(Delivery::schema_fields_REVISION);
            $staleReason = $this->staleReasonForSlot($currentNew, $slot);
            if ($staleReason !== null) {
                if ($this->deliveries->skipPending($newId, $staleReason)) {
                    return;
                }
                $reloaded = $this->deliveries->find($newId);
                if ($reloaded !== null
                    && (string)$reloaded->getData(Delivery::schema_fields_STATUS) === 'skipped') {
                    return;
                }
                throw new CoalesceConflictException(__('旧版本 Delivery 跳过时状态已变化'));
            }
            $currentId = (int)$slot->getData(CoalesceSlot::schema_fields_CURRENT_DELIVERY_ID);
            if ($currentId === $newId) {
                return;
            }

            try {
                $this->transactions->withSavepoint(
                    $this->slotModel->getConnection(),
                    'event_coalesce',
                    function () use ($slot, $currentId, $currentNew, $newId): void {
                        $old = $this->deliveries->find($currentId);
                        if ($old !== null && (string)$old->getData(Delivery::schema_fields_STATUS) === 'pending') {
                            $merged = $this->mergePending($old, $currentNew);
                            if ($merged && !$this->deliveries->supersedePending($currentId, $newId)) {
                                throw new CoalesceConflictException(__('异步事件待合并 Delivery 状态已变化'));
                            }
                        }

                        $lockVersion = (int)$slot->getData(CoalesceSlot::schema_fields_LOCK_VERSION);
                        $update = $this->newSlot();
                        $update->where(CoalesceSlot::schema_fields_ID, (int)$slot->getData(CoalesceSlot::schema_fields_ID))
                            ->where(CoalesceSlot::schema_fields_LOCK_VERSION, $lockVersion)
                            ->where(CoalesceSlot::schema_fields_CURRENT_DELIVERY_ID, $currentId);
                        $updated = $update->getQuery()
                            ->update([
                                CoalesceSlot::schema_fields_CURRENT_DELIVERY_ID => $newId,
                                CoalesceSlot::schema_fields_LOCK_VERSION => $lockVersion + 1,
                                CoalesceSlot::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
                            ])
                            ->fetch();
                        if (!($updated === true || (is_int($updated) && $updated === 1))) {
                            throw new CoalesceConflictException(__('异步事件 coalesce slot CAS 冲突'));
                        }
                    },
                );
                return;
            } catch (CoalesceConflictException) {
                continue;
            }
        }

        throw new CoalesceConflictException(__('异步事件 coalesce slot 冲突超过 %{1} 次', [self::MAX_CAS_ATTEMPTS]));
    }

    public function markSucceeded(Delivery $delivery): void
    {
        if ((string)$delivery->getData(Delivery::schema_fields_COALESCE_MODE) !== 'latest') {
            return;
        }
        $observerKey = (string)$delivery->getData(Delivery::schema_fields_OBSERVER_KEY);
        $coalesceKey = (string)$delivery->getData(Delivery::schema_fields_COALESCE_KEY);
        $revision = (int)$delivery->getData(Delivery::schema_fields_REVISION);
        if ($observerKey === '' || $coalesceKey === '' || $revision < 1) {
            return;
        }
        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $slot = $this->findSlot(hash('sha256', $observerKey), hash('sha256', $coalesceKey));
            if ($slot === null) {
                return;
            }
            $oldRevision = (int)$slot->getData(CoalesceSlot::schema_fields_LAST_SUCCESS_REVISION);
            if ($revision <= $oldRevision) {
                return;
            }
            $lockVersion = (int)$slot->getData(CoalesceSlot::schema_fields_LOCK_VERSION);
            $update = $this->newSlot();
            $update->where(CoalesceSlot::schema_fields_ID, (int)$slot->getData(CoalesceSlot::schema_fields_ID))
                ->where(CoalesceSlot::schema_fields_LOCK_VERSION, $lockVersion)
                ->where(CoalesceSlot::schema_fields_LAST_SUCCESS_REVISION, $oldRevision);
            $updated = $update->getQuery()
                ->update([
                    CoalesceSlot::schema_fields_LAST_SUCCESS_REVISION => $revision,
                    CoalesceSlot::schema_fields_LOCK_VERSION => $lockVersion + 1,
                    CoalesceSlot::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
                ])
                ->fetch();
            if ($updated === true || (is_int($updated) && $updated === 1)) {
                return;
            }
        }
        w_log_warning(
            'event_async_stale_fence_noop',
            [
                'delivery_id' => (int)$delivery->getData(Delivery::schema_fields_ID),
                'error_code' => 'coalesce_success_revision_cas_conflict',
            ],
            'event_async.log',
        );
    }

    /**
     * Return a stable reason when a latest-coalesced Delivery must not invoke
     * its Observer. A dead row at the same revision is deliberately replayable;
     * an active or already-succeeded row at that revision is a duplicate.
     */
    public function staleReason(Delivery $candidate): ?string
    {
        if ((string)$candidate->getData(Delivery::schema_fields_COALESCE_MODE) !== 'latest') {
            return null;
        }
        $observerKey = (string)$candidate->getData(Delivery::schema_fields_OBSERVER_KEY);
        $coalesceKey = (string)$candidate->getData(Delivery::schema_fields_COALESCE_KEY);
        if ($observerKey === '' || $coalesceKey === '') {
            return null;
        }
        $slot = $this->findSlot(hash('sha256', $observerKey), hash('sha256', $coalesceKey));
        if ($slot === null) {
            return null;
        }
        if ((string)$slot->getData(CoalesceSlot::schema_fields_OBSERVER_KEY) !== $observerKey
            || (string)$slot->getData(CoalesceSlot::schema_fields_COALESCE_KEY) !== $coalesceKey) {
            throw new \RuntimeException(__('异步事件 coalesce SHA-256 碰撞'));
        }

        return $this->staleReasonForSlot($candidate, $slot);
    }

    private function staleReasonForSlot(Delivery $candidate, CoalesceSlot $slot): ?string
    {
        $candidateRevision = (int)$candidate->getData(Delivery::schema_fields_REVISION);
        if ($candidateRevision < 1) {
            return null;
        }
        if ($candidateRevision <= (int)$slot->getData(CoalesceSlot::schema_fields_LAST_SUCCESS_REVISION)) {
            return 'older_revision_already_succeeded';
        }
        $candidateId = (int)$candidate->getData(Delivery::schema_fields_ID);
        $currentId = (int)$slot->getData(CoalesceSlot::schema_fields_CURRENT_DELIVERY_ID);
        if ($currentId < 1 || $currentId === $candidateId) {
            return null;
        }
        $current = $this->deliveries->find($currentId);
        if ($current === null) {
            return null;
        }
        $currentRevision = (int)$current->getData(Delivery::schema_fields_REVISION);
        if ($currentRevision > 0 && $candidateRevision < $currentRevision) {
            return 'older_revision_successor';
        }
        if ($candidateRevision === $currentRevision
            && in_array(
                (string)$current->getData(Delivery::schema_fields_STATUS),
                ['pending', 'provisioning', 'queued', 'running', 'retry_wait', 'succeeded'],
                true,
            )) {
            return 'duplicate_revision_active';
        }

        return null;
    }

    private function mergePending(Delivery $old, Delivery $new): bool
    {
        $oldPayload = $this->decode((string)$old->getData(Delivery::schema_fields_PAYLOAD_JSON));
        $newPayload = $this->decode((string)$new->getData(Delivery::schema_fields_PAYLOAD_JSON));
        if (!$this->compatible($oldPayload, $newPayload)) {
            return false;
        }

        $merged = $newPayload;
        $merged['before'] = $oldPayload['before'] ?? null;
        $merged['changed_fields'] = $this->union(
            (array)($oldPayload['changed_fields'] ?? []),
            (array)($newPayload['changed_fields'] ?? []),
        );
        foreach (['namespaces', 'urls'] as $currentKey) {
            $previousKey = 'previous_' . $currentKey;
            $previous = $this->union(
                (array)($oldPayload['impact'][$previousKey] ?? []),
                (array)($oldPayload['impact'][$currentKey] ?? []),
                (array)($newPayload['impact'][$previousKey] ?? []),
            );
            $merged['impact'][$previousKey] = array_values(array_diff(
                $previous,
                (array)($newPayload['impact'][$currentKey] ?? []),
            ));
        }
        $merged['coalesced_event_ids'] = $this->union(
            (array)($oldPayload['coalesced_event_ids'] ?? [$oldPayload['event_id'] ?? '']),
            [(string)($newPayload['event_id'] ?? '')],
        );

        try {
            $json = $this->canonicalJson->encode($merged);
        } catch (AsyncEventValidationException $exception) {
            unset($exception);
            w_log_warning(
                'event_async_delivery_state_changed',
                [
                    'delivery_id' => (int)$new->getData(Delivery::schema_fields_ID),
                    'error_code' => 'coalesce_skipped_payload_limit',
                ],
                'event_async.log',
            );
            return false;
        }
        $newId = (int)$new->getData(Delivery::schema_fields_ID);
        $lockVersion = (int)$new->getData(Delivery::schema_fields_LOCK_VERSION);
        if (!$this->deliveries->cas([
            Delivery::schema_fields_ID => $newId,
            Delivery::schema_fields_STATUS => 'pending',
            Delivery::schema_fields_LOCK_VERSION => $lockVersion,
        ], [
            Delivery::schema_fields_PAYLOAD_JSON => $json,
            Delivery::schema_fields_PAYLOAD_SHA256 => hash('sha256', $json),
            Delivery::schema_fields_REVISION => (int)($merged['resource']['revision'] ?? 0),
            Delivery::schema_fields_LOCK_VERSION => $lockVersion + 1,
        ])) {
            throw new CoalesceConflictException(__('新 Delivery 在合并时已被其他执行者修改'));
        }
        return true;
    }

    /** @param array<string,mixed> $old @param array<string,mixed> $new */
    private function compatible(array $old, array $new): bool
    {
        return (int)($old['schema_version'] ?? 0) === (int)($new['schema_version'] ?? 0)
            && (string)($old['resource']['type'] ?? '') === (string)($new['resource']['type'] ?? '')
            && (string)($old['resource']['id'] ?? '') === (string)($new['resource']['id'] ?? '')
            && (string)($old['resource']['action'] ?? '') === 'upsert'
            && (string)($new['resource']['action'] ?? '') === 'upsert';
    }

    private function createSlot(
        string $observerKey,
        string $coalesceKey,
        string $observerHash,
        string $coalesceHash,
        int $deliveryId,
    ): bool {
        $slot = $this->newSlot();
        $slot->setData([
            CoalesceSlot::schema_fields_OBSERVER_KEY_HASH => $observerHash,
            CoalesceSlot::schema_fields_COALESCE_KEY_HASH => $coalesceHash,
            CoalesceSlot::schema_fields_OBSERVER_KEY => $observerKey,
            CoalesceSlot::schema_fields_COALESCE_KEY => $coalesceKey,
            CoalesceSlot::schema_fields_CURRENT_DELIVERY_ID => $deliveryId,
            CoalesceSlot::schema_fields_LAST_SUCCESS_REVISION => 0,
            CoalesceSlot::schema_fields_LOCK_VERSION => 0,
        ]);
        $slot->save();
        return true;
    }

    private function findSlot(string $observerHash, string $coalesceHash): ?CoalesceSlot
    {
        $slot = $this->newSlot();
        $slot->where(CoalesceSlot::schema_fields_OBSERVER_KEY_HASH, $observerHash)
            ->where(CoalesceSlot::schema_fields_COALESCE_KEY_HASH, $coalesceHash)
            ->find()
            ->fetch();
        return $slot->getId() ? $slot : null;
    }

    private function newSlot(): CoalesceSlot
    {
        $model = clone $this->slotModel;
        return $model->clearData()->clearQuery();
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AsyncEventValidationException(__('异步 Delivery 载荷 JSON 无效'), previous: $exception);
        }
        if (!is_array($value)) {
            throw new AsyncEventValidationException(__('异步 Delivery 载荷必须是 JSON object'));
        }
        return $value;
    }

    /** @return list<string> */
    private function union(array ...$lists): array
    {
        $result = [];
        foreach ($lists as $list) {
            foreach ($list as $value) {
                $value = (string)$value;
                if ($value !== '') {
                    $result[$value] = $value;
                }
            }
        }
        return array_values($result);
    }
}
