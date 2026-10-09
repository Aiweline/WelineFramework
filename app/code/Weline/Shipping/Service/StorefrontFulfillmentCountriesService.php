<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Shipping\Api\StorefrontFulfillmentCountriesContributorInterface;
use Weline\Shipping\Api\StorefrontFulfillmentCountriesInterface;

/**
 * 合并各贡献方的「有可售 offer」履约仓国。
 */
final class StorefrontFulfillmentCountriesService implements StorefrontFulfillmentCountriesInterface
{
    /** @var list<class-string<StorefrontFulfillmentCountriesContributorInterface>> */
    private const DEFAULT_CONTRIBUTORS = [
        \Weline\Dropship\Service\DropshipFulfillmentCountriesContributor::class,
        \Weline\Inventory\Service\InventoryFulfillmentCountriesContributor::class,
    ];

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function listCountryCodes(int $websiteId = -1, int $storeId = -1): array
    {
        $websiteId = $websiteId >= 0 ? $websiteId : max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = $storeId >= 0 ? $storeId : max(0, (int)RequestContext::getWelineStoreId());
        $set = [];
        foreach (self::DEFAULT_CONTRIBUTORS as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $contributor = $this->objectManager->getInstance($class);
                if (!$contributor instanceof StorefrontFulfillmentCountriesContributorInterface) {
                    continue;
                }
                foreach ($contributor->listCountryCodes($websiteId, $storeId) as $code) {
                    $cc = StorefrontOfferOriginCountryService::normalizeCountryCode((string)$code);
                    if ($cc !== '') {
                        $set[$cc] = true;
                    }
                }
            } catch (\Throwable) {
                // 贡献方不可用则跳过
            }
        }
        $list = array_keys($set);
        sort($list);

        return $list;
    }
}
