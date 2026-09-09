<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

/**
 * Cart persistence TTL / expiry policy.
 *
 * Authoritative store is DB (`CartDbStore` → `weline_cart`):
 * - guest: expires_at = now + 15 days (Cookie / guest_session 同 TTL)
 * - customer: expires_at = NULL（永久）
 *
 * CUSTOMER_TTL_SECONDS 仅遗留 `CartCacheStore` 兼容（CachePool 不能表达无过期）。
 */
final class CartPersistencePolicy
{
    /** Half month — guest cookie / DB expires_at / browser guest_session. */
    public const GUEST_TTL_SECONDS = 1_296_000; // 15 * 24 * 3600

    /**
     * Logged-in customer carts (legacy cache only). ~50 years ≈ permanent under CachePool.
     * DB 路径请用 expiresAtForCart() → NULL。
     */
    public const CUSTOMER_TTL_SECONDS = 1_577_880_000; // 50 * 365.25 * 24 * 3600

    public static function guestTtlSeconds(): int
    {
        return self::GUEST_TTL_SECONDS;
    }

    public static function customerTtlSeconds(): int
    {
        return self::CUSTOMER_TTL_SECONDS;
    }

    public static function ttlForOwnerKind(string $ownerKind): int
    {
        return strtolower(trim($ownerKind)) === CartService::OWNER_CUSTOMER
            ? self::CUSTOMER_TTL_SECONDS
            : self::GUEST_TTL_SECONDS;
    }

    /**
     * @param array<string, mixed> $cart
     */
    public static function ttlForCart(array $cart): int
    {
        $kind = (string)($cart['owner_kind'] ?? '');
        if ($kind !== '') {
            return self::ttlForOwnerKind($kind);
        }

        return self::ttlForCartKey((string)($cart['owner_id'] ?? ''));
    }

    public static function ttlForCartKey(string $cartKey): int
    {
        if (str_contains($cartKey, '|customer:')) {
            return self::CUSTOMER_TTL_SECONDS;
        }

        return self::GUEST_TTL_SECONDS;
    }

    public static function guestExpiresAtMs(?float $nowMs = null): int
    {
        $now = $nowMs ?? (\microtime(true) * 1000);

        return (int)(\round($now) + (self::GUEST_TTL_SECONDS * 1000));
    }

    /**
     * DB expires_at（UTC datetime）或 NULL（客户永久）。
     *
     * @param array<string, mixed> $cart
     */
    public static function expiresAtForCart(array $cart, string $cartKey = ''): ?string
    {
        $kind = strtolower(trim((string)($cart['owner_kind'] ?? '')));
        if ($kind === '') {
            $kind = str_contains($cartKey, '|customer:')
                ? CartService::OWNER_CUSTOMER
                : CartService::OWNER_GUEST;
        }
        if ($kind === CartService::OWNER_CUSTOMER) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', time() + self::GUEST_TTL_SECONDS);
    }
}
