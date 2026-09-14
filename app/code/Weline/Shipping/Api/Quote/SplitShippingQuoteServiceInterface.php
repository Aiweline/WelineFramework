<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Quote;

interface SplitShippingQuoteServiceInterface
{
    /**
     * @return list<array<string,mixed>> family options (family_code, label, total_amount_minor, packages preview)
     */
    public function listSplitOptions(ShippingQuoteRequest $request): array;

    public function quoteSplit(ShippingQuoteRequest $request, string $familyCode): SplitShippingQuote;
}
