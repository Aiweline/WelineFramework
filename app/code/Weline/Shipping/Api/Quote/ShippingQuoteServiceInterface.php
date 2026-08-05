<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Quote;

interface ShippingQuoteServiceInterface
{
    /**
     * Deterministic version of the active service/template/rule configuration.
     */
    public function activeConfigVersion(): string;

    /**
     * @return list<array<string, mixed>> option summaries (service_code, label, amount_minor?, …)
     */
    public function listOptions(ShippingQuoteRequest $request): array;

    public function quote(ShippingQuoteRequest $request, string $serviceCode): ShippingQuote;
}
