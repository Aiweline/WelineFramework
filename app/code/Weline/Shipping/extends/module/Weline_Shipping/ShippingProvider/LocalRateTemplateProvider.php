<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider;

use Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityResult;
use Weline\Shipping\Api\Data\Shipping\ShippingQuoteRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingQuoteResult;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingResult;
use Weline\Shipping\Service\Provider\AbstractShippingProvider;
use Weline\Shipping\Service\Provider\LocalTemplatePricingService;
use Weline\Shipping\Service\TrackingUrlResolver;

/**
 * Built-in local rate-template provider (default for manual carriers).
 */
final class LocalRateTemplateProvider extends AbstractShippingProvider
{
    public function __construct(
        private readonly LocalTemplatePricingService $pricing,
    ) {
    }

    public function getCode(): string
    {
        return 'local';
    }

    public function getCapabilities(): array
    {
        return [
            'quote' => true,
            'shipment' => true,
            'cancel' => false,
            'confirm' => false,
            'label' => false,
            'tracking' => true,
            'webhook' => false,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => 'Local rate template',
            'description' => 'In-module fee templates, free-shipping rules, and URL tracking.',
            'icon' => '',
        ];
    }

    public function checkAvailability(ShippingAvailabilityRequest $request): ShippingAvailabilityResult
    {
        return ShippingAvailabilityResult::yes();
    }

    public function quote(ShippingQuoteRequest $request): ShippingQuoteResult
    {
        $priced = $this->pricing->priceMatchedServices(
            $request->matchedServices,
            $request->lines,
            $request->address,
            $request->currency,
            $request->currencyPrecision,
            $request->freeShippingSubtotalMinor,
            $request->scopeContext,
            $request->addons,
        );

        return ShippingQuoteResult::ok(
            $priced['rates'],
            $priced['fx_skipped'],
            [
                'provider' => 'local',
                'unavailable_reasons' => $priced['unavailable_reasons'] ?? [],
            ],
        );
    }

    public function createShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        $tracking = trim($request->trackingNumber);
        if ($tracking === '') {
            return ShippingShipmentResult::failed('tracking_number_required');
        }

        return ShippingShipmentResult::ok($tracking, $tracking, [
            'mode' => 'manual_register',
            'order_number' => $request->orderNumber,
            'service_code' => $request->serviceCode,
        ]);
    }

    public function queryTracking(ShippingTrackingRequest $request): ShippingTrackingResult
    {
        $template = (string)($request->carrierSnapshot['tracking_url_template'] ?? '');
        /** @var TrackingUrlResolver $resolver */
        $resolver = \Weline\Framework\Manager\ObjectManager::getInstance(TrackingUrlResolver::class);
        $url = $resolver->resolve($request->trackingNumber, '', $template);

        return ShippingTrackingResult::ok(
            'registered',
            '',
            $url,
            [
                [
                    'time' => date('Y-m-d H:i:s'),
                    'status' => 'registered',
                    'description' => (string)__('运单已登记，等待承运商更新轨迹'),
                ],
            ],
            [
                'mode' => 'url_template',
                'tracking_number' => $request->trackingNumber,
            ],
        );
    }

    public function testConnection(ShippingTestConnectionRequest $request): ShippingTestConnectionResult
    {
        return ShippingTestConnectionResult::ok('local_ready');
    }
}
