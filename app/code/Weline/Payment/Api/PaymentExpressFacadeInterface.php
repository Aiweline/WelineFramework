<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

/**
 * Shell entry for 快捷智能支付 (express checkout). Shared by checkout slot and PDP.
 */
interface PaymentExpressFacadeInterface
{
    public const META_AWAITING_CONFIRM = 'express_awaiting_confirm';

    /**
     * Active payment methods that declare express_checkout capability.
     *
     * @param array<string, mixed> $context
     * @return list<array{
     *   method_code:string,
     *   title:string,
     *   icon_url:string,
     *   express_modes:list<string>,
     *   next_action_hint:string
     * }>
     */
    public function listExpressMethods(array $context = []): array;

    /**
     * Whether method_code supports express under current scope.
     *
     * @param array<string, mixed> $context
     */
    public function supportsExpress(string $methodCode, array $context = []): bool;

    /**
     * Merge express flags into payment create context (idempotent).
     *
     * @param array<string, mixed> $paymentContext
     * @return array<string, mixed>
     */
    public function withExpressContext(array $paymentContext, string $methodCode = ''): array;

    /**
     * Evaluate provider-imported profile completeness for express-review.
     *
     * @param array<string, mixed> $profile
     * @return array{
     *   complete:bool,
     *   missing_fields:list<string>,
     *   requires_shipping:bool
     * }
     */
    public function evaluateExpressProfile(array $profile, bool $requiresShipping = true): array;

    /**
     * After provider resume/capture: import express_profile via registered sinks.
     *
     * @param array<string, mixed> $resultPayload PaymentResult data or response blob
     * @param array<string, mixed> $transactionContext request_data + ids
     * @return array{applied:bool,sinks:int}
     */
    public function applyExpressProfileFromPaymentResult(array $resultPayload, array $transactionContext = []): array;

    /**
     * Mark unpaid Express transaction as failed terminal state (PaymentTransaction + metadata).
     * Already paid → skipped; Checkout must not write Payment Model directly.
     *
     * @return array{
     *   transaction_no: string,
     *   abandoned: bool,
     *   skipped?: string,
     *   status?: string,
     *   order_uuid?: string,
     *   error?: string
     * }
     */
    public function abandonExpressPayment(string $transactionNo, string $reason = 'abandoned'): array;
}
