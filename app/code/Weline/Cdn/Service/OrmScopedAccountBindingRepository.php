<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\ScopedAccountBindingRepositoryInterface;
use Weline\Cdn\Model\ScopedAccountBinding;

final class OrmScopedAccountBindingRepository implements ScopedAccountBindingRepositoryInterface
{
    public function __construct(
        private readonly ScopedAccountBinding $model = new ScopedAccountBinding(),
    ) {
    }

    public function find(string $storageScope, string $storeMode, string $adapter): ?array
    {
        $hit = $this->findModel($storageScope, $storeMode, $adapter);

        return $hit->getId() ? $this->project($hit) : null;
    }

    public function save(
        string $storageScope,
        string $storeMode,
        string $adapter,
        int $accountId,
        string $mediaBaseUrl,
        string $globalAlias,
    ): array {
        $model = $this->findModel($storageScope, $storeMode, $adapter);
        $now = \gmdate('Y-m-d H:i:s');
        if (!$model->getId()) {
            $model->setData(ScopedAccountBinding::schema_fields_CREATED_AT, $now);
        }
        $model->setData([
            ScopedAccountBinding::schema_fields_STORAGE_SCOPE => $storageScope,
            ScopedAccountBinding::schema_fields_STORE_MODE => $storeMode,
            ScopedAccountBinding::schema_fields_ADAPTER => $adapter,
            ScopedAccountBinding::schema_fields_ACCOUNT_ID => $accountId,
            ScopedAccountBinding::schema_fields_MEDIA_BASE_URL => $mediaBaseUrl,
            ScopedAccountBinding::schema_fields_GLOBAL_ALIAS => $globalAlias,
            ScopedAccountBinding::schema_fields_UPDATED_AT => $now,
        ])->save();

        return $this->find($storageScope, $storeMode, $adapter)
            ?? throw new \RuntimeException('cdn_account_binding_persist_failed');
    }

    public function delete(string $storageScope, string $storeMode, string $adapter): bool
    {
        $model = $this->findModel($storageScope, $storeMode, $adapter);
        $id = (int)$model->getId();
        if ($id <= 0) {
            return false;
        }
        // find()->fetch() 后可能残留条件；按主键重建删除，避免 WHERE ( = $1)
        $deleter = clone $this->model;
        $deleter->clear()
            ->where(ScopedAccountBinding::schema_fields_ID, $id)
            ->delete();

        return true;
    }

    public function listForMode(string $storeMode): array
    {
        $query = clone $this->model;
        $rows = $query->clear()
            ->where(ScopedAccountBinding::schema_fields_STORE_MODE, $storeMode)
            ->order(ScopedAccountBinding::schema_fields_STORAGE_SCOPE, 'ASC')
            ->order(ScopedAccountBinding::schema_fields_ADAPTER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof ScopedAccountBinding) {
                $out[] = $this->project($row);
            }
        }

        return $out;
    }

    private function findModel(string $storageScope, string $storeMode, string $adapter): ScopedAccountBinding
    {
        $model = clone $this->model;
        $model->clear();
        $hit = $model
            ->where(ScopedAccountBinding::schema_fields_STORAGE_SCOPE, $storageScope)
            ->where(ScopedAccountBinding::schema_fields_STORE_MODE, $storeMode)
            ->where(ScopedAccountBinding::schema_fields_ADAPTER, $adapter)
            ->find()
            ->fetch();

        return $hit instanceof ScopedAccountBinding ? $hit : $model;
    }

    /**
     * @return array{
     *   account_id:int,
     *   adapter:string,
     *   media_base_url:string,
     *   global_alias:string,
     *   storage_scope:string,
     *   store_mode:string
     * }
     */
    private function project(ScopedAccountBinding $model): array
    {
        return [
            'account_id' => (int)$model->getData(ScopedAccountBinding::schema_fields_ACCOUNT_ID),
            'adapter' => (string)$model->getData(ScopedAccountBinding::schema_fields_ADAPTER),
            'media_base_url' => (string)$model->getData(ScopedAccountBinding::schema_fields_MEDIA_BASE_URL),
            'global_alias' => (string)$model->getData(ScopedAccountBinding::schema_fields_GLOBAL_ALIAS),
            'storage_scope' => (string)$model->getData(ScopedAccountBinding::schema_fields_STORAGE_SCOPE),
            'store_mode' => (string)$model->getData(ScopedAccountBinding::schema_fields_STORE_MODE),
        ];
    }
}
