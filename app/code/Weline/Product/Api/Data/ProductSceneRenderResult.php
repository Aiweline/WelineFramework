<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/** Structured product scene HTML result (REQ-008 / TEST-P2C-RENDER-02). */
final class ProductSceneRenderResult
{
    public function __construct(
        public readonly string $html,
        public readonly bool $handledEmpty = false,
        public readonly bool $usedFallback = false,
        public readonly string $cacheKey = '',
        public readonly string $providerCode = '',
        public readonly ?string $errorCode = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'handled_empty' => $this->handledEmpty,
            'used_fallback' => $this->usedFallback,
            'cache_key' => $this->cacheKey,
            'provider_code' => $this->providerCode,
            'error_code' => $this->errorCode,
        ];
    }
}
