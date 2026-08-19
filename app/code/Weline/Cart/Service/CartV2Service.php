<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartV2CartStoreInterface;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Cart V2 service：Scope 隔离、guest token、同 Scope 合车、服务端 selection hash.
 */
final class CartV2Service
{
    public const ERROR_SCOPE_MISMATCH = 'cart_scope_mismatch';
    public const ERROR_CROSS_CURRENCY = 'cart_cross_currency_forbidden';
    public const ERROR_NOT_SELLABLE = 'cart_item_not_sellable';
    public const ERROR_NOT_FOUND = 'cart_item_not_found';
    public const ERROR_GUEST_TOKEN = 'cart_guest_token_required';

    public const OWNER_GUEST = 'guest';
    public const OWNER_CUSTOMER = 'customer';

    public const GUEST_TOKEN_COOKIE = 'weline_cart_guest_token';

    private readonly CartV2CartStoreInterface $store;

    public function __construct(
        private readonly CartItemSnapshotProviderV2Registry $registry,
        ?CartV2CartStoreInterface $store = null,
    ) {
        $this->store = $store ?? ObjectManager::getInstance(CartV2CacheStore::class);
    }

    public static function forTesting(CartItemSnapshotProviderV2Registry $registry): self
    {
        return new self($registry, new CartV2MemoryStore());
    }

    public function registry(): CartItemSnapshotProviderV2Registry
    {
        return $this->registry;
    }

    public function issueGuestToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, scalar|null> $selection
     * @return array<string, mixed>
     */
    public function add(
        ScopeIdentity $scope,
        OfferIdentity $offer,
        array $selection = [],
        int $qty = 1,
        ?string $guestToken = null,
        ?int $customerId = null,
        ?string $clientSelectionHash = null,
        ?string $currency = null,
    ): array {
        $qty = max(1, min(999, $qty));
        $selection = CartSelectionHash::normalizeSelection($selection);
        $serverHash = CartSelectionHash::compute(
            $offer->globalOfferUuid,
            $offer->selectionSchemaVersion,
            $selection,
        );
        CartSelectionHash::assertClientHashOrIgnore($clientSelectionHash, $serverHash);

        $snapshot = $this->registry->resolve($offer, $scope, $selection);
        if (!$snapshot->found) {
            throw new CartV2ConflictException(
                self::ERROR_NOT_FOUND,
                $snapshot->message !== '' ? $snapshot->message : __('商品不存在或已下架'),
            );
        }
        if (!$snapshot->sellable) {
            throw new CartV2ConflictException(
                self::ERROR_NOT_SELLABLE,
                $snapshot->message !== '' ? $snapshot->message : __('该商品暂不可售'),
            );
        }

        $cartKey = $this->cartKey($scope, $guestToken, $customerId);
        $cart = $this->store->get($cartKey) ?? $this->newCart($scope, $guestToken, $customerId, $currency ?? $snapshot->currency);
        if ($cart['currency'] !== '' && $cart['currency'] !== $snapshot->currency) {
            throw new CartV2ConflictException(
                self::ERROR_CROSS_CURRENCY,
                __('跨币种购物车不可合并'),
                ['cart_currency' => $cart['currency'], 'item_currency' => $snapshot->currency],
            );
        }
        $cart['currency'] = $snapshot->currency;

        $adjusted = false;
        $requested = $qty;
        if ($snapshot->stock !== null) {
            $existingQty = 0;
            foreach ($cart['items'] as $row) {
                if ((string)$row['selection_hash'] === $serverHash) {
                    $existingQty = (int)$row['qty'];
                    break;
                }
            }
            $room = max(0, $snapshot->stock - $existingQty);
            if ($room <= 0) {
                throw new CartV2ConflictException(
                    self::ERROR_NOT_SELLABLE,
                    __('库存不足，购物车中该商品数量已达到当前可售库存。'),
                );
            }
            if ($qty > $room) {
                $qty = $room;
                $adjusted = true;
            }
        }

        $merged = false;
        foreach ($cart['items'] as &$row) {
            if ((string)$row['selection_hash'] !== $serverHash) {
                continue;
            }
            $row['qty'] = (int)$row['qty'] + $qty;
            $row['unit_price_minor'] = $snapshot->unitPriceMinor;
            $row['name'] = $snapshot->name;
            $row['row_total_minor'] = (int)$row['qty'] * (int)$row['unit_price_minor'];
            $merged = true;
            break;
        }
        unset($row);

        if (!$merged) {
            $cart['items'][] = $this->lineFromSnapshot($snapshot, $selection, $serverHash, $qty);
        }

        $this->store->set($cartKey, $cart);
        return $this->summary($cart, true, $adjusted
            ? (string)__('库存不足，已按当前可售数量加入购物车。')
            : (string)__('已加入购物车。'), [
            'quantity_adjusted' => $adjusted,
            'requested_quantity' => $requested,
            'adjusted_quantity' => $qty,
            'selection_hash' => $serverHash,
        ]);
    }

    /**
     * Merge guest cart into customer cart within the same Scope only（TEST-P2E-02）.
     *
     * @return array<string, mixed>
     */
    public function mergeGuestIntoCustomer(
        ScopeIdentity $scope,
        string $guestToken,
        int $customerId,
    ): array {
        if (trim($guestToken) === '') {
            throw new CartV2ConflictException(self::ERROR_GUEST_TOKEN, __('guest_token 不能为空'));
        }
        if ($customerId <= 0) {
            throw new \InvalidArgumentException(__('customer_id 须 >0'));
        }

        $guestKey = $this->cartKey($scope, $guestToken, null);
        $customerKey = $this->cartKey($scope, null, $customerId);
        $guest = $this->store->get($guestKey);
        $customer = $this->store->get($customerKey);

        $truncateNotes = [];
        if ($guest !== null) {
            if ($guest['scope_key'] !== $scope->canonicalKey()) {
                throw new CartV2ConflictException(self::ERROR_SCOPE_MISMATCH, __('游客车 Scope 不匹配'));
            }
            if ($customer !== null && $customer['scope_key'] !== $scope->canonicalKey()) {
                throw new CartV2ConflictException(self::ERROR_SCOPE_MISMATCH, __('客户车 Scope 不匹配'));
            }
            $guestCurrency = $this->validatedCartCurrency($guest, self::OWNER_GUEST);
            $customerCurrency = $customer === null
                ? ''
                : $this->validatedCartCurrency($customer, self::OWNER_CUSTOMER);
            if ($guestCurrency !== ''
                && $customerCurrency !== ''
                && $guestCurrency !== $customerCurrency
            ) {
                throw new CartV2ConflictException(
                    self::ERROR_CROSS_CURRENCY,
                    __('跨币种购物车不可合并'),
                    [
                        'guest_currency' => $guestCurrency,
                        'customer_currency' => $customerCurrency,
                    ],
                );
            }
            $mergedCurrency = $customerCurrency !== '' ? $customerCurrency : $guestCurrency;
            $customer ??= $this->newCart($scope, null, $customerId, $mergedCurrency);
            $customer['currency'] = $mergedCurrency;

            foreach ($guest['items'] as $line) {
                $hash = (string)$line['selection_hash'];
                $merged = false;
                foreach ($customer['items'] as &$crow) {
                    if ((string)$crow['selection_hash'] !== $hash) {
                        continue;
                    }
                    $nextQty = (int)$crow['qty'] + (int)$line['qty'];
                    $stock = $crow['stock'] ?? null;
                    if ($stock !== null && $nextQty > (int)$stock) {
                        $trunc = (int)$stock;
                        $truncateNotes[] = [
                            'selection_hash' => $hash,
                            'requested_qty' => $nextQty,
                            'capped_qty' => $trunc,
                        ];
                        $nextQty = $trunc;
                    }
                    $crow['qty'] = $nextQty;
                    $crow['row_total_minor'] = $nextQty * (int)$crow['unit_price_minor'];
                    $merged = true;
                    break;
                }
                unset($crow);
                if (!$merged) {
                    $addQty = (int)$line['qty'];
                    $stock = $line['stock'] ?? null;
                    if ($stock !== null && $addQty > (int)$stock) {
                        $truncateNotes[] = [
                            'selection_hash' => $hash,
                            'requested_qty' => $addQty,
                            'capped_qty' => (int)$stock,
                        ];
                        $addQty = (int)$stock;
                    }
                    if ($addQty > 0) {
                        $line['qty'] = $addQty;
                        $line['row_total_minor'] = $addQty * (int)$line['unit_price_minor'];
                        $customer['items'][] = $line;
                    }
                }
            }
            $this->store->delete($guestKey);
        }

        $customer ??= $this->newCart($scope, null, $customerId, '');
        $this->store->set($customerKey, $customer);
        $summary = $this->summary(
            $customer,
            true,
            $truncateNotes === []
                ? (string)__('游客购物车已合并。')
                : (string)__('游客购物车已合并；部分数量因可售上限被截断。'),
        );
        $summary['truncated_notes'] = $truncateNotes;
        $summary['quantity_truncated'] = $truncateNotes !== [];
        return $summary;
    }

    /**
     * Validate all line currencies before merge mutates either cart.
     *
     * @param array<string, mixed> $cart
     */
    private function validatedCartCurrency(array $cart, string $ownerKind): string
    {
        $currency = strtoupper(trim((string)($cart['currency'] ?? '')));
        foreach ($cart['items'] ?? [] as $line) {
            $lineCurrency = strtoupper(trim((string)($line['currency'] ?? '')));
            if ($lineCurrency === '') {
                continue;
            }
            if ($currency === '') {
                $currency = $lineCurrency;
                continue;
            }
            if ($currency !== $lineCurrency) {
                throw new CartV2ConflictException(
                    self::ERROR_CROSS_CURRENCY,
                    __('购物车包含跨币种商品，禁止合并'),
                    [
                        'owner_kind' => $ownerKind,
                        'cart_currency' => $currency,
                        'line_currency' => $lineCurrency,
                    ],
                );
            }
        }
        return $currency;
    }

    /** @return array<string, mixed> */
    public function getCart(ScopeIdentity $scope, ?string $guestToken = null, ?int $customerId = null): array
    {
        $key = $this->cartKey($scope, $guestToken, $customerId);
        $cart = $this->store->get($key) ?? $this->newCart($scope, $guestToken, $customerId, '');
        return $this->summary($cart);
    }

    /** @return array<string, mixed> */
    public function updateItem(
        ScopeIdentity $scope,
        string $itemId,
        int $qty,
        ?string $guestToken = null,
        ?int $customerId = null,
    ): array {
        $itemId = trim($itemId);
        if ($itemId === '') {
            throw new CartV2ConflictException(self::ERROR_NOT_FOUND, __('请选择要更新的购物车商品。'));
        }

        $key = $this->cartKey($scope, $guestToken, $customerId);
        $cart = $this->store->get($key);
        if ($cart === null) {
            throw new CartV2ConflictException(self::ERROR_NOT_FOUND, __('未找到要更新的购物车商品。'));
        }

        $requestedQty = max(1, min(999, $qty));
        $adjustedQty = $requestedQty;
        $updated = false;
        foreach ($cart['items'] as &$item) {
            if ((string)($item['item_id'] ?? '') !== $itemId) {
                continue;
            }
            $stock = $item['stock'] ?? null;
            if ($stock !== null) {
                $adjustedQty = min($adjustedQty, max(0, (int)$stock));
            }
            if ($adjustedQty <= 0) {
                throw new CartV2ConflictException(self::ERROR_NOT_SELLABLE, __('该商品暂不可售'));
            }
            $item['qty'] = $adjustedQty;
            $item['row_total_minor'] = $adjustedQty * (int)($item['unit_price_minor'] ?? 0);
            $updated = true;
            break;
        }
        unset($item);

        if (!$updated) {
            throw new CartV2ConflictException(self::ERROR_NOT_FOUND, __('未找到要更新的购物车商品。'));
        }

        $this->store->set($key, $cart);
        return $this->summary(
            $cart,
            true,
            $adjustedQty === $requestedQty
                ? (string)__('购物车已更新。')
                : (string)__('库存不足，已按当前可售数量更新购物车。'),
            [
                'quantity_adjusted' => $adjustedQty !== $requestedQty,
                'requested_quantity' => $requestedQty,
                'adjusted_quantity' => $adjustedQty,
            ],
        );
    }

    /** @return array<string, mixed> */
    public function removeItem(
        ScopeIdentity $scope,
        string $itemId,
        ?string $guestToken = null,
        ?int $customerId = null,
    ): array {
        $itemId = trim($itemId);
        if ($itemId === '') {
            throw new CartV2ConflictException(self::ERROR_NOT_FOUND, __('请选择要移除的购物车商品。'));
        }

        $key = $this->cartKey($scope, $guestToken, $customerId);
        $cart = $this->store->get($key);
        if ($cart === null) {
            throw new CartV2ConflictException(self::ERROR_NOT_FOUND, __('未找到要移除的购物车商品。'));
        }

        $before = count($cart['items']);
        $cart['items'] = array_values(array_filter(
            $cart['items'],
            static fn(array $item): bool => (string)($item['item_id'] ?? '') !== $itemId,
        ));
        if (count($cart['items']) === $before) {
            throw new CartV2ConflictException(self::ERROR_NOT_FOUND, __('未找到要移除的购物车商品。'));
        }

        $this->store->set($key, $cart);
        return $this->summary($cart, true, (string)__('商品已从购物车移除。'));
    }

    /** @return array<string, mixed> */
    public function clearCart(
        ScopeIdentity $scope,
        ?string $guestToken = null,
        ?int $customerId = null,
    ): array {
        $key = $this->cartKey($scope, $guestToken, $customerId);
        $this->store->delete($key);

        return $this->summary(
            $this->newCart($scope, $guestToken, $customerId, ''),
            true,
            (string)__('购物车已清空。'),
        );
    }

    /** @internal tests */
    public function cartCountForScope(ScopeIdentity $scope): int
    {
        $n = 0;
        foreach ($this->store->listByScopeKey($scope->canonicalKey()) as $cart) {
            $n += count($cart['items'] ?? []);
        }
        return $n;
    }

    private function cartKey(ScopeIdentity $scope, ?string $guestToken, ?int $customerId): string
    {
        if ($customerId !== null && $customerId > 0) {
            return $scope->canonicalKey() . '|customer:' . $customerId;
        }
        $token = trim((string)$guestToken);
        if ($token === '') {
            throw new CartV2ConflictException(self::ERROR_GUEST_TOKEN, __('游客加购需要 guest_token'));
        }
        return $scope->canonicalKey() . '|guest:' . $token;
    }

    /**
     * @return array{
     *   scope_key:string,
     *   currency:string,
     *   owner_kind:string,
     *   owner_id:string,
     *   guest_token:?string,
     *   items:list<array<string,mixed>>
     * }
     */
    private function newCart(ScopeIdentity $scope, ?string $guestToken, ?int $customerId, string $currency): array
    {
        if ($customerId !== null && $customerId > 0) {
            return [
                'scope_key' => $scope->canonicalKey(),
                'currency' => $currency,
                'owner_kind' => self::OWNER_CUSTOMER,
                'owner_id' => (string)$customerId,
                'guest_token' => null,
                'items' => [],
            ];
        }
        return [
            'scope_key' => $scope->canonicalKey(),
            'currency' => $currency,
            'owner_kind' => self::OWNER_GUEST,
            'owner_id' => (string)$guestToken,
            'guest_token' => $guestToken,
            'items' => [],
        ];
    }

    /**
     * @param array<string, scalar|null> $selection
     * @return array<string, mixed>
     */
    private function lineFromSnapshot(
        CartItemSnapshot $snapshot,
        array $selection,
        string $selectionHash,
        int $qty,
    ): array {
        return [
            'item_id' => 'v2-' . substr($selectionHash, 0, 16),
            'selection_hash' => $selectionHash,
            'selection' => $selection,
            'offer' => $snapshot->offer->toArray(),
            'name' => $snapshot->name,
            'sku' => $snapshot->sku,
            'image' => $snapshot->image,
            'currency' => $snapshot->currency,
            'unit_price_minor' => $snapshot->unitPriceMinor,
            'qty' => $qty,
            'stock' => $snapshot->stock,
            'product_type' => $snapshot->productType,
            'source_module' => $snapshot->sourceModule,
            'source_app' => $snapshot->sourceApp,
            'offer_id' => $snapshot->offerId ?? $snapshot->offer->legacyProductId ?? 0,
            'product_id' => $snapshot->productId ?? $snapshot->offer->legacyProductId ?? 0,
            'split_key' => trim($snapshot->splitKey) ?: 'default',
            'legal_entity' => trim($snapshot->legalEntity) ?: 'default',
            'requires_shipping' => $snapshot->requiresShipping,
            'weight_minor' => max(0, $snapshot->weightMinor),
            'volume_minor' => max(0, $snapshot->volumeMinor),
            'tax_class_code' => trim($snapshot->taxClassCode) ?: 'standard',
            'row_total_minor' => $qty * $snapshot->unitPriceMinor,
        ];
    }

    /**
     * @param array<string, mixed> $cart
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function summary(array $cart, bool $success = true, string $message = '', array $extra = []): array
    {
        $count = 0;
        $subtotal = 0;
        foreach ($cart['items'] as $item) {
            $count += (int)$item['qty'];
            $subtotal += (int)$item['row_total_minor'];
        }
        return [
            'success' => $success,
            'message' => $message,
            'scope_key' => $cart['scope_key'],
            'currency' => $cart['currency'],
            'owner_kind' => $cart['owner_kind'],
            'owner_id' => $cart['owner_id'],
            'guest_token' => $cart['guest_token'],
            'items' => $cart['items'],
            'cart_count' => $count,
            'item_count' => $count,
            'distinct_count' => count($cart['items']),
            'subtotal_minor' => $subtotal,
            'grand_total_minor' => $subtotal,
            'is_empty' => $cart['items'] === [],
        ] + $extra;
    }
}
