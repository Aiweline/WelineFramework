<?php

declare(strict_types=1);

namespace Weline\Product\Service\Provider;

use Weline\Product\Api\Data\ProductTypeDefinition;

final class ConfigurableProductProvider extends AbstractBuiltInProductProvider
{
    public function getCode(): string { return 'builtin_configurable'; }
    public function getType(): string { return 'configurable'; }
    public function getLabel(): string { return (string)__('多规格商品'); }
    public function getSortOrder(): int { return 10; }

    public function getDefinition(): ProductTypeDefinition
    {
        return new ProductTypeDefinition(
            code: $this->getType(),
            label: $this->getLabel(),
            minimumOffers: 1,
            maximumOffers: null,
            formSchema: [
                'sections' => ['basic', 'attributes', 'variant_axes', 'variant_matrix', 'prices', 'inventory', 'shipping'],
                'fields' => [],
                'variant_axes_source' => 'eav',
                'matrix' => ['bulk_edit' => ['sku', 'price', 'inventory']],
            ],
            requiredProductAttributes: ['name'],
            requiredOfferAttributes: ['sku', 'price'],
            supportsVariants: true,
            supportsPricing: true,
            tracksInventory: true,
            requiresShipping: true,
            supportsDigitalDelivery: false,
            supportsComposition: false,
        );
    }
}
