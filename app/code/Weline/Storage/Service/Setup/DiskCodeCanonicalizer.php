<?php

declare(strict_types=1);

namespace Weline\Storage\Service\Setup;

use JsonException;
use RuntimeException;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Model\StorageConfig;

/**
 * Data half of canonicalize_storage_disk_codes_20260822-v1.2.0.
 *
 * Schema (name/driver widths, config_revision) is owned by StorageConfig #[Col]
 * and the RSSE planner. This service canonicalizes legacy names, seals secrets,
 * and validates alias ownership before touching rows.
 */
final class DiskCodeCanonicalizer
{
    private const MAX_CONFIGS = 1000;

    public function __construct(private readonly ConnectionFactory $connectionFactory)
    {
    }

    public function canonicalize(): void
    {
        $connection = $this->connectionFactory->getConnection();
        /** @var StorageConfig $prototype */
        $prototype = ObjectManager::getInstance(StorageConfig::class);
        if (!$connection->tableExist($prototype->getTable())) {
            return;
        }

        $rows = (clone $prototype)->clearData()->reset()
            ->order(StorageConfig::schema_fields_CONFIG_ID, 'ASC')
            ->limit(self::MAX_CONFIGS + 1)
            ->select()
            ->fetch()
            ->getItems();
        if (count($rows) > self::MAX_CONFIGS) {
            throw new RuntimeException((string)__('存储磁盘配置数量超过迁移上限。'));
        }

        $plan = [];
        $owners = [];
        foreach ($rows as $row) {
            if (!$row instanceof StorageConfig || !$row->getId()) {
                continue;
            }
            $old = strtolower(trim((string)$row->getData(StorageConfig::schema_fields_NAME)));
            $driver = (string)$row->getData(StorageConfig::schema_fields_DRIVER);
            $provider = StorageConfig::providerCodeForDriver($driver);
            $canonical = StorageConfig::canonicalDiskCode($driver, $old);
            if (isset($owners[$canonical])) {
                throw new RuntimeException((string)__('存储磁盘迁移冲突：%{1}', [$canonical]));
            }
            $rawConfig = trim((string)$row->getData(StorageConfig::schema_fields_CONFIG));
            try {
                $config = $rawConfig === '' ? [] : json_decode($rawConfig, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException((string)__('存储配置 JSON 无效。'), 0, $exception);
            }
            if (!is_array($config)) {
                throw new RuntimeException((string)__('存储配置 JSON 无效。'));
            }
            $owners[$canonical] = (int)$row->getId();
            $plan[] = [$row, $old, $canonical, $provider, $config, $rawConfig];
        }

        $claims = [];
        foreach ($owners as $canonical => $configId) {
            $claims[$canonical] = $configId;
        }
        foreach ($plan as $offset => [$row, $old, $canonical, $provider, $config, $sourceConfigJson]) {
            $aliases = is_array($config['legacy_aliases'] ?? null) ? $config['legacy_aliases'] : [];
            if ($old !== '' && $old !== $canonical) {
                $aliases[] = $old;
            }
            $normalized = [];
            foreach ($aliases as $alias) {
                if (!is_string($alias)) {
                    throw new RuntimeException((string)__('存储磁盘旧别名格式无效。'));
                }
                $alias = strtolower(trim($alias));
                if ($alias !== ''
                    && $alias !== $canonical
                    && strlen($alias) <= 190
                    && preg_match('/[\x00-\x1F\x7F]/', $alias) !== 1
                ) {
                    $normalized[$alias] = $alias;
                } elseif ($alias !== '' && $alias !== $canonical) {
                    throw new RuntimeException((string)__('存储磁盘旧别名格式无效。'));
                }
                if (count($normalized) > 100) {
                    throw new RuntimeException((string)__('单个存储磁盘的旧别名数量超过迁移上限。'));
                }
            }
            $configId = (int)$row->getId();
            foreach ($normalized as $alias) {
                if (in_array($alias, ['local', '__local__'], true)
                    && $canonical !== StorageDiskCode::BUILTIN_LOCAL_MEDIA
                ) {
                    throw new RuntimeException((string)__('存储磁盘旧别名与内置本地磁盘冲突：%{1}', [$alias]));
                }
                if (isset($claims[$alias]) && $claims[$alias] !== $configId) {
                    throw new RuntimeException((string)__('存储磁盘旧别名存在归属冲突：%{1}', [$alias]));
                }
                $claims[$alias] = $configId;
            }
            $config['legacy_aliases'] = array_values($normalized);
            $row->setConfigArray($config);
            $plan[$offset] = [
                $row,
                $old,
                $canonical,
                $provider,
                (string)$row->getData(StorageConfig::schema_fields_CONFIG),
                $sourceConfigJson,
            ];
        }

        foreach ($plan as $offset => [$row, $old, $canonical, $provider, $targetConfigJson, $sourceConfigJson]) {
            $currentDriver = strtolower(trim((string)$row->getData(StorageConfig::schema_fields_DRIVER)));
            $currentRevision = (int)$row->getData(StorageConfig::schema_fields_CONFIG_REVISION);
            if ($old === $canonical
                && $currentDriver === $provider
                && $sourceConfigJson === $targetConfigJson
                && $currentRevision >= 1
            ) {
                continue;
            }
            (clone $prototype)->clearData()->reset()
                ->where(StorageConfig::schema_fields_CONFIG_ID, (int)$row->getId())
                ->update([
                    StorageConfig::schema_fields_NAME => $canonical,
                    StorageConfig::schema_fields_DRIVER => $provider,
                    StorageConfig::schema_fields_CONFIG => $targetConfigJson,
                    StorageConfig::schema_fields_CONFIG_REVISION => max(1, $currentRevision) + 1,
                    StorageConfig::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])
                ->fetch();
            if (($offset + 1) % 50 === 0) {
                SchedulerSystem::yield();
            }
        }
    }
}
