<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Product\StorefrontShippingProfileCatalogProvider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Storefront\StorefrontShippingProfileCatalogProviderInterface;
use Weline\Shipping\Model\ShippingService;

final class ShippingServiceProfileCatalogProvider implements StorefrontShippingProfileCatalogProviderInterface
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function getCode(): string
    {
        return 'shipping_service';
    }

    public function listActiveProfiles(): array
    {
        /** @var ShippingService $model */
        $model = $this->objectManager->getInstance(ShippingService::class);
        $items = $model->reset()
            ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingService::schema_fields_SORT_ORDER, 'ASC')
            ->order(ShippingService::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof ShippingService) {
                continue;
            }
            $code = trim((string)$item->getData(ShippingService::schema_fields_SERVICE_CODE));
            if ($code === '') {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => (string)$item->getData(ShippingService::schema_fields_SERVICE_NAME),
                'is_free_shipping' => (bool)$item->getData(ShippingService::schema_fields_IS_FREE_SHIPPING),
                'estimated_days_min' => $item->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MIN),
                'estimated_days_max' => $item->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MAX),
            ];
        }

        return $out;
    }

    public function previewHint(string $profileCode, bool $requiresShipping): ?array
    {
        if (!$requiresShipping) {
            return null;
        }
        $code = trim($profileCode);
        $profiles = $this->listActiveProfiles();
        $hit = null;
        foreach ($profiles as $profile) {
            if ($code !== '' && $profile['code'] === $code) {
                $hit = $profile;
                break;
            }
        }
        if ($hit === null && $code === '' && $profiles !== []) {
            // Unbound: neutral checkout note only.
            return [
                'badge' => null,
                'note' => (string)__('运费以结算页为准'),
            ];
        }
        if ($hit === null) {
            return [
                'badge' => null,
                'note' => (string)__('运费以结算页为准'),
            ];
        }
        if (!empty($hit['is_free_shipping'])) {
            return [
                'badge' => (string)__('包邮'),
                'note' => (string)__('本配送方案为服务级免邮，具体以结算页为准'),
            ];
        }

        return [
            'badge' => null,
            'note' => (string)__('运费以结算页为准'),
        ];
    }
}
