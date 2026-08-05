<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * Website-bound shard repository base. Never runs DDL on request path.
 *
 * @template T of AbstractWebsiteShardModel
 */
abstract class AbstractWebsiteShardRepository
{
    public function __construct(
        protected readonly ProductShardProvisioner $provisioner,
    ) {
    }

    /**
     * @return T
     */
    abstract protected function newModel(int $websiteId): AbstractWebsiteShardModel;

    protected function assertWebsite(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数：%{1}', [$websiteId]));
        }
        $this->provisioner->assertReady($websiteId);
    }

    protected function assertStoreId(int $storeId): void
    {
        if ($storeId < 0) {
            throw new \InvalidArgumentException(__('store_id 不能为负数：%{1}', [$storeId]));
        }
    }

    protected function assertStoreOverlayId(int $storeId, string $entity): void
    {
        $this->assertStoreId($storeId);
        if ($storeId === 0) {
            throw new \InvalidArgumentException(__(
                '%{1}.store_id 不能为 0（Website 层）',
                [$entity],
            ));
        }
    }
}
