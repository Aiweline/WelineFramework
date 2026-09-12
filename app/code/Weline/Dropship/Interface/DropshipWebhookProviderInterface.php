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
     * Must normalize vendor payload into shell-standard fulfillment keys (not vendor-specific keys).
     *
     * @param array<string, mixed> $headers
     * @return array{
     *   ok:bool,
     *   event?:string,
     *   external_id?:string,
     *   fulfillment?:array{
     *     external_order_id?:string,
     *     order_uuid?:string,
     *     tracking_number?:string,
     *     carrier?:string,
     *     status?:string
     *   },
     *   payload?:array<string,mixed>,
     *   message?:string
     * }
     */
    public function parseWebhook(array $headers, string $body): array;
}
