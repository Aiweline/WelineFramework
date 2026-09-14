<?php

declare(strict_types=1);

namespace Weline\Shipping\Interface;

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

/**
 * Complete shipping vendor capability surface. Shell never special-cases vendors.
 */
interface ShippingProviderInterface
{
    public function getCode(): string;

    public function getProviderCode(): string;

    public function getProviderApiVersion(): string;

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array;

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array;

    /**
     * @return array<string, mixed>
     */
    public function getDynamicFormSchema(ShippingAvailabilityRequest $request): array;

    /**
     * @return array<string, list<string>>
     */
    public function cspDirectives(): array;

    public function checkAvailability(ShippingAvailabilityRequest $request): ShippingAvailabilityResult;

    /**
     * Optional default coverage seeds (country / region rows). Empty = no seed from provider.
     *
     * @return list<array<string, mixed>>
     */
    public function defaultCoverageRegions(): array;

    public function quote(ShippingQuoteRequest $request): ShippingQuoteResult;

    public function createShipment(ShippingShipmentRequest $request): ShippingShipmentResult;

    public function cancelShipment(ShippingShipmentRequest $request): ShippingShipmentResult;

    public function confirmShipment(ShippingShipmentRequest $request): ShippingShipmentResult;

    public function getLabel(ShippingLabelRequest $request): ShippingLabelResult;

    public function queryTracking(ShippingTrackingRequest $request): ShippingTrackingResult;

    /** Pure: no side effects. */
    public function verifyCallback(ShippingCallbackRequest $request): ShippingCallbackResult;

    /** Pure: no side effects / no state transition. */
    public function parseCallback(ShippingCallbackRequest $request): ShippingCallbackResult;

    public function testConnection(ShippingTestConnectionRequest $request): ShippingTestConnectionResult;

    /**
     * @param Throwable|array<string, mixed> $error
     */
    public function normalizeError(Throwable|array $error): ShippingProviderError;
}
