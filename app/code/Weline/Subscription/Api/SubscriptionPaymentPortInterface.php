<?php

declare(strict_types=1);

namespace Weline\Subscription\Api;

/**
 * Sanitized Payment boundary for Subscription billing.
 *
 * Amount, currency, merchant account and Provider references are deliberately
 * absent: Payment resolves them from the frozen Order payable snapshot.
 */
interface SubscriptionPaymentPortInterface
{
    /**
     * @param array{
     *   period_key:string,
     *   subscription_id:string,
     *   order_ref:string,
     *   website_id:int,
     *   store_id:int,
     *   customer_id:string,
     *   environment:string
     * } $command
     * @return array{
     *   status:string,
     *   terminal:bool,
     *   intent_code:?string,
     *   payment_attempt_code:?string,
     *   error_code:?string,
     *   replayed?:bool
     * }
     */
    public function startPeriodPayment(array $command): array;

    /**
     * @param array{
     *   order_ref:string,
     *   customer_id:string,
     *   intent_code?:?string
     * } $command
     * @return array{
     *   status:string,
     *   terminal:bool,
     *   intent_code:?string,
     *   payment_attempt_code:?string,
     *   error_code:?string,
     *   replayed?:bool
     * }
     */
    public function queryPeriodPayment(array $command): array;
}

