<?php

declare(strict_types=1);

namespace Weline\FileManager\Service\Setup;

use Throwable;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Backfills FileAsset.object_identity_hash and validates collisions.
 *
 * Schema (column + unique index) is owned by the declarative model/planner;
 * the unique index is deferred to contract when the column is new.
 */
final class FileAssetHashBackfiller
{
    private const BATCH_SIZE = 500;
    private const MAX_PREFLIGHT_ROWS = 100000;

    public function __construct(private readonly ConnectionFactory $connectionFactory)
    {
    }

    public function backfill(): void
    {
        $connection = $this->connectionFactory->getConnection();
        /** @var FileAsset $prototype */
        $prototype = \Weline\Framework\Manager\ObjectManager::getInstance(FileAsset::class);
        $table = $prototype->getTable();
        if (!$connection->tableExist($table)) {
            return;
        }

        $this->auditRows($prototype, false);
        $this->backfillHashes($prototype);
        $this->auditRows($prototype, true);
    }

    private function backfillHashes(FileAsset $prototype): void
    {
        $field = FileAsset::schema_fields_OBJECT_IDENTITY_HASH;
        $lastId = '';
        while (true) {
            $query = (clone $prototype)->clearData()->reset()
                ->order(FileAsset::schema_fields_ID, 'ASC')
                ->limit(self::BATCH_SIZE);
            if ($lastId !== '') {
                $query->where(FileAsset::schema_fields_ID, $lastId, '>');
            }
            $rows = $query->select()->fetch()->getItems();
            if ($rows === []) {
                return;
            }
            $nextId = $lastId;
            foreach ($rows as $row) {
                if (!$row instanceof FileAsset) {
                    throw new \RuntimeException((string)__('FileAsset 哈希回填读取到无效数据。'));
                }
                $assetId = $row->getAssetId();
                if ($assetId === '' || ($lastId !== '' && strcmp($assetId, $lastId) <= 0)) {
                    continue;
                }
                $hash = FileAsset::objectIdentityHash($row->getDiskCode(), $row->getObjectKey());
                if (!hash_equals($hash, (string)$row->getData($field))) {
                    (clone $prototype)->clearData()->reset()
                        ->where(FileAsset::schema_fields_ID, $assetId)
                        ->update([$field => $hash])
                        ->fetch();
                }
                $nextId = $assetId;
            }
            if ($nextId === $lastId) {
                throw new \RuntimeException((string)__('FileAsset 哈希回填无数据进展。'));
            }
            $lastId = $nextId;
            SchedulerSystem::yield();
            if (count($rows) < self::BATCH_SIZE) {
                return;
            }
        }
    }

    private function auditRows(FileAsset $prototype, bool $verifyStoredHash): void
    {
        $lastId = '';
        $processed = 0;
        $seenHashes = [];
        while (true) {
            $query = (clone $prototype)->clearData()->reset()
                ->order(FileAsset::schema_fields_ID, 'ASC')
                ->limit(self::BATCH_SIZE);
            if ($lastId !== '') {
                $query->where(FileAsset::schema_fields_ID, $lastId, '>');
            }
            $rows = $query->select()->fetch()->getItems();
            if ($rows === []) {
                return;
            }
            $nextId = $lastId;
            foreach ($rows as $row) {
                if (!$row instanceof FileAsset) {
                    throw new \RuntimeException((string)__('FileAsset 旧数据无效，升级已停止。'));
                }
                $assetId = $row->getAssetId();
                if ($assetId === '' || ($lastId !== '' && strcmp($assetId, $lastId) <= 0)) {
                    continue;
                }
                try {
                    $hash = FileAsset::objectIdentityHash($row->getDiskCode(), $row->getObjectKey());
                } catch (Throwable $throwable) {
                    throw new \RuntimeException((string)__('FileAsset 旧存储身份无效，升级已停止。'), 0, $throwable);
                }
                if (isset($seenHashes[$hash])) {
                    throw new \RuntimeException((string)__('FileAsset 旧数据存在重复存储对象身份，升级已停止。'));
                }
                if ($verifyStoredHash && !hash_equals($hash, (string)$row->getData(FileAsset::schema_fields_OBJECT_IDENTITY_HASH))) {
                    throw new \RuntimeException((string)__('FileAsset 存储身份哈希回填校验失败。'));
                }
                $seenHashes[$hash] = true;
                if (++$processed > self::MAX_PREFLIGHT_ROWS) {
                    throw new \RuntimeException((string)__('FileAsset 旧数据超过安全升级上限。'));
                }
                $nextId = $assetId;
            }
            if ($nextId === $lastId) {
                throw new \RuntimeException((string)__('FileAsset 旧数据预检无数据进展。'));
            }
            $lastId = $nextId;
            SchedulerSystem::yield();
            if (count($rows) < self::BATCH_SIZE) {
                return;
            }
        }
    }
}
