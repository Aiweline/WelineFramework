<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Backend\Api\UserData\BackendCurrentUserDataInterface;
use Weline\Eav\Api\Attribute\AttributeDependenceResolverInterface;
use Weline\Eav\Api\EavAttribute;
use Weline\Framework\Database\Connection\Api\Sql\WriteIntentQueryInterface;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;
use Weline\Queue\QueueInterface;

/**
 * Shared application service for authenticated Queue backend pages.
 *
 * Controller routes remain as navigation/no-script compatibility entries,
 * while browser business requests enter through QueueAdminQueryProvider. Both
 * mutation paths delegate here so transactions, events and safety fencing
 * cannot drift apart. Listing projection and partial rendering belong to the
 * QueueAdminListingView presentation object.
 */
final class QueueAdminService
{
    public const MAX_BATCH_SIZE = 100;

    private const PUBLIC_ACTIONS = ['delete', 'stop', 'continue', 'retry', 'reset'];
    private const PUBLIC_BATCH_ACTIONS = ['delete', 'stop', 'continue'];
    public function __construct(
        private readonly QueueDispatchService $queueDispatch,
        private readonly RuntimeProviderResolver $runtimeProviders,
        private readonly EventsManager $eventsManager,
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true,code:200,msg:string,data:list<array<string,mixed>>}
     */
    public function searchTypes(array $params): array
    {
        $search = $this->boundedString($params['q'] ?? '', 120, 'q');
        $module = $this->boundedString($params['module'] ?? '', 128, 'module');
        $dir = $this->boundedString($params['dir'] ?? '', 128, 'dir');
        $typeModel = $this->newType();
        if ($search !== '') {
            $typeModel->where(Type::schema_fields_name, '%' . $search . '%', 'LIKE');
        }
        if ($module !== '') {
            $typeModel->where(Type::schema_fields_module_name, $module);
        }
        if ($dir !== '') {
            $escapedDir = \str_replace('\\', '\\\\', \ucfirst($dir));
            $typeModel->where(Type::schema_fields_class, '%' . $escapedDir . '%', 'LIKE');
        }
        $rows = $typeModel->where(Type::schema_fields_enable, 1)
            ->pagination(1, 100)
            ->select()
            ->fetchArray();
        $items = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $class = (string)($row[Type::schema_fields_class] ?? '');
            $tip = \htmlspecialchars(
                (string)($row[Type::schema_fields_tip] ?? ''),
                \ENT_QUOTES | \ENT_SUBSTITUTE,
                'UTF-8',
            );
            $items[] = [
                'type_id' => (int)($row[Type::schema_fields_ID] ?? 0),
                'name' => (string)($row[Type::schema_fields_name] ?? ''),
                'module_name' => (string)($row[Type::schema_fields_module_name] ?? ''),
                'class' => $class,
                'tip' => \nl2br($tip) . '<hr><br><span class="w-text" data-tone="primary">'
                    . \htmlspecialchars((string)__('执行类：'), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                    . \htmlspecialchars($class, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                    . '</span>',
            ];
        }

        return ['success' => true, 'code' => 200, 'msg' => '', 'data' => $items];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:bool,code:int,msg:string,data:list<array<string,mixed>>}
     */
    public function typeAttributes(array $params): array
    {
        $queueId = (int)($params['queue_id'] ?? 0);
        $typeId = (int)($params['type_id'] ?? 0);
        if ($typeId <= 0) {
            return $this->readFailure((string)__('请选择队列类型后再操作！'));
        }
        $type = $this->loadType($typeId);
        if ((int)$type->getId() <= 0 || !$type->getEnable()) {
            return $this->readFailure((string)__('队列类型不存在或已停用。'));
        }

        $queue = $this->newQueue();
        if ($queueId > 0) {
            $this->loadQueueFresh($queueId, $queue);
            if ((int)$queue->getId() <= 0) {
                return $this->readFailure((string)__('队列不存在'));
            }
        }
        $userData = $this->currentUserData()->getScope('queue');
        $options = [
            'label_class' => 'control-label',
            'attrs' => [
                'class' => 'form-control w-100',
                'scope' => 'queue',
                'file-ext' => '*',
                'file-size' => '102400000',
            ],
            'need_array' => 1,
            'values' => $userData,
        ];
        if ((int)$queue->getId() > 0) {
            $options['entity'] = $queue;
        } else {
            $options['eav_entity_id'] = $queue->getEavEntityId();
        }
        $attributes = $type->getAttributes($options);
        $attributeCodes = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute)) {
                continue;
            }
            $code = \trim((string)($attribute['code'] ?? ''));
            if ($code !== '') {
                $attributeCodes[$code] = true;
            }
        }
        $items = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute)) {
                continue;
            }
            $code = \trim((string)($attribute['code'] ?? ''));
            $items[] = [
                'code' => $code,
                'name' => (string)($attribute['name'] ?? ''),
                'required' => !empty($attribute['required']) || !empty($attribute['is_required']),
                'html' => $this->stripAttributeScripts((string)($attribute['html'] ?? '')),
                'value' => $attribute['value'] ?? null,
                'dependence' => $this->normalizeDependenceCodes(
                    $attribute[EavAttribute::schema_fields_dependence] ?? '',
                    $attributeCodes,
                ),
            ];
        }

        return ['success' => true, 'code' => 200, 'msg' => '', 'data' => $items];
    }

    /**
     * Resolve one Queue attribute's options from another Queue attribute.
     *
     * The browser may select only the Queue type and attribute codes. Entity
     * identity and resolver implementation are fixed on the server so callers
     * cannot cross an EAV entity boundary or choose an executable class.
     *
     * @param array<string,mixed> $params
     * @return array{success:bool,code:int,msg:string,data:array}
     */
    public function resolveAttributeDependence(array $params): array
    {
        $typeId = (int)($params['type_id'] ?? 0);
        if ($typeId <= 0) {
            return $this->dependenceFailure((string)__('请选择队列类型后再操作！'));
        }
        $type = $this->loadType($typeId);
        if ((int)$type->getId() <= 0 || !$type->getEnable()) {
            return $this->dependenceFailure((string)__('队列类型不存在或已停用。'), 404);
        }

        try {
            $attributeCode = $this->boundedString($params['attribute'] ?? '', 255, 'attribute');
            $dependenceCode = $this->boundedString(
                $params['dependence_attribute'] ?? '',
                255,
                'dependence_attribute',
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->dependenceFailure($exception->getMessage());
        }
        if ($attributeCode === '' || $dependenceCode === '') {
            return $this->dependenceFailure((string)__('队列属性编码不能为空。'));
        }
        if (!\array_key_exists('dependence_value', $params)) {
            return $this->dependenceFailure((string)__('需要选择依赖值'));
        }

        try {
            $queue = $this->newQueue();
            $eavEntityId = $queue->getEavEntityId();
            if ($eavEntityId <= 0) {
                throw new \DomainException((string)__('Queue EAV 实体不存在。'));
            }
            $attributeCodes = $this->queueTypeAttributeCodes($type, $eavEntityId);
            if (!isset($attributeCodes[$attributeCode]) || !isset($attributeCodes[$dependenceCode])) {
                return $this->dependenceFailure((string)__('队列属性不属于所选类型。'));
            }

            $resolver = $this->runtimeProviders->resolve(AttributeDependenceResolverInterface::class);
            if (!$resolver instanceof AttributeDependenceResolverInterface) {
                throw new \RuntimeException('attribute_dependence_resolver_unavailable');
            }
            $data = $resolver->resolve([
                'eav_entity_id' => $eavEntityId,
                'attribute' => $attributeCode,
                'dependence_attribute' => $dependenceCode,
                'dependence_value' => $params['dependence_value'],
                'attribute_value' => $params['attribute_value'] ?? '',
            ]);

            return [
                'success' => true,
                'code' => 200,
                'msg' => '',
                'data' => $data,
            ];
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->dependenceFailure($exception->getMessage());
        } catch (\Throwable) {
            return $this->dependenceFailure((string)__('解析队列属性依赖失败，请稍后重试。'), 503);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function save(array $params): array
    {
        $queueId = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $typeId = (int)($params['type_id'] ?? 0);
        if ($queueId < 0 || $typeId <= 0) {
            return $this->saveFailure((string)__('请选择有效的队列类型。'));
        }
        $type = $this->loadType($typeId);
        if ((int)$type->getId() <= 0 || !$type->getEnable()) {
            return $this->saveFailure((string)__('队列类型不存在或已停用。'));
        }
        $module = \trim($type->getModuleName());
        if ($module === '') {
            return $this->saveFailure((string)__('队列类型缺少所属模块。'));
        }
        try {
            $name = $this->boundedString($params['name'] ?? '', 255, 'name');
        } catch (\InvalidArgumentException $exception) {
            return $this->saveFailure($exception->getMessage());
        }
        if ($name === '') {
            $name = $type->getName();
        }
        if ($name === '') {
            return $this->saveFailure((string)__('队列名称不能为空。'));
        }
        $attributes = $params['attributes'] ?? [];
        if (!\is_array($attributes) || !\array_is_list($attributes) || \count($attributes) > 100) {
            return $this->saveFailure((string)__('队列属性参数格式无效。'));
        }

        $queue = $this->newQueue();
        try {
            $normalizedAttributes = $this->validateSubmittedAttributes(
                $type,
                $attributes,
                $queue->getEavEntityId(),
            );
        } catch (\Throwable $throwable) {
            return $this->saveFailure($this->publicSaveError($throwable));
        }

        if (TransactionContext::transactionState($queue->getConnection()->getConnector()) !== null) {
            return $this->saveFailure((string)__('Queue 原子编辑不能嵌套在已有数据库事务中。'));
        }
        $transactionQuery = $queue->getQuery();
        if (!$transactionQuery instanceof WriteIntentQueryInterface) {
            return $this->saveFailure((string)__('当前数据库不支持 Queue 原子编辑。'));
        }

        $editing = $queueId > 0;
        $transactionQuery->beginWriteTransaction();
        try {
            if ($editing) {
                $this->loadQueueFresh($queueId, $queue);
                if ((int)$queue->getId() <= 0) {
                    throw new \DomainException((string)__('队列不存在'));
                }
                $updates = [
                    Queue::schema_fields_type_id => $typeId,
                    Queue::schema_fields_name => $name,
                    Queue::schema_fields_module => $module,
                ];
                if (\array_key_exists('biz_key', $params)) {
                    $bizKey = $this->boundedString($params['biz_key'], 191, 'biz_key');
                    $queue->setBizKey($bizKey !== '' ? $bizKey : null);
                    $updates[Queue::schema_fields_BIZ_KEY] = $queue->getData(Queue::schema_fields_BIZ_KEY);
                }
                $updateResult = $this->queueDispatch->updatePendingQueueSafely($queueId, $updates);
                if (empty($updateResult['confirmed'])) {
                    throw new \DomainException((string)($updateResult['message'] ?? __('队列编辑失败。')));
                }
            } else {
                $queue->setTypeId($typeId)->setName($name)->setModule($module);
                if (\array_key_exists('biz_key', $params)) {
                    $bizKey = $this->boundedString($params['biz_key'], 191, 'biz_key');
                    $queue->setBizKey($bizKey !== '' ? $bizKey : null);
                }
                $queueId = (int)$queue->save();
            }

            $this->loadQueueFresh($queueId, $queue);
            if ($queueId <= 0 || (int)$queue->getId() <= 0) {
                throw new \DomainException((string)__('创建队列失败'));
            }

            // The pending-row CAS keeps the main row locked while all EAV
            // values are written, so a Worker can never observe a half edit.
            foreach ($normalizedAttributes as $attribute) {
                $queueAttribute = $attribute['attribute'];
                if (!$queueAttribute instanceof EavAttribute || $queueAttribute->getAttributeId() <= 0) {
                    throw new \DomainException((string)__('队列属性编码不存在：%{1}', $attribute['code']));
                }
                $queueAttribute->setValue($queueId, $attribute['value']);
            }

            $consumer = ObjectManager::getInstance($type->getClass());
            if (!$consumer instanceof QueueConsumerInterface && !$consumer instanceof QueueInterface) {
                throw new \DomainException((string)__('队列消费者未实现受支持的消费接口。'));
            }
            if (!$consumer->validate($queue)) {
                throw new \DomainException((string)__(
                    '队列校验失败，校验消息：%{1}',
                    $queue->getResult(),
                ));
            }
            $transactionQuery->commit();
        } catch (\Throwable $throwable) {
            $this->rollBack($transactionQuery);
            $message = $this->publicSaveError($throwable);
            if ($editing && $queueId > 0) {
                $this->recordPendingEditError($queueId, $message);
            }
            return $this->saveFailure($message);
        }

        $warnings = [];
        try {
            $this->loadQueueFresh($queueId, $queue);
        } catch (\Throwable $throwable) {
            $this->logPostCommitFailure('refresh', $throwable);
            $warnings[] = (string)__('队列已保存，但提交后的数据刷新未完成。');
        }
        $eventData = ['queue' => $queue];
        $eventWarning = $this->dispatchAfterCommit(
            'Weline_Queue::' . ($editing ? 'edit' : 'add'),
            $eventData,
        );
        if ($eventWarning !== null) {
            $warnings[] = $eventWarning;
        }
        try {
            $this->currentUserData()->clearScope('queue');
        } catch (\Throwable $throwable) {
            $this->logPostCommitFailure('clear_scope', $throwable);
            $warnings[] = (string)__('队列已保存，但临时表单数据未能清理。');
        }

        return [
            'success' => true,
            'code' => 200,
            'msg' => $editing
                ? (string)__('队列已编辑！等待运行中...')
                : (string)__('队列已成功创建！等待运行中...'),
            'queue_id' => $queueId,
            'created' => !$editing,
            'data' => $queue->getData(),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function action(array $params): array
    {
        $queueId = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $action = \trim((string)($params['action'] ?? ''));
        if ($queueId <= 0) {
            return $this->actionFailure($queueId, $action, (string)__('请选择要操作的队列'), 'invalid_queue_id');
        }
        if (!\in_array($action, self::PUBLIC_ACTIONS, true)) {
            return $this->actionFailure(
                $queueId,
                $action,
                (string)__('不支持的操作类型：%{1}', $action),
                'unsupported_action',
            );
        }
        $queue = $this->newQueue();
        $this->loadQueueFresh($queueId, $queue);
        if ((int)$queue->getId() <= 0) {
            return $this->actionFailure($queueId, $action, (string)__('队列记录不存在'), 'queue_not_found');
        }

        try {
            if ($action === 'delete') {
                $result = $this->queueDispatch->deleteQueueSafely($queueId, false);
                if (empty($result['confirmed'])) {
                    return $this->controlFailure($queueId, $action, $result, (string)__('队列删除失败。'));
                }
                if (\is_array($result['data'] ?? null)) {
                    $queue->clearData()->setData($result['data']);
                }
                $eventData = ['queue' => $queue];
                $eventWarning = $this->dispatchAfterCommit('Weline_Queue::delete', $eventData);

                $response = [
                    'success' => true,
                    'queue_id' => $queueId,
                    'action' => $action,
                    'removed' => true,
                    'msg' => (string)($result['message'] ?? __('队列已成功删除')),
                    'data' => $queue->getData(),
                ];
                if ($eventWarning !== null) {
                    $response['warnings'] = [$eventWarning];
                }

                return $response;
            }

            $result = $action === 'stop'
                ? $this->queueDispatch->stopQueueSafely($queueId)
                : $this->queueDispatch->requeueQueueSafely($queueId);
            if (empty($result['confirmed'])) {
                $fallback = $action === 'stop' ? __('队列暂停失败。') : __('队列继续失败。');
                return $this->controlFailure($queueId, $action, $result, (string)$fallback);
            }
            $warnings = [];
            try {
                $this->hydrateQueueFromResult($queueId, $queue, $result);
            } catch (\Throwable $throwable) {
                $this->logPostCommitFailure('control_refresh', $throwable);
                $warnings[] = (string)__('队列状态已更新，但提交后的数据刷新未完成。');
            }
            $eventName = $action === 'stop'
                ? 'stop'
                : ($action === 'reset' ? 'reset' : 'continue');
            $eventData = ['queue' => $queue];
            $eventWarning = $this->dispatchAfterCommit('Weline_Queue::' . $eventName, $eventData);
            if ($eventWarning !== null) {
                $warnings[] = $eventWarning;
            }
            $message = match ($action) {
                'stop' => (string)($result['message'] ?? __('队列已暂停')),
                'retry' => (string)__('队列已重试'),
                'reset' => (string)__('队列已重置'),
                default => (string)($result['message'] ?? __('队列已继续')),
            };

            return [
                'success' => true,
                'queue_id' => $queueId,
                'action' => $action,
                'removed' => false,
                'msg' => $message,
                'data' => $queue->getData(),
                'warnings' => $warnings,
            ];
        } catch (\Throwable $throwable) {
            return $this->actionFailure(
                $queueId,
                $action,
                (string)__('操作失败：%{1}', $throwable->getMessage()),
                'operation_failed',
            );
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function batchAction(array $params): array
    {
        $action = \trim((string)($params['action'] ?? ''));
        if (!\in_array($action, self::PUBLIC_BATCH_ACTIONS, true)) {
            return $this->batchFailure((string)__('不支持的批量操作。'));
        }
        $rawIds = $params['queue_ids'] ?? $params['ids'] ?? [];
        if (!\is_array($rawIds) || !\array_is_list($rawIds) || $rawIds === []) {
            return $this->batchFailure((string)__('请选择要操作的队列'));
        }
        if (\count($rawIds) > self::MAX_BATCH_SIZE) {
            return $this->batchFailure((string)__('单次最多操作 %{1} 条队列。', self::MAX_BATCH_SIZE));
        }
        $ids = [];
        foreach ($rawIds as $rawId) {
            if ((!\is_int($rawId) && !(\is_string($rawId) && \ctype_digit($rawId))) || (int)$rawId <= 0) {
                return $this->batchFailure((string)__('批量队列 ID 格式无效。'));
            }
            $ids[(int)$rawId] = (int)$rawId;
        }

        $successCount = 0;
        $failureCount = 0;
        $results = [];
        foreach (\array_values($ids) as $queueId) {
            $result = $this->action(['queue_id' => $queueId, 'action' => $action]);
            $success = !empty($result['success']);
            $success ? $successCount++ : $failureCount++;
            $results[] = [
                'queue_id' => $queueId,
                'success' => $success,
                'msg' => (string)($result['msg'] ?? ''),
            ];
        }

        return [
            'success' => $successCount > 0,
            'partial' => $successCount > 0 && $failureCount > 0,
            'msg' => (string)__('操作完成。成功：%{1}，失败：%{2}', [$successCount, $failureCount]),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results,
        ];
    }

    /** @return array<string,mixed> */
    public function setTypeEnabled(array $params): array
    {
        $typeId = (int)($params['type_id'] ?? 0);
        if ($typeId <= 0 || !\array_key_exists('enabled', $params)) {
            return [
                'success' => false,
                'msg' => (string)__('请选择要操作的队列类型'),
                'type_id' => $typeId,
                'enabled' => false,
            ];
        }
        $enabled = \filter_var($params['enabled'], \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return [
                'success' => false,
                'msg' => (string)__('队列类型启用状态无效。'),
                'type_id' => $typeId,
                'enabled' => false,
            ];
        }
        $type = $this->loadType($typeId);
        if ((int)$type->getId() <= 0) {
            return [
                'success' => false,
                'msg' => (string)__('队列类型不存在'),
                'type_id' => $typeId,
                'enabled' => $enabled,
            ];
        }
        if ($type->getEnable() !== $enabled) {
            $type->setEnable($enabled)->save();
        }
        $message = $enabled
            ? (string)__('队列类型 "%{1}" 已成功启用', $type->getName())
            : (string)__('队列类型 "%{1}" 已成功禁用', $type->getName());

        return [
            'success' => true,
            'msg' => $message,
            'type_id' => $typeId,
            'enabled' => $enabled,
            'data' => $type->getData(),
        ];
    }

    /**
     * @param array<string,true> $allowedCodes
     * @return list<string>
     */
    private function normalizeDependenceCodes(mixed $dependence, array $allowedCodes): array
    {
        $values = \is_array($dependence) ? $dependence : \explode(',', (string)$dependence);
        $codes = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }
            $code = \trim((string)$value);
            if ($code === '' || \strlen($code) > 255 || !isset($allowedCodes[$code])) {
                continue;
            }
            $codes[$code] = true;
        }

        return \array_keys($codes);
    }

    private function stripAttributeScripts(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $withoutBlocks = \preg_replace(
            '~<script\b[^>]*>[\s\S]*?</script\s*>~i',
            '',
            $html,
        );
        if (!\is_string($withoutBlocks)) {
            return '';
        }
        $withoutTags = \preg_replace('~<script\b[^>]*/?\s*>~i', '', $withoutBlocks);

        return \is_string($withoutTags) ? $withoutTags : '';
    }

    /** @return array<string,true> */
    private function queueTypeAttributeCodes(Type $type, int $eavEntityId): array
    {
        return \array_fill_keys(
            \array_keys($this->queueTypeAttributes($type, $eavEntityId)),
            true,
        );
    }

    /** @return array<string,EavAttribute> */
    private function queueTypeAttributes(Type $type, int $eavEntityId): array
    {
        // The public EAV model name is a runtime class_alias. `instanceof`
        // does not autoload an unresolved alias, so resolve it explicitly
        // before validating model instances returned by the legacy ORM.
        \class_exists(EavAttribute::class);
        $options = [
            'eav_entity_id' => $eavEntityId,
            'no_html' => 1,
        ];
        $attributes = $type->getAttributes($options);
        $byCode = [];
        foreach ($attributes as $attribute) {
            if (!$attribute instanceof EavAttribute) {
                continue;
            }
            $code = \trim($attribute->getCode());
            if ($code !== '') {
                $byCode[$code] = $attribute;
            }
        }

        return $byCode;
    }

    /**
     * @param list<mixed> $attributes
     * @return list<array{code:string,value:array|string|int,attribute:EavAttribute}>
     */
    private function validateSubmittedAttributes(Type $type, array $attributes, int $eavEntityId): array
    {
        if ($eavEntityId <= 0) {
            throw new \DomainException((string)__('Queue EAV 实体不存在。'));
        }
        $definedAttributes = $this->queueTypeAttributes($type, $eavEntityId);
        $allowedCodes = \array_fill_keys(\array_keys($definedAttributes), true);
        $normalized = [];
        $submittedValues = [];
        foreach ($attributes as $value) {
            if (!\is_array($value)) {
                throw new \DomainException((string)__('队列属性参数格式无效。'));
            }
            $code = \trim((string)($value['code'] ?? ''));
            if ($code === '' || \strlen($code) > 255 || \array_key_exists($code, $submittedValues)) {
                throw new \DomainException((string)__('队列属性编码无效或重复。'));
            }
            $attribute = $definedAttributes[$code] ?? null;
            if (!$attribute instanceof EavAttribute || $attribute->getAttributeId() <= 0) {
                throw new \DomainException((string)__(
                    '队列属性编码不存在或不属于所选类型：%{1}',
                    $code,
                ));
            }
            $submittedValues[$code] = $this->normalizeSubmittedAttributeValue($value['value'] ?? null);
            $normalized[] = [
                'code' => $code,
                'value' => $submittedValues[$code],
                'attribute' => $attribute,
            ];
        }

        foreach ($definedAttributes as $code => $attribute) {
            if (!$attribute->getTypeModel()->getRequired()) {
                continue;
            }
            $dependencies = $this->normalizeDependenceCodes($attribute->getDependence(), $allowedCodes);
            if ($dependencies !== [] && !$this->dependenciesAreActive($dependencies, $submittedValues)) {
                continue;
            }
            if (!\array_key_exists($code, $submittedValues)
                || !$this->hasRequiredValue($submittedValues[$code])) {
                throw new \DomainException((string)__('队列属性值不能为空：%{1}', $attribute->getName()));
            }
        }

        return $normalized;
    }

    /** @param list<string> $dependencies @param array<string,mixed> $submittedValues */
    private function dependenciesAreActive(array $dependencies, array $submittedValues): bool
    {
        foreach ($dependencies as $dependency) {
            if (!\array_key_exists($dependency, $submittedValues)
                || !$this->hasRequiredValue($submittedValues[$dependency])) {
                return false;
            }
        }

        return true;
    }

    private function hasRequiredValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (\is_string($value)) {
            return \trim($value) !== '';
        }
        if (\is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /** @return array|string|int */
    private function normalizeSubmittedAttributeValue(mixed $value): array|string|int
    {
        if ($value === null) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) || $value instanceof \Stringable) {
            $value = (string)$value;
        }
        if (\is_string($value)) {
            if (\strlen($value) > 1048576) {
                throw new \DomainException((string)__('单个队列属性值不能超过 1 MiB。'));
            }
            return $value;
        }
        if (!\is_array($value) || !\array_is_list($value) || \count($value) > 500) {
            throw new \DomainException((string)__('队列属性值格式无效。'));
        }
        $normalized = [];
        foreach ($value as $item) {
            if (\is_bool($item)) {
                $item = $item ? 1 : 0;
            } elseif (\is_float($item) || $item instanceof \Stringable) {
                $item = (string)$item;
            }
            if (!\is_string($item) && !\is_int($item)) {
                throw new \DomainException((string)__('队列属性值格式无效。'));
            }
            if (\is_string($item) && \strlen($item) > 1048576) {
                throw new \DomainException((string)__('单个队列属性值不能超过 1 MiB。'));
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    private function publicSaveError(\Throwable $throwable): string
    {
        if ($throwable instanceof \DomainException
            || $throwable instanceof \InvalidArgumentException
            || $throwable instanceof ModelException
            || $throwable instanceof \ReflectionException
            || $throwable instanceof \Weline\Framework\App\Exception
            || $throwable instanceof Core) {
            return $throwable->getMessage();
        }

        return (string)__('保存队列失败，请稍后重试。');
    }

    /** @return array{success:false,code:422,msg:string} */
    private function saveFailure(string $message): array
    {
        return ['success' => false, 'code' => 422, 'msg' => $message];
    }

    /** @return array{success:false,code:404,msg:string,data:array} */
    private function readFailure(string $message): array
    {
        return ['success' => false, 'code' => 404, 'msg' => $message, 'data' => []];
    }

    /** @return array{success:false,code:int,msg:string,data:array} */
    private function dependenceFailure(string $message, int $code = 422): array
    {
        return ['success' => false, 'code' => $code, 'msg' => $message, 'data' => []];
    }

    /** @return array<string,mixed> */
    private function actionFailure(int $queueId, string $action, string $message, string $errorCode): array
    {
        return [
            'success' => false,
            'queue_id' => $queueId,
            'action' => $action,
            'msg' => $message,
            'error_code' => $errorCode,
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function controlFailure(int $queueId, string $action, array $result, string $fallback): array
    {
        return $this->actionFailure(
            $queueId,
            $action,
            (string)($result['message'] ?? $fallback),
            (string)($result['error_code'] ?? 'not_confirmed'),
        ) + ['retryable' => !empty($result['retryable'])];
    }

    /** @param array<string,mixed> $eventData */
    private function dispatchAfterCommit(string $eventName, array &$eventData): ?string
    {
        try {
            $this->eventsManager->dispatch($eventName, $eventData);
            return null;
        } catch (\Throwable $throwable) {
            $this->logPostCommitFailure('event:' . $eventName, $throwable);
            return (string)__('队列状态已保存，但后续事件通知未完全完成。');
        }
    }

    private function logPostCommitFailure(string $stage, \Throwable $throwable): void
    {
        try {
            if (\function_exists('w_log_error')) {
                \w_log_error('Queue admin post-commit ' . $stage . ' failed: ' . $throwable->getMessage());
            }
        } catch (\Throwable) {
            // Reporting must never reverse an already committed Queue state.
        }
    }

    /** @return array<string,mixed> */
    private function batchFailure(string $message): array
    {
        return [
            'success' => false,
            'partial' => false,
            'msg' => $message,
            'success_count' => 0,
            'failure_count' => 0,
            'results' => [],
        ];
    }

    /** @param array<string,mixed> $result */
    private function hydrateQueueFromResult(int $queueId, Queue $queue, array $result): Queue
    {
        if (\is_array($result['data'] ?? null)) {
            return $queue->clearData()->setData($result['data']);
        }

        return $this->loadQueueFresh($queueId, $queue);
    }

    private function recordPendingEditError(int $queueId, string $message): void
    {
        try {
            $this->queueDispatch->updatePendingQueueSafely($queueId, [
                Queue::schema_fields_result => $message,
            ]);
        } catch (\Throwable) {
            // Preserve the original save failure; diagnostic persistence is
            // best effort and must not replace it.
        }
    }

    private function rollBack(WriteIntentQueryInterface $transactionQuery): void
    {
        try {
            $transactionQuery->rollBack();
        } catch (\Throwable) {
            // A failed physical connection may already have been discarded.
        }
    }

    private function currentUserData(): BackendCurrentUserDataInterface
    {
        $provider = $this->runtimeProviders->resolve(BackendCurrentUserDataInterface::class);
        if (!$provider instanceof BackendCurrentUserDataInterface) {
            throw new \RuntimeException('backend_current_user_data_provider_unavailable');
        }

        return $provider;
    }

    private function newQueue(): Queue
    {
        /** @var Queue $queue */
        $queue = ObjectManager::make(Queue::class);
        $queue->clearData()->clearQuery();

        return $queue;
    }

    private function newType(): Type
    {
        /** @var Type $type */
        $type = ObjectManager::make(Type::class);
        $type->clearData()->clearQuery();

        return $type;
    }

    private function loadType(int $typeId): Type
    {
        $type = $this->newType();
        $type->where(Type::schema_fields_ID, $typeId)->find()->fetch();

        return $type;
    }

    private function loadQueueFresh(int $queueId, Queue $queue): Queue
    {
        $queue->clearData()->clearQuery()
            ->where(Queue::schema_fields_ID, $queueId)
            ->find()
            ->fetch();

        return $queue;
    }

    private function boundedString(mixed $value, int $maxLength, string $field): string
    {
        $value = \trim((string)$value);
        if (\strlen($value) > $maxLength) {
            throw new \InvalidArgumentException((string)__('%{1} 长度不能超过 %{2}。', [$field, $maxLength]));
        }

        return $value;
    }
}
