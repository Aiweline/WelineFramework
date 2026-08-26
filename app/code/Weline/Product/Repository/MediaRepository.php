<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * Same-Website media sharing with one durable blob owner and transactional COW.
 *
 * The owner row has cow_source_media_id=NULL and stores the number of rows that
 * reference the blob. Every copy points to the owner and keeps ref_count=1.
 * All owner mutations use writer-owned CAS tokens; adapter affected-row values
 * are deliberately ignored.
 */
final class MediaRepository extends AbstractWebsiteShardRepository
{
    private const MAX_CAS_ATTEMPTS = 8;

    /** @var (\Closure(int): Media)|null */
    private readonly mixed $modelFactory;

    /** @var (\Closure(): string)|null */
    private readonly mixed $casTokenFactory;

    /**
     * @param (\Closure(int): Media)|null $modelFactory
     * @param (\Closure(): string)|null $casTokenFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        ?callable $modelFactory = null,
        ?callable $casTokenFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
        $this->casTokenFactory = $casTokenFactory;
    }

    public function findById(int $websiteId, int $mediaId): ?Media
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Media::schema_fields_ID, $mediaId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    public function findByBlobKey(int $websiteId, string $blobKey): ?Media
    {
        $this->assertWebsite($websiteId);
        $blobKey = trim($blobKey);
        if ($blobKey === '') {
            return null;
        }
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Media::schema_fields_BLOB_KEY, $blobKey)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * Existing consumers receive Website media by default. Product admin can
     * request explicit Store scopes without leaking overlays into storefronts.
     *
     * @param list<int> $productIds
     * @param list<int>|null $storeIds null means every explicit scope
     * @return list<array<string, mixed>>
     */
    public function listByProductIds(
        int $websiteId,
        array $productIds,
        ?array $storeIds = null,
    ): array {
        $this->assertWebsite($websiteId);
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }
        $query = $this->newModel($websiteId)
            ->clear()
            ->where(Media::schema_fields_PRODUCT_ID, $productIds, 'IN');
        if ($storeIds !== null) {
            $storeIds = array_values(array_unique(array_filter(
                array_map('intval', $storeIds),
                static fn(int $id): bool => $id >= 0,
            )));
            if ($storeIds === []) {
                return [];
            }
            $query->where(Media::schema_fields_STORE_ID, $storeIds, 'IN');
        }
        try {
            $rows = $query->select()->fetchArray();
        } catch (\PDOException $e) {
            // Legacy website shards may predate store_id; retry without scope filter.
            if ($storeIds === null || !str_contains($e->getMessage(), 'store_id')) {
                throw $e;
            }
            $rows = $this->newModel($websiteId)
                ->clear()
                ->where(Media::schema_fields_PRODUCT_ID, $productIds, 'IN')
                ->select()
                ->fetchArray();
        }
        usort(
            $rows,
            static fn(array $left, array $right): int => [
                (int)($left[Media::schema_fields_PRODUCT_ID] ?? 0),
                (int)($left[Media::schema_fields_STORE_ID] ?? 0),
                (int)($left[Media::schema_fields_POSITION] ?? 0),
                (int)($left[Media::schema_fields_ID] ?? 0),
            ] <=> [
                (int)($right[Media::schema_fields_PRODUCT_ID] ?? 0),
                (int)($right[Media::schema_fields_STORE_ID] ?? 0),
                (int)($right[Media::schema_fields_POSITION] ?? 0),
                (int)($right[Media::schema_fields_ID] ?? 0),
            ],
        );
        return $rows;
    }

    /**
     * Complete the asset-backed rows for one Product scope. Legacy path/blob
     * rows are migration inputs and are deliberately left untouched.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function syncProductScope(
        int $websiteId,
        int $productId,
        int $storeId,
        array $rows,
    ): array {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        if ($productId < 1) {
            throw new \InvalidArgumentException('product_media_product_invalid');
        }

        $desired = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('product_media_assignment_invalid');
            }
            $assetId = strtolower(trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')));
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $assetId) !== 1) {
                throw new \InvalidArgumentException('product_media_asset_id_invalid');
            }
            if (isset($desired[$assetId])) {
                throw new \InvalidArgumentException('product_media_asset_duplicate');
            }
            $role = strtolower(trim((string)($row[Media::schema_fields_ROLE] ?? 'gallery')));
            $visibility = strtolower(trim((string)($row[Media::schema_fields_ASSET_VISIBILITY] ?? 'public')));
            $mimeType = strtolower(trim((string)($row[Media::schema_fields_MIME_TYPE] ?? '')));
            $policy = $row[Media::schema_fields_ACCESS_POLICY_JSON] ?? null;
            $position = (int)($row[Media::schema_fields_POSITION] ?? $index);
            if (!in_array($role, ['main', 'gallery', 'file', 'download'], true)
                || !in_array($visibility, ['public', 'private'], true)
                || $mimeType === ''
                || strlen($mimeType) > 128
                || $position < 0
                || ($policy !== null && !is_string($policy))
            ) {
                throw new \InvalidArgumentException('product_media_assignment_invalid');
            }
            if ($policy !== null) {
                try {
                    $decoded = json_decode($policy, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \InvalidArgumentException('product_media_access_policy_invalid');
                }
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException('product_media_access_policy_invalid');
                }
            }
            $desired[$assetId] = [
                Media::schema_fields_STORE_ID => $storeId,
                Media::schema_fields_SCOPE_STATE => 'explicit',
                Media::schema_fields_HIDDEN => !empty($row[Media::schema_fields_HIDDEN]) ? 1 : 0,
                Media::schema_fields_ROLE => $role,
                Media::schema_fields_ASSET_ID => $assetId,
                Media::schema_fields_ASSET_VISIBILITY => $visibility,
                Media::schema_fields_MIME_TYPE => $mimeType,
                Media::schema_fields_ACCESS_POLICY_JSON => $policy,
                Media::schema_fields_POSITION => $position,
            ];
        }

        $existing = $this->listByProductIds($websiteId, [$productId], [$storeId]);
        $byAsset = [];
        $duplicates = [];
        foreach ($existing as $row) {
            $assetId = strtolower(trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')));
            if ($assetId === '') {
                continue;
            }
            if (isset($byAsset[$assetId])) {
                $duplicates[] = (int)($row[Media::schema_fields_ID] ?? 0);
                continue;
            }
            $byAsset[$assetId] = $row;
        }

        foreach ($desired as $assetId => $fields) {
            $existingRow = $byAsset[$assetId] ?? null;
            if ($existingRow === null) {
                $this->create($websiteId, array_merge($fields, [
                    Media::schema_fields_PRODUCT_ID => $productId,
                    Media::schema_fields_PATH => 'asset://' . $assetId,
                    Media::schema_fields_BLOB_KEY => 'asset:' . hash(
                        'sha256',
                        implode("\0", [
                            (string)$websiteId,
                            (string)$productId,
                            (string)$storeId,
                            $assetId,
                            bin2hex(random_bytes(16)),
                        ]),
                    ),
                ]));
                continue;
            }
            $media = $this->findById($websiteId, (int)$existingRow[Media::schema_fields_ID]);
            if ($media === null) {
                throw new \RuntimeException('product_media_assignment_readback_failed');
            }
            foreach ($fields as $field => $value) {
                $media->setData($field, $value);
            }
            $media->save();
            unset($byAsset[$assetId]);
        }

        foreach ($byAsset as $row) {
            $this->remove($websiteId, (int)$row[Media::schema_fields_ID]);
        }
        foreach ($duplicates as $mediaId) {
            if ($mediaId > 0) {
                $this->remove($websiteId, $mediaId);
            }
        }
        return $this->listByProductIds($websiteId, [$productId], [$storeId]);
    }

    /**
     * Remove one media row while preserving legacy COW owner/ref-count rules.
     */
    public function remove(int $websiteId, int $mediaId): void
    {
        $this->assertWebsite($websiteId);
        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            try {
                $this->transactions->run(
                    $this->connectionFactory,
                    function () use ($websiteId, $mediaId): void {
                        $media = $this->findById($websiteId, $mediaId);
                        if ($media === null) {
                            return;
                        }
                        $blobKey = (string)$media->getData(Media::schema_fields_BLOB_KEY);
                        $group = $this->loadBlobGroup($websiteId, $blobKey);
                        $owner = $this->blobOwner($group, $blobKey);
                        $this->assertBlobInvariant($group, $owner, $blobKey);
                        $count = count($group);
                        $claimedOwner = $this->claimOwner($websiteId, $owner, $count, $count);
                        if ($claimedOwner === null) {
                            throw $this->contention($websiteId, $blobKey);
                        }

                        if ($count === 1) {
                            $claimedOwner->delete();
                            return;
                        }
                        $ownerId = (int)$claimedOwner->getId();
                        if ($mediaId === $ownerId) {
                            $this->promoteReplacementOwner(
                                $websiteId,
                                $group,
                                $claimedOwner,
                                $blobKey,
                            );
                            $claimedOwner->delete();
                        } else {
                            $claimedOwner
                                ->setData(Media::schema_fields_REF_COUNT, $count - 1)
                                ->setData(Media::schema_fields_CAS_TOKEN, $this->newCasToken())
                                ->save();
                            $media->delete();
                        }

                        $remaining = $this->loadBlobGroup($websiteId, $blobKey);
                        $remainingOwner = $this->blobOwner($remaining, $blobKey);
                        $this->assertBlobInvariant($remaining, $remainingOwner, $blobKey);
                    },
                );
                return;
            } catch (CatalogConflictException $exception) {
                if ($exception->errorCode() !== 'media_cas_contention') {
                    throw $exception;
                }
            }
        }
        throw new CatalogConflictException(
            'media_cas_contention',
            __('Media remove 并发重试耗尽：media_id=%{1}', [$mediaId]),
            ['website_id' => $websiteId, 'media_id' => $mediaId],
        );
    }

    /**
     * Create an independent blob owner. Shared rows must use shareCopy().
     *
     * @param array<string, mixed> $data
     */
    public function create(int $websiteId, array $data): Media
    {
        $this->assertWebsite($websiteId);
        $path = trim((string)($data[Media::schema_fields_PATH] ?? ''));
        $blobKey = trim((string)($data[Media::schema_fields_BLOB_KEY] ?? ''));
        if ($path === '' || $blobKey === '') {
            throw new \InvalidArgumentException(__('Media path/blob_key 不能为空'));
        }
        if ($this->findBlobGroup($websiteId, $blobKey) !== []) {
            throw new CatalogConflictException(
                'media_blob_key_conflict',
                __('Media blob_key 已存在；共享必须使用 shareCopy：%{1}', [$blobKey]),
                ['website_id' => $websiteId, 'blob_key' => $blobKey],
            );
        }
        $data[Media::schema_fields_PATH] = $path;
        $data[Media::schema_fields_BLOB_KEY] = $blobKey;
        return $this->insertMedia($websiteId, $data, null);
    }

    /**
     * Copy media on the same Website shard while retaining one blob owner.
     */
    public function shareCopy(
        int $websiteId,
        int $sourceMediaId,
        int $targetProductId,
        int $position = 0,
    ): Media {
        $this->assertWebsite($websiteId);

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            try {
                return $this->transactions->run(
                    $this->connectionFactory,
                    function () use (
                        $websiteId,
                        $sourceMediaId,
                        $targetProductId,
                        $position,
                    ): Media {
                        $source = $this->findById($websiteId, $sourceMediaId);
                        if ($source === null) {
                            throw new \InvalidArgumentException(__(
                                '源 Media 不存在：%{1}',
                                [$sourceMediaId],
                            ));
                        }

                        $blobKey = (string)$source->getData(Media::schema_fields_BLOB_KEY);
                        $group = $this->loadBlobGroup($websiteId, $blobKey);
                        $owner = $this->blobOwner($group, $blobKey);
                        $this->assertBlobInvariant($group, $owner, $blobKey);

                        $claimed = $this->claimOwner(
                            $websiteId,
                            $owner,
                            count($group),
                            count($group) + 1,
                        );
                        if ($claimed === null) {
                            throw $this->contention($websiteId, $blobKey);
                        }

                        $copy = $this->insertMedia(
                            $websiteId,
                            [
                                Media::schema_fields_PRODUCT_ID => $targetProductId,
                                Media::schema_fields_STORE_ID => 0,
                                Media::schema_fields_SCOPE_STATE => 'explicit',
                                Media::schema_fields_HIDDEN => (int)$source->getData(Media::schema_fields_HIDDEN),
                                Media::schema_fields_ROLE => (string)$source->getData(Media::schema_fields_ROLE),
                                Media::schema_fields_ASSET_ID => $source->getData(Media::schema_fields_ASSET_ID),
                                Media::schema_fields_ASSET_VISIBILITY => (string)$source->getData(
                                    Media::schema_fields_ASSET_VISIBILITY,
                                ),
                                Media::schema_fields_MIME_TYPE => $source->getData(Media::schema_fields_MIME_TYPE),
                                Media::schema_fields_ACCESS_POLICY_JSON => $source->getData(
                                    Media::schema_fields_ACCESS_POLICY_JSON,
                                ),
                                Media::schema_fields_PATH => (string)$source->getData(
                                    Media::schema_fields_PATH,
                                ),
                                Media::schema_fields_BLOB_KEY => $blobKey,
                                Media::schema_fields_POSITION => $position,
                            ],
                            (int)$claimed->getId(),
                        );

                        $after = $this->loadBlobGroup($websiteId, $blobKey);
                        $afterOwner = $this->blobOwner($after, $blobKey);
                        $this->assertBlobInvariant($after, $afterOwner, $blobKey);
                        if (count($after) !== count($group) + 1) {
                            throw new CatalogConflictException(
                                'media_ref_count_corrupt',
                                __('Media shareCopy 后 blob 引用数不守恒：%{1}', [$blobKey]),
                                ['website_id' => $websiteId, 'blob_key' => $blobKey],
                            );
                        }
                        return $copy;
                    },
                );
            } catch (CatalogConflictException $exception) {
                if ($exception->errorCode() !== 'media_cas_contention') {
                    throw $exception;
                }
            }
        }

        throw new CatalogConflictException(
            'media_cas_contention',
            __('Media shareCopy 并发重试耗尽：media_id=%{1}', [$sourceMediaId]),
            [
                'website_id' => $websiteId,
                'media_id' => $sourceMediaId,
                'attempts' => self::MAX_CAS_ATTEMPTS,
            ],
        );
    }

    /**
     * Edit media content. Shared blob rows fork; an exclusive owner edits in place.
     *
     * @return array{media: Media, cow: bool, previous_blob_key: string}
     */
    public function cowEdit(
        int $websiteId,
        int $mediaId,
        string $newPath,
        string $newBlobKey,
    ): array {
        $this->assertWebsite($websiteId);
        $newPath = trim($newPath);
        $newBlobKey = trim($newBlobKey);
        if ($newPath === '' || $newBlobKey === '') {
            throw new \InvalidArgumentException(__('Media path/blob_key 不能为空'));
        }

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            try {
                return $this->transactions->run(
                    $this->connectionFactory,
                    function () use ($websiteId, $mediaId, $newPath, $newBlobKey): array {
                        $media = $this->findById($websiteId, $mediaId);
                        if ($media === null) {
                            throw new \InvalidArgumentException(__('Media 不存在：%{1}', [$mediaId]));
                        }

                        $previousBlob = (string)$media->getData(Media::schema_fields_BLOB_KEY);
                        $group = $this->loadBlobGroup($websiteId, $previousBlob);
                        $owner = $this->blobOwner($group, $previousBlob);
                        $this->assertBlobInvariant($group, $owner, $previousBlob);
                        $count = count($group);
                        if ($count > 1 && $newBlobKey === $previousBlob) {
                            throw new CatalogConflictException(
                                'media_blob_key_conflict',
                                __('共享 Media COW 必须使用新的 blob_key：%{1}', [$previousBlob]),
                                ['website_id' => $websiteId, 'blob_key' => $previousBlob],
                            );
                        }
                        if ($newBlobKey !== $previousBlob
                            && $this->findBlobGroup($websiteId, $newBlobKey) !== []
                        ) {
                            throw new CatalogConflictException(
                                'media_blob_key_conflict',
                                __('Media COW 目标 blob_key 已存在：%{1}', [$newBlobKey]),
                                ['website_id' => $websiteId, 'blob_key' => $newBlobKey],
                            );
                        }

                        $claimedOwner = $this->claimOwner(
                            $websiteId,
                            $owner,
                            $count,
                            $count,
                        );
                        if ($claimedOwner === null) {
                            throw $this->contention($websiteId, $previousBlob);
                        }

                        $ownerId = (int)$claimedOwner->getId();
                        if ($mediaId === $ownerId) {
                            $media = $claimedOwner;
                        } else {
                            $media = $this->findById($websiteId, $mediaId);
                            if ($media === null
                                || (string)$media->getData(Media::schema_fields_BLOB_KEY)
                                    !== $previousBlob
                                || (int)$media->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID)
                                    !== $ownerId
                            ) {
                                throw $this->contention($websiteId, $previousBlob);
                            }
                        }

                        if ($count === 1) {
                            $this->setIndependentBlob($media, $newPath, $newBlobKey);
                            $updated = $this->findById($websiteId, $mediaId);
                            if ($updated === null) {
                                throw new \RuntimeException(__('Media 编辑后无法回读：%{1}', [$mediaId]));
                            }
                            $this->assertBlobInvariant([$updated], $updated, $newBlobKey);
                            return [
                                'media' => $updated,
                                'cow' => false,
                                'previous_blob_key' => $previousBlob,
                            ];
                        }

                        if ($mediaId === $ownerId) {
                            $this->promoteReplacementOwner(
                                $websiteId,
                                $group,
                                $claimedOwner,
                                $previousBlob,
                            );
                        } else {
                            $claimedOwner
                                ->setData(Media::schema_fields_REF_COUNT, $count - 1)
                                ->setData(Media::schema_fields_CAS_TOKEN, $this->newCasToken())
                                ->save();
                        }

                        $this->setIndependentBlob($media, $newPath, $newBlobKey);
                        $updated = $this->findById($websiteId, $mediaId);
                        if ($updated === null) {
                            throw new \RuntimeException(__('Media COW 后无法回读：%{1}', [$mediaId]));
                        }

                        $oldGroup = $this->loadBlobGroup($websiteId, $previousBlob);
                        $oldOwner = $this->blobOwner($oldGroup, $previousBlob);
                        $this->assertBlobInvariant($oldGroup, $oldOwner, $previousBlob);
                        $this->assertBlobInvariant([$updated], $updated, $newBlobKey);

                        return [
                            'media' => $updated,
                            'cow' => true,
                            'previous_blob_key' => $previousBlob,
                        ];
                    },
                );
            } catch (CatalogConflictException $exception) {
                if ($exception->errorCode() !== 'media_cas_contention') {
                    throw $exception;
                }
            }
        }

        throw new CatalogConflictException(
            'media_cas_contention',
            __('Media cowEdit 并发重试耗尽：media_id=%{1}', [$mediaId]),
            [
                'website_id' => $websiteId,
                'media_id' => $mediaId,
                'attempts' => self::MAX_CAS_ATTEMPTS,
            ],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertMedia(int $websiteId, array $data, ?int $ownerMediaId): Media
    {
        unset(
            $data[Media::schema_fields_ID],
            $data[Media::schema_fields_REF_COUNT],
            $data[Media::schema_fields_COW_SOURCE_MEDIA_ID],
            $data[Media::schema_fields_CAS_TOKEN],
        );
        $model = $this->newModel($websiteId);
        $model->clear()->setData(array_merge($data, [
            Media::schema_fields_REF_COUNT => 1,
            Media::schema_fields_COW_SOURCE_MEDIA_ID => $ownerMediaId,
            Media::schema_fields_CAS_TOKEN => '',
            Media::schema_fields_POSITION => (int)($data[Media::schema_fields_POSITION] ?? 0),
        ]))->save();
        $id = (int)$model->getId();
        $loaded = $this->findById($websiteId, $id);
        if ($loaded === null) {
            throw new \RuntimeException(__('Media 写入后无法回读：%{1}', [$id]));
        }
        return $loaded;
    }

    /**
     * @param list<Media> $group
     */
    private function blobOwner(array $group, string $blobKey): Media
    {
        $owners = array_values(array_filter(
            $group,
            static function (Media $media): bool {
                $source = $media->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID);
                return $source === null || $source === '' || (int)$source === 0;
            },
        ));
        if (count($owners) !== 1) {
            throw new CatalogConflictException(
                'media_ref_count_corrupt',
                __('Media blob owner 数量异常：blob=%{1} owners=%{2}', [$blobKey, count($owners)]),
                ['blob_key' => $blobKey, 'owner_count' => count($owners)],
            );
        }
        return $owners[0];
    }

    /**
     * @return list<Media>
     */
    private function loadBlobGroup(int $websiteId, string $blobKey): array
    {
        $group = $this->findBlobGroup($websiteId, $blobKey);
        if ($group === []) {
            throw new CatalogConflictException(
                'media_ref_count_corrupt',
                __('Media blob 没有引用行：%{1}', [$blobKey]),
                ['website_id' => $websiteId, 'blob_key' => $blobKey],
            );
        }
        return $group;
    }

    /**
     * @return list<Media>
     */
    private function findBlobGroup(int $websiteId, string $blobKey): array
    {
        $model = $this->newModel($websiteId);
        $rows = $model->clear()
            ->where(Media::schema_fields_BLOB_KEY, $blobKey)
            ->select()
            ->fetchArray();
        $group = [];
        foreach ($rows as $row) {
            $mediaId = (int)($row[Media::schema_fields_ID] ?? 0);
            if ($mediaId <= 0) {
                continue;
            }
            $loaded = $this->findById($websiteId, $mediaId);
            if ($loaded !== null) {
                $group[] = $loaded;
            }
        }
        return $group;
    }

    /**
     * @param list<Media> $group
     */
    private function assertBlobInvariant(array $group, Media $owner, string $blobKey): void
    {
        $ownerId = (int)$owner->getId();
        $count = count($group);
        if ((string)$owner->getData(Media::schema_fields_BLOB_KEY) !== $blobKey
            || (int)$owner->getData(Media::schema_fields_REF_COUNT) !== $count
        ) {
            throw new CatalogConflictException(
                'media_ref_count_corrupt',
                __('Media blob owner ref_count 不守恒：%{1}', [$blobKey]),
                [
                    'blob_key' => $blobKey,
                    'owner_media_id' => $ownerId,
                    'expected_ref_count' => $count,
                    'actual_ref_count' => (int)$owner->getData(Media::schema_fields_REF_COUNT),
                ],
            );
        }

        foreach ($group as $media) {
            $mediaId = (int)$media->getId();
            $sourceId = (int)$media->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID);
            if ($mediaId === $ownerId) {
                if ($sourceId !== 0) {
                    throw new CatalogConflictException(
                        'media_ref_count_corrupt',
                        __('Media owner 不能指向 cow_source：%{1}', [$ownerId]),
                        ['blob_key' => $blobKey, 'owner_media_id' => $ownerId],
                    );
                }
                continue;
            }
            if ($sourceId !== $ownerId
                || (int)$media->getData(Media::schema_fields_REF_COUNT) !== 1
            ) {
                throw new CatalogConflictException(
                    'media_ref_count_corrupt',
                    __('Media copy owner/ref_count 不守恒：%{1}', [$mediaId]),
                    [
                        'blob_key' => $blobKey,
                        'media_id' => $mediaId,
                        'owner_media_id' => $ownerId,
                    ],
                );
            }
        }
    }

    private function claimOwner(
        int $websiteId,
        Media $owner,
        int $expectedRefCount,
        int $nextRefCount,
    ): ?Media {
        $ownerId = (int)$owner->getId();
        $expectedToken = (string)$owner->getData(Media::schema_fields_CAS_TOKEN);
        $writerToken = $this->newCasToken();
        $candidate = $this->newModel($websiteId)->clear();
        $candidate->getQuery()
            ->where(Media::schema_fields_ID, $ownerId)
            ->where(Media::schema_fields_REF_COUNT, $expectedRefCount)
            ->where(Media::schema_fields_CAS_TOKEN, $expectedToken)
            ->update([
                Media::schema_fields_REF_COUNT => $nextRefCount,
                Media::schema_fields_CAS_TOKEN => $writerToken,
            ])
            ->fetch();
        $current = $this->findById($websiteId, $ownerId);
        return $current !== null
            && hash_equals(
                $writerToken,
                (string)$current->getData(Media::schema_fields_CAS_TOKEN),
            )
            ? $current
            : null;
    }

    /**
     * @param list<Media> $group
     */
    private function promoteReplacementOwner(
        int $websiteId,
        array $group,
        Media $oldOwner,
        string $blobKey,
    ): void {
        $oldOwnerId = (int)$oldOwner->getId();
        $replacement = null;
        foreach ($group as $candidate) {
            if ((int)$candidate->getId() !== $oldOwnerId) {
                $replacement = $candidate;
                break;
            }
        }
        if ($replacement === null) {
            throw new CatalogConflictException(
                'media_ref_count_corrupt',
                __('共享 Media 缺少可提升 owner：%{1}', [$blobKey]),
                ['website_id' => $websiteId, 'blob_key' => $blobKey],
            );
        }

        $replacementId = (int)$replacement->getId();
        $remainingCount = count($group) - 1;
        $replacementCandidate = $this->newModel($websiteId)->clear();
        $replacementCandidate->getQuery()
            ->where(Media::schema_fields_ID, $replacementId)
            ->update([
                Media::schema_fields_REF_COUNT => $remainingCount,
                Media::schema_fields_COW_SOURCE_MEDIA_ID => null,
                Media::schema_fields_CAS_TOKEN => $this->newCasToken(),
            ])
            ->fetch();

        foreach ($group as $copy) {
            $copyId = (int)$copy->getId();
            if ($copyId === $oldOwnerId || $copyId === $replacementId) {
                continue;
            }
            $copy
                ->setData(Media::schema_fields_REF_COUNT, 1)
                ->setData(Media::schema_fields_COW_SOURCE_MEDIA_ID, $replacementId)
                ->save();
        }
    }

    private function setIndependentBlob(Media $media, string $path, string $blobKey): void
    {
        $candidate = $this->newModel($media->websiteId())->clear();
        $candidate->getQuery()
            ->where(Media::schema_fields_ID, (int)$media->getId())
            ->update([
                Media::schema_fields_PATH => $path,
                Media::schema_fields_BLOB_KEY => $blobKey,
                Media::schema_fields_REF_COUNT => 1,
                Media::schema_fields_COW_SOURCE_MEDIA_ID => null,
                Media::schema_fields_CAS_TOKEN => $this->newCasToken(),
            ])
            ->fetch();
    }

    private function contention(int $websiteId, string $blobKey): CatalogConflictException
    {
        return new CatalogConflictException(
            'media_cas_contention',
            __('Media blob 并发冲突：%{1}', [$blobKey]),
            ['website_id' => $websiteId, 'blob_key' => $blobKey],
        );
    }

    private function newCasToken(): string
    {
        $token = $this->casTokenFactory !== null
            ? strtolower(trim((string)($this->casTokenFactory)()))
            : bin2hex(random_bytes(32));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw new \LogicException(__('Media CAS token factory 必须返回 32-64 位十六进制'));
        }
        return $token;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var Media $model */
        $model = ObjectManager::create(Media::class, [], false);
        return $model->forWebsite($websiteId);
    }
}
