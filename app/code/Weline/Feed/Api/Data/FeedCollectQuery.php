<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedCollectQuery
{
    public function __construct(
        public int $websiteId,
        public int $storeId,
        public string $locale,
        public string $currency,
        public string $sellingMode,
        public string $sourceCode,
    ) {
    }

    public static function fromDefinition(FeedDefinition $definition): self
    {
        return new self(
            websiteId: $definition->websiteId,
            storeId: $definition->storeId(),
            locale: $definition->locale,
            currency: $definition->currency,
            sellingMode: $definition->sellingMode(),
            sourceCode: $definition->sourceCode,
        );
    }
}
