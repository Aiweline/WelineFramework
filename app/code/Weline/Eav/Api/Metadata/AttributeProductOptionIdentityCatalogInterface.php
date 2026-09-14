<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

use Weline\Eav\Api\Entity\EntityDefinitionInterface;

/** Optional complete shared/product identity lookup, including unplaced legacy options. */
interface AttributeProductOptionIdentityCatalogInterface
{
    /**
     * Shared code precedes product-private code; numeric tokens are option IDs.
     * A successful null is an authoritative miss in these scopes. Read failures
     * must throw so consumers can retain their legacy fallback and retry later.
     */
    public function productOptionIdentity(
        EntityDefinitionInterface $entity,
        int $productId,
        string $token,
    ): ?AttributeOptionMetadata;
}
