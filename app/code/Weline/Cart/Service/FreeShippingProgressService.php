<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

/**
 * Mini-cart summaries do not establish destination or shipping-service eligibility.
 * A subtotal alone must not be presented as a free-shipping entitlement.
 * This does not change real shipping rules or checkout quote results.
 */
final class FreeShippingProgressService
{
    /**
     * @param array<string, mixed> $summary Cart summary without matched shipping evidence
     * @return array{enabled: false}
     */
    public function build(array $summary): array
    {
        return ['enabled' => false];
    }
}
