<?php

declare(strict_types=1);

namespace Weline\Queue\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Queue\Helper\Helper;
use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;

/**
 * 任务队列统一入口：模块间一律通过 w_query('queue', ...) 读写队列，避免直接依赖 Queue 模型类。
 *
 * 说明：实现 {@see \Weline\Queue\QueueInterface} 的消费类在 execute(Queue $queue) 中仍接收模型实例，属框架契约，与业务侧 CRUD 分离。
 */
class QueueQueryProvider implements QueryProviderInterface
{
    /** @var list<string> */
    private const VALID_STATUSES = [
        Queue::status_pending,
        Queue::status_running,
        Queue::status_done,
        Queue::status_error,
        Queue::status_stop,
    ];

    public function __construct(
        private readonly Queue $queueModel,
        private readonly Type $typeModel,
        private readonly EventsManager $eventsManager,
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
            'update' => $this->updateQueue($params),
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
        $queue = clone $this->queueModel;
        $queue->clearData()->load($queueId);
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
            $queue->where("concat(main_table.name,main_table.content,main_table.result) like '%$search%'");
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
     * @param array<string, mixed> $params type_id 或 class、name、module 必填；content、status、auto、biz_key 等可选
     * @return array{success: true, queue_id: int, data: array<string, mixed>}
     */
    private function createQueue(array $params): array
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
        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException((string)__('无效的 status。'));
        }

        $content = $this->normalizeContentParam($params['content'] ?? '');

        $queue = clone $this->queueModel;
        $queue->clearData();
        $queue->setTypeId($typeId)
            ->setName($name)
            ->setModule($module)
            ->setStatus($status)
            ->setContent($content)
            ->setAuto((bool)($params['auto'] ?? true));

        if (\array_key_exists('biz_key', $params)) {
            $rawBk = $params['biz_key'];
            if ($rawBk === null || $rawBk === '') {
                $queue->setBizKey(null);
            } else {
                $queue->setBizKey((string)$rawBk);
            }
        }

        $queueId = $queue->save(true);
        if ((int)$queueId <= 0) {
            $queueId = (int)$queue->getId();
        }
        if ($queueId <= 0) {
            throw new \RuntimeException((string)__('创建队列失败。'));
        }
        $queue->clearData()->load($queueId);

        $eventData = ['queue' => $queue];
        $this->eventsManager->dispatch('Weline_Queue::add', $eventData);

        return [
            'success' => true,
            'queue_id' => $queueId,
            'data' => $queue->getData(),
        ];
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

        $patch = $params['patch'] ?? null;
        if (\is_array($patch)) {
            $this->applyQueuePatch($queue, $patch, false);
        } else {
            $this->applyQueuePatch($queue, $params, true);
        }

        $queue->save();
        $queue->clearData()->load((int)$queue->getId());

        $eventData = ['queue' => $queue];
        $this->eventsManager->dispatch('Weline_Queue::edit', $eventData);

        return [
            'success' => true,
            'queue_id' => (int)$queue->getId(),
            'data' => $queue->getData(),
        ];
    }

    /**
     * 删除队列（与后台一致：运行中不可删，除非 force=true）
     *
     * @param array<string, mixed> $params queue_id 或 biz_key；force 可选
     * @return array{success: bool, message?: string, queue_id?: int}
     */
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

        if ($queue->getStatus() === Queue::status_running && !$force) {
            return [
                'success' => false,
                'message' => (string)__('队列正在运行中，无法删除。请先暂停或传 force=true（慎用）。'),
                'queue_id' => $queueId,
            ];
        }

        $queue->delete()->fetch();

        $eventData = ['queue' => $queue];
        $this->eventsManager->dispatch('Weline_Queue::delete', $eventData);

        return [
            'success' => true,
            'queue_id' => $queueId,
            'message' => (string)__('队列已删除。'),
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
            $queue->load($queueId);

            return $queue;
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
    private function applyQueuePatch(Queue $queue, array $patch, bool $flatParams): void
    {
        $skip = ['queue_id', 'patch', 'id', 'provider', 'operation', 'force', 'class'];
        if ($flatParams) {
            $skip[] = 'biz_key';
        }

        foreach ($patch as $key => $value) {
            $k = (string)$key;
            if (\in_array($k, $skip, true)) {
                continue;
            }

            match ($k) {
                'name' => $queue->setName(\trim((string)$value)),
                'module' => $queue->setModule(\trim((string)$value)),
                'status' => $this->applyStatusToQueue($queue, $value),
                'content' => $queue->setContent($this->normalizeContentValue($value)),
                'result' => $queue->setResult((string)$value),
                'process' => $queue->setProcess(\is_string($value) ? $value : (string)\json_encode($value, \JSON_UNESCAPED_UNICODE)),
                'biz_key' => $queue->setBizKey($value === null || $value === '' ? null : (string)$value),
                'auto' => $queue->setAuto((bool)$value),
                'finished' => $queue->setFinished((bool)$value),
                'pid' => $queue->setPid((int)$value),
                'type_id' => $queue->setTypeId((int)$value),
                default => null,
            };
        }
    }

    private function applyStatusToQueue(Queue $queue, mixed $value): void
    {
        $s = \trim((string)$value);
        if ($s === '') {
            return;
        }
        if (!$this->isValidStatus($s)) {
            throw new \InvalidArgumentException((string)__('无效的 status。'));
        }
        $queue->setStatus($s);
    }

    private function normalizeContentParam(mixed $content): string
    {
        if (\is_array($content)) {
            return (string)\json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        return (string)$content;
    }

    private function normalizeContentValue(mixed $value): string
    {
        if (\is_array($value)) {
            return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        return (string)$value;
    }

    private function isValidStatus(string $status): bool
    {
        return \in_array($status, self::VALID_STATUSES, true);
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
                        ['name' => 'content', 'type' => 'string|array', 'required' => false, 'description' => __('JSON 或数组')],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => __('默认 pending')],
                        ['name' => 'auto', 'type' => 'bool', 'required' => false, 'description' => __('是否参与自动消费')],
                        ['name' => 'biz_key', 'type' => 'string|null', 'required' => false, 'description' => __('业务检索键')],
                    ],
                ],
                [
                    'name' => 'update',
                    'description' => __('更新队列（queue_id 或 biz_key）'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'description' => __('与 biz_key 二选一')],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'description' => __('定位键')],
                        ['name' => 'patch', 'type' => 'array', 'required' => false, 'description' => __('字段补丁；也可顶层传 name/status/content 等')],
                    ],
                ],
                [
                    'name' => 'delete',
                    'description' => __('删除队列（queue_id 或 biz_key；运行中需 force）'),
                    'params' => [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'description' => __('主键')],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'description' => __('业务键')],
                        ['name' => 'force', 'type' => 'bool', 'required' => false, 'description' => __('为 true 时允许删除运行中任务')],
                    ],
                ],
            ],
        ];
    }
}
