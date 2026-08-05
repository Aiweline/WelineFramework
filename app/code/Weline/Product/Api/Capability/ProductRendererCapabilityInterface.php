<?php

declare(strict_types=1);

namespace Weline\Product\Api\Capability;

/**
 * Renderer capability SPI — scene metadata only in P2A.
 * Actual HTML/ViewModel dispatch belongs to TASK-P2C-001 ProductSceneRenderer.
 */
interface ProductRendererCapabilityInterface
{
    public const SCENE_LIST = 'list';
    public const SCENE_DETAIL = 'detail';
    public const SCENE_CART = 'cart';
    public const SCENE_CHECKOUT = 'checkout';
    public const SCENE_ORDER_SNAPSHOT = 'order_snapshot';

    /**
     * @return list<string>
     */
    public function supportedScenes(): array;

    public function supportsScene(string $scene): bool;

    /** True when a custom renderer class is declared (P2C will dispatch). */
    public function hasCustomRenderer(): bool;

    /** Optional FQCN for P2C; empty = use default templates. */
    public function getRendererClass(): string;
}
