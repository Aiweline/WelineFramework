<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Deal;

/** Result of an external deal discount upsert. */
final class ExternalDealDiscountResult
{
    /**
     * @param list<string> $skus
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly array $skus = [],
        public readonly bool $enabled = false,
    ) {
    }
}
