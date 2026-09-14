<?php

declare(strict_types=1);

namespace Weline\Shipping\Service\Provider;

use Throwable;
use Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityResult;
use Weline\Shipping\Api\Data\Shipping\ShippingCallbackRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingCallbackResult;
use Weline\Shipping\Api\Data\Shipping\ShippingLabelRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingLabelResult;
use Weline\Shipping\Api\Data\Shipping\ShippingProviderError;
use Weline\Shipping\Api\Data\Shipping\ShippingQuoteRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingQuoteResult;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingResult;
use Weline\Shipping\Interface\ShippingProviderInterface;

/**
 * Default unsupported implementations. Concrete providers extend and override.
 */
abstract class AbstractShippingProvider implements ShippingProviderInterface
{
    abstract public function getCode(): string;

    public function getProviderCode(): string
    {
        return $this->getCode();
    }

    public function getProviderApiVersion(): string
    {
        return '1.0';
    }

    public function getCapabilities(): array
    {
        return [
            'quote' => false,
            'shipment' => false,
            'cancel' => false,
            'confirm' => false,
            'label' => false,
            'tracking' => false,
            'webhook' => false,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => $this->getCode(),
            'description' => '',
            'icon' => '',
        ];
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function getDynamicFormSchema(ShippingAvailabilityRequest $request): array
    {
        return [];
    }

    public function cspDirectives(): array
    {
        return [];
    }

    public function checkAvailability(ShippingAvailabilityRequest $request): ShippingAvailabilityResult
    {
        return ShippingAvailabilityResult::yes();
    }

    public function defaultCoverageRegions(): array
    {
        return [];
    }

    public function quote(ShippingQuoteRequest $request): ShippingQuoteResult
    {
        return ShippingQuoteResult::unsupported('quote');
    }

    public function createShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        return ShippingShipmentResult::unsupported('createShipment');
    }

    public function cancelShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        return ShippingShipmentResult::unsupported('cancelShipment');
    }

    public function confirmShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        return ShippingShipmentResult::unsupported('confirmShipment');
    }

    public function getLabel(ShippingLabelRequest $request): ShippingLabelResult
    {
        return ShippingLabelResult::unsupported('getLabel');
    }

    public function queryTracking(ShippingTrackingRequest $request): ShippingTrackingResult
    {
        return ShippingTrackingResult::unsupported('queryTracking');
    }

    public function verifyCallback(ShippingCallbackRequest $request): ShippingCallbackResult
    {
        return ShippingCallbackResult::unsupported('verifyCallback');
    }

    public function parseCallback(ShippingCallbackRequest $request): ShippingCallbackResult
    {
        return ShippingCallbackResult::unsupported('parseCallback');
    }

    public function testConnection(ShippingTestConnectionRequest $request): ShippingTestConnectionResult
    {
        return ShippingTestConnectionResult::unsupported('testConnection');
    }

    public function normalizeError(Throwable|array $error): ShippingProviderError
    {
        if ($error instanceof Throwable) {
            return new ShippingProviderError(
                'provider_error',
                $error->getMessage() !== '' ? $error->getMessage() : $error::class,
                true,
                ['class' => $error::class],
            );
        }

        return new ShippingProviderError(
            (string)($error['code'] ?? 'provider_error'),
            (string)($error['message'] ?? 'unknown'),
            (bool)($error['retryable'] ?? false),
            is_array($error['context'] ?? null) ? $error['context'] : [],
        );
    }
}
