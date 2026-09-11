<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartStoreInterface;

/**
 * 进程内车篮（单测 / harness）。
 */
final class CartMemoryStore implements CartStoreInterface
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

    public function touch(string $cartKey): bool
    {
        return isset($this->carts[$cartKey]);
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

    public function listByGuestTokenHint(string $hint, ?string $scopeKey = null): array
    {
        $hint = trim($hint);
        if (strlen($hint) < 4) {
            return [];
        }
        $scope = $scopeKey !== null ? trim($scopeKey) : '';
        $out = [];
        foreach ($this->carts as $cart) {
            if (!is_array($cart)) {
                continue;
            }
            if ($scope !== '' && (string)($cart['scope_key'] ?? '') !== $scope) {
                continue;
            }
            $token = trim((string)($cart['guest_token'] ?? $cart['owner_id'] ?? ''));
            if ($token === '') {
                continue;
            }
            if ($token !== $hint && !str_ends_with($token, $hint)) {
                continue;
            }
            $out[] = $cart;
        }

        return $out;
    }
}
