<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Api;

/**
 * Stable cross-module view of a fail-closed CustomerAsset conflict.
 *
 * Implementations are throwable runtime exceptions, but consumers depend on
 * this API contract instead of the module's internal Service exception class.
 */
interface CustomerAssetConflictInterface
{
    public function getErrorCode(): string;

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array;
}
