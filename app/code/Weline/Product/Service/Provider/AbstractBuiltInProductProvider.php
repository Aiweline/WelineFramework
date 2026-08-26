<?php

declare(strict_types=1);

namespace Weline\Product\Service\Provider;

use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\Data\ProductTypeDefinition;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;
use Weline\Product\Api\ProductProviderV2Interface;
use Weline\Product\Service\Capability\DefaultProductInventoryCapability;
use Weline\Product\Service\Capability\DefaultProductPricingCapability;
use Weline\Product\Service\Capability\DefaultProductRendererCapability;

abstract class AbstractBuiltInProductProvider implements ProductProviderV2Interface
{
    private readonly ProductPricingCapabilityInterface $pricing;
    private readonly ProductInventoryCapabilityInterface $inventory;
    private readonly ProductRendererCapabilityInterface $renderer;

    public function __construct(
        private readonly bool $enabled = true,
        ?ProductPricingCapabilityInterface $pricing = null,
        ?ProductInventoryCapabilityInterface $inventory = null,
        ?ProductRendererCapabilityInterface $renderer = null,
    ) {
        $this->pricing = $pricing ?? new DefaultProductPricingCapability();
        $this->inventory = $inventory ?? new DefaultProductInventoryCapability();
        $this->renderer = $renderer ?? new DefaultProductRendererCapability();
    }

    final public function isEnabled(): bool
    {
        return $this->enabled;
    }

    final public function getRequiredAttributes(): array
    {
        return array_values(array_unique(array_merge(
            $this->getDefinition()->requiredProductAttributes,
            $this->getDefinition()->requiredOfferAttributes,
        )));
    }

    final public function getCapabilityMap(): array
    {
        return [
            ProductPricingCapabilityInterface::class => $this->getDefinition()->supportsPricing,
            ProductInventoryCapabilityInterface::class => true,
            ProductRendererCapabilityInterface::class => true,
        ];
    }

    final public function getPricingCapability(): ?ProductPricingCapabilityInterface
    {
        return $this->getDefinition()->supportsPricing ? $this->pricing : null;
    }

    final public function getInventoryCapability(): ?ProductInventoryCapabilityInterface
    {
        return $this->inventory;
    }

    final public function getRendererCapability(): ?ProductRendererCapabilityInterface
    {
        return $this->renderer;
    }

    final public function validateForPublish(ProductValidationContext $context): ProductValidationResult
    {
        return BuiltInProductValidation::validate($this->getDefinition(), $context)
            ->merge($this->validateProviderSpecific($context));
    }

    protected function validateProviderSpecific(ProductValidationContext $context): ProductValidationResult
    {
        return new ProductValidationResult();
    }

    final public function getMetadata(): array
    {
        return [
            'code' => $this->getCode(),
            'type' => $this->getType(),
            'label' => $this->getLabel(),
            'enabled' => $this->isEnabled(),
            'sort_order' => $this->getSortOrder(),
            'required_attributes' => $this->getRequiredAttributes(),
            'capabilities' => array_keys(array_filter($this->getCapabilityMap())),
            'definition' => $this->getDefinition()->toArray(),
        ];
    }

    abstract public function getCode(): string;

    abstract public function getType(): string;

    abstract public function getLabel(): string;

    abstract public function getSortOrder(): int;

    abstract public function getDefinition(): ProductTypeDefinition;
}
