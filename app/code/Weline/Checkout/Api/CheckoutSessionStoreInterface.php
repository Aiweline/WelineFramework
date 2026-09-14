<?php

declare(strict_types=1);

namespace Weline\Checkout\Api;

/**
 * Checkout V2 freeze session persistence（跨 Worker / HTTP）。
 */
interface CheckoutSessionStoreInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function put(string $quoteToken, array $payload, ?string $expiresAt = null): void;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $quoteToken): ?array;

    /**
     * Lock the session row when the active database supports `FOR UPDATE`.
     *
     * The caller must own the surrounding DML transaction.
     *
     * @return array<string, mixed>|null
     */
    public function getForUpdate(string $quoteToken): ?array;

    public function delete(string $quoteToken): bool;

    /**
     * Latest unsubmitted quoted session for this cart identity, or null.
     */
    public function findQuotedTokenByFingerprint(string $fingerprint): ?string;

    /**
     * Overwrite the single current error snapshot. Does not insert a new session.
     *
     * @param array<string, mixed> $snapshot
     */
    public function setErrorSnapshot(string $quoteToken, string $code, string $message, array $snapshot): void;

    public function clearErrorSnapshot(string $quoteToken): void;

    /**
     * @return array{code:string,message:string,snapshot:array<string,mixed>,at:?string}|null
     */
    public function getErrorSnapshot(string $quoteToken): ?array;

    /**
     * Latest submitted session quote token that includes this order_uuid, or null.
     * Used by Order to build capability-gated continue-pay URLs (no Marketing coupling).
     */
    public function findSubmittedTokenByOrderUuid(string $orderUuid): ?string;
}
