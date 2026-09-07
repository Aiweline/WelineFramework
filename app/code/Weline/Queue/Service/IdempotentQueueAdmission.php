<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueStatus;
use Weline\Queue\Model\Queue;

/**
 * Shared createIfAbsent admission: one durable slot per idempotency key.
 *
 * pending → refresh content; running → one followup slot; terminal → reopen.
 */
class IdempotentQueueAdmission
{
    public function __construct(
        private readonly QueueDispatchService $dispatch,
    ) {
    }

    /**
     * @param array{
     *   class:class-string,
     *   name:string,
     *   module:string,
     *   content:array<string,mixed>,
     *   biz_key:string,
     *   idempotency_scope:string,
     *   idempotency_key?:string,
     *   auto?:bool,
     *   dispatch?:bool
     * } $params
     */
    public function admit(array $params): int
    {
        $class = \trim((string)($params['class'] ?? ''));
        $name = \trim((string)($params['name'] ?? ''));
        $module = \trim((string)($params['module'] ?? ''));
        $bizKey = \trim((string)($params['biz_key'] ?? ''));
        $scope = \trim((string)($params['idempotency_scope'] ?? ''));
        $idempotencyKey = \trim((string)($params['idempotency_key'] ?? $bizKey));
        $content = $params['content'] ?? null;
        if ($class === '' || $name === '' || $module === '' || $bizKey === ''
            || $scope === '' || $idempotencyKey === '' || !\is_array($content)
        ) {
            throw new \InvalidArgumentException('idempotent_queue_admission_params_invalid');
        }

        return $this->admitSlot(
            $class,
            $name,
            $module,
            $content,
            $bizKey,
            $scope,
            $idempotencyKey,
            (bool)($params['auto'] ?? true),
            \array_key_exists('dispatch', $params) ? (bool)$params['dispatch'] : true,
            false,
        );
    }

    /**
     * @param array<string,mixed> $content
     */
    private function admitSlot(
        string $class,
        string $name,
        string $module,
        array $content,
        string $bizKey,
        string $scope,
        string $idempotencyKey,
        bool $auto,
        bool $dispatch,
        bool $isFollowUp,
    ): int {
        $payload = [
            'class' => $class,
            'name' => $isFollowUp ? $name . ' / followup' : $name,
            'module' => $module,
            'content' => $content,
            'status' => QueueStatus::PENDING,
            'auto' => $auto,
            'biz_key' => $bizKey,
            'idempotency_scope' => $scope,
            'idempotency_key' => $idempotencyKey,
        ];
        if (!$dispatch) {
            $payload['dispatch'] = false;
        }

        $created = \w_query('queue', 'createIfAbsent', $payload);
        if (!\is_array($created)
            || empty($created['success'])
            || (int)($created['queue_id'] ?? 0) < 1
        ) {
            throw new \RuntimeException('idempotent_queue_admission_failed');
        }
        if (!empty($created['created'])) {
            return (int)$created['queue_id'];
        }

        $queueId = (int)$created['queue_id'];
        $status = (string)($created['status'] ?? '');
        if ($status === Queue::status_pending) {
            $this->refreshPending($queueId, $name, $content, $isFollowUp);

            return $queueId;
        }
        if ($status === Queue::status_running) {
            if ($isFollowUp) {
                return $queueId;
            }

            return $this->admitSlot(
                $class,
                $name,
                $module,
                $content,
                $bizKey . ':followup',
                $scope,
                $idempotencyKey . ':followup',
                $auto,
                $dispatch,
                true,
            );
        }

        $this->reopenTerminal($queueId, $name, $content, $isFollowUp);

        return $queueId;
    }

    /**
     * @param array<string,mixed> $content
     */
    private function refreshPending(int $queueId, string $name, array $content, bool $isFollowUp): void
    {
        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'content' => $content,
            'name' => $isFollowUp ? $name . ' / followup' : $name,
        ]);
        if (\is_array($updated) && !empty($updated['success'])) {
            return;
        }

        $errorCode = \is_array($updated) ? (string)($updated['error_code'] ?? '') : '';
        if ($errorCode === 'queue_edit_active' || $errorCode === 'queue_state_changed') {
            return;
        }

        throw new \RuntimeException('idempotent_queue_coalesce_update_failed');
    }

    /**
     * @param array<string,mixed> $content
     */
    private function reopenTerminal(int $queueId, string $name, array $content, bool $isFollowUp): void
    {
        $requeued = $this->dispatch->requeueQueueSafely($queueId);
        if (empty($requeued['confirmed'])) {
            throw new \RuntimeException(
                'idempotent_queue_reopen_failed:' . (string)($requeued['error_code'] ?? 'unknown'),
            );
        }

        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'content' => $content,
            'name' => $isFollowUp ? $name . ' / followup' : $name,
        ]);
        if (!\is_array($updated) || empty($updated['success'])) {
            throw new \RuntimeException('idempotent_queue_reopen_update_failed');
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
}
