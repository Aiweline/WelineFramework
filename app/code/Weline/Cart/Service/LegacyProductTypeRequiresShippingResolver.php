<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\ProductTypeRequiresShippingResolverInterface;

/**
 * Built-in fallback when Weline_Product preference is not registered.
 * Only the historic virtual/digital/downloadable codes; unknown → null.
 */
final class LegacyProductTypeRequiresShippingResolver implements ProductTypeRequiresShippingResolverInterface
{
    private const DIGITAL_TYPES = ['virtual', 'digital', 'downloadable'];

    public function requiresShippingForProductType(string $productType): ?bool
    {
        $type = strtolower(trim($productType));
        if ($type === '') {
            return null;
        }
        if (in_array($type, self::DIGITAL_TYPES, true)) {
            return false;
        }

        return null;
    }
}
