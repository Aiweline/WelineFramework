<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

use Weline\Cart\Service\CartSelectionHash as CartSelectionHashService;

/**
 * Public Cart selection canonicalization and server-owned hash contract.
 */
final class CartSelectionHash
{
    public const ERROR_INVALID_SELECTION = CartSelectionHashService::ERROR_INVALID_SELECTION;
    public const ERROR_HASH_MISMATCH = CartSelectionHashService::ERROR_HASH_MISMATCH;

    /**
     * @param array<string, scalar|null> $selection
     */
    public static function compute(
        string $globalOfferUuid,
        string $selectionSchemaVersion,
        array $selection,
    ): string {
        return CartSelectionHashService::compute(
            $globalOfferUuid,
            $selectionSchemaVersion,
            $selection,
        );
    }

    /**
     * @param array<string, scalar|null> $selection
     * @return array<string, scalar|null>
     */
    public static function normalizeSelection(array $selection): array
    {
        return CartSelectionHashService::normalizeSelection($selection);
    }

    /**
     * @param array<string, scalar|null> $selection
     */
    public static function canonicalJson(array $selection): string
    {
        return CartSelectionHashService::canonicalJson($selection);
    }

    public static function assertClientHashOrIgnore(
        ?string $clientHash,
        string $serverHash,
    ): void {
        CartSelectionHashService::assertClientHashOrIgnore($clientHash, $serverHash);
    }
}
