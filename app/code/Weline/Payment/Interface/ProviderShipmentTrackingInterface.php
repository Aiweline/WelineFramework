<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

/**
 * Optional: push local shipment tracking to the payment gateway after ship.
 * Shell schedules via method_code; Provider owns gateway-specific tracking APIs only.
 */
interface ProviderShipmentTrackingInterface
{
    /**
     * @param array{
     *   order_uuid?:string,
     *   order_id?:int,
     *   tracking_number?:string,
     *   carrier?:string,
     *   tracking_status?:string,
     *   shipment?:mixed,
     *   order?:mixed
     * } $context
     * @return array{ok:bool,message:string,capture_id?:string,tracking_number?:string,payload?:array<string,mixed>}
     */
    public function syncShipmentTracking(array $context): array;
}
