<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Shipping\Model\ShippingCommercePolicy;

/**
 * Same-order multi-shipment shipping charge (orthogonal to multi-order charge owner).
 */
final class SplitShipmentShippingService
{
    public function __construct(
        private readonly ShippingCommercePolicyService $commercePolicy,
        private readonly RateCalculationService $rateCalculationService,
    ) {
    }

    /**
     * @param array{website_id?:int,scope_type?:string,scope_id?:int}|null $context
     * @return array{split_shipment_shipping:string,outbound_shipping_minor:int}
     */
    public function buildCheckoutSnapshot(int $outboundShippingMinor, ?array $context = null): array
    {
        $policy = $this->commercePolicy->resolve($context);

        return [
            'split_shipment_shipping' => $policy['split_shipment_shipping'],
            'outbound_shipping_minor' => max(0, $outboundShippingMinor),
        ];
    }

    /**
     * Quote shipping for the Nth outbound shipment on the same order (1-based index).
     *
     * @param list<array<string,mixed>> $remainingLines
     * @param array{split_shipment_shipping?:string,outbound_shipping_minor?:int}|null $snapshot
     * @param \Weline\Shipping\Model\RateTemplate|null $template for each_shipment recalculation
     * @param array{website_id?:int,scope_type?:string,scope_id?:int}|null $context
     * @return array{amount_minor:int,strategy:string,shipment_index:int}
     */
    public function quoteSubsequentShipment(
        int $shipmentIndex,
        array $remainingLines,
        ?array $snapshot = null,
        ?\Weline\Shipping\Model\RateTemplate $template = null,
        int $currencyPrecision = 2,
        ?array $context = null,
    ): array {
        $strategy = strtolower(trim((string)($snapshot['split_shipment_shipping']
            ?? $this->commercePolicy->resolve($context)['split_shipment_shipping'])));
        if ($strategy !== ShippingCommercePolicy::SPLIT_EACH) {
            $strategy = ShippingCommercePolicy::SPLIT_FIRST_ONLY;
        }
        $shipmentIndex = max(1, $shipmentIndex);
        if ($shipmentIndex === 1) {
            return [
                'amount_minor' => max(0, (int)($snapshot['outbound_shipping_minor'] ?? 0)),
                'strategy' => $strategy,
                'shipment_index' => 1,
            ];
        }
        if ($strategy === ShippingCommercePolicy::SPLIT_FIRST_ONLY) {
            return [
                'amount_minor' => 0,
                'strategy' => $strategy,
                'shipment_index' => $shipmentIndex,
            ];
        }
        if ($template === null) {
            throw new \InvalidArgumentException('each_shipment_requires_template');
        }
        $amount = $this->rateCalculationService->calculateTemplateMinor(
            $template,
            $remainingLines,
            $currencyPrecision,
        );

        return [
            'amount_minor' => max(0, $amount),
            'strategy' => $strategy,
            'shipment_index' => $shipmentIndex,
        ];
    }
}
