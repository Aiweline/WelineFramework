<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\ProductShardProvisioner;

final class OfferRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): Offer)|null */
    private readonly mixed $modelFactory;

    /** @var (\Closure(): string)|null */
    private readonly mixed $casTokenFactory;

    /**
     * @param (\Closure(int): Offer)|null $modelFactory
     * @param (\Closure(): string)|null $casTokenFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
        ?callable $casTokenFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
        $this->casTokenFactory = $casTokenFactory;
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
