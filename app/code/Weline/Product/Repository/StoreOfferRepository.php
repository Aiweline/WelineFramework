<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Service\NoopProductSearchProjectionMutationCoordinator;
use Weline\Product\Service\ProductShardProvisioner;

final class StoreOfferRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): StoreOffer)|null */
    private readonly mixed $modelFactory;

    private readonly ?OfferRepository $offers;

    private readonly ProductSearchProjectionMutationCoordinatorInterface $projectionMutations;

    /**
     * @param (\Closure(int): StoreOffer)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
        ?OfferRepository $offers = null,
        ?ProductSearchProjectionMutationCoordinatorInterface $projectionMutations = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
        $this->offers = $offers
            ?? ($modelFactory !== null ? null : ObjectManager::getInstance(OfferRepository::class));
        $this->projectionMutations = $projectionMutations
            ?? ($modelFactory !== null
                ? new NoopProductSearchProjectionMutationCoordinator()
                : ObjectManager::getInstance(ProductSearchProjectionMutationCoordinatorInterface::class));

        if ($projectionMutations !== null && $this->offers === null) {
            throw new \LogicException('store_offer_projection_requires_offer_repository');
        }
    }

    public function select(int $websiteId, int $storeId, int $offerId, bool $selected = true): StoreOffer
    {
        if ($this->offers === null) {
            return $this->selectCurrent($websiteId, $storeId, $offerId, $selected);
        }

        $this->assertWebsite($websiteId);
        $this->assertStoreOverlayId($storeId, 'store_offer');
        $offer = $this->offers->findById($websiteId, $offerId)
            ?? throw new \InvalidArgumentException(__('Offer 不存在：%{1}', [$offerId]));
        $productId = (int)$offer->getData(Offer::schema_fields_PRODUCT_ID);
        if ($productId <= 0) {
            throw new \LogicException(__('Offer 缺少有效的父 Product：%{1}', [$offerId]));
        }
        $connection = $this->newModel($websiteId)->getConnection();

        return $this->projectionMutations->execute(
            $connection,
            $websiteId,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_STORE_PRODUCT,
            $productId,
            $storeId,
            fn(): StoreOffer => $this->selectCurrent($websiteId, $storeId, $offerId, $selected),
        );
    }

    private function selectCurrent(
        int $websiteId,
        int $storeId,
        int $offerId,
        bool $selected,
    ): StoreOffer {
        $this->assertWebsite($websiteId);
        $this->assertStoreOverlayId($storeId, 'store_offer');
        $existing = $this->find($websiteId, $storeId, $offerId);
        if ($existing !== null) {
            $existing->setData(StoreOffer::schema_fields_SELECTED, $selected ? 1 : 0);
            $existing->setData('inheritance_mode', 'explicit');
            $existing->setData('version', (int)$existing->getData('version') + 1);
            $existing->save();
            return $existing;
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData([
            StoreOffer::schema_fields_STORE_ID => $storeId,
            StoreOffer::schema_fields_OFFER_ID => $offerId,
            StoreOffer::schema_fields_SELECTED => $selected ? 1 : 0,
            'inheritance_mode' => 'explicit',
            'version' => 1,
        ])->save();
        $loaded = $this->find($websiteId, $storeId, $offerId);
        if ($loaded === null) {
            throw new \RuntimeException(__('StoreOffer 写入后无法回读'));
        }
        return $loaded;
    }

    public function find(int $websiteId, int $storeId, int $offerId): ?StoreOffer
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreOverlayId($storeId, 'store_offer');
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(StoreOffer::schema_fields_STORE_ID, $storeId)
            ->where(StoreOffer::schema_fields_OFFER_ID, $offerId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    public function isSelected(int $websiteId, int $storeId, int $offerId): bool
    {
        $row = $this->find($websiteId, $storeId, $offerId);
        if ($row === null) {
            return true;
        }
        return (int)$row->getData(StoreOffer::schema_fields_SELECTED) === 1;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var StoreOffer $model */
        $model = ObjectManager::create(StoreOffer::class, [], false);
        return $model->forWebsite($websiteId);
    }
}
