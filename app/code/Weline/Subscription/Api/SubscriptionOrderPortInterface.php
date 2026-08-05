<?php

declare(strict_types=1);

namespace Weline\Subscription\Api;

/**
 * Thin Order creation port for Subscription billing（P4B-002）.
 *
 * Keeps Order Facade internals out of Subscription domain services.
 * Each successful call MUST create a new Order identity (never reuse prior period Order).
 */
interface SubscriptionOrderPortInterface
{
    /**
     * @param array{
     *   period_key:string,
     *   subscription_id:string,
     *   website_id:int,
     *   store_id:int,
     *   customer_id:string,
     *   plan_code:string,
     *   amount_minor:int,
     *   currency?:string
     * } $command
     * @return array{ok:bool,order_ref:string,replayed?:bool}
     */
    public function createPeriodOrder(array $command): array;
}
