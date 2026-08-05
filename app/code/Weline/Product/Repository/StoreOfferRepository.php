<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Service\ProductShardProvisioner;

final class StoreOfferRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): StoreOffer)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): StoreOffer)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    public function select(int $websiteId, int $storeId, int $offerId, bool $selected = true): StoreOffer
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreOverlayId($storeId, 'store_offer');
        $existing = $this->find($websiteId, $storeId, $offerId);
        if ($existing !== null) {
            $existing->setData(StoreOffer::schema_fields_SELECTED, $selected ? 1 : 0)->save();
            return $existing;
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData([
            StoreOffer::schema_fields_STORE_ID => $storeId,
            StoreOffer::schema_fields_OFFER_ID => $offerId,
            StoreOffer::schema_fields_SELECTED => $selected ? 1 : 0,
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
