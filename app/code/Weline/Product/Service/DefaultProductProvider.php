<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\ProductProviderInterface;
use Weline\Product\Service\Capability\DefaultProductInventoryCapability;
use Weline\Product\Service\Capability\DefaultProductPricingCapability;
use Weline\Product\Service\Capability\DefaultProductRendererCapability;

/**
 * Built-in simple product Provider (type=simple, code=default).
 */
final class DefaultProductProvider implements ProductProviderInterface
{
    /** @var list<string> */
    public const REQUIRED_ATTRIBUTES = ['name', 'sku'];

    private readonly ProductPricingCapabilityInterface $pricing;
    private readonly ProductInventoryCapabilityInterface $inventory;
    private readonly ProductRendererCapabilityInterface $renderer;

    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $sortOrder = 0,
        ?ProductPricingCapabilityInterface $pricing = null,
        ?ProductInventoryCapabilityInterface $inventory = null,
        ?ProductRendererCapabilityInterface $renderer = null,
    ) {
        $this->pricing = $pricing ?? new DefaultProductPricingCapability();
        $this->inventory = $inventory ?? new DefaultProductInventoryCapability();
        $this->renderer = $renderer ?? new DefaultProductRendererCapability();
    }

    public function getCode(): string
    {
        return self::CODE_DEFAULT;
    }

    public function getType(): string
    {
        return self::TYPE_SIMPLE;
    }

    public function getLabel(): string
    {
        return (string)__('简单商品');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getRequiredAttributes(): array
    {
        return self::REQUIRED_ATTRIBUTES;
    }

    public function getCapabilityMap(): array
    {
        return [
            ProductPricingCapabilityInterface::class => true,
            ProductInventoryCapabilityInterface::class => true,
            ProductRendererCapabilityInterface::class => true,
        ];
    }

    public function getPricingCapability(): ?ProductPricingCapabilityInterface
    {
        return $this->pricing;
    }

    public function getInventoryCapability(): ?ProductInventoryCapabilityInterface
    {
        return $this->inventory;
    }

    public function getRendererCapability(): ?ProductRendererCapabilityInterface
    {
        return $this->renderer;
    }

    public function getMetadata(): array
    {
        return [
            'code' => $this->getCode(),
            'type' => $this->getType(),
            'label' => $this->getLabel(),
            'enabled' => $this->isEnabled(),
            'sort_order' => $this->getSortOrder(),
            'required_attributes' => $this->getRequiredAttributes(),
            'capabilities' => array_keys(array_filter($this->getCapabilityMap())),
            'pricing' => [
                'currencies' => $this->pricing->supportedCurrencies(),
                'allows_cleared' => $this->pricing->allowsClearedPrice(),
            ],
            'inventory' => [
                'strategy' => $this->inventory->strategy(),
                'supports_reservation' => $this->inventory->supportsReservation(),
            ],
            'renderer' => [
                'scenes' => $this->renderer->supportedScenes(),
                'has_custom' => $this->renderer->hasCustomRenderer(),
                'renderer_class' => $this->renderer->getRendererClass(),
            ],
        ];
    }
}
