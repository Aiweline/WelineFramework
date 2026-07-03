<?php
declare(strict_types=1);

namespace Weline\Trash\Service;

use Weline\Trash\Model\TrashItem;

class TrashService
{
    /** @var list<string> */
    private const OPEN_STATUSES = [
        TrashItem::STATUS_ACTIVE,
        TrashItem::STATUS_RESTORE_FAILED,
        TrashItem::STATUS_PURGE_FAILED,
    ];

    public function __construct(
        private readonly TrashItem $trashItemModel,
        private readonly TrashProviderRegistry $providerRegistry
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function delete(string $code, array $data, array $context = []): array
    {
        $definition = $this->requireDefinition($code);
        $provider = $definition['class'];
        $this->trashItemModel->beginTransaction();
        try {
            $result = $provider::trash($data, $context);
            if (empty($result['success'])) {
                $this->trashItemModel->rollBack();
                return [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('业务删除失败，未写入回收站。')),
                    'result' => $result,
                ];
            }

            $entityKey = trim((string)($result['entity_key'] ?? ''));
            $entityId = trim((string)($result['entity_id'] ?? ''));
            if ($entityKey === '') {
                $entityKey = $entityId !== '' ? $entityId : md5($this->encodeJson($result['raw_data'] ?? $data));
            }
            $existing = $this->findOpenItem($definition['code'], $entityKey);
            if ($existing !== null) {
                $this->trashItemModel->rollBack();
                return [
                    'success' => true,
                    'status' => 'already_in_trash',
                    'message' => (string)__('该数据已经在回收站中。'),
                    'item' => $existing->toApiArray(),
                ];
            }

            $now = date('Y-m-d H:i:s');
            $item = clone $this->trashItemModel;
            $item->setData(TrashItem::schema_fields_TRASH_CODE, $definition['code']);
            $item->setData(TrashItem::schema_fields_PROVIDER_LABEL, $definition['label']);
            $item->setData(TrashItem::schema_fields_ENTITY_ID, $entityId);
            $item->setData(TrashItem::schema_fields_ENTITY_KEY, $entityKey);
            $item->setData(TrashItem::schema_fields_LABEL, (string)($result['label'] ?? $entityKey));
            $item->setData(TrashItem::schema_fields_STATUS, TrashItem::STATUS_ACTIVE);
            $item->setData(TrashItem::schema_fields_SUMMARY_JSON, $this->encodeJson($result['summary'] ?? []));
            $item->setData(TrashItem::schema_fields_RAW_DATA_JSON, $this->encodeJson($result['raw_data'] ?? $data));
            $item->setData(TrashItem::schema_fields_SCOPE_JSON, $this->encodeJson($result['scope'] ?? []));
            $item->setData(TrashItem::schema_fields_CONTEXT_JSON, $this->encodeJson($context));
            $item->setData(TrashItem::schema_fields_PROVIDER_VERSION, (string)($result['provider_version'] ?? '1'));
            $item->setData(TrashItem::schema_fields_DELETED_BY, (string)($context['operator'] ?? $context['deleted_by'] ?? ''));
            $item->setData(TrashItem::schema_fields_DELETED_AT, (string)($result['deleted_at'] ?? $now));
            $item->save();
            $this->trashItemModel->commit();

            return [
                'success' => true,
                'message' => (string)($result['message'] ?? __('已移入回收站。')),
                'item' => $item->toApiArray(),
            ];
        } catch (\Throwable $e) {
            $this->trashItemModel->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function restore(int $trashId, array $context = []): array
    {
        $item = $this->requireItem($trashId);
        if (!$item->isOpen()) {
            return [
                'success' => false,
                'message' => (string)__('该回收站记录当前状态不可恢复。'),
                'item' => $item->toApiArray(),
            ];
        }

        $item->setData(TrashItem::schema_fields_LAST_RESTORE_ATTEMPT_AT, date('Y-m-d H:i:s'));
        $item->setData(TrashItem::schema_fields_LAST_RESTORE_ATTEMPT_BY, (string)($context['operator'] ?? $context['restored_by'] ?? ''));

        try {
            $definition = $this->requireDefinition($item->getTrashCode());
            $result = $definition['class']::restore($item->toApiArray(), $context);
            if (empty($result['success'])) {
                return $this->recordRestoreFailure($item, (string)($result['message'] ?? __('恢复失败。')), $result);
            }

            $item->setData(TrashItem::schema_fields_STATUS, TrashItem::STATUS_RESTORED);
            $item->setData(TrashItem::schema_fields_RESTORED_BY, (string)($context['operator'] ?? $context['restored_by'] ?? ''));
            $item->setData(TrashItem::schema_fields_RESTORED_AT, date('Y-m-d H:i:s'));
            $item->setData(TrashItem::schema_fields_LAST_ERROR, null);
            $item->setData(TrashItem::schema_fields_LAST_ERROR_CODE, '');
            $item->setData(TrashItem::schema_fields_LAST_ERROR_DETAIL_JSON, null);
            $item->save();

            return [
                'success' => true,
                'message' => (string)($result['message'] ?? __('恢复成功。')),
                'item' => $item->toApiArray(),
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            return $this->recordRestoreFailure($item, $e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function purge(int $trashId, array $context = []): array
    {
        $item = $this->requireItem($trashId);
        if (!$item->isOpen()) {
            return [
                'success' => false,
                'message' => (string)__('该回收站记录当前状态不可永久清理。'),
                'item' => $item->toApiArray(),
            ];
        }

        try {
            $definition = $this->requireDefinition($item->getTrashCode());
            $result = $definition['class']::purge($item->toApiArray(), $context);
            if (empty($result['success'])) {
                $item->setData(TrashItem::schema_fields_STATUS, TrashItem::STATUS_PURGE_FAILED);
                $item->setData(TrashItem::schema_fields_LAST_ERROR, (string)($result['message'] ?? __('永久清理失败。')));
                $item->setData(TrashItem::schema_fields_LAST_ERROR_CODE, (string)($result['code'] ?? 'purge_failed'));
                $item->setData(TrashItem::schema_fields_LAST_ERROR_DETAIL_JSON, $this->encodeJson($result));
                $item->save();

                return [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('永久清理失败。')),
                    'item' => $item->toApiArray(),
                    'result' => $result,
                ];
            }

            $item->setData(TrashItem::schema_fields_STATUS, TrashItem::STATUS_PURGED);
            $item->setData(TrashItem::schema_fields_PURGED_BY, (string)($context['operator'] ?? $context['purged_by'] ?? ''));
            $item->setData(TrashItem::schema_fields_PURGED_AT, date('Y-m-d H:i:s'));
            $item->setData(TrashItem::schema_fields_LAST_ERROR, null);
            $item->setData(TrashItem::schema_fields_LAST_ERROR_CODE, '');
            $item->setData(TrashItem::schema_fields_LAST_ERROR_DETAIL_JSON, null);
            $item->save();

            return [
                'success' => true,
                'message' => (string)($result['message'] ?? __('已永久清理。')),
                'item' => $item->toApiArray(),
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            $item->setData(TrashItem::schema_fields_STATUS, TrashItem::STATUS_PURGE_FAILED);
            $item->setData(TrashItem::schema_fields_LAST_ERROR, $e->getMessage());
            $item->setData(TrashItem::schema_fields_LAST_ERROR_CODE, 'purge_exception');
            $item->setData(TrashItem::schema_fields_LAST_ERROR_DETAIL_JSON, $this->encodeJson([
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
            $item->save();

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'item' => $item->toApiArray(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array{items:list<array<string,mixed>>,pagination:mixed}
     */
    public function listItems(array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = min(200, max(1, (int)($params['page_size'] ?? 20)));
        $code = trim((string)($params['code'] ?? $params['trash_code'] ?? ''));
        $status = trim((string)($params['status'] ?? 'open'));
        $search = trim((string)($params['q'] ?? $params['search'] ?? ''));

        $model = clone $this->trashItemModel;
        $query = $model->clearData()->reset();
        if ($code !== '') {
            $query->where(TrashItem::schema_fields_TRASH_CODE, $this->providerRegistry->normalizeCode($code));
        }
        if ($status === '' || $status === 'open') {
            $query->where(TrashItem::schema_fields_STATUS, self::OPEN_STATUSES, 'IN');
        } elseif ($status !== 'all') {
            $query->where(TrashItem::schema_fields_STATUS, strtolower($status));
        }
        if ($search !== '') {
            $query->where(
                'CONCAT(main_table.trash_code,main_table.provider_label,main_table.entity_id,main_table.entity_key,main_table.label,main_table.status)',
                '%' . $search . '%',
                'LIKE'
            );
        }

        $rows = $query->order('main_table.' . TrashItem::schema_fields_DELETED_AT, 'DESC')
            ->order('main_table.' . TrashItem::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize)
            ->select()
            ->fetchArray();

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $item = clone $this->trashItemModel;
                $item->clearData()->setData($row);
                $items[] = $item->toApiArray();
            }
        }

        return [
            'items' => $items,
            'pagination' => $model->getPagination(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getItem(int $trashId): ?array
    {
        $item = $this->loadItem($trashId);
        return $item ? $item->toApiArray() : null;
    }

    /**
     * @return list<array{code:string,label:string,module:string,class:string}>
     */
    public function listTypes(): array
    {
        return $this->providerRegistry->listTypes();
    }

    private function loadItem(int $trashId): ?TrashItem
    {
        if ($trashId <= 0) {
            return null;
        }

        $item = clone $this->trashItemModel;
        $item->load($trashId);
        return $item->getTrashId() > 0 ? $item : null;
    }

    private function requireItem(int $trashId): TrashItem
    {
        $item = $this->loadItem($trashId);
        if ($item === null) {
            throw new \InvalidArgumentException((string)__('回收站记录不存在。'));
        }

        return $item;
    }

    /**
     * @return array{code:string,label:string,class:class-string<\Weline\Trash\Api\TrashProviderInterface>,module:string,file:string}
     */
    private function requireDefinition(string $code): array
    {
        $definition = $this->providerRegistry->getDefinition($code);
        if ($definition === null) {
            throw new \InvalidArgumentException((string)__('未注册的回收站 provider：%{1}', [$code]));
        }

        /** @var array{code:string,label:string,class:class-string<\Weline\Trash\Api\TrashProviderInterface>,module:string,file:string} $definition */
        return $definition;
    }

    private function findOpenItem(string $code, string $entityKey): ?TrashItem
    {
        if ($entityKey === '') {
            return null;
        }

        $item = clone $this->trashItemModel;
        $item->clearData()->reset()
            ->where(TrashItem::schema_fields_TRASH_CODE, $code)
            ->where(TrashItem::schema_fields_ENTITY_KEY, $entityKey)
            ->where(TrashItem::schema_fields_STATUS, self::OPEN_STATUSES, 'IN')
            ->find()
            ->fetch();

        return $item->getTrashId() > 0 ? $item : null;
    }

    /**
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private function recordRestoreFailure(TrashItem $item, string $message, array $detail): array
    {
        $message = trim($message) !== '' ? $message : (string)__('恢复失败。');
        $item->setData(TrashItem::schema_fields_STATUS, TrashItem::STATUS_RESTORE_FAILED);
        $item->setData(TrashItem::schema_fields_LAST_ERROR, $message);
        $item->setData(TrashItem::schema_fields_LAST_ERROR_CODE, (string)($detail['code'] ?? 'restore_failed'));
        $item->setData(TrashItem::schema_fields_LAST_ERROR_DETAIL_JSON, $this->encodeJson($detail));
        $item->save();

        return [
            'success' => false,
            'message' => $message,
            'item' => $item->toApiArray(),
            'result' => $detail,
        ];
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
