<?php

declare(strict_types=1);

namespace Weline\FileManager\Service\MediaReference;

use Weline\FileManager\Model\FileAssetReference;
use Weline\FileManager\Service\FileAssetReferenceIndexer;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Bind / unbind media references. Replace-on-select; never delete files on swap.
 */
final class MediaReferenceService
{
    public function __construct(
        private readonly FileAssetReferenceIndexer $indexer,
        private readonly FileAssetReference $references,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly MediaReferenceIdentityBuilder $builder,
        private readonly MediaReferenceScopeResolver $scopes,
    ) {
    }

    /**
     * @param array<string, mixed> $slot
     */
    public function identity(?string $scope, string $type, string $code, array $slot = []): MediaReferenceIdentity
    {
        return $this->builder->build($scope, $type, $code, $slot);
    }

    /**
     * Single-slot bind: unload prior refs for same owner+field, then index the new asset.
     * Does not delete physical files.
     */
    public function bindSingle(
        MediaReferenceIdentity $identity,
        string $assetId,
        string $ownerType,
        string $ownerId,
        int $ownerVersion,
        string $fieldPath,
        string $localeCode = '',
    ): void {
        $assetId = trim($assetId);
        if ($assetId === '') {
            throw new \InvalidArgumentException('asset_id 无效。');
        }
        $this->indexer->replace($ownerType, $ownerId, $ownerVersion, [[
            'asset_id' => $assetId,
            'scope_key' => $identity->scope,
            'locale_code' => $localeCode,
            'field_path' => $fieldPath !== '' ? $fieldPath : $identity->path,
        ]]);
    }

    /**
     * Multi-slot sync by selected asset ids for one owner version field prefix.
     * @param list<string> $keepAssetIds
     */
    public function syncMulti(
        MediaReferenceIdentity $identity,
        array $keepAssetIds,
        string $ownerType,
        string $ownerId,
        int $ownerVersion,
        string $fieldPathPrefix,
        string $localeCode = '',
    ): void {
        $items = [];
        $i = 0;
        foreach ($keepAssetIds as $assetId) {
            $assetId = trim((string)$assetId);
            if ($assetId === '') {
                continue;
            }
            $items[] = [
                'asset_id' => $assetId,
                'scope_key' => $identity->scope,
                'locale_code' => $localeCode,
                'field_path' => $fieldPathPrefix . '.index.' . $i,
            ];
            $i++;
        }
        $this->indexer->replace($ownerType, $ownerId, $ownerVersion, $items);
    }

    /**
     * Unload all references under a storage_scope (and optional child prefixes).
     * Does not delete physical files; callers may purge zero-ref assets separately.
     *
     * @return array{removed:int,scope:string,shared_kept:int}
     */
    public function deleteReferencesByScope(string $scope, bool $includeChildren = true): array
    {
        $scope = $this->scopes->assertStorageScope($scope);
        $removed = 0;
        $touchedAssets = [];
        $run = function () use ($scope, $includeChildren, &$removed, &$touchedAssets): void {
            $model = clone $this->references;
            $rows = $model->clearData()->reset()->select()->fetchArray();
            if (!is_array($rows)) {
                return;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowScope = (string)($row[FileAssetReference::schema_fields_SCOPE_KEY] ?? '');
                $match = $rowScope === $scope
                    || ($includeChildren && $this->scopeSharesWebsite($scope, $rowScope));
                if (!$match) {
                    continue;
                }
                $id = (int)($row[FileAssetReference::schema_fields_ID] ?? 0);
                $assetId = (string)($row[FileAssetReference::schema_fields_ASSET_ID] ?? '');
                if ($id <= 0) {
                    continue;
                }
                $del = clone $this->references;
                $del->clearData()->reset()
                    ->where(FileAssetReference::schema_fields_ID, $id)
                    ->delete()->fetch();
                $removed++;
                if ($assetId !== '') {
                    $touchedAssets[$assetId] = true;
                }
            }
        };

        $connection = $this->references->getConnection();
        if ($this->transactions->isActive($connection)) {
            if (!$this->transactions->isWriteIntent($connection)) {
                throw new \LogicException('文件资源引用写入必须位于写意图事务内。');
            }
            $this->transactions->withSavepoint($connection, 'media_ref_delete_by_scope', $run);
        } else {
            $this->transactions->runWrite($connection, $run);
        }

        $sharedKept = 0;
        foreach (array_keys($touchedAssets) as $assetId) {
            if ($this->indexer->isReferenced((string)$assetId)) {
                $sharedKept++;
            }
        }

        return [
            'removed' => $removed,
            'scope' => $scope,
            'shared_kept' => $sharedKept,
        ];
    }

    /**
     * Tag AND query against field_path / scope_key / owner.
     * @param array<string, string> $tags
     * @return list<array<string, mixed>>
     */
    public function listByTags(array $tags): array
    {
        $model = clone $this->references;
        $rows = $model->clearData()->reset()->select()->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $scopeKey = (string)($row[FileAssetReference::schema_fields_SCOPE_KEY] ?? '');
            $fieldPath = (string)($row[FileAssetReference::schema_fields_FIELD_PATH] ?? '');
            $hay = strtolower($scopeKey . "\0" . $fieldPath . "\0" . (string)($row[FileAssetReference::schema_fields_OWNER_TYPE] ?? '') . "\0" . (string)($row[FileAssetReference::schema_fields_OWNER_ID] ?? ''));
            $ok = true;
            foreach ($tags as $k => $v) {
                $k = strtolower(trim((string)$k));
                $v = strtolower(trim((string)$v));
                if ($k === '' || $v === '') {
                    continue;
                }
                if ($k === 'scope' || $k === 'scope_key') {
                    if (strtolower($scopeKey) !== $v) {
                        $ok = false;
                        break;
                    }
                    continue;
                }
                if ($k === 'root') {
                    if (!str_starts_with(strtolower($fieldPath), $v . ':')
                        && !str_starts_with(strtolower((string)($row[FileAssetReference::schema_fields_OWNER_TYPE] ?? '')), $v)
                    ) {
                        $ok = false;
                        break;
                    }
                    continue;
                }
                if (!str_contains($hay, $k . ':' . $v) && !str_contains($hay, $v)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function scopeSharesWebsite(string $target, string $candidate): bool
    {
        $t = explode('.', strtolower($target));
        $c = explode('.', strtolower($candidate));
        if (count($t) !== 3 || count($c) !== 3) {
            return false;
        }
        // Same website; store/channel may differ when includeChildren
        return $t[0] === $c[0];
    }

    /**
     * Unbind all references matching type root + scope + identity code (AND).
     * Does not delete files.
     */
    public function unbindWhere(string $type, string $scope, string $code): int
    {
        $type = strtolower(trim($type));
        $scope = $this->builder->build($scope, $type, $code)->scope;
        $code = trim($code);
        if ($type === '' || $code === '') {
            return 0;
        }

        $removed = 0;
        $run = function () use ($type, $scope, $code, &$removed): void {
            $model = clone $this->references;
            $rows = $model->clearData()->reset()
                ->where(FileAssetReference::schema_fields_SCOPE_KEY, $scope)
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ownerType = (string)($row[FileAssetReference::schema_fields_OWNER_TYPE] ?? '');
                $fieldPath = (string)($row[FileAssetReference::schema_fields_FIELD_PATH] ?? '');
                $ownerId = (string)($row[FileAssetReference::schema_fields_OWNER_ID] ?? '');
                if (!$this->rowMatchesEntity($type, $code, $ownerType, $ownerId, $fieldPath)) {
                    continue;
                }
                $id = (int)($row[FileAssetReference::schema_fields_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $del = clone $this->references;
                $del->clearData()->reset()
                    ->where(FileAssetReference::schema_fields_ID, $id)
                    ->delete()->fetch();
                $removed++;
            }
        };

        $connection = $this->references->getConnection();
        if ($this->transactions->isActive($connection)) {
            if (!$this->transactions->isWriteIntent($connection)) {
                throw new \LogicException('文件资源引用写入必须位于写意图事务内。');
            }
            $this->transactions->withSavepoint($connection, 'media_ref_unbind_where', $run);
        } else {
            $this->transactions->runWrite($connection, $run);
        }

        return $removed;
    }

    private function rowMatchesEntity(
        string $type,
        string $code,
        string $ownerType,
        string $ownerId,
        string $fieldPath,
    ): bool {
        $hay = strtolower($ownerType . "\0" . $ownerId . "\0" . $fieldPath);
        $type = strtolower($type);
        $codeNeedle = strtolower($code);
        if (!str_contains($hay, $type) && !str_starts_with(strtolower($ownerType), $type)) {
            // path-style field often starts with root
            if (!str_starts_with(strtolower($fieldPath), $type . ':')) {
                return false;
            }
        }
        // Identity code must appear as typed segment or owner_id
        if ($ownerId === $code || str_ends_with($ownerId, ':' . $code) || str_contains($ownerId, '~' . $code)) {
            return true;
        }
        foreach (['sku:', 'theme:', 'brand:', 'post:', 'category:', 'website:', 'code:'] as $prefix) {
            if (str_contains(strtolower($fieldPath), $prefix . $codeNeedle)) {
                return true;
            }
        }

        return str_contains(strtolower($fieldPath), ':' . $codeNeedle . ':')
            || str_ends_with(strtolower($fieldPath), ':' . $codeNeedle);
    }
}
