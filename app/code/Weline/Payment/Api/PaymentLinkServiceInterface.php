<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

/**
 * Shareable payment_link + selection_share short tokens.
 *
 * HelpPay owns orchestration; Payment owns persistence and decode.
 */
interface PaymentLinkServiceInterface
{
    public const KIND_HELP_PAY = 'help_pay';
    public const KIND_SELECTION_SHARE = 'selection_share';
    public const KIND_QUICK_PAY = 'quick_pay_self';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONSUMED = 'consumed';

    /**
     * @param array{
     *   kind:string,
     *   payable_type?:string,
     *   payable_id?:string,
     *   owner_customer_id?:int|null,
     *   amount_minor?:int,
     *   currency_code?:string,
     *   shipping_locked?:bool,
     *   shipping_snapshot?:array<string,mixed>|null,
     *   selection_snapshot?:array<string,mixed>|null,
     *   meta?:array<string,mixed>,
     *   ttl_seconds?:int
     * } $input
     * @return array{
     *   payment_link_code:string,
     *   token:string,
     *   kind:string,
     *   path:string,
     *   absolute_url:string,
     *   expires_at:int,
     *   shipping_locked:bool
     * }
     */
    public function create(array $input, string $publicOrigin = ''): array;

    /**
     * Resolve active token for payer / friend open. Never returns shipping_snapshot for storefront.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $token, string $kind): ?array;

    /**
     * Server-side only: shipping for fulfillment / ACL.
     *
     * @return array<string,mixed>|null
     */
    public function resolveShippingForFulfillment(string $token, string $kind): ?array;

    public function revoke(string $token, string $kind, ?int $actorCustomerId = null): bool;
}
