<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Data\ProductTypeDefinition;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;
use Weline\Product\Api\ProductProviderInterface;
use Weline\Product\Service\Provider\BuiltInProductValidation;

/**
 * Conservative V1 adapter: one Offer and existing capabilities, without inventing type behavior.
 */
final readonly class ProductProviderV1Adapter
{
    public function __construct(private ProductProviderInterface $provider)
    {
    }

    public function getDefinition(): ProductTypeDefinition
    {
        $metadata = $this->provider->getMetadata();
        return new ProductTypeDefinition(
            code: $this->provider->getType(),
            label: $this->provider->getLabel(),
            minimumOffers: 1,
            maximumOffers: 1,
            formSchema: is_array($metadata['form_schema'] ?? null)
                ? $metadata['form_schema']
                : ['sections' => ['basic', 'attributes', 'prices']],
            requiredProductAttributes: array_values(array_filter(
                $this->provider->getRequiredAttributes(),
                static fn (string $code): bool => strtolower($code) !== 'sku',
            )),
            requiredOfferAttributes: ['sku'],
            supportsVariants: false,
            supportsPricing: $this->provider->getPricingCapability() !== null,
            tracksInventory: $this->provider->getInventoryCapability() !== null,
            requiresShipping: (bool)($metadata['requires_shipping'] ?? true),
            supportsDigitalDelivery: (bool)($metadata['digital_delivery'] ?? false),
            supportsComposition: (bool)($metadata['composition'] ?? false),
            mainImageRequired: (bool)($metadata['main_image_required'] ?? false),
        );
    }

    public function validateForPublish(ProductValidationContext $context): ProductValidationResult
    {
        return BuiltInProductValidation::validate($this->getDefinition(), $context);
    }
}
