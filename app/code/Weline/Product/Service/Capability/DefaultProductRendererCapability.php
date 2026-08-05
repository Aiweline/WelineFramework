<?php

declare(strict_types=1);

namespace Weline\Product\Service\Capability;

use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;

/**
 * Default renderer capability: scenes declared, no custom renderer class (P2C fallback).
 */
final class DefaultProductRendererCapability implements ProductRendererCapabilityInterface
{
    /** @param list<string> $scenes */
    public function __construct(
        private readonly array $scenes = [
            self::SCENE_LIST,
            self::SCENE_DETAIL,
            self::SCENE_CART,
            self::SCENE_CHECKOUT,
            self::SCENE_ORDER_SNAPSHOT,
        ],
        private readonly string $rendererClass = '',
    ) {
    }

    public function supportedScenes(): array
    {
        return array_values($this->scenes);
    }

    public function supportsScene(string $scene): bool
    {
        return in_array(trim($scene), $this->supportedScenes(), true);
    }

    public function hasCustomRenderer(): bool
    {
        return trim($this->rendererClass) !== '';
    }

    public function getRendererClass(): string
    {
        return trim($this->rendererClass);
    }
}
