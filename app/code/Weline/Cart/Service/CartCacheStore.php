<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartStoreInterface;

/**
 * 遗留 cache 实现（非默认）。权威存储见 `CartDbStore` / 表 `weline_cart`。
 *
 * 保留供对照与回退实验；生产默认请走 DB。
 */
final class CartCacheStore implements CartStoreInterface
{
    public const ERROR_PERSIST = 'cart_persist_failed';

    private const CACHE_IDENTITY = 'cart';
    private const INDEX_PREFIX = 'idx:';

    public function get(string $cartKey): ?array
    {
        $raw = w_cache(self::CACHE_IDENTITY)->getCustom($this->payloadKey($cartKey));
        if (!is_array($raw)) {
            return null;
        }

        return $raw;
    }

    public function set(string $cartKey, array $cart): void
    {
        $cache = w_cache(self::CACHE_IDENTITY);
        $ttl = CartPersistencePolicy::ttlForCart($cart);
        if (str_contains($cartKey, '|customer:')) {
            $ttl = CartPersistencePolicy::CUSTOMER_TTL_SECONDS;
        }
        if (!$cache->setCustom($this->payloadKey($cartKey), $cart, $ttl)) {
            throw new CartConflictException(
                self::ERROR_PERSIST,
                (string)__('购物车保存失败，请重试'),
            );
        }
        $scopeKey = (string)($cart['scope_key'] ?? '');
        if ($scopeKey === '') {
            return;
        }
        $indexKey = self::INDEX_PREFIX . $scopeKey;
        $index = $cache->getCustom($indexKey);
        $keys = is_array($index) ? $index : [];
        if (!in_array($cartKey, $keys, true)) {
            $keys[] = $cartKey;
        }
        // Index is best-effort; payload already durable. Still try to refresh.
        $cache->setCustom($indexKey, array_values($keys), $ttl);
    }

    public function delete(string $cartKey): void
    {
        $cache = w_cache(self::CACHE_IDENTITY);
        $existing = $this->get($cartKey);
        $cache->deleteCustom($this->payloadKey($cartKey));
        if (!is_array($existing)) {
            return;
        }
        $scopeKey = (string)($existing['scope_key'] ?? '');
        if ($scopeKey === '') {
            return;
        }
        $indexKey = self::INDEX_PREFIX . $scopeKey;
        $index = $cache->getCustom($indexKey);
        if (!is_array($index)) {
            return;
        }
        $keys = array_values(array_filter(
            $index,
            static fn ($k): bool => is_string($k) && $k !== $cartKey,
        ));
        $cache->setCustom(
            $indexKey,
            $keys,
            CartPersistencePolicy::ttlForCart($existing),
        );
    }

    public function touch(string $cartKey): bool
    {
        $cart = $this->get($cartKey);
        if (!is_array($cart)) {
            return false;
        }
        $this->set($cartKey, $cart);

        return true;
    }

    public function listByScopeKey(string $scopeKey): array
    {
        $cache = w_cache(self::CACHE_IDENTITY);
        $index = $cache->getCustom(self::INDEX_PREFIX . $scopeKey);
        if (!is_array($index)) {
            return [];
        }
        $out = [];
        foreach ($index as $cartKey) {
            if (!is_string($cartKey) || $cartKey === '') {
                continue;
            }
            $cart = $this->get($cartKey);
            if (is_array($cart)) {
                $out[] = $cart;
            }
        }

        return $out;
    }

    private function payloadKey(string $cartKey): string
    {
        return 'cart:' . $cartKey;
    }
}
