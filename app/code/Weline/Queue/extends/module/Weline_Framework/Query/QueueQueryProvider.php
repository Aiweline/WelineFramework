<?php

declare(strict_types=1);

namespace Weline\Queue\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Database\Exception\UniqueConstraintViolationDetector;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Queue\Helper\Helper;
use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;
use Weline\Queue\Service\QueueDispatchService;

/**
 * 任务队列统一入口：模块间一律通过 w_query('queue', ...) 读写队列，避免直接依赖 Queue 模型类。
 *
 * 新消费类实现 {@see \Weline\Queue\Api\QueueConsumerInterface}，只接收
 * {@see \Weline\Queue\Api\QueueTaskContextInterface}。旧 {@see \Weline\Queue\QueueInterface}
 * 仅作第三方兼容桥，收集和执行管线仍保持支持。
 */
class QueueQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Queue $queueModel,
        private readonly Type $typeModel,
        private readonly EventsManager $eventsManager,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly UniqueConstraintViolationDetector $uniqueViolation,
    ) {
    }

    public function getProviderName(): string
    {
        return 'queue';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'get', 'load' => $this->getRow($params),
            'getByBizKey' => $this->getByBizKey($params),
            'list' => $this->listRows($params),
            'stats' => $this->stats(),
            'getTypeIdByClass' => $this->getTypeIdByClass($params),
            'create' => $this->createQueue($params),
            'createIfAbsent' => $this->createQueueIfAbsent($params),
            'dispatch' => $this->dispatchQueue($params),
            'update' => $this->updateQueue($params),
            'stop' => $this->stopQueue($params),
            'takeover' => $this->takeoverQueue($params),
            'delete' => $this->deleteQueue($params),
            default => throw new \InvalidArgumentException(
                (string)__('Queue 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function getRow(array $params): ?array
    {
        $queueId = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        if ($queueId <= 0) {
            throw new \InvalidArgumentException((string)__('请提供有效的 queue_id。'));
        }
        $queue = $this->loadQueueFreshById($queueId);
        if ((int)$queue->getId() <= 0) {
            return null;
        }

        return $queue->getData();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function getByBizKey(array $params): ?array
    {
        $bizKey = \trim((string)($params['biz_key'] ?? ''));
        if ($bizKey === '') {
            throw new \InvalidArgumentException((string)__('请提供 biz_key。'));
        }
        $queue = clone $this->queueModel;
        $rows = $queue->clearData()->reset()
            ->where(Queue::schema_fields_BIZ_KEY, $bizKey)
            ->order(Queue::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = $rows[0] ?? [];
        if (!is_array($row) || $row === []) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, pagination: mixed}
     */
    private function listRows(array $params): array
    {
        $page = \max(1, (int)($params['page'] ?? 1));
        $pageSize = \min(1000, \max(1, (int)($params['page_size'] ?? 20)));
        $module = \trim((string)($params['module'] ?? ''));
        $status = \trim((string)($params['status'] ?? ''));
        $search = \trim((string)($params['q'] ?? ''));
        $queueId = (int)($params['queue_id'] ?? 0);
        $typeId = (int)($params['type_id'] ?? 0);
        $bizKey = \trim((string)($params['biz_key'] ?? ''));

        $queue = clone $this->queueModel;
        $queue->clearData()->reset();
        $queue->joinModel(Type::class, 't', 'main_table.type_id=t.type_id', 'left');

        if ($module !== '') {
            $queue->where('t.module_name', $module);
        }
        if ($search !== '') {
            $queue->where(
                'CONCAT(main_table.name,main_table.content,main_table.result)',
                '%' . $search . '%',
                'LIKE'
            );
        }
        if ($queueId > 0) {
            $queue->where('main_table.' . Queue::schema_fields_ID, $queueId);
        }
        if ($typeId > 0) {
            $queue->where('main_table.' . Queue::schema_fields_type_id, $typeId);
        }
        if ($status !== '') {
            $queue->where('main_table.status', $status);
        }
        if ($bizKey !== '') {
            $queue->where('main_table.' . Queue::schema_fields_BIZ_KEY, $bizKey);
        }

        $queue->additional('AND (t.enable = 1 OR t.enable IS NULL)')
            ->order('main_table.queue_id', 'DESC');
        $queue->pagination($page, $pageSize)->select()->fetch();

        return [
            'items' => $queue->getItems(),
            'pagination' => $queue->getPagination(),
        ];
    }

    /**
     * @return array{all: int, pending: int, running: int, done: int, error: int, stop: int}
     */
    private function stats(): array
    {
        $queueModel = clone $this->queueModel;

        return [
            'all' => (int)$queueModel->reset()->count('queue_id'),
            'pending' => (int)$queueModel->reset()->where('status', Queue::status_pending)->count('queue_id'),
            'running' => (int)$queueModel->reset()->where('status', Queue::status_running)->count('queue_id'),
            'done' => (int)$queueModel->reset()->where('status', Queue::status_done)->count('queue_id'),
            'error' => (int)$queueModel->reset()->where('status', Queue::status_error)->count('queue_id'),
            'stop' => (int)$queueModel->reset()->where('status', Queue::status_stop)->count('queue_id'),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getTypeIdByClass(array $params): int
    {
        $class = \trim((string)($params['class'] ?? ''));
        if ($class === '') {
            return 0;
        }
        $typeId = $this->findTypeIdByClass($class);
        if ($typeId > 0) {
            return $typeId;
        }
        Helper::collect();

        return $this->findTypeIdByClass($class);
    }

    private function findTypeIdByClass(string $class): int
    {
        $type = clone $this->typeModel;
        $type->clearData()->reset()
            ->where(Type::schema_fields_class, $class)
            ->find()
            ->fetch();

        return (int)$type->getId();
    }

    /**
     * 新建队列任务（与后台手工创建语义一致，会派发 Weline_Queue::add）
     *
     * @param array<string, mixed> $params type_id 或 class、name、module 必填；content、status、auto、biz_key、dispatch 等可选
     * @return array{success: true, created: bool, queue_id: int, status: string, dispatched: bool, data: array<string, mixed>}
     */
    /**
     * 幂等创建队列：同一 idempotency_scope + idempotency_key 只会落库一条记录。
     *
     * 已存在时返回既有行并带 'created' => false；并发下依赖
     * uk_idempotency_key UNIQUE 约束兜底，冲突后回读既有行。
     *
     * @param array<string, mixed> $params 除 createQueue 的参数外还需
     *        idempotency_key（必填）与可选 idempotency_scope
     * @return array{success: true, created: bool, queue_id: int, status: string, dispatched: bool, data: array<string, mixed>}
     */
    private function createQueueIfAbsent(array $params): array
    {
        $key = \trim((string)($params['idempotency_key'] ?? ''));
        if ($key === '') {
            throw new \InvalidArgumentException((string)__('请提供 idempotency_key。'));
        }
        $scope = \trim((string)($params['idempotency_scope'] ?? ''));
        $storageKey = $scope !== '' ? $scope . ':' . $key : $key;
        if (\strlen($storageKey) > Queue::IDEMPOTENCY_KEY_MAX_BYTES) {
            throw new \InvalidArgumentException((string)__(
                'idempotency_scope 与 idempotency_key 组合后不能超过 %{1} 字节',
                [Queue::IDEMPOTENCY_KEY_MAX_BYTES],
            ));
        }

        $connection = $this->queueModel->getConnection();
        if ($this->transactions->isActive($connection)) {
            if ($this->isSqlite() && !$this->transactions->isWriteIntent($connection)) {
                throw new \LogicException('queue_sqlite_write_intent_required');
            }
            return $this->createQueueIfAbsentInTransaction($params, $storageKey);
        }

        $result = $this->transactions->runWrite(
            $connection,
            fn(): array => $this->createQueueIfAbsentInTransaction($params, $storageKey),
        );

        return $this->refreshCreateResultAfterOwnedCommit($result);
    }

    /**
     * The insert must be isolated by a savepoint: Model::save() marks its
     * logical transaction rollback-only on an exception. A concurrent UNIQUE
     * winner is expected control flow and must not poison the caller's outer
     * transaction. The locking reread is deliberate for MySQL REPEATABLE READ:
     * it observes the latest committed winner instead of the earlier snapshot.
     *
     * @param array<string,mixed> $params
     * @return array{success: true, created: bool, queue_id: int, status: string, dispatched: bool, data: array<string, mixed>}
     */
    private function createQueueIfAbsentInTransaction(array $params, string $storageKey): array
    {
        $existing = $this->findByIdempotencyKey($storageKey);
        if ($existing !== null) {
            return $this->formatCreateResult(false, $existing, false);
        }

        try {
            return $this->transactions->withSavepoint(
                $this->queueModel->getConnection(),
                'queue_idempotent_create',
                fn(): array => $this->createQueue($params, $storageKey),
            );
        } catch (\Throwable $throwable) {
            if (!$this->isIdempotencyUniqueConflict($throwable)) {
                throw $throwable;
            }

            // 仅 uk_idempotency_key 冲突表示并发竞争者已创建；回读同一精确键。
            $existing = $this->findByIdempotencyKey($storageKey, true);
            if ($existing === null) {
                throw $throwable;
            }

            return $this->formatCreateResult(false, $existing, false);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByIdempotencyKey(string $storageKey, bool $lockingRead = false): ?array
    {
        $queue = clone $this->queueModel;
        $queue->clearData()->reset()
            ->where(Queue::schema_fields_IDEMPOTENCY_KEY, $storageKey);
        if ($lockingRead) {
            if ($this->supportsForUpdate()) {
                $queue->additional('FOR UPDATE');
            }
            $queue->find()->fetch();
            $row = $queue->getData();
        } else {
            $rows = $queue->order(Queue::schema_fields_ID, 'DESC')
                ->limit(1)
                ->select()
                ->fetchArray();
            $row = $rows[0] ?? [];
        }

        return \is_array($row) && $row !== [] ? $row : null;
    }

    private function supportsForUpdate(): bool
    {
        $type = \strtolower((string)$this->queueModel->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return \in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function isSqlite(): bool
    {
        return \strtolower((string)$this->queueModel->getConnection()
            ->getConnector()->getConfigProvider()->getDbType()) === 'sqlite';
    }

    private function isIdempotencyUniqueConflict(\Throwable $throwable): bool
    {
        return $this->uniqueViolation->matches(
            $throwable,
            'uk_idempotency_key',
            $this->queueModel->getConnection()->getConfigProvider()->getPrefix() . Queue::schema_table,
            Queue::schema_fields_IDEMPOTENCY_KEY,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: true, created: bool, queue_id: int, status: string, dispatched: bool, data: array<string, mixed>}
     */
    private function formatCreateResult(bool $created, array $data, bool $dispatched): array
    {
        return [
            'success' => true,
            'created' => $created,
            'queue_id' => (int)($data[Queue::schema_fields_ID] ?? 0),
            'status' => (string)($data[Queue::schema_fields_status] ?? ''),
            'dispatched' => $dispatched,
            'data' => $data,
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function refreshCreateResultAfterOwnedCommit(array $result): array
    {
        $queueId = (int)($result['queue_id'] ?? 0);
        if (empty($result['created']) || $queueId < 1) {
            return $result;
        }
        $queue = $this->loadQueueFreshById($queueId);
        if ((int)$queue->getId() < 1) {
            return $result;
        }
        $data = $queue->getData();
        $result['status'] = (string)$queue->getStatus();
        $result['data'] = $data;
        if (!empty($result['dispatch_deferred'])) {
            $result['dispatched'] = ($data[Queue::schema_fields_start_at] ?? null) !== null
                || (int)$queue->getPid() > 0
                || $queue->getDispatchToken() !== ''
                || $queue->getStatus() !== Queue::status_pending;
            $result['dispatch_deferred'] = false;
        }

        return $result;
    }

    private function createQueue(array $params, ?string $idempotencyStorageKey = null): array
    {
        $typeId = $this->resolveTypeIdFromParams($params);
        if ($typeId <= 0) {
            throw new \InvalidArgumentException((string)__('请提供有效的 type_id 或 class（队列类型）。'));
        }
        $type = clone $this->typeModel;
        $type->clearData()->load($typeId);
        if ((int)$type->getId() <= 0) {
            throw new \InvalidArgumentException((string)__('队列类型不存在。'));
        }

        $name = \trim((string)($params['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('请提供队列名称 name。'));
        }
        $module = \trim((string)($params['module'] ?? ''));
        if ($module === '') {
            throw new \InvalidArgumentException((string)__('请提供 module。'));
        }

        $status = \array_key_exists('status', $params)
            ? \trim((string)$params['status'])
            : Queue::status_pending;
        if ($status === '') {
            $status = Queue::status_pending;
        }
        if ($status !== Queue::status_pending) {
            throw new \InvalidArgumentException(
                (string)__('公开 Queue create 只允许 status=pending；运行态和终态必须通过专用控制操作进入。')
            );
        }

        $content = $this->normalizeContentParam($params['content'] ?? '');
        $this->assertNoScopeLeakInContent($content);
        $this->assertNoLooseScopeParams($params);

        $queue = clone $this->queueModel;
        $queue->clearData();
        $queue->setTypeId($typeId)
            ->setName($name)
            ->setModule($module)
            ->setStatus($status)
            ->setContent($content)
            ->setPid(0)
            ->setFinished(false)
            ->setAuto((bool)($params['auto'] ?? true));

        if (\array_key_exists('biz_key', $params)) {
            $rawBk = $params['biz_key'];
            if ($rawBk === null || $rawBk === '') {
                $queue->setBizKey(null);
            } else {
                $queue->setBizKey((string)$rawBk);
            }
        }
        if ($idempotencyStorageKey !== null && $idempotencyStorageKey !== '') {
            $queue->setIdempotencyKey($idempotencyStorageKey);
        }
        $this->applyScopeEnvelopeParam($queue, $params);

        $queueId = $queue->save(true);
        if ((int)$queueId <= 0) {
            $queueId = (int)$queue->getId();
        }
        if ($queueId <= 0) {
            throw new \RuntimeException((string)__('创建队列失败。'));
        }
        $queue = $this->loadQueueFreshById($queueId);

        // Auto queues must start immediately. Waiting for the */1 cron left AI
        // publish pending for up to a minute, so the live site looked unchanged
        // until a second publish or a lucky cron tick.
        $shouldDispatch = !\array_key_exists('dispatch', $params) || (bool)$params['dispatch'];
        $connection = $this->queueModel->getConnection();
        $dispatchDeferred = $shouldDispatch
            && (bool)$queue->getAuto()
            && $queue->getStatus() === Queue::status_pending
            && $this->transactions->isActive($connection);
        $dispatched = false;
        $this->transactions->afterCommit(
            $connection,
            'queue:add:' . $queueId,
            function () use ($queueId, $shouldDispatch, &$dispatched): void {
                $committed = $this->loadQueueFreshById($queueId);
                if ((int)$committed->getId() < 1) {
                    return;
                }
                $eventData = ['queue' => $committed];
                $this->eventsManager->dispatch('Weline_Queue::add', $eventData);
                if ($shouldDispatch) {
                    $dispatched = $this->maybeDispatchAutoQueue($committed);
                }
            },
        );
        $queue = $this->loadQueueFreshById($queueId);
        $result = $this->formatCreateResult(true, $queue->getData(), $dispatched);
        $result['dispatch_deferred'] = $dispatchDeferred;

        return $result;
    }

    /**
     * 更新队列（派发 Weline_Queue::edit）
     *
     * @param array<string, mixed> $params queue_id 或 biz_key 定位；其余可更新字段见 applyQueuePatch
     * @return array{success: true, queue_id: int, data: array<string, mixed>}
     */
    private function updateQueue(array $params): array
    {
        $queueIdArg = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $bizKeyArg = \trim((string)($params['biz_key'] ?? ''));
        if ($queueIdArg <= 0 && $bizKeyArg === '') {
            throw new \InvalidArgumentException((string)__('请提供 queue_id 或 biz_key。'));
        }

        $queue = $this->loadQueueByIdOrBizKey($params);
        if ((int)$queue->getId() <= 0) {
            return [
                'success' => false,
                'message' => (string)__('队列不存在。'),
            ];
        }

        $queueId = (int)$queue->getId();
        $patch = $params['patch'] ?? null;
        $updates = \is_array($patch)
            ? $this->applyQueuePatch($queue, $patch, false)
            : $this->applyQueuePatch($queue, $params, true);
        $result = $this->queueDispatchService()->updatePendingQueueSafely($queueId, $updates);
        if (empty($result['confirmed'])) {
            return [
                'success' => false,
                'queue_id' => $queueId,
                'message' => (string)($result['message'] ?? __('队列编辑失败。')),
                'error_code' => (string)($result['error_code'] ?? 'queue_edit_failed'),
                'retryable' => !empty($result['retryable']),
            ];
        }
        if (\is_array($result['data'] ?? null)) {
            $queue->clearData()->setData($result['data']);
        } else {
            $queue = $this->loadQueueFreshById($queueId);
        }
        $this->transactions->afterCommit(
            $this->queueModel->getConnection(),
            'queue:edit:' . $queueId,
            function () use ($queueId): void {
                $committed = $this->loadQueueFreshById($queueId);
                if ((int)$committed->getId() > 0) {
                    $eventData = ['queue' => $committed];
                    $this->eventsManager->dispatch('Weline_Queue::edit', $eventData);
                }
            },
        );

        return [
            'success' => true,
            'queue_id' => $queueId,
            'data' => $queue->getData(),
        ];
    }

    /**
     * Pause one Queue after its recorded attempt is proven released.
     *
     * @param array<string, mixed> $params queue_id or biz_key
     * @return array{success: bool, message?: string, queue_id?: int, data?: array<string, mixed>}
     */
    private function stopQueue(array $params): array
    {
        $queueIdArg = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $bizKeyArg = \trim((string)($params['biz_key'] ?? ''));
        if ($queueIdArg <= 0 && $bizKeyArg === '') {
            throw new \InvalidArgumentException((string)__('请提供 queue_id 或 biz_key。'));
        }

        $queue = $this->loadQueueByIdOrBizKey($params);
        if ((int)$queue->getId() <= 0) {
            return [
                'success' => false,
                'message' => (string)__('队列不存在。'),
            ];
        }

        $queueId = (int)$queue->getId();
        $result = $this->queueDispatchService()->stopQueueSafely($queueId);
        if (empty($result['confirmed'])) {
            return [
                'success' => false,
                'queue_id' => $queueId,
                'message' => (string)($result['message'] ?? __('队列暂停失败。')),
                'error_code' => (string)($result['error_code'] ?? 'queue_stop_failed'),
                'retryable' => !empty($result['retryable']),
            ];
        }
        if (\is_array($result['data'] ?? null)) {
            $queue->clearData()->setData($result['data']);
        } else {
            $queue = $this->loadQueueFreshById($queueId);
        }

        $eventData = ['queue' => $queue];
        $this->eventsManager->dispatch('Weline_Queue::stop', $eventData);
        $this->eventsManager->dispatch('Weline_Queue::edit', $eventData);

        return [
            'success' => true,
            'confirmed' => true,
            'queue_id' => $queueId,
            'data' => $queue->getData(),
            'message' => (string)($result['message'] ?? __('队列已暂停。')),
        ];
    }

    /**
     * Release a queue row from an old worker without executing it in this call.
     *
     * @param array<string, mixed> $params queue_id or biz_key; force/owner/reason optional
     * @return array{success: bool, message?: string, queue_id?: int, data?: array<string, mixed>}
     */
    private function takeoverQueue(array $params): array
    {
        $queueIdArg = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $bizKeyArg = \trim((string)($params['biz_key'] ?? ''));
        if ($queueIdArg <= 0 && $bizKeyArg === '') {
            throw new \InvalidArgumentException((string)__('请提供 queue_id 或 biz_key。'));
        }

        $queue = $this->loadQueueByIdOrBizKey($params);
        if ((int)$queue->getId() <= 0) {
            return [
                'success' => false,
                'message' => (string)__('队列不存在。'),
            ];
        }

        $queueId = (int)$queue->getId();
        $force = !\array_key_exists('force', $params) || (bool)$params['force'];
        $owner = \trim((string)($params['owner'] ?? 'system_scheduler'));
        $reason = \trim((string)($params['reason'] ?? 'force_takeover'));
        $result = $this->queueDispatchService()->takeoverQueueSafely(
            $queueId,
            $force,
            $owner,
            $reason,
            \array_key_exists('auto', $params) ? (bool)$params['auto'] : null,
            !\array_key_exists('mark_force_rebuild', $params) || (bool)$params['mark_force_rebuild'],
            (bool)($params['clear_output'] ?? false),
        );
        if (empty($result['confirmed'])) {
            return [
                'success' => false,
                'queue_id' => $queueId,
                'message' => (string)($result['message'] ?? __('队列接管失败。')),
                'error_code' => (string)($result['error_code'] ?? 'queue_takeover_failed'),
                'retryable' => !empty($result['retryable']),
            ];
        }
        if (\is_array($result['data'] ?? null)) {
            $queue->clearData()->setData($result['data']);
        } else {
            $queue = $this->loadQueueFreshById($queueId);
        }

        $eventData = ['queue' => $queue];
        $this->eventsManager->dispatch('Weline_Queue::takeover', $eventData);
        $this->eventsManager->dispatch('Weline_Queue::edit', $eventData);

        return [
            'success' => true,
            'queue_id' => $queueId,
            'data' => $queue->getData(),
            'message' => (string)($result['message'] ?? __('队列接管完成。')),
        ];
    }

    private function deleteQueue(array $params): array
    {
        $queueIdArg = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $bizKeyArg = \trim((string)($params['biz_key'] ?? ''));
        if ($queueIdArg <= 0 && $bizKeyArg === '') {
            throw new \InvalidArgumentException((string)__('请提供 queue_id 或 biz_key。'));
        }

        $queue = $this->loadQueueByIdOrBizKey($params);
        if ((int)$queue->getId() <= 0) {
            return [
                'success' => false,
                'message' => (string)__('队列不存在。'),
            ];
        }

        $queueId = (int)$queue->getId();
        $force = (bool)($params['force'] ?? false);

        $result = $this->queueDispatchService()->deleteQueueSafely($queueId, $force);
        if (empty($result['confirmed'])) {
            return [
                'success' => false,
                'message' => (string)($result['message'] ?? __('队列删除失败。')),
                'queue_id' => $queueId,
                'error_code' => (string)($result['error_code'] ?? 'queue_delete_failed'),
                'retryable' => !empty($result['retryable']),
            ];
        }
        if (\is_array($result['data'] ?? null)) {
            $queue->clearData()->setData($result['data']);
        }

        $eventData = ['queue' => $queue];
        $this->eventsManager->dispatch('Weline_Queue::delete', $eventData);

        return [
            'success' => true,
            'queue_id' => $queueId,
            'message' => (string)($result['message'] ?? __('队列已删除。')),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveTypeIdFromParams(array $params): int
    {
        $typeId = (int)($params['type_id'] ?? 0);
        if ($typeId > 0) {
            return $typeId;
        }
        $class = \trim((string)($params['class'] ?? ''));

        return $this->getTypeIdByClass(['class' => $class]);
    }

    /**
     * @param array<string, mixed> $params
     * @param bool $preferLatestBizKey 按 biz_key 多条时是否取 queue_id 最大
     */
    private function loadQueueByIdOrBizKey(array $params, bool $preferLatestBizKey = true): Queue
    {
        $queueId = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $bizKey = \trim((string)($params['biz_key'] ?? ''));

        $queue = clone $this->queueModel;
        $queue->clearData();

        if ($queueId > 0) {
            return $this->loadQueueFreshById($queueId);
        }
        if ($bizKey !== '') {
            $queue->reset()
                ->where(Queue::schema_fields_BIZ_KEY, $bizKey)
                ->order(Queue::schema_fields_ID, $preferLatestBizKey ? 'DESC' : 'ASC')
                ->limit(1)
                ->select()
                ->fetch();

            return $queue;
        }

        return $queue;
    }

    /**
     * 仅允许业务可安全修改的列（避免误改主键等）
     *
     * @param array<string, mixed> $patch
     * @param bool $flatParams 为 true 时表示来自顶层 $params（将忽略 biz_key，以免与定位用的 biz_key 混淆；改键请用 patch 子数组）
     */
    private function applyQueuePatch(Queue $queue, array $patch, bool $flatParams): array
    {
        $skip = ['queue_id', 'patch', 'id', 'provider', 'operation', 'force', 'class'];
        if ($flatParams) {
            $skip[] = 'biz_key';
        }

        $updates = [];
        foreach ($patch as $key => $value) {
            $k = (string)$key;
            if (\in_array($k, $skip, true)) {
                continue;
            }

            if (\in_array($k, ['status', 'pid', 'finished', 'dispatch_token', 'dispatch_until'], true)) {
                throw new \InvalidArgumentException(
                    (string)__('队列状态、PID、完成标记和 dispatch fence 只能通过专用控制操作修改。')
                );
            }

            switch ($k) {
                case 'name':
                    $queue->setName(\trim((string)$value));
                    $updates[Queue::schema_fields_name] = $queue->getData(Queue::schema_fields_name);
                    break;
                case 'module':
                    $queue->setModule(\trim((string)$value));
                    $updates[Queue::schema_fields_module] = $queue->getData(Queue::schema_fields_module);
                    break;
                case 'content':
                    $normalized = $this->normalizeContentValue($value);
                    $this->assertNoScopeLeakInContent($normalized);
                    $queue->setContent($normalized);
                    $updates[Queue::schema_fields_content] = $queue->getData(Queue::schema_fields_content);
                    break;
                case 'result':
                    $queue->setResult((string)$value);
                    $updates[Queue::schema_fields_result] = $queue->getData(Queue::schema_fields_result);
                    break;
                case 'process':
                    $queue->setProcess(\is_string($value) ? $value : (string)\json_encode($value, \JSON_UNESCAPED_UNICODE));
                    $updates[Queue::schema_fields_process] = $queue->getData(Queue::schema_fields_process);
                    break;
                case 'biz_key':
                    $queue->setBizKey($value === null || $value === '' ? null : (string)$value);
                    $updates[Queue::schema_fields_BIZ_KEY] = $queue->getData(Queue::schema_fields_BIZ_KEY);
                    break;
                case 'auto':
                    $queue->setAuto((bool)$value);
                    $updates[Queue::schema_fields_auto] = $queue->getData(Queue::schema_fields_auto);
                    break;
                case 'type_id':
                    $queue->setTypeId((int)$value);
                    $updates[Queue::schema_fields_type_id] = $queue->getData(Queue::schema_fields_type_id);
                    break;
            }
        }

        return $updates;
    }

    private function normalizeContentParam(mixed $content): string
    {
        if (\is_array($content)) {
            return (string)\json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
        }

        return (string)$content;
    }

    private function normalizeContentValue(mixed $value): string
    {
        if (\is_array($value)) {
            return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
        }

        return (string)$value;
    }

    /**
     * create/createIfAbsent 只接受单个 scope_envelope；禁止平行散落 Scope 列。
     *
     * @param array<string, mixed> $params
     */
    private function assertNoLooseScopeParams(array $params): void
    {
        $forbidden = [
            'scope_kind',
            'website_id',
            'website_code',
            'store_code',
            'channel_code',
            'store_mode',
            'scope_website_id',
            'scope_website_code',
            'scope_store_code',
            'scope_channel_code',
            'scope_store_mode',
            'scope_envelope_version',
            'envelope_version',
            'context_version',
        ];
        foreach ($forbidden as $key) {
            if (\array_key_exists($key, $params)) {
                throw new \InvalidArgumentException(
                    (string)__('Queue create 禁止直接传入 Scope 列 %{1}；请使用唯一入参 scope_envelope。', [$key])
                );
            }
        }
    }

    /**
     * 业务 content 不得混入 Scope 身份字段。
     */
    private function assertNoScopeLeakInContent(string $content): void
    {
        $decoded = $this->decodeQueueContent($content);
        if ($decoded === []) {
            return;
        }
        $forbidden = [
            'scope_kind',
            'scope_envelope',
            'envelope_version',
            'context_version',
            'scope_envelope_version',
            'scope_website_id',
            'scope_website_code',
            'scope_store_code',
            'scope_channel_code',
            'scope_store_mode',
        ];
        foreach ($forbidden as $key) {
            if (\array_key_exists($key, $decoded)) {
                throw new \InvalidArgumentException(
                    (string)__('Queue content 禁止混入 Scope 协议字段 %{1}；请使用 scope_envelope。', [$key])
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyScopeEnvelopeParam(Queue $queue, array $params): void
    {
        if (!\array_key_exists('scope_envelope', $params)) {
            return;
        }
        $raw = $params['scope_envelope'];
        if ($raw === null) {
            $queue->setScopeEnvelope(null);

            return;
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException((string)__('scope_envelope 必须是数组。'));
        }
        $queue->setScopeEnvelope($this->parseScopeEnvelopeParam($raw));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function parseScopeEnvelopeParam(array $raw): ScopeEnvelope
    {
        if (\array_key_exists('context_version', $raw) && \array_key_exists('envelope_version', $raw)) {
            return ScopeEnvelope::fromArray($raw);
        }
        if (\array_key_exists('envelope_version', $raw)
            && !\array_key_exists('context_version', $raw)
            && \array_key_exists('scope_kind', $raw)
            && \array_key_exists('website_id', $raw)
            && \array_key_exists('website_code', $raw)
            && \array_key_exists('store_code', $raw)
            && \array_key_exists('channel_code', $raw)
            && \array_key_exists('store_mode', $raw)
        ) {
            return ScopeEnvelope::fromV1StorageArray([
                'scope_kind' => $raw['scope_kind'],
                'website_id' => $raw['website_id'],
                'website_code' => $raw['website_code'],
                'store_code' => $raw['store_code'],
                'channel_code' => $raw['channel_code'],
                'store_mode' => $raw['store_mode'],
                'envelope_version' => $raw['envelope_version'],
            ]);
        }

        throw new \InvalidArgumentException(
            (string)__('scope_envelope 必须是 ScopeEnvelope::toArray() 或 Queue v1 固定列形状。')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeQueueContent(string $content): array
    {
        $decoded = \json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{success: bool, dispatched: bool, queue_id: int, status: string, data: array<string, mixed>}
     */
    private function dispatchQueue(array $params): array
    {
        $queueId = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        if ($queueId <= 0) {
            throw new \InvalidArgumentException((string)__('请提供有效的 queue_id。'));
        }
        $queue = $this->loadQueueFreshById($queueId);
        if ((int)$queue->getId() <= 0) {
            throw new \InvalidArgumentException((string)__('队列不存在。'));
        }

        $connection = $this->queueModel->getConnection();
        $eligible = (bool)$queue->getAuto() && $queue->getStatus() === Queue::status_pending;
        $dispatchDeferred = $eligible && $this->transactions->isActive($connection);
        $dispatched = false;
        if ($dispatchDeferred) {
            $this->transactions->afterCommit(
                $connection,
                'queue:dispatch:' . $queueId,
                function () use ($queueId): void {
                    $committed = $this->loadQueueFreshById($queueId);
                    if ((int)$committed->getId() > 0) {
                        $this->maybeDispatchAutoQueue($committed);
                    }
                },
            );
        } else {
            $dispatched = $this->maybeDispatchAutoQueue($queue);
        }
        $queue = $this->loadQueueFreshById($queueId);

        return [
            'success' => true,
            'dispatched' => $dispatched,
            'dispatch_deferred' => $dispatchDeferred,
            'queue_id' => $queueId,
            'status' => (string)$queue->getStatus(),
            'data' => $queue->getData(),
        ];
    }

    private function loadQueueFreshById(int $queueId): Queue
    {
        $queue = clone $this->queueModel;
        $queue->clearData()->clearQuery()
            ->where(Queue::schema_fields_ID, $queueId)
            ->find()
            ->fetch();

        return $queue;
    }

    private function maybeDispatchAutoQueue(Queue $queue): bool
    {
        if ((int)$queue->getId() <= 0) {
            return false;
        }
        if (!(bool)$queue->getAuto()) {
            return false;
        }
        if ((string)$queue->getStatus() !== Queue::status_pending) {
            return false;
        }

        try {
            return $this->queueDispatchService()->dispatchQueueIfEligible($queue);
        } catch (\Throwable) {
            // Cron remains the fallback scheduler; never fail create/dispatch API
            // because the worker spawn path is temporarily unavailable.
            return false;
        }
    }

    private function queueDispatchService(): QueueDispatchService
    {
        return ObjectManager::getInstance(QueueDispatchService::class);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'queue',
            'name' => __('Queue 统一服务'),
            'description' => __('任务队列增删改查与统计，模块间请使用 w_query(\'queue\', ...) 代替直接使用 Queue 模型。'),
            'module' => 'Weline_Queue',
            'operations' => [
                [
                    'name' => 'get',
                    'description' => __('获取单条队列记录'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => true, 'description' => __('队列主键')],
                    ],
                ],
                [
                    'name' => 'load',
                    'description' => __('同 get'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => true, 'description' => __('队列主键')],
                    ],
                ],
                [
                    'name' => 'getByBizKey',
                    'description' => __('按 biz_key 精确查询一条（多命中取最新 queue_id）'),
                    'params' => [
                        ['name' => 'biz_key', 'type' => 'string', 'required' => true, 'description' => __('业务检索键')],
                    ],
                ],
                [
                    'name' => 'list',
                    'description' => __('分页列出队列'),
                    'params' => [
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'description' => __('页码')],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'description' => __('每页条数')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块名')],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => __('状态')],
                        ['name' => 'type_id', 'type' => 'int', 'required' => false, 'description' => __('类型 ID')],
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'description' => __('主键筛选')],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'description' => __('业务键')],
                        ['name' => 'q', 'type' => 'string', 'required' => false, 'description' => __('模糊搜索')],
                    ],
                ],
                [
                    'name' => 'stats',
                    'description' => __('各状态数量'),
                    'params' => [],
                ],
                [
                    'name' => 'getTypeIdByClass',
                    'description' => __('按处理器类解析 type_id'),
                    'params' => [
                        ['name' => 'class', 'type' => 'string', 'required' => true, 'description' => __('QueueInterface 实现类全名')],
                    ],
                ],
                [
                    'name' => 'create',
                    'description' => __('创建队列任务'),
                    'params' => [
                        ['name' => 'type_id', 'type' => 'int', 'required' => false, 'description' => __('与 class 二选一')],
                        ['name' => 'class', 'type' => 'string', 'required' => false, 'description' => __('处理器类全名')],
                        ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => __('任务名称')],
                        ['name' => 'module', 'type' => 'string', 'required' => true, 'description' => __('所属模块名')],
                        ['name' => 'content', 'type' => 'string|array', 'required' => false, 'description' => __('JSON 或数组；禁止混入 Scope 字段')],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => __('仅允许 pending')],
                        ['name' => 'auto', 'type' => 'bool', 'required' => false, 'description' => __('是否参与自动消费')],
                        ['name' => 'biz_key', 'type' => 'string|null', 'required' => false, 'description' => __('业务检索键')],
                        ['name' => 'scope_envelope', 'type' => 'array', 'required' => false, 'description' => __('唯一 Scope 入参；展开为 Queue v1 固定列。省略时新建行在 save_before 捕获当前请求 Scope（无上下文→global）')],
                        ['name' => 'dispatch', 'type' => 'bool', 'required' => false, 'description' => __('默认 true；false 时仅创建，后续用 dispatch 操作显式派发')],
                    ],
                ],
                [
                    'name' => 'createIfAbsent',
                    'description' => __('按 idempotency_key 幂等创建；默认立即尝试派发，可用 dispatch=false 延后'),
                    'params' => [
                        ['name' => 'class', 'type' => 'string', 'required' => false, 'description' => __('与 type_id 二选一')],
                        ['name' => 'type_id', 'type' => 'int', 'required' => false, 'description' => __('与 class 二选一')],
                        ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => __('任务名称')],
                        ['name' => 'module', 'type' => 'string', 'required' => true, 'description' => __('所属模块名')],
                        ['name' => 'content', 'type' => 'string|array', 'required' => false, 'description' => __('JSON 或数组；禁止混入 Scope 字段')],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => __('仅允许 pending')],
                        ['name' => 'auto', 'type' => 'bool', 'required' => false, 'description' => __('是否参与自动消费')],
                        ['name' => 'biz_key', 'type' => 'string|null', 'required' => false, 'description' => __('业务检索键')],
                        ['name' => 'scope_envelope', 'type' => 'array', 'required' => false, 'description' => __('唯一 Scope 入参；展开为 Queue v1 固定列')],
                        ['name' => 'idempotency_key', 'type' => 'string', 'required' => true, 'description' => __('幂等键')],
                        ['name' => 'idempotency_scope', 'type' => 'string', 'required' => false, 'description' => __('幂等作用域')],
                        ['name' => 'dispatch', 'type' => 'bool', 'required' => false, 'description' => __('默认 true；false 时仅创建，后续用 dispatch 操作显式派发')],
                    ],
                ],
                [
                    'name' => 'dispatch',
                    'description' => __('立即尝试派发一条 pending+auto 队列到后台 Worker'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => true, 'description' => __('队列主键')],
                    ],
                ],
                [
                    'name' => 'update',
                    'description' => __('更新无活动执行代次的 pending 队列业务字段（queue_id 或 biz_key）'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'description' => __('与 biz_key 二选一')],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'description' => __('定位键')],
                        ['name' => 'patch', 'type' => 'array', 'required' => false, 'description' => __('允许字段：name/module/content/result/process/biz_key/auto/type_id；禁止直接修改状态与 dispatch fence')],
                    ],
                ],
                [
                    'name' => 'stop',
                    'description' => __('安全释放当前 Queue attempt 并将状态置为 stop，不在当前请求中执行消费者。'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'description' => __('队列主键')],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'description' => __('业务键')],
                    ],
                ],
                [
                    'name' => 'takeover',
                    'description' => __('安全释放当前 Queue attempt 并重置为 pending，不在当前请求中执行消费者。'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'description' => __('队列主键')],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'description' => __('业务键')],
                        ['name' => 'force', 'type' => 'bool', 'required' => false, 'description' => __('为 true 时仅在受管身份确认后终止当前 Worker')],
                        ['name' => 'owner', 'type' => 'string', 'required' => false, 'description' => __('接管所有者：system_scheduler 或 manual_cli')],
                        ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => __('接管原因')],
                    ],
                ],
                [
                    'name' => 'delete',
                    'description' => __('删除队列（queue_id 或 biz_key；活动或脏执行代次需 force）'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'description' => __('主键')],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'description' => __('业务键')],
                        ['name' => 'force', 'type' => 'bool', 'required' => false, 'description' => __('为 true 时仅在受管身份确认并释放后删除活动或脏执行代次')],
                    ],
                ],
            ],
        ];
    }
}
