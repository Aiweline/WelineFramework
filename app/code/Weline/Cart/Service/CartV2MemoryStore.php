<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartV2CartStoreInterface;

/**
 * 进程内车篮（单测 / harness）。
 */
final class CartV2MemoryStore implements CartV2CartStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $carts = [];

    public function get(string $cartKey): ?array
    {
        $cart = $this->carts[$cartKey] ?? null;

        return is_array($cart) ? $cart : null;
    }

    public function set(string $cartKey, array $cart): void
    {
        $this->carts[$cartKey] = $cart;
    }

    public function delete(string $cartKey): void
    {
        unset($this->carts[$cartKey]);
    }

    public function listByScopeKey(string $scopeKey): array
    {
        $out = [];
        foreach ($this->carts as $cart) {
            if ((string)($cart['scope_key'] ?? '') === $scopeKey) {
                $out[] = $cart;
            }
        }

        return $out;
    }
}
