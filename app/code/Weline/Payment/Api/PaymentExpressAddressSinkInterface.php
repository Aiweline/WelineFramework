<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

/**
 * Optional sink: apply express payer shipping into storefront address books.
 *
 * Register via module provides key prefix {@see CAPABILITY_PREFIX}.
 * Payment shell must not hard-depend on Checkout/Shipping models.
 */
interface PaymentExpressAddressSinkInterface
{
    public const CAPABILITY_PREFIX = 'payment.express_address_sink.';

    /**
     * @param array{
     *   method_code?:string,
     *   transaction_no?:string,
     *   order_id?:string,
     *   profile:array<string,mixed>
     * } $payload profile uses Checkout form-shaped keys when possible
     *   (name/phone/email/country_code/province/city/address1/postal_code/…).
     * @return array{applied:bool,message?:string}
     */
    public function applyExpressAddress(array $payload): array;
}
