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
        $this->assertWebsite($websiteId);
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }
        $rows = $this->newModel($websiteId)
            ->clear()
            ->where(Offer::schema_fields_PRODUCT_ID, $productIds, 'IN')
            ->select()
            ->fetchArray();
        usort(
            $rows,
            static fn(array $left, array $right): int => (int)($left[Offer::schema_fields_ID] ?? 0)
                <=> (int)($right[Offer::schema_fields_ID] ?? 0),
        );
        return $rows;
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
