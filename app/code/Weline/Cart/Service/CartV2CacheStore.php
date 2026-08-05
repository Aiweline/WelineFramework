<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartV2CartStoreInterface;

/**
 * 跨 Worker 共享车篮：w_cache Custom 全维度逃逸（键内已含 Scope）。
 */
final class CartV2CacheStore implements CartV2CartStoreInterface
{
    private const CACHE_IDENTITY = 'cart_v2';
    private const TTL = 604800; // 7d
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
        $cache->setCustom($this->payloadKey($cartKey), $cart, self::TTL);
        $scopeKey = (string)($cart['scope_key'] ?? '');
        if ($scopeKey === '') {
            return;
        }
        $indexKey = self::INDEX_PREFIX . $scopeKey;
        $index = $cache->getCustom($indexKey);
        $keys = is_array($index) ? $index : [];
        if (!in_array($cartKey, $keys, true)) {
            $keys[] = $cartKey;
            $cache->setCustom($indexKey, array_values($keys), self::TTL);
        }
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
        $cache->setCustom($indexKey, $keys, self::TTL);
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
