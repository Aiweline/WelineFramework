<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

interface DropshipWebhookProviderInterface extends DropshipProviderInterface
{
    /**
     * Pure verify — signature/secret/time-window only; no side effects / no remote calls.
     *
     * @param array<string, mixed> $headers
     * @return array{ok:bool,message?:string}
     */
    public function verifyWebhook(array $headers, string $body): array;

    /**
     * Pure parse — no side effects / no remote calls.
     * Normalize vendor payload into shell-standard blocks (never leak vendor-private keys to the shell).
     *
     * Shell topic values: order|product|stock|logistics|makeup|private_order|dispute|unknown
     *
     * Capability keys (getCapabilities): webhook (master), webhook_order, webhook_product,
     * webhook_stock, webhook_logistics, webhook_makeup, webhook_private_order, webhook_dispute.
     * Missing fine-grained keys default to true when webhook=true; set false to opt out.
     *
     * @param array<string, mixed> $headers
     * @return array{
     *   ok:bool,
     *   event?:string,
     *   topic?:string,
     *   external_id?:string,
     *   fulfillment?:array{
     *     external_order_id?:string,
     *     order_uuid?:string,
     *     tracking_number?:string,
     *     carrier?:string,
     *     status?:string
     *   },
     *   catalog?:array{
     *     external_spu?:string,
     *     external_sku?:string,
     *     qty?:int|null,
     *     shelf_status?:string,
     *     origin_price_minor?:int|null,
     *     origin_currency?:string,
     *     title?:string
     *   },
     *   makeup?:array{
     *     external_id?:string,
     *     related_external_order_id?:string,
     *     status?:string,
     *     amount_minor?:int|null,
     *     currency?:string
     *   },
     *   payload?:array<string,mixed>,
     *   message?:string
     * }
     */
    public function parseWebhook(array $headers, string $body): array;
}
