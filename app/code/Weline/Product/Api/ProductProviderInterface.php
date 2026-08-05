<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;

/**
 * Product type Provider SPI（小接口；不暴露内部 Service/Model）.
 *
 * code 与 type 均全局唯一；禁用 Provider 时默认 type 仍可解析。
 */
interface ProductProviderInterface
{
    public const TYPE_SIMPLE = 'simple';
    public const CODE_DEFAULT = 'default';

    /** Unique provider code (e.g. default, subscription). */
    public function getCode(): string;

    /** Unique product type key (e.g. simple, configurable). */
    public function getType(): string;

    public function getLabel(): string;

    public function isEnabled(): bool;

    public function getSortOrder(): int;

    /**
     * Required attribute codes for publish/sellability (fixed per provider).
     *
     * @return list<string>
     */
    public function getRequiredAttributes(): array;

    /**
     * Capability discovery map: capability FQCN => true when attached.
     *
     * @return array<class-string, bool>
     */
    public function getCapabilityMap(): array;

    public function getPricingCapability(): ?ProductPricingCapabilityInterface;

    public function getInventoryCapability(): ?ProductInventoryCapabilityInterface;

    public function getRendererCapability(): ?ProductRendererCapabilityInterface;

    /**
     * Registry / admin metadata only (no render side effects).
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}
