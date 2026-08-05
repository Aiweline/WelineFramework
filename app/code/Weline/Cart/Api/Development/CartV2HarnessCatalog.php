<?php

declare(strict_types=1);

namespace Weline\Cart\Api\Development;

use Weline\Cart\Service\CartV2HarnessCatalog as CartV2HarnessCatalogService;

/**
 * Public development/E2E bridge for cross-process Cart V2 offer fixtures.
 */
final class CartV2HarnessCatalog
{
    /**
     * @param array<string, mixed> $row
     */
    public static function put(string $globalOfferUuid, array $row): void
    {
        CartV2HarnessCatalogService::put($globalOfferUuid, $row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $globalOfferUuid): ?array
    {
        return CartV2HarnessCatalogService::get($globalOfferUuid);
    }

    public static function delete(string $globalOfferUuid): void
    {
        CartV2HarnessCatalogService::delete($globalOfferUuid);
    }
}
