<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Product\StorefrontShippingProfileCatalogProvider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Storefront\StorefrontShippingProfileCatalogProviderInterface;
use Weline\Shipping\Model\ShippingProfile;

final class ShippingServiceProfileCatalogProvider implements StorefrontShippingProfileCatalogProviderInterface
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function getCode(): string
    {
        return 'shipping_profile';
    }

    public function listActiveProfiles(): array
    {
        /** @var ShippingProfile $model */
        $model = $this->objectManager->getInstance(ShippingProfile::class);
        $items = $model->reset()
            ->where(ShippingProfile::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingProfile::schema_fields_IS_GENERAL, 'DESC')
            ->order(ShippingProfile::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof ShippingProfile) {
                continue;
            }
            $code = trim((string)$item->getData(ShippingProfile::schema_fields_PROFILE_CODE));
            if ($code === '') {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => (string)$item->getData(ShippingProfile::schema_fields_PROFILE_NAME),
                'is_free_shipping' => false,
                'is_general' => (bool)$item->getData(ShippingProfile::schema_fields_IS_GENERAL),
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
        if ($code === '' || $code === ShippingProfile::SEED_GENERAL) {
            return [
                'badge' => null,
                'note' => (string)__('运费以结算页为准'),
            ];
        }
        if ($code === ShippingProfile::SEED_HEAVY) {
            return [
                'badge' => (string)__('重货'),
                'note' => (string)__('本商品使用重货配送方案，运费以结算页为准'),
            ];
        }

        return [
            'badge' => null,
            'note' => (string)__('运费以结算页为准'),
        ];
    }
}
