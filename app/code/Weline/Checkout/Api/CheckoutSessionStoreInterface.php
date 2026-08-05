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
}
