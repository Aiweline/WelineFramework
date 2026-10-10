<?php

declare(strict_types=1);

namespace Weline\Checkout\Api;

/**
 * Optional commerce-type policy for storefront money summary (SSR + paint DTO).
 * Checkout builds a retail baseline; providers (e.g. B2B ToB) adjust without Checkout
 * hardcoding cart_type business branches.
 */
interface StorefrontMoneySummaryPolicyInterface
{
    /**
     * Adjust SSR first-paint money / payment chrome.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function adjustSsrPayload(array $payload): array;

    /**
     * Adjust a money-summary paint DTO (server-side mirrors / tests).
     *
     * @param array<string, mixed> $dto
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function adjustDto(array $dto, array $ctx = []): array;
}
