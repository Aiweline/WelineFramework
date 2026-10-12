<?php
declare(strict_types=1);

namespace Weline\Meta\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Model\MetaLocal;

/**
 * MetaLocal 热路径 GC（版本删除 / 预览丢弃）。
 */
final class MetaLocalGcService
{
    public function purgeByVersionId(int $versionId): int
    {
        if ($versionId <= 0) {
            return 0;
        }
        /** @var MetaLocal $model */
        $model = ObjectManager::getInstance(MetaLocal::class);
        try {
            $connection = $model->getConnection();
            $table = $model->getTable();
            $col = $connection->quoteIdentifier(MetaLocal::schema_fields_VERSION_ID);
            $sql = 'DELETE FROM ' . $table . ' WHERE ' . $col . ' = ' . (int)$versionId;
            return (int)$connection->getLink()->exec($sql);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function purgeByScopePrefix(string $prefix): int
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === MetaLocal::DEFAULT_SCOPE) {
            return 0;
        }
        /** @var MetaLocal $model */
        $model = ObjectManager::getInstance(MetaLocal::class);
        try {
            $connection = $model->getConnection();
            $table = $model->getTable();
            $col = $connection->quoteIdentifier(MetaLocal::schema_fields_SCOPE);
            $like = $connection->quote($prefix . '%');
            $sql = 'DELETE FROM ' . $table . ' WHERE ' . $col . ' LIKE ' . $like;
            return (int)$connection->getLink()->exec($sql);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function purgeByExactScope(string $scope): int
    {
        $scope = trim($scope);
        if ($scope === '' || $scope === MetaLocal::DEFAULT_SCOPE) {
            return 0;
        }
        /** @var MetaLocal $model */
        $model = ObjectManager::getInstance(MetaLocal::class);
        try {
            $connection = $model->getConnection();
            $table = $model->getTable();
            $col = $connection->quoteIdentifier(MetaLocal::schema_fields_SCOPE);
            $sql = 'DELETE FROM ' . $table . ' WHERE ' . $col . ' = ' . $connection->quote($scope);
            return (int)$connection->getLink()->exec($sql);
        } catch (\Throwable) {
            return 0;
        }
    }
}
