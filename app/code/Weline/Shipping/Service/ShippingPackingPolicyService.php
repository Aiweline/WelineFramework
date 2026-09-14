<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ShippingPackingPolicy;

final class ShippingPackingPolicyService
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @param array{scope_type?:string,scope_id?:int,website_id?:int}|null $context
     * @return array{max_weight_kg:float,max_volume_cm3:float}
     */
    public function resolveLimits(?array $context = null): array
    {
        $scopeType = (string)($context['scope_type'] ?? ShippingPackingPolicy::SCOPE_WEBSITE);
        $scopeId = (int)($context['scope_id'] ?? $context['website_id'] ?? 0);
        try {
            /** @var ShippingPackingPolicy $model */
            $model = $this->objectManager->getInstance(ShippingPackingPolicy::class, [], false);
            $items = $model->reset()
                ->where(ShippingPackingPolicy::schema_fields_SCOPE_TYPE, $scopeType)
                ->where(ShippingPackingPolicy::schema_fields_SCOPE_ID, $scopeId)
                ->where(ShippingPackingPolicy::schema_fields_IS_ACTIVE, 1)
                ->order(ShippingPackingPolicy::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($items) ? ($items[0] ?? null) : null;
            if ($row instanceof ShippingPackingPolicy && (int)$row->getId() > 0) {
                return [
                    'max_weight_kg' => max(0.001, (float)$row->getData(ShippingPackingPolicy::schema_fields_MAX_WEIGHT_KG)),
                    'max_volume_cm3' => max(1.0, (float)$row->getData(ShippingPackingPolicy::schema_fields_MAX_VOLUME_CM3)),
                ];
            }
        } catch (\Throwable) {
        }

        return ['max_weight_kg' => 30.0, 'max_volume_cm3' => 120000.0];
    }
}
