<?php
declare(strict_types=1);

namespace Weline\Meta\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Model\Meta;
use Weline\Meta\Model\MetaLocal;

/**
 * 轻量流式 MetaLocal 老旧/孤儿 GC（cron 调用；最终一致）。
 */
final class MetaLocalStaleGcService
{
    public const DEFAULT_BATCH = 300;
    public const DEFAULT_MAX_DELETE = 2000;
    public const DEFAULT_MAX_SECONDS = 25;
    public const PREVIEW_TTL_SECONDS = 604800; // 7 days

    /**
     * @return array{scanned:int,deleted_orphan_version:int,deleted_stale_preview:int,deleted_orphan_meta:int,stopped_by_limit:bool}
     */
    public function run(
        int $batch = self::DEFAULT_BATCH,
        int $maxDelete = self::DEFAULT_MAX_DELETE,
        int $maxSeconds = self::DEFAULT_MAX_SECONDS,
    ): array {
        $started = microtime(true);
        $scanned = 0;
        $deletedOrphanVersion = 0;
        $deletedStalePreview = 0;
        $deletedOrphanMeta = 0;
        $stopped = false;
        $cursor = 0;

        /** @var MetaLocal $model */
        $model = ObjectManager::getInstance(MetaLocal::class);
        $aliveVersionIds = $this->loadAliveVersionIds();
        $aliveMetaIds = $this->loadAliveMetaIds();

        while (true) {
            if ($deletedOrphanVersion + $deletedStalePreview + $deletedOrphanMeta >= $maxDelete) {
                $stopped = true;
                break;
            }
            if ((microtime(true) - $started) >= $maxSeconds) {
                $stopped = true;
                break;
            }

            $rows = $model->reset()
                ->where(MetaLocal::schema_fields_META_ID, $cursor, '>')
                ->order(MetaLocal::schema_fields_META_ID, 'ASC')
                ->limit($batch)
                ->select()
                ->fetchArray();
            if ($rows === []) {
                break;
            }

            $deleteKeys = [];
            foreach ($rows as $row) {
                $scanned++;
                $metaId = (int)($row[MetaLocal::schema_fields_META_ID] ?? 0);
                $cursor = max($cursor, $metaId);
                $versionId = (int)($row[MetaLocal::schema_fields_VERSION_ID] ?? 0);
                $scope = (string)($row[MetaLocal::schema_fields_SCOPE] ?? MetaLocal::DEFAULT_SCOPE);
                $locale = (string)($row[MetaLocal::schema_fields_LOCALE_CODE] ?? '');
                $configKey = (string)($row[MetaLocal::schema_fields_CONFIG_KEY] ?? '');

                $reason = null;
                if ($versionId > 0 && !isset($aliveVersionIds[$versionId])) {
                    $reason = 'orphan_version';
                } elseif (MetaLocalWordParser::isPreviewScope($scope)) {
                    // Preview scopes without matching workspaces: TTL heuristic via lacking alive version
                    // and non-default scope name; without updated_at column, drop preview with no version row.
                    if ($versionId > 0 && !isset($aliveVersionIds[$versionId])) {
                        $reason = 'stale_preview';
                    } elseif ($versionId === 0) {
                        // preview + version 0: treat as stale to prevent unbounded growth
                        $reason = 'stale_preview';
                    }
                } elseif ($metaId > 0 && !isset($aliveMetaIds[$metaId])) {
                    $reason = 'orphan_meta';
                }

                if ($reason === null) {
                    continue;
                }
                $deleteKeys[] = [
                    'meta_id' => $metaId,
                    'locale' => $locale,
                    'config_key' => $configKey,
                    'scope' => $scope,
                    'version_id' => $versionId,
                    'reason' => $reason,
                ];
            }

            if ($deleteKeys === []) {
                if (count($rows) < $batch) {
                    break;
                }
                continue;
            }

            foreach ($deleteKeys as $key) {
                if ($deletedOrphanVersion + $deletedStalePreview + $deletedOrphanMeta >= $maxDelete) {
                    $stopped = true;
                    break 2;
                }
                if ((microtime(true) - $started) >= $maxSeconds) {
                    $stopped = true;
                    break 2;
                }
                $ok = $this->deleteOne($model, $key);
                if (!$ok) {
                    continue;
                }
                match ($key['reason']) {
                    'orphan_version' => $deletedOrphanVersion++,
                    'stale_preview' => $deletedStalePreview++,
                    default => $deletedOrphanMeta++,
                };
            }

            if (count($rows) < $batch) {
                break;
            }
        }

        return [
            'scanned' => $scanned,
            'deleted_orphan_version' => $deletedOrphanVersion,
            'deleted_stale_preview' => $deletedStalePreview,
            'deleted_orphan_meta' => $deletedOrphanMeta,
            'stopped_by_limit' => $stopped,
        ];
    }

    /**
     * @param array{meta_id:int,locale:string,config_key:string,scope:string,version_id:int} $key
     */
    private function deleteOne(MetaLocal $model, array $key): bool
    {
        try {
            $connection = $model->getConnection();
            $table = $model->getTable();
            $sql = sprintf(
                'DELETE FROM %s WHERE %s=%d AND %s=%s AND %s=%s AND %s=%s AND %s=%d',
                $table,
                $connection->quoteIdentifier(MetaLocal::schema_fields_META_ID),
                (int)$key['meta_id'],
                $connection->quoteIdentifier(MetaLocal::schema_fields_LOCALE_CODE),
                $connection->quote((string)$key['locale']),
                $connection->quoteIdentifier(MetaLocal::schema_fields_CONFIG_KEY),
                $connection->quote((string)$key['config_key']),
                $connection->quoteIdentifier(MetaLocal::schema_fields_SCOPE),
                $connection->quote((string)$key['scope']),
                $connection->quoteIdentifier(MetaLocal::schema_fields_VERSION_ID),
                (int)$key['version_id'],
            );
            return (int)$connection->getLink()->exec($sql) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, true>
     */
    private function loadAliveVersionIds(): array
    {
        $alive = [];
        try {
            if (!class_exists(\Weline\Theme\Model\ThemeScopeVersion::class)) {
                return $alive;
            }
            /** @var \Weline\Theme\Model\ThemeScopeVersion $version */
            $version = ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class);
            $pk = $version::schema_primary_key ?? 'version_id';
            foreach ($version->reset()->select()->fetchArray() as $row) {
                $id = (int)($row[$pk] ?? $row['version_id'] ?? 0);
                if ($id > 0) {
                    $alive[$id] = true;
                }
            }
        } catch (\Throwable) {
            // Theme 未装或表不可用时不误删 version 行（跳过 orphan_version）
        }
        return $alive;
    }

    /**
     * @return array<int, true>
     */
    private function loadAliveMetaIds(): array
    {
        $alive = [];
        try {
            /** @var Meta $meta */
            $meta = ObjectManager::getInstance(Meta::class);
            foreach ($meta->reset()->select()->fetchArray() as $row) {
                $id = (int)($row[Meta::schema_fields_ID] ?? $row['meta_id'] ?? 0);
                if ($id > 0) {
                    $alive[$id] = true;
                }
            }
        } catch (\Throwable) {
        }
        return $alive;
    }
}
