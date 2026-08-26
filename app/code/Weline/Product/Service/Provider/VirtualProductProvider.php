<?php

declare(strict_types=1);

namespace Weline\Product\Service\Provider;

use Weline\Product\Api\Data\ProductTypeDefinition;

final class VirtualProductProvider extends AbstractBuiltInProductProvider
{
    public function getCode(): string { return 'builtin_virtual'; }
    public function getType(): string { return 'virtual'; }
    public function getLabel(): string { return (string)__('虚拟/服务商品'); }
    public function getSortOrder(): int { return 20; }

    public function getDefinition(): ProductTypeDefinition
    {
        return new ProductTypeDefinition(
            code: $this->getType(),
            label: $this->getLabel(),
            minimumOffers: 1,
            maximumOffers: null,
            formSchema: [
                'sections' => ['basic', 'attributes', 'service_plans', 'prices'],
                'fields' => [
                    [
                        'code' => 'service_plans',
                        'label' => (string)__('服务方案'),
                        'type' => 'json',
                        'default' => [],
                        'help' => (string)__('可为服务商品声明一个或多个方案；草稿阶段允许暂时为空。'),
                    ],
                ],
                'inventory_default' => 'unlimited',
                'shipping' => 'none',
            ],
            requiredProductAttributes: ['name'],
            requiredOfferAttributes: ['sku', 'price'],
            supportsVariants: false,
            supportsPricing: true,
            tracksInventory: false,
            requiresShipping: false,
            supportsDigitalDelivery: false,
            supportsComposition: false,
        );
    }
}
