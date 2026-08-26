<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Immutable Provider V2 definition consumed by admin, publish validation and projections.
 */
final readonly class ProductTypeDefinition
{
    /**
     * @param array<string, mixed> $formSchema
     * @param list<string> $requiredProductAttributes
     * @param list<string> $requiredOfferAttributes
     */
    public function __construct(
        public string $code,
        public string $label,
        public int $minimumOffers,
        public ?int $maximumOffers,
        public array $formSchema,
        public array $requiredProductAttributes,
        public array $requiredOfferAttributes,
        public bool $supportsVariants,
        public bool $supportsPricing,
        public bool $tracksInventory,
        public bool $requiresShipping,
        public bool $supportsDigitalDelivery,
        public bool $supportsComposition,
        public bool $mainImageRequired = false,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code)) {
            throw new \InvalidArgumentException('product_type_code_invalid');
        }
        if (trim($label) === '') {
            throw new \InvalidArgumentException('product_type_label_empty');
        }
        if ($minimumOffers < 1 || ($maximumOffers !== null && $maximumOffers < $minimumOffers)) {
            throw new \InvalidArgumentException('product_type_offer_cardinality_invalid');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'offer_cardinality' => [
                'minimum' => $this->minimumOffers,
                'maximum' => $this->maximumOffers,
            ],
            'form_schema' => $this->formSchema,
            'required_product_attributes' => $this->requiredProductAttributes,
            'required_offer_attributes' => $this->requiredOfferAttributes,
            'capabilities' => [
                'variants' => $this->supportsVariants,
                'pricing' => $this->supportsPricing,
                'inventory' => $this->tracksInventory,
                'shipping' => $this->requiresShipping,
                'digital_delivery' => $this->supportsDigitalDelivery,
                'composition' => $this->supportsComposition,
            ],
            'main_image_required' => $this->mainImageRequired,
        ];
    }
}
