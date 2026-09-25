<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\NoopProductSearchProjectionMutationCoordinator;
use Weline\Product\Service\ProductShardProvisioner;

final class OfferRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): Offer)|null */
    private readonly mixed $modelFactory;

    /** @var (\Closure(): string)|null */
    private readonly mixed $casTokenFactory;

    private readonly ProductSearchProjectionMutationCoordinatorInterface $projectionMutations;

    /**
     * @param (\Closure(int): Offer)|null $modelFactory
     * @param (\Closure(): string)|null $casTokenFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
        ?callable $casTokenFactory = null,
        ?ProductSearchProjectionMutationCoordinatorInterface $projectionMutations = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
        $this->casTokenFactory = $casTokenFactory;
        $this->projectionMutations = $projectionMutations
            ?? ($modelFactory !== null
                ? new NoopProductSearchProjectionMutationCoordinator()
                : ObjectManager::getInstance(ProductSearchProjectionMutationCoordinatorInterface::class));
    }

    public function findById(int $websiteId, int $offerId): ?Offer
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Offer::schema_fields_ID, $offerId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    public function findByGlobalUuid(int $websiteId, string $uuid): ?Offer
    {
        $this->assertWebsite($websiteId);
        $uuid = trim($uuid);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Offer::schema_fields_GLOBAL_OFFER_UUID, $uuid)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    public function listByProductIds(int $websiteId, array $productIds): array
    {
        return $this->listByProductIdsWithStatus($websiteId, $productIds, null);
    }

    /**
     * Read only published offers for storefront projections.
     *
     * Keeping this predicate in the shard query avoids materializing draft,
     * disabled, and archived rows before the storefront representative scan.
     *
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    public function listPublishedByProductIds(int $websiteId, array $productIds): array
    {
        return $this->listByProductIdsWithStatus($websiteId, $productIds, Offer::STATUS_PUBLISHED);
    }

    /**
     * Read the first published offer for each product directly in SQL.
     *
     * Storefront listing pages only expose one representative offer per
     * product. Grouping the published shard rows before hydrating the full
     * offer records avoids transferring every variant into PHP for that
     * path; the second query preserves the existing offer-id ordering and
     * returns the same complete rows as listPublishedByProductIds().
     *
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    public function listPublishedRepresentativeByProductIds(int $websiteId, array $productIds): array
    {
        $this->assertWebsite($websiteId);
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }

        $representatives = $this->newModel($websiteId)
            ->clear()
            ->fields([
                Offer::schema_fields_PRODUCT_ID,
                'representative_offer_id' => 'MIN(' . Offer::schema_fields_ID . ')',
            ])
            ->where(Offer::schema_fields_PRODUCT_ID, $productIds, 'IN')
            ->where(Offer::schema_fields_STATUS, Offer::STATUS_PUBLISHED)
            ->group(Offer::schema_fields_PRODUCT_ID)
            ->select()
            ->fetchArray();
        $offerIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['representative_offer_id'] ?? 0),
            $representatives,
        ), static fn(int $id): bool => $id > 0));
        if ($offerIds === []) {
            return [];
        }

        return $this->newModel($websiteId)
            ->clear()
            ->where(Offer::schema_fields_ID, $offerIds, 'IN')
            ->order(Offer::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * Page first-published representatives in the same offer-id order as listings.
     * HAVING applies to the first offer, so later variants never reappear on the next page.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublishedRepresentativePage(int $websiteId, int $limit, int $afterOfferId = 0): array
    {
        $this->assertWebsite($websiteId);
        $limit = max(1, min(500, $limit));
        $representatives = $this->newModel($websiteId)->clear()
            ->fields([Offer::schema_fields_PRODUCT_ID, 'representative_offer_id' => 'MIN(' . Offer::schema_fields_ID . ')'])
            ->where(Offer::schema_fields_STATUS, Offer::STATUS_PUBLISHED)
            ->group(Offer::schema_fields_PRODUCT_ID)
            ->having('MIN(' . Offer::schema_fields_ID . ') > ' . max(0, $afterOfferId))
            ->order('representative_offer_id', 'ASC')
            ->limit($limit)
            ->select()->fetchArray();
        $ids = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['representative_offer_id'] ?? 0),
            $representatives,
        ), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        return $this->newModel($websiteId)->clear()
            ->where(Offer::schema_fields_ID, $ids, 'IN')
            ->order(Offer::schema_fields_ID, 'ASC')
            ->select()->fetchArray();
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    private function listByProductIdsWithStatus(int $websiteId, array $productIds, ?string $status): array
    {
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
            ->where(Offer::schema_fields_PRODUCT_ID, $productIds, 'IN');
        if ($status !== null && trim($status) !== '') {
            $query->where(Offer::schema_fields_STATUS, trim($status));
        }

        return $query
            ->order(Offer::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }
    /**
     * @param list<int> $productIds
     * @return array{deleted:int,offer_ids:list<int>,offer_uuids:list<string>}
     */
    public function deleteByProductIds(int $websiteId, array $productIds): array
    {
        $this->assertWebsite($websiteId);
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            return ['deleted' => 0, 'offer_ids' => [], 'offer_uuids' => []];
        }
        $rows = $this->listByProductIds($websiteId, $productIds);
        if ($rows === []) {
            return ['deleted' => 0, 'offer_ids' => [], 'offer_uuids' => []];
        }
        $offerIds = array_map(
            static fn(array $row): int => (int)($row[Offer::schema_fields_ID] ?? 0),
            $rows,
        );
        $offerUuids = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? '')),
            $rows,
        ), static fn(string $uuid): bool => $uuid !== ''));
        $this->newModel($websiteId)
            ->clear()
            ->where(Offer::schema_fields_PRODUCT_ID, $productIds, 'IN')
            ->delete()
            ->fetch();
        return [
            'deleted' => count($rows),
            'offer_ids' => $offerIds,
            'offer_uuids' => $offerUuids,
        ];
    }


    /**
     * @param array<string, mixed> $data
     */
    public function create(int $websiteId, array $data): Offer
    {
        $productId = (int)($data[Offer::schema_fields_PRODUCT_ID] ?? 0);
        if ($productId <= 0) {
            throw new \InvalidArgumentException('offer_product_id_invalid');
        }
        $connection = $this->newModel($websiteId)->getConnection();

        return $this->projectionMutations->execute(
            $connection,
            $websiteId,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_PRODUCT,
            $productId,
            null,
            fn(): Offer => $this->createCurrent($websiteId, $data),
        );
    }

    /** @param array<string, mixed> $data */
    private function createCurrent(int $websiteId, array $data): Offer
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $now = date('Y-m-d H:i:s');
        unset($data[Offer::schema_fields_ID]);
        $model->clear()->setData(array_merge($data, [
            Offer::schema_fields_STATUS => 'draft',
            Offer::schema_fields_PUBLISH_VERSION => 0,
            Offer::schema_fields_CAS_TOKEN => '',
            Offer::schema_fields_CREATED_AT => $now,
            Offer::schema_fields_UPDATED_AT => $now,
        ]))->save();
        $id = (int)$model->getId();
        $loaded = $this->findById($websiteId, $id);
        if ($loaded === null) {
            throw new \RuntimeException(__('Offer 写入后无法回读：%{1}', [$id]));
        }
        return $loaded;
    }

    public function publish(int $websiteId, int $offerId, int $expectedVersion): Offer
    {
        $offer = $this->findById($websiteId, $offerId)
            ?? throw new \InvalidArgumentException(__('Offer 不存在：%{1}', [$offerId]));
        $productId = $this->parentProductId($offer, $offerId);
        $connection = $this->newModel($websiteId)->getConnection();

        return $this->projectionMutations->execute(
            $connection,
            $websiteId,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_PRODUCT,
            $productId,
            null,
            fn(): Offer => $this->publishCurrent($websiteId, $offerId, $expectedVersion),
        );
    }

    private function publishCurrent(int $websiteId, int $offerId, int $expectedVersion): Offer
    {
        $this->assertWebsite($websiteId);
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException(__('publish_version 不能为负数：%{1}', [$expectedVersion]));
        }
        $offer = $this->findById($websiteId, $offerId);
        if ($offer === null) {
            throw new \InvalidArgumentException(__('Offer 不存在：%{1}', [$offerId]));
        }
        $current = (int)$offer->getData(Offer::schema_fields_PUBLISH_VERSION);
        if ($current !== $expectedVersion) {
            throw new CatalogConflictException(
                'publish_version_conflict',
                __('Offer publish_version 冲突：expected=%{1} actual=%{2}', [$expectedVersion, $current]),
                [
                    'website_id' => $websiteId,
                    'offer_id' => $offerId,
                    'expected' => $expectedVersion,
                    'actual' => $current,
                ],
            );
        }
        $next = $expectedVersion + 1;
        $previousToken = (string)$offer->getData(Offer::schema_fields_CAS_TOKEN);
        $writerToken = $this->newCasToken();
        $candidate = $this->newModel($websiteId)->clear();
        $candidate->getQuery()
            ->where(Offer::schema_fields_ID, $offerId)
            ->where(Offer::schema_fields_PUBLISH_VERSION, $expectedVersion)
            ->where(Offer::schema_fields_CAS_TOKEN, $previousToken)
            ->update([
                Offer::schema_fields_STATUS => 'published',
                Offer::schema_fields_PUBLISH_VERSION => $next,
                Offer::schema_fields_CAS_TOKEN => $writerToken,
                Offer::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();
        $reloaded = $this->findById($websiteId, $offerId);
        if ($reloaded === null
            || (int)$reloaded->getData(Offer::schema_fields_PUBLISH_VERSION) !== $next
            || !hash_equals(
                $writerToken,
                (string)$reloaded->getData(Offer::schema_fields_CAS_TOKEN),
            )
        ) {
            throw new CatalogConflictException(
                'publish_version_conflict',
                __('Offer publish CAS 失败：offer_id=%{1}', [$offerId]),
                [
                    'website_id' => $websiteId,
                    'offer_id' => $offerId,
                    'expected' => $expectedVersion,
                    'actual' => $reloaded === null
                        ? null
                        : (int)$reloaded->getData(Offer::schema_fields_PUBLISH_VERSION),
                ],
            );
        }
        return $reloaded;
    }

    /** @param array<string, mixed> $fields */
    public function updateVersioned(
        int $websiteId,
        int $offerId,
        int $expectedVersion,
        array $fields,
    ): Offer {
        $allowed = [
            'sku',
            'identity_version',
            'combination_key',
            'is_default',
            'requires_shipping',
            'shipping_profile_code',
            'shipping_hazard_class',
            'is_free_shipping',
            'free_shipping_min_amount',
            'type_config_json',
        ];
        foreach (array_keys($fields) as $field) {
            if (!in_array((string)$field, $allowed, true)) {
                throw new \InvalidArgumentException('offer_projection_field_forbidden');
            }
        }
        return $this->mutateVersioned($websiteId, $offerId, $expectedVersion, $fields);
    }

    public function transition(
        int $websiteId,
        int $offerId,
        int $expectedVersion,
        string $targetStatus,
    ): Offer {
        $targetStatus = strtolower(trim($targetStatus));
        if (!in_array($targetStatus, ['draft', 'published', 'disabled', 'archived'], true)) {
            throw new \InvalidArgumentException('offer_status_invalid');
        }
        return $this->mutateVersioned(
            $websiteId,
            $offerId,
            $expectedVersion,
            [Offer::schema_fields_STATUS => $targetStatus],
        );
    }

    /** @param array<string, mixed> $fields */
    private function mutateVersioned(
        int $websiteId,
        int $offerId,
        int $expectedVersion,
        array $fields,
    ): Offer {
        $offer = $this->findById($websiteId, $offerId)
            ?? throw new \InvalidArgumentException('offer_website_projection_not_found');
        $productId = $this->parentProductId($offer, $offerId);
        $connection = $this->newModel($websiteId)->getConnection();

        return $this->projectionMutations->execute(
            $connection,
            $websiteId,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_PRODUCT,
            $productId,
            null,
            fn(): Offer => $this->mutateVersionedCurrent(
                $websiteId,
                $offerId,
                $expectedVersion,
                $fields,
            ),
        );
    }

    /** @param array<string, mixed> $fields */
    private function mutateVersionedCurrent(
        int $websiteId,
        int $offerId,
        int $expectedVersion,
        array $fields,
    ): Offer {
        $this->assertWebsite($websiteId);
        $offer = $this->findById($websiteId, $offerId)
            ?? throw new \InvalidArgumentException('offer_website_projection_not_found');
        $actual = (int)$offer->getData(Offer::schema_fields_PUBLISH_VERSION);
        if ($expectedVersion < 0 || $actual !== $expectedVersion) {
            throw new CatalogConflictException(
                'publish_version_conflict',
                __('Offer 版本已变化，请刷新后重试'),
                ['website_id' => $websiteId, 'offer_id' => $offerId, 'expected' => $expectedVersion, 'actual' => $actual],
            );
        }
        $next = $expectedVersion + 1;
        $writerToken = $this->newCasToken();
        $previousToken = (string)$offer->getData(Offer::schema_fields_CAS_TOKEN);
        $this->newModel($websiteId)->clear()->getQuery()
            ->where(Offer::schema_fields_ID, $offerId)
            ->where(Offer::schema_fields_PUBLISH_VERSION, $expectedVersion)
            ->where(Offer::schema_fields_CAS_TOKEN, $previousToken)
            ->update(array_merge($fields, [
                Offer::schema_fields_PUBLISH_VERSION => $next,
                Offer::schema_fields_CAS_TOKEN => $writerToken,
                Offer::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ]))->fetch();
        $updated = $this->findById($websiteId, $offerId);
        if ($updated === null
            || (int)$updated->getData(Offer::schema_fields_PUBLISH_VERSION) !== $next
            || !hash_equals($writerToken, (string)$updated->getData(Offer::schema_fields_CAS_TOKEN))
        ) {
            throw new CatalogConflictException(
                'publish_version_conflict',
                __('Offer 更新 CAS 失败'),
                ['website_id' => $websiteId, 'offer_id' => $offerId, 'expected' => $expectedVersion],
            );
        }
        return $updated;
    }

    private function parentProductId(Offer $offer, int $offerId): int
    {
        $productId = (int)$offer->getData(Offer::schema_fields_PRODUCT_ID);
        if ($productId <= 0) {
            throw new \LogicException(__('Offer 缺少有效的父 Product：%{1}', [$offerId]));
        }
        return $productId;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var Offer $model */
        $model = ObjectManager::create(Offer::class, [], false);
        return $model->forWebsite($websiteId);
    }

    private function newCasToken(): string
    {
        $token = $this->casTokenFactory !== null
            ? strtolower(trim((string)($this->casTokenFactory)()))
            : bin2hex(random_bytes(32));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw new \LogicException(__('Offer CAS token factory 必须返回 32-64 位十六进制'));
        }
        return $token;
    }
}
