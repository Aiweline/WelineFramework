<?php

declare(strict_types=1);

namespace Weline\Product\Service\Provider;

use Weline\Product\Api\Data\ProductTypeDefinition;

final class BundleProductProvider extends AbstractBuiltInProductProvider
{
    public function getCode(): string { return 'builtin_bundle'; }
    public function getType(): string { return 'bundle'; }
    public function getLabel(): string { return (string)__('组合商品'); }
    public function getSortOrder(): int { return 40; }

    public function getDefinition(): ProductTypeDefinition
    {
        return new ProductTypeDefinition(
            code: $this->getType(),
            label: $this->getLabel(),
            minimumOffers: 1,
            maximumOffers: 1,
            formSchema: [
                'sections' => ['basic', 'attributes', 'component_groups', 'price_mode'],
                'fields' => [
                    [
                        'code' => 'component_groups',
                        'label' => (string)__('组件组'),
                        'type' => 'json',
                        'required' => true,
                        'default' => [],
                        'help' => (string)__('每个组件组至少引用一个已发布 Offer；发布时会检查循环引用。'),
                    ],
                    [
                        'code' => 'price_mode',
                        'label' => (string)__('价格模式'),
                        'type' => 'select',
                        'required' => true,
                        'default' => 'fixed',
                        'options' => [
                            ['value' => 'fixed', 'label' => (string)__('固定价格')],
                            ['value' => 'dynamic', 'label' => (string)__('按组件动态汇总')],
                        ],
                    ],
                ],
                'price_modes' => ['fixed', 'dynamic'],
                'inventory' => 'required_components',
                'shipping' => 'selected_components',
            ],
            requiredProductAttributes: ['name'],
            requiredOfferAttributes: ['sku'],
            supportsVariants: false,
            supportsPricing: true,
            tracksInventory: true,
            requiresShipping: true,
            supportsDigitalDelivery: false,
            supportsComposition: true,
        );
    }
}
