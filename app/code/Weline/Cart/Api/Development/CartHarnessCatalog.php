<?php

declare(strict_types=1);

namespace Weline\Cart\Api\Development;

use Weline\Cart\Service\CartHarnessCatalog as CartHarnessCatalogService;

/**
 * Public development/E2E bridge for cross-process Cart offer fixtures.
 */
final class CartHarnessCatalog
{
    /**
     * @param array<string, mixed> $row
     */
    public static function put(string $globalOfferUuid, array $row): void
    {
        CartHarnessCatalogService::put($globalOfferUuid, $row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $globalOfferUuid): ?array
    {
        return CartHarnessCatalogService::get($globalOfferUuid);
    }

    public static function delete(string $globalOfferUuid): void
    {
        CartHarnessCatalogService::delete($globalOfferUuid);
    }
}
