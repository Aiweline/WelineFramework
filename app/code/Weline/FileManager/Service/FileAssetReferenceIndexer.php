<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Model\FileAssetReference;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;

final class FileAssetReferenceIndexer
{
    private const MAX_REFERENCES_PER_OWNER_VERSION = 10000;

    public function __construct(
        private readonly FileAssetReference $references,
        private readonly FileAsset $assets,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    /**
     * Rebuild the derived index for one durable owner version. This index is never a content fact source.
     * @param list<array{asset_id:string,scope_key:string,locale_code:string,field_path:string}> $items
     */
    public function replace(string $ownerType, string $ownerId, int $ownerVersion, array $items): void
    {
        $ownerType = trim($ownerType);
        $ownerId = trim($ownerId);
        if ($ownerType === '' || strlen($ownerType) > 64
            || $ownerId === '' || strlen($ownerId) > 128
            || $ownerVersion < 1
        ) {
            throw new \InvalidArgumentException((string)__('资源引用 owner 身份无效。'));
        }
        if (count($items) > self::MAX_REFERENCES_PER_OWNER_VERSION) {
            throw new \InvalidArgumentException((string)__('单个业务版本的文件资源引用数量超过限制。'));
        }

        $normalized = $this->normalizeItems($items);
        $replace = function () use ($ownerType, $ownerId, $ownerVersion, $normalized): void {
            $this->lockReferencedAssets($normalized);
            $delete = clone $this->references;
            $delete->clearData()->reset()
                ->where(FileAssetReference::schema_fields_OWNER_TYPE, $ownerType)
                ->where(FileAssetReference::schema_fields_OWNER_ID, $ownerId)
                ->where(FileAssetReference::schema_fields_OWNER_VERSION, $ownerVersion)
                ->delete()->fetch();

            $now = date('Y-m-d H:i:s');
            foreach ($normalized as $item) {
                /** @var FileAssetReference $reference */
                $reference = ObjectManager::create(FileAssetReference::class, [], false);
                $reference->setData(FileAssetReference::schema_fields_ASSET_ID, $item['asset_id']);
                $reference->setData(FileAssetReference::schema_fields_OWNER_TYPE, $ownerType);
                $reference->setData(FileAssetReference::schema_fields_OWNER_ID, $ownerId);
                $reference->setData(FileAssetReference::schema_fields_SCOPE_KEY, $item['scope_key']);
                $reference->setData(FileAssetReference::schema_fields_LOCALE_CODE, $item['locale_code']);
                $reference->setData(FileAssetReference::schema_fields_FIELD_PATH, $item['field_path']);
                $reference->setData(FileAssetReference::schema_fields_OWNER_VERSION, $ownerVersion);
                $reference->setData(FileAssetReference::schema_fields_CREATED_AT, $now);
                $reference->save();
            }
        };

        $connection = $this->references->getConnection();
        if ($this->transactions->isActive($connection)) {
            if (!$this->transactions->isWriteIntent($connection)) {
                throw new \LogicException((string)__('文件资源引用写入必须位于写意图事务内。'));
            }
            $this->transactions->withSavepoint($connection, 'file_asset_reference_replace', $replace);
            return;
        }
        $this->transactions->runWrite($connection, $replace);
    }

    public function isReferenced(string $assetId): bool
    {
        $model = clone $this->references;
        $model->clearData()->reset()
            ->where(FileAssetReference::schema_fields_ASSET_ID, trim($assetId))
            ->find()->fetch();
        return (int)$model->getData(FileAssetReference::schema_fields_ID) > 0;
    }

    /**
     * @param list<array{asset_id:string,scope_key:string,locale_code:string,field_path:string}> $items
     * @return list<array{asset_id:string,scope_key:string,locale_code:string,field_path:string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            $assetId = trim((string)($item['asset_id'] ?? ''));
            $scopeKey = trim((string)($item['scope_key'] ?? ''));
            $localeCode = trim((string)($item['locale_code'] ?? ''));
            $fieldPath = trim((string)($item['field_path'] ?? ''));
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $assetId) !== 1
                || $scopeKey === '' || strlen($scopeKey) > 512
                || strlen($localeCode) > 16
                || ($localeCode !== '' && preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $localeCode) !== 1)
                || $fieldPath === '' || strlen($fieldPath) > 512
            ) {
                throw new \InvalidArgumentException((string)__('文件资源引用数据无效。'));
            }
            $key = implode("\0", [$assetId, $scopeKey, $localeCode, $fieldPath]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = [
                'asset_id' => $assetId,
                'scope_key' => $scopeKey,
                'locale_code' => $localeCode,
                'field_path' => $fieldPath,
            ];
        }
        return $normalized;
    }

    /**
     * Serialize reference creation with deletion. A delete locks the same asset
     * identity before checking this derived index, so neither operation can win
     * using a stale pre-transaction view.
     *
     * @param list<array{asset_id:string,scope_key:string,locale_code:string,field_path:string}> $items
     */
    private function lockReferencedAssets(array $items): void
    {
        $assetIds = [];
        foreach ($items as $item) {
            $assetIds[$item['asset_id']] = true;
        }
        $assetIds = array_keys($assetIds);
        sort($assetIds, SORT_STRING);
        foreach (array_chunk($assetIds, 200) as $chunk) {
            $query = clone $this->assets;
            $query->clearData()->reset()
                ->where(FileAsset::schema_fields_ID, $chunk, 'IN')
                ->order(FileAsset::schema_fields_ID, 'ASC');
            if ($this->supportsForUpdate()) {
                $query->additional('FOR UPDATE');
            }
            $rows = $query->select()->fetch()->getItems();
            $loaded = [];
            foreach ($rows as $asset) {
                if (!$asset instanceof FileAsset) {
                    continue;
                }
                $loaded[$asset->getAssetId()] = true;
                if ($asset->isDeleted() || !$asset->isReady()) {
                    throw new \RuntimeException((string)__('引用的文件资源不可用。'));
                }
            }
            foreach ($chunk as $assetId) {
                if (!isset($loaded[$assetId])) {
                    throw new \RuntimeException((string)__('引用的文件资源不存在。'));
                }
            }
        }
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->references->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }
}
