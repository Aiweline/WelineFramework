<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

/**
 * Cart 跨请求车篮存储（guest_token / customer_id 键）。
 */
interface CartStoreInterface
{
    /**
     * @return array{
     *   scope_key:string,
     *   currency:string,
     *   owner_kind:string,
     *   owner_id:string,
     *   guest_token:?string,
     *   items:list<array<string,mixed>>
     * }|null
     */
    public function get(string $cartKey): ?array;

    /**
     * @param array{
     *   scope_key:string,
     *   currency:string,
     *   owner_kind:string,
     *   owner_id:string,
     *   guest_token:?string,
     *   items:list<array<string,mixed>>
     * } $cart
     */
    public function set(string $cartKey, array $cart): void;

    public function delete(string $cartKey): void;

    /**
     * Refresh TTL for an existing cart without mutating items.
     * Returns false when the cart key is missing.
     */
    public function touch(string $cartKey): bool;

    /**
     * @return list<array{
     *   scope_key:string,
     *   currency:string,
     *   owner_kind:string,
     *   owner_id:string,
     *   guest_token:?string,
     *   items:list<array<string,mixed>>
     * }>
     */
    public function listByScopeKey(string $scopeKey): array;

    /**
     * Lookup guest carts by full token or suffix (min 4 chars). Optional scope filter.
     *
     * @return list<array{
     *   scope_key:string,
     *   currency:string,
     *   owner_kind:string,
     *   owner_id:string,
     *   guest_token:?string,
     *   cart_type?:string,
     *   expires_at?:?string,
     *   updated_at?:?string,
     *   items:list<array<string,mixed>>
     * }>
     */
    public function listByGuestTokenHint(string $hint, ?string $scopeKey = null): array;
}
