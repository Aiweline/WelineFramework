<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

/**
 * Cart V2 跨请求车篮存储（guest_token / customer_id 键）。
 */
interface CartV2CartStoreInterface
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
}
