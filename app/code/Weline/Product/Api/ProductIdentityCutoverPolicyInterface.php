<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/**
 * Read-only gate for the legacy 1:1 SKU registry during the V2 identity cutover.
 */
interface ProductIdentityCutoverPolicyInterface
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_DUAL_READ = 'dual_read';
    public const MODE_V2_AUTHORITATIVE = 'v2_authoritative';

    public function mode(): string;

    public function legacyWritesAllowed(): bool;
}
