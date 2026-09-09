<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartStoreInterface;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Cart service：Scope 隔离、guest token、同 Scope 合车、服务端 selection hash.
 */
final class CartService
{
    public const ERROR_SCOPE_MISMATCH = 'cart_scope_mismatch';
    public const ERROR_CROSS_CURRENCY = 'cart_cross_currency_forbidden';
    public const ERROR_NOT_SELLABLE = 'cart_item_not_sellable';
    public const ERROR_NOT_FOUND = 'cart_item_not_found';
    public const ERROR_GUEST_TOKEN = 'cart_guest_token_required';
    public const ERROR_TYPE_MISMATCH = 'cart_type_mismatch';

    public const OWNER_GUEST = 'guest';
    public const OWNER_CUSTOMER = 'customer';

    public const GUEST_TOKEN_COOKIE = 'weline_cart_guest_token';

    private readonly CartStoreInterface $store;
    private readonly CommerceCartTypeRegistry $typeRegistry;
    private readonly SellingTypeResolver $sellingTypeResolver;

    public function __construct(
        private readonly CartItemSnapshotProviderRegistry $registry,
        ?CartStoreInterface $store = null,
        ?CommerceCartTypeRegistry $typeRegistry = null,
        ?SellingTypeResolver $sellingTypeResolver = null,
    ) {
        $this->store = $store ?? ObjectManager::getInstance(CartDbStore::class);
        $this->typeRegistry = $typeRegistry ?? ObjectManager::getInstance(CommerceCartTypeRegistry::class);
        $this->sellingTypeResolver = $sellingTypeResolver
            ?? new SellingTypeResolver($this->typeRegistry);
    }

    public static function forTesting(
        CartItemSnapshotProviderRegistry $registry,
        ?CommerceCartTypeRegistry $typeRegistry = null,
        ?SellingTypeResolver $sellingTypeResolver = null,
    ): self {
        $types = $typeRegistry ?? CommerceCartTypeRegistry::forTesting();

        return new self(
            $registry,
            new CartMemoryStore(),
            $types,
            $sellingTypeResolver ?? SellingTypeResolver::forTesting(
                $types,
                static fn (string $_code): bool => true,
            ),
        );
    }

    public function registry(): CartItemSnapshotProviderRegistry
    {
        return $this->registry;
    }

    public function issueGuestToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Query / controller entry: build OfferIdentity + Scope from flat params.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function addFromParams(array $params): array
    {
        try {
            $scopeResolver = ObjectManager::getInstance(CartScopeResolver::class);
            $scope = $scopeResolver instanceof CartScopeResolver
                ? $scopeResolver->fromParams($params)
                : ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL);
            $offer = OfferIdentity::fromArray($params);
            $selection = $params['selection'] ?? $params['selected_options'] ?? $params['options'] ?? [];
            $selection = \is_array($selection) ? $selection : [];
            $qty = max(1, min(999, (int)($params['qty'] ?? 1)));
            $result = $this->add(
                scope: $scope,
                offer: $offer,
                selection: $selection,
                qty: $qty,
                guestToken: isset($params['guest_token']) ? (string)$params['guest_token'] : null,
                customerId: isset($params['customer_id']) ? (int)$params['customer_id'] : null,
                clientSelectionHash: isset($params['selection_hash']) ? (string)$params['selection_hash'] : null,
                currency: isset($params['currency']) ? (string)$params['currency'] : null,
                cartTypePreference: $this->preferenceFromParams($params),
            );
            $result['subtotal'] = round(((int)($result['subtotal_minor'] ?? 0)) / 100, 2);
            $result['grand_total'] = round(((int)($result['grand_total_minor'] ?? 0)) / 100, 2);

            return $result;
        } catch (CartConflictException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'error_context' => $e->context(),
                'items' => [],
                'cart_count' => 0,
                'item_count' => 0,
                'distinct_count' => 0,
                'is_empty' => true,
                'subtotal' => 0.0,
                'grand_total' => 0.0,
                'subtotal_minor' => 0,
                'grand_total_minor' => 0,
            ];
        }
    }

    /**
     * Storefront HTML bootstrap: read the trusted cart for the current browser owner.
     *
     * @return array<string, mixed>
     */
    public function storefrontSummary(): array
    {
        $customerResolver = ObjectManager::getInstance(CartCurrentCustomerResolver::class);
        $scopeResolver = ObjectManager::getInstance(CartScopeResolver::class);
        $customerId = $customerResolver instanceof CartCurrentCustomerResolver
            ? $customerResolver->currentCustomerId()
            : null;
        $guestToken = $customerId === null
            ? \trim((string)Cookie::get(self::GUEST_TOKEN_COOKIE))
            : null;
        if ($customerId === null && ($guestToken === null || $guestToken === '')) {
            return [
                'success' => true,
                'message' => '',
                'items' => [],
                'cart_count' => 0,
                'item_count' => 0,
                'distinct_count' => 0,
                'is_empty' => true,
                'subtotal' => 0.0,
                'grand_total' => 0.0,
                'subtotal_minor' => 0,
                'grand_total_minor' => 0,
            ];
        }

        $scope = $scopeResolver instanceof CartScopeResolver
            ? $scopeResolver->fromParams([])
            : ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL);
        $summary = $this->getCart($scope, $guestToken, $customerId, $this->preferenceFromParams([]));
        $items = \is_array($summary['items'] ?? null) ? $summary['items'] : [];
        foreach ($items as &$item) {
            if (!\is_array($item)) {
                continue;
            }
            $item['price'] = \round(((int)($item['unit_price_minor'] ?? 0)) / 100, 2);
            $item['row_total'] = \round(((int)($item['row_total_minor'] ?? 0)) / 100, 2);
            $compareAtMinor = max(0, (int)($item['compare_at_minor'] ?? 0));
            $item['compare_at_minor'] = $compareAtMinor;
            $item['original_price'] = \round($compareAtMinor / 100, 2);
            $item['has_deal'] = $compareAtMinor > (int)($item['unit_price_minor'] ?? 0)
                && (int)($item['unit_price_minor'] ?? 0) > 0;
            $item['campaign_label'] = trim((string)($item['campaign_label'] ?? ''));
            $item['campaign_url'] = trim((string)($item['campaign_url'] ?? ''));
        }
        unset($item);
        $summary['items'] = $items;
        $summary['subtotal'] = \round(((int)($summary['subtotal_minor'] ?? 0)) / 100, 2);
        $summary['grand_total'] = \round(((int)($summary['grand_total_minor'] ?? 0)) / 100, 2);

        return $summary;
    }

    /**
     * Extend guest cart TTL (+ cookie) without rotating the token.
     */
    public function touchGuestCart(
        ScopeIdentity $scope,
        string $guestToken,
        ?string $cartTypePreference = null,
    ): bool {
        $guestToken = \trim($guestToken);
        if ($guestToken === '') {
            return false;
        }
        $resolved = $this->resolveCartType($cartTypePreference, null, $scope);
        $key = $this->cartKey($scope, $guestToken, null, $resolved['code']);

        return $this->store->touch($key);
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
        ?string $cartTypePreference = null,
    ): array {
        $qty = max(1, min(999, $qty));
        $selection = CartSelectionHash::normalizeSelection($selection);
        $serverHash = CartSelectionHash::compute(
            $offer->globalOfferUuid,
            $offer->selectionSchemaVersion,
            $selection,
        );
        CartSelectionHash::assertClientHashOrIgnore($clientSelectionHash, $serverHash);

        $resolved = $this->resolveCartType($cartTypePreference, $customerId, $scope);
        $cartType = $resolved['code'];

        $snapshot = $this->registry->resolve($offer, $scope, $selection);
        if (!$snapshot->found) {
            throw new CartConflictException(
                self::ERROR_NOT_FOUND,
                $snapshot->message !== '' ? $snapshot->message : __('商品不存在或已下架'),
            );
        }
        if (!$snapshot->sellable) {
            $stockName = $snapshot->name !== '' ? $snapshot->name : (string)__('该商品');
            $message = trim($snapshot->message);
            $genericCandidates = [
                (string)__('商品库存不足'),
                '商品库存不足',
                'Out of stock',
            ];
            if ($message === '' || in_array($message, $genericCandidates, true)) {
                $message = (string)__('「%{1}」库存不足', [$stockName]);
            }
            throw new CartConflictException(
                self::ERROR_NOT_SELLABLE,
                $message !== '' ? $message : (string)__('该商品暂不可售'),
            );
        }

        $cartKey = $this->cartKey($scope, $guestToken, $customerId, $cartType);
        $cart = $this->loadCart($scope, $guestToken, $customerId, $cartType)
            ?? $this->newCart($scope, $guestToken, $customerId, $currency ?? $snapshot->currency, $cartType);
        if ($cart['currency'] !== '' && $cart['currency'] !== $snapshot->currency) {
            throw new CartConflictException(
                self::ERROR_CROSS_CURRENCY,
                __('跨币种购物车不可合并'),
                ['cart_currency' => $cart['currency'], 'item_currency' => $snapshot->currency],
            );
        }
        $cart['currency'] = $snapshot->currency;
        $cart['cart_type'] = $cartType;

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
                throw new CartConflictException(
                    self::ERROR_NOT_SELLABLE,
                    __('「%{1}」库存不足，购物车中该商品数量已达到当前可售库存。', [$snapshot->name !== '' ? $snapshot->name : (string)__('该商品')]),
                );
            }
            if ($qty > $room) {
                $qty = $room;
                $adjusted = true;
            }
        }

        $resultingQty = $qty;
        foreach ($cart['items'] as $probe) {
            if ((string)$probe['selection_hash'] === $serverHash) {
                $resultingQty = (int)$probe['qty'] + $qty;
                break;
            }
        }
        $this->assertQtyPolicy(
            $cartType,
            $resultingQty,
            (string)$snapshot->sku,
            $scope,
            $customerId,
            (int)($snapshot->productId ?? 0),
        );

        $merged = false;
        foreach ($cart['items'] as &$row) {
            if ((string)$row['selection_hash'] !== $serverHash) {
                continue;
            }
            $row['qty'] = (int)$row['qty'] + $qty;
            $row['unit_price_minor'] = $snapshot->unitPriceMinor;
            $row['name'] = $snapshot->name;
            $row['sku'] = $snapshot->sku;
            // Refresh presentation fields so stale asset:// snapshots do not stick after re-add.
            $row['image'] = $snapshot->image;
            $row['options'] = $this->normalizeOptions(
                $snapshot->options !== [] ? $snapshot->options : $this->optionsFromSelection($selection),
            );
            $row['row_total_minor'] = (int)$row['qty'] * (int)$row['unit_price_minor'];
            $merged = true;
            break;
        }
        unset($row);

        if (!$merged) {
            $cart['items'][] = $this->lineFromSnapshot($snapshot, $selection, $serverHash, $qty);
        }

        $this->store->set($cartKey, $cart);
        $summary = $this->summary($cart, true, $adjusted
            ? (string)__('「%{1}」库存不足，已按当前可售数量加入购物车。', [$snapshot->name !== '' ? $snapshot->name : (string)__('该商品')])
            : (string)__('已加入购物车。'), [
            'quantity_adjusted' => $adjusted,
            'requested_quantity' => $requested,
            'adjusted_quantity' => $qty,
            'selection_hash' => $serverHash,
        ], $scope);
        $this->dispatchTypedCartEvent('Weline_Cart::cart_item_added', $summary, [
            'selection_hash' => $serverHash,
            'quantity_adjusted' => $adjusted,
        ]);

        return $summary;
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
        ?string $cartTypePreference = null,
    ): array {
        if (trim($guestToken) === '') {
            throw new CartConflictException(self::ERROR_GUEST_TOKEN, __('guest_token 不能为空'));
        }
        if ($customerId <= 0) {
            throw new \InvalidArgumentException(__('customer_id 须 >0'));
        }

        $resolved = $this->resolveCartType($cartTypePreference, $customerId, $scope);
        $cartType = $resolved['code'];
        // tob 无游客车：仅落客户 typed 车，不跨类型吞 toc 游客车。
        $allowGuestMerge = $cartType === CommerceCartTypeRegistry::CODE_TOC
            && !$resolved['type']->requiresCustomerLogin();

        $guestKey = $this->cartKey($scope, $guestToken, null, $cartType);
        $customerKey = $this->cartKey($scope, null, $customerId, $cartType);
        $guest = $allowGuestMerge
            ? $this->loadCart($scope, $guestToken, null, $cartType, createIfMissing: false)
            : null;
        $customer = $this->loadCart($scope, null, $customerId, $cartType, createIfMissing: false);

        $truncateNotes = [];
        if ($guest !== null) {
            $this->assertCartTypeMatch($guest, $cartType);
            if ($guest['scope_key'] !== $scope->canonicalKey()) {
                throw new CartConflictException(self::ERROR_SCOPE_MISMATCH, __('游客车 Scope 不匹配'));
            }
            if ($customer !== null && $customer['scope_key'] !== $scope->canonicalKey()) {
                throw new CartConflictException(self::ERROR_SCOPE_MISMATCH, __('客户车 Scope 不匹配'));
            }
            if ($customer !== null) {
                $this->assertCartTypeMatch($customer, $cartType);
            }
            $guestCurrency = $this->validatedCartCurrency($guest, self::OWNER_GUEST);
            $customerCurrency = $customer === null
                ? ''
                : $this->validatedCartCurrency($customer, self::OWNER_CUSTOMER);
            if ($guestCurrency !== ''
                && $customerCurrency !== ''
                && $guestCurrency !== $customerCurrency
            ) {
                throw new CartConflictException(
                    self::ERROR_CROSS_CURRENCY,
                    __('跨币种购物车不可合并'),
                    [
                        'guest_currency' => $guestCurrency,
                        'customer_currency' => $customerCurrency,
                    ],
                );
            }
            $mergedCurrency = $customerCurrency !== '' ? $customerCurrency : $guestCurrency;
            $customer ??= $this->newCart($scope, null, $customerId, $mergedCurrency, $cartType);
            $customer['currency'] = $mergedCurrency;
            $customer['cart_type'] = $cartType;

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
            $legacyGuestKey = $this->legacyCartKey($scope, $guestToken, null);
            if ($legacyGuestKey !== $guestKey) {
                $this->store->delete($legacyGuestKey);
            }
        }

        $customer ??= $this->newCart($scope, null, $customerId, '', $cartType);
        $customer['cart_type'] = $cartType;
        $this->store->set($customerKey, $customer);
        $summary = $this->summary(
            $customer,
            true,
            $truncateNotes === []
                ? (string)__('游客购物车已合并。')
                : (string)__('游客购物车已合并；部分数量因可售上限被截断。'),
            [],
            $scope,
        );
        $summary['truncated_notes'] = $truncateNotes;
        $summary['quantity_truncated'] = $truncateNotes !== [];
        $this->dispatchTypedCartEvent('Weline_Cart::cart_merged', $summary, [
            'quantity_truncated' => $truncateNotes !== [],
            'truncated_notes' => $truncateNotes,
        ]);

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
                throw new CartConflictException(
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
    public function getCart(
        ScopeIdentity $scope,
        ?string $guestToken = null,
        ?int $customerId = null,
        ?string $cartTypePreference = null,
    ): array {
        // Read-only: mini-cart / storefront may poll before issueGuestToken.
        if (($customerId === null || $customerId <= 0) && trim((string)$guestToken) === '') {
            $resolved = $this->resolveCartType($cartTypePreference, null, $scope);
            return $this->summary(
                $this->newCart($scope, null, null, '', $resolved['code']),
                true,
                '',
                [],
                $scope,
            );
        }
        $resolved = $this->resolveCartType($cartTypePreference, $customerId, $scope);
        $cartType = $resolved['code'];
        $cart = $this->loadCart($scope, $guestToken, $customerId, $cartType)
            ?? $this->newCart($scope, $guestToken, $customerId, '', $cartType);
        return $this->summary($cart, true, '', [], $scope);
    }

    /** @return array<string, mixed> */
    public function updateItem(
        ScopeIdentity $scope,
        string $itemId,
        int $qty,
        ?string $guestToken = null,
        ?int $customerId = null,
        ?string $cartTypePreference = null,
    ): array {
        $itemId = trim($itemId);
        if ($itemId === '') {
            throw new CartConflictException(self::ERROR_NOT_FOUND, __('请选择要更新的购物车商品。'));
        }

        $resolved = $this->resolveCartType($cartTypePreference, $customerId, $scope);
        $cartType = $resolved['code'];
        $key = $this->cartKey($scope, $guestToken, $customerId, $cartType);
        $cart = $this->loadCart($scope, $guestToken, $customerId, $cartType, createIfMissing: false);
        if ($cart === null) {
            throw new CartConflictException(self::ERROR_NOT_FOUND, __('未找到要更新的购物车商品。'));
        }

        $requestedQty = max(1, min(999, $qty));
        $adjustedQty = $requestedQty;
        $updated = false;
        foreach ($cart['items'] as &$item) {
            if ((string)($item['item_id'] ?? '') !== $itemId) {
                continue;
            }
            if (!is_array($item['offer'] ?? null)) {
                throw new CartConflictException(
                    self::ERROR_NOT_FOUND,
                    __('该商品已不存在或已下架，请从购物车移除'),
                );
            }
            $selection = CartSelectionHash::normalizeSelection(
                is_array($item['selection'] ?? null) ? $item['selection'] : [],
            );
            $snapshot = $this->registry->resolve(
                OfferIdentity::fromArray($item['offer']),
                $scope,
                $selection,
            );
            if (!$snapshot->found) {
                throw new CartConflictException(
                    self::ERROR_NOT_FOUND,
                    $this->humanizeSnapshotMessage($snapshot),
                );
            }
            if (!$snapshot->sellable) {
                throw new CartConflictException(
                    self::ERROR_NOT_SELLABLE,
                    $this->humanizeSnapshotMessage($snapshot),
                );
            }
            $stock = $snapshot->stock ?? ($item['stock'] ?? null);
            if ($stock !== null) {
                $adjustedQty = min($adjustedQty, max(0, (int)$stock));
                $item['stock'] = (int)$stock;
            }
            if ($adjustedQty <= 0) {
                throw new CartConflictException(self::ERROR_NOT_SELLABLE, __('该商品暂不可售'));
            }
            $this->assertQtyPolicy(
                $cartType,
                $adjustedQty,
                (string)($item['sku'] ?? $snapshot->sku),
                $scope,
                $customerId,
                (int)($item['product_id'] ?? $snapshot->productId ?? 0),
            );
            $item['qty'] = $adjustedQty;
            if ($snapshot->unitPriceMinor >= 0
                && !($cartType === 'tob'
                    && (
                        array_key_exists('b2b_amount_minor', $item)
                        || trim((string)($item['b2b_price_list_id'] ?? '')) !== ''
                    ))
            ) {
                $item['unit_price_minor'] = $snapshot->unitPriceMinor;
            }
            $item['row_total_minor'] = $adjustedQty * (int)($item['unit_price_minor'] ?? 0);
            $updated = true;
            break;
        }
        unset($item);

        if (!$updated) {
            throw new CartConflictException(self::ERROR_NOT_FOUND, __('未找到要更新的购物车商品。'));
        }

        $cart['cart_type'] = $cartType;
        $this->store->set($key, $cart);
        $updatedName = '';
        foreach ($cart['items'] as $row) {
            if ((string)($row['item_id'] ?? '') === $itemId) {
                $updatedName = trim((string)($row['name'] ?? ''));
                break;
            }
        }
        return $this->summary(
            $cart,
            true,
            $adjustedQty === $requestedQty
                ? (string)__('购物车已更新。')
                : (string)__('「%{1}」库存不足，已按当前可售数量更新购物车。', [$updatedName !== '' ? $updatedName : (string)__('该商品')]),
            [
                'quantity_adjusted' => $adjustedQty !== $requestedQty,
                'requested_quantity' => $requestedQty,
                'adjusted_quantity' => $adjustedQty,
            ],
            $scope,
        );
    }

    /** @return array<string, mixed> */
    public function removeItem(
        ScopeIdentity $scope,
        string $itemId,
        ?string $guestToken = null,
        ?int $customerId = null,
        ?string $cartTypePreference = null,
    ): array {
        $itemId = trim($itemId);
        if ($itemId === '') {
            throw new CartConflictException(self::ERROR_NOT_FOUND, __('请选择要移除的购物车商品。'));
        }

        $resolved = $this->resolveCartType($cartTypePreference, $customerId, $scope);
        $cartType = $resolved['code'];
        $key = $this->cartKey($scope, $guestToken, $customerId, $cartType);
        $cart = $this->loadCart($scope, $guestToken, $customerId, $cartType, createIfMissing: false);
        if ($cart === null) {
            throw new CartConflictException(self::ERROR_NOT_FOUND, __('未找到要移除的购物车商品。'));
        }

        $before = count($cart['items']);
        $cart['items'] = array_values(array_filter(
            $cart['items'],
            static fn(array $item): bool => (string)($item['item_id'] ?? '') !== $itemId,
        ));
        if (count($cart['items']) === $before) {
            throw new CartConflictException(self::ERROR_NOT_FOUND, __('未找到要移除的购物车商品。'));
        }

        $cart['cart_type'] = $cartType;
        $this->store->set($key, $cart);
        return $this->summary($cart, true, (string)__('商品已从购物车移除。'), [], $scope);
    }

    /** @return array<string, mixed> */
    public function clearCart(
        ScopeIdentity $scope,
        ?string $guestToken = null,
        ?int $customerId = null,
        ?string $cartTypePreference = null,
    ): array {
        $resolved = $this->resolveCartType($cartTypePreference, $customerId, $scope);
        $cartType = $resolved['code'];
        $key = $this->cartKey($scope, $guestToken, $customerId, $cartType);
        $this->store->delete($key);
        if ($cartType === CommerceCartTypeRegistry::CODE_TOC) {
            $legacy = $this->legacyCartKey($scope, $guestToken, $customerId);
            if ($legacy !== $key) {
                $this->store->delete($legacy);
            }
        }

        $summary = $this->summary(
            $this->newCart($scope, $guestToken, $customerId, '', $cartType),
            true,
            (string)__('购物车已清空。'),
            [],
            $scope,
        );
        $this->dispatchTypedCartEvent('Weline_Cart::cart_cleared', $summary);

        return $summary;
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

    /** @internal tests */
    public function cartKeyForTesting(
        ScopeIdentity $scope,
        ?string $guestToken,
        ?int $customerId,
        string $cartType = CommerceCartTypeRegistry::CODE_TOC,
    ): string {
        return $this->cartKey($scope, $guestToken, $customerId, $cartType);
    }

    private function cartKey(
        ScopeIdentity $scope,
        ?string $guestToken,
        ?int $customerId,
        string $cartType = CommerceCartTypeRegistry::CODE_TOC,
    ): string {
        $cartType = strtolower(trim($cartType)) ?: CommerceCartTypeRegistry::CODE_TOC;
        $typeSuffix = '|type:' . $cartType;
        if ($customerId !== null && $customerId > 0) {
            return $scope->canonicalKey() . '|customer:' . $customerId . $typeSuffix;
        }
        $token = trim((string)$guestToken);
        if ($token === '') {
            throw new CartConflictException(self::ERROR_GUEST_TOKEN, __('游客加购需要 guest_token'));
        }
        return $scope->canonicalKey() . '|guest:' . $token . $typeSuffix;
    }

    private function legacyCartKey(ScopeIdentity $scope, ?string $guestToken, ?int $customerId): string
    {
        if ($customerId !== null && $customerId > 0) {
            return $scope->canonicalKey() . '|customer:' . $customerId;
        }
        $token = trim((string)$guestToken);
        if ($token === '') {
            throw new CartConflictException(self::ERROR_GUEST_TOKEN, __('游客加购需要 guest_token'));
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
     *   cart_type:string,
     *   items:list<array<string,mixed>>
     * }
     */
    private function newCart(
        ScopeIdentity $scope,
        ?string $guestToken,
        ?int $customerId,
        string $currency,
        string $cartType = CommerceCartTypeRegistry::CODE_TOC,
    ): array {
        $cartType = strtolower(trim($cartType)) ?: CommerceCartTypeRegistry::CODE_TOC;
        if ($customerId !== null && $customerId > 0) {
            return [
                'scope_key' => $scope->canonicalKey(),
                'currency' => $currency,
                'owner_kind' => self::OWNER_CUSTOMER,
                'owner_id' => (string)$customerId,
                'guest_token' => null,
                'cart_type' => $cartType,
                'items' => [],
            ];
        }
        return [
            'scope_key' => $scope->canonicalKey(),
            'currency' => $currency,
            'owner_kind' => self::OWNER_GUEST,
            'owner_id' => (string)$guestToken,
            'guest_token' => $guestToken,
            'cart_type' => $cartType,
            'items' => [],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function preferenceFromParams(array $params): ?string
    {
        foreach (['cart_type', 'selling_mode', 'sellingMode'] as $key) {
            if (!isset($params[$key])) {
                continue;
            }
            $value = strtolower(trim((string)$params[$key]));
            if ($value !== '') {
                return $value;
            }
        }

        $cookie = strtolower(trim((string)\Weline\Framework\Http\Cookie::get('weline_selling_mode')));
        if ($cookie === 'toc' || $cookie === 'tob') {
            return $cookie;
        }

        return null;
    }

    /**
     * @return array{code:string,type:\Weline\Cart\Api\CommerceCartTypeInterface}
     */
    private function resolveCartType(
        ?string $preference,
        ?int $customerId,
        ?ScopeIdentity $scope = null,
    ): array {
        $cid = $customerId !== null && $customerId > 0 ? $customerId : 0;

        return $this->sellingTypeResolver->resolve(
            $preference,
            $cid > 0,
            $cid,
            $scope?->websiteId ?? 0,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCart(
        ScopeIdentity $scope,
        ?string $guestToken,
        ?int $customerId,
        string $cartType,
        bool $createIfMissing = true,
    ): ?array {
        $key = $this->cartKey($scope, $guestToken, $customerId, $cartType);
        $cart = $this->store->get($key);
        if ($cart === null && $cartType === CommerceCartTypeRegistry::CODE_TOC) {
            $legacyKey = $this->legacyCartKey($scope, $guestToken, $customerId);
            $legacy = $this->store->get($legacyKey);
            if ($legacy !== null) {
                $legacy['cart_type'] = CommerceCartTypeRegistry::CODE_TOC;
                $this->store->set($key, $legacy);
                if ($legacyKey !== $key) {
                    $this->store->delete($legacyKey);
                }
                $cart = $legacy;
            }
        }
        if ($cart === null) {
            return $createIfMissing
                ? $this->newCart($scope, $guestToken, $customerId, '', $cartType)
                : null;
        }
        $existingType = strtolower(trim((string)($cart['cart_type'] ?? '')));
        if ($existingType === '') {
            $cart['cart_type'] = CommerceCartTypeRegistry::CODE_TOC;
            $existingType = CommerceCartTypeRegistry::CODE_TOC;
        }
        $this->assertCartTypeMatch($cart, $cartType);

        return $cart;
    }

    /**
     * @param array<string, mixed> $cart
     */
    private function assertCartTypeMatch(array $cart, string $expectedType): void
    {
        $actual = strtolower(trim((string)($cart['cart_type'] ?? CommerceCartTypeRegistry::CODE_TOC)));
        $expected = strtolower(trim($expectedType)) ?: CommerceCartTypeRegistry::CODE_TOC;
        if ($actual !== $expected) {
            throw new CartConflictException(
                self::ERROR_TYPE_MISMATCH,
                __('购物车售卖类型不匹配'),
                ['cart_type' => $actual, 'expected' => $expected],
            );
        }
    }

    private function assertQtyPolicy(
        string $cartType,
        int $qty,
        string $sku,
        ScopeIdentity $scope,
        ?int $customerId,
        int $productId = 0,
    ): void {
        $cartType = strtolower(trim($cartType));
        if ($cartType === '' || $cartType === CommerceCartTypeRegistry::CODE_TOC) {
            return;
        }

        try {
            $resolver = ObjectManager::getInstance(\Weline\Framework\Runtime\RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(\Weline\Cart\Api\CommerceCartQtyPolicyInterface::class);
        } catch (\Throwable) {
            return;
        }
        if ($resolution->status === \Weline\Framework\Runtime\RuntimeProviderResolution::NOT_CONFIGURED) {
            return;
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof \Weline\Cart\Api\CommerceCartQtyPolicyInterface
        ) {
            return;
        }

        $result = $resolution->provider->assertQty([
            'cart_type' => $cartType,
            'qty' => $qty,
            'sku' => $sku,
            'product_id' => $productId,
            'website_id' => (int)($scope->websiteId ?? 0),
            'store_code' => (string)($scope->storeCode ?? ''),
            'customer_id' => $customerId,
        ]);
        if (($result['ok'] ?? false) === true) {
            return;
        }

        throw new CartConflictException(
            (string)($result['error_code'] ?? 'cart_qty_policy_rejected'),
            (string)($result['message'] ?? __('购物车数量不符合规则')),
            is_array($result['detail'] ?? null) ? $result['detail'] : [],
        );
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
        $options = $this->normalizeOptions(
            $snapshot->options !== [] ? $snapshot->options : $this->optionsFromSelection($selection),
        );
        $line = [
            'item_id' => 'v2-' . substr($selectionHash, 0, 16),
            'selection_hash' => $selectionHash,
            'selection' => $selection,
            'options' => $options,
            'offer' => $snapshot->offer->toArray(),
            'name' => $snapshot->name,
            'sku' => $snapshot->sku,
            'image' => $snapshot->image,
            'currency' => $snapshot->currency,
            'unit_price_minor' => $snapshot->unitPriceMinor,
            'compare_at_minor' => max(0, $snapshot->compareAtMinor),
            'campaign_label' => trim($snapshot->campaignLabel),
            'campaign_url' => trim($snapshot->campaignUrl),
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
        if ($snapshot->fulfillmentMetadata !== []) {
            $line['fulfillment_metadata'] = $snapshot->fulfillmentMetadata;
        }
        return $line;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $extra
     */
    private function dispatchTypedCartEvent(string $eventName, array $summary, array $extra = []): void
    {
        try {
            /** @var EventsManager $events */
            $events = ObjectManager::getInstance(EventsManager::class);
        } catch (\Throwable) {
            return;
        }

        $payload = CartTypeEventEnvelope::append([
            'summary' => $summary,
            'cart' => $summary,
        ] + $extra, $summary, $this->typeRegistry);
        $events->dispatch($eventName, $payload);
    }

    /**
     * @param array<string, mixed> $cart
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function summary(
        array $cart,
        bool $success = true,
        string $message = '',
        array $extra = [],
        ?ScopeIdentity $scope = null,
    ): array {
        $cartType = strtolower(trim((string)($cart['cart_type'] ?? '')));
        if ($cartType === '') {
            $cartType = CommerceCartTypeRegistry::CODE_TOC;
        }
        $count = 0;
        $subtotal = 0;
        $items = [];
        $lineIssues = [];
        foreach ($cart['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item = $this->presentLine($item, $scope, $cartType);
            $count += (int)$item['qty'];
            $subtotal += (int)$item['row_total_minor'];
            $items[] = $item;
            if (empty($item['found']) || empty($item['sellable'])) {
                $lineIssues[] = [
                    'item_id' => (string)($item['item_id'] ?? ''),
                    'error_code' => empty($item['found']) ? self::ERROR_NOT_FOUND : self::ERROR_NOT_SELLABLE,
                    'message' => trim((string)($item['message'] ?? '')),
                ];
            }
        }
        $checkoutBlocked = $lineIssues !== [];
        $blockingMessage = '';
        if ($checkoutBlocked) {
            $blockingMessage = trim((string)($lineIssues[0]['message'] ?? ''));
            if ($blockingMessage === '') {
                $blockingMessage = (string)__('购物车中有不可结算的商品，请移除后重试');
            }
            if ($message === '') {
                $message = $blockingMessage;
            }
        }
        return [
            'success' => $success,
            'message' => $message,
            'scope_key' => $cart['scope_key'],
            'currency' => $this->presentationCurrency($cart, $items),
            'owner_kind' => $cart['owner_kind'],
            'owner_id' => $cart['owner_id'],
            'guest_token' => $cart['guest_token'],
            'cart_type' => $cartType,
            'type_payload' => $this->typePayload($cartType),
            'items' => $items,
            'cart_count' => $count,
            'item_count' => $count,
            'distinct_count' => count($items),
            'subtotal_minor' => $subtotal,
            'grand_total_minor' => $subtotal,
            'is_empty' => $items === [],
            'checkout_blocked' => $checkoutBlocked,
            'line_issues' => $lineIssues,
            'blocking_message' => $blockingMessage,
        ] + $extra;
    }

    /**
     * Storefront presentation currency: prefer the active request display currency
     * so switching USD/EUR does not keep labeling prices as the cart's add-time CNY.
     *
     * @param array<string, mixed> $cart
     * @param list<array<string, mixed>> $items
     */
    private function presentationCurrency(array $cart, array $items): string
    {
        $display = strtoupper(trim(RequestContext::getWelineUserCurrency()));
        if ($display !== '') {
            return $display;
        }
        foreach ($items as $item) {
            $lineCurrency = strtoupper(trim((string)($item['currency'] ?? '')));
            if ($lineCurrency !== '') {
                return $lineCurrency;
            }
        }
        $cartCurrency = strtoupper(trim((string)($cart['currency'] ?? '')));

        return $cartCurrency !== '' ? $cartCurrency : 'CNY';
    }

    /**
     * Convert cart line minors between currencies for storefront presentation.
     * Returns null when FX is unavailable so callers do not relabel the currency.
     */
    private function convertPriceMinor(int $minor, string $fromCurrency, string $toCurrency): ?int
    {
        $from = strtoupper(trim($fromCurrency)) ?: 'CNY';
        $to = strtoupper(trim($toCurrency)) ?: $from;
        if ($minor === 0 || $from === $to) {
            return max(0, $minor);
        }
        try {
            /** @var \Weline\Currency\Service\CurrencyRateService $rates */
            $rates = ObjectManager::getInstance(\Weline\Currency\Service\CurrencyRateService::class);
            $major = $rates->tryConvert($minor / 100.0, $from, $to);
            if ($major === null) {
                return null;
            }

            return max(0, (int)round($major * 100));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{discounts_applied:bool,extras:array<string,mixed>}
     */
    private function typePayload(string $cartType): array
    {
        $type = $this->typeRegistry->get($cartType)
            ?? $this->typeRegistry->require(CommerceCartTypeRegistry::CODE_TOC);

        return [
            'discounts_applied' => !$type->disablesStorefrontDiscounts(),
            'extras' => [],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function presentLine(
        array $item,
        ?ScopeIdentity $scope,
        string $cartType = CommerceCartTypeRegistry::CODE_TOC,
    ): array {
        $item['cart_type'] = $cartType;
        $item['image'] = $this->presentableImage(trim((string)($item['image'] ?? '')));
        $selection = is_array($item['selection'] ?? null) ? $item['selection'] : [];
        $existingOptions = $this->normalizeOptions(is_array($item['options'] ?? null) ? $item['options'] : []);

        // One snapshot resolve on every cart refresh/update presentation so stale
        // Offer rows surface before checkout submit.
        $snapshot = null;
        if ($scope !== null && is_array($item['offer'] ?? null)) {
            try {
                $snapshot = $this->registry->resolve(
                    OfferIdentity::fromArray($item['offer']),
                    $scope,
                    CartSelectionHash::normalizeSelection($selection),
                );
            } catch (\Throwable) {
                $snapshot = null;
            }
        }

        if ($snapshot === null) {
            $item['found'] = false;
            $item['sellable'] = false;
            $item['message'] = (string)__('该商品已不存在或已下架，请从购物车移除');
            $item['options'] = $this->presentLineOptions($item, $scope);
            return $item;
        }

        $item['found'] = $snapshot->found;
        $item['sellable'] = $snapshot->found && $snapshot->sellable;
        $item['message'] = ($snapshot->found && $snapshot->sellable)
            ? ''
            : $this->humanizeSnapshotMessage($snapshot);

        if ($snapshot->found) {
            $fromSnapshot = $this->normalizeOptions($snapshot->options);
            $item['options'] = $fromSnapshot !== [] ? $fromSnapshot : (
                $existingOptions !== [] ? $existingOptions : $this->optionsFromSelection($selection)
            );
            $preserveWholesale = $cartType === 'tob'
                && (
                    array_key_exists('b2b_amount_minor', $item)
                    || trim((string)($item['b2b_price_list_id'] ?? '')) !== ''
                );
            if ($snapshot->sellable && $snapshot->unitPriceMinor >= 0) {
                $qty = max(0, (int)($item['qty'] ?? 0));
                $displayCurrency = strtoupper(trim(RequestContext::getWelineUserCurrency()));
                if (!$preserveWholesale) {
                    $item['unit_price_minor'] = $snapshot->unitPriceMinor;
                    $item['row_total_minor'] = $qty * $snapshot->unitPriceMinor;
                    $item['compare_at_minor'] = max(0, $snapshot->compareAtMinor);
                    $item['campaign_label'] = trim($snapshot->campaignLabel);
                    $item['campaign_url'] = trim($snapshot->campaignUrl);
                    $item['original_price'] = round($item['compare_at_minor'] / 100, 2);
                    $item['has_deal'] = $item['compare_at_minor'] > $snapshot->unitPriceMinor
                        && $snapshot->unitPriceMinor > 0;
                    $item['price'] = round($snapshot->unitPriceMinor / 100, 2);
                    $item['row_total'] = round(((int)$item['row_total_minor']) / 100, 2);
                    if ($snapshot->currency !== '') {
                        $item['currency'] = $snapshot->currency;
                    }
                } else {
                    $fromCurrency = strtoupper(trim((string)($item['currency'] ?? '')));
                    if ($fromCurrency === '') {
                        $fromCurrency = 'CNY';
                    }
                    $unit = (int)($item['unit_price_minor'] ?? 0);
                    if ($displayCurrency !== '' && $displayCurrency !== $fromCurrency) {
                        $convertedUnit = $this->convertPriceMinor($unit, $fromCurrency, $displayCurrency);
                        if ($convertedUnit !== null) {
                            $unit = $convertedUnit;
                            $item['currency'] = $displayCurrency;
                        }
                    } elseif ($displayCurrency !== '' && $displayCurrency === $fromCurrency) {
                        $item['currency'] = $displayCurrency;
                    }
                    $item['unit_price_minor'] = $unit;
                    $item['row_total_minor'] = $qty * $unit;
                    $item['price'] = round($unit / 100, 2);
                    $item['row_total'] = round(((int)$item['row_total_minor']) / 100, 2);
                }
                // Keep the currency that matches the amount. Never stamp display
                // currency onto unconverted minors (symbol-only switch).
                $lineCurrency = strtoupper(trim((string)($item['currency'] ?? '')));
                if ($displayCurrency !== '' && $lineCurrency === $displayCurrency) {
                    $item['currency'] = $displayCurrency;
                } elseif ($lineCurrency === '' && $snapshot->currency !== '') {
                    $item['currency'] = $snapshot->currency;
                }
                if ($snapshot->name !== '') {
                    $item['name'] = $snapshot->name;
                }
                if ($snapshot->image !== '') {
                    $item['image'] = $this->presentableImage($snapshot->image);
                }
                if ($snapshot->stock !== null) {
                    $item['stock'] = $snapshot->stock;
                }
            }
        } else {
            $item['options'] = $this->presentLineOptions($item, $scope);
        }

        return $item;
    }

    private function humanizeSnapshotMessage(CartItemSnapshot $snapshot): string
    {
        $message = trim($snapshot->message);
        if ($message === '' || stripos($message, 'Offer') !== false) {
            if (!$snapshot->found) {
                return (string)__('该商品已不存在或已下架，请从购物车移除');
            }

            return (string)__('该商品暂不可售，请从购物车移除或稍后再试');
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{code:string,label:string,value:string,value_label:string}>
     */
    private function presentLineOptions(array $item, ?ScopeIdentity $scope): array
    {
        $existing = $this->normalizeOptions(is_array($item['options'] ?? null) ? $item['options'] : []);
        $selection = is_array($item['selection'] ?? null) ? $item['selection'] : [];
        if ($selection === []) {
            return $existing;
        }

        // Prefer fresh catalog options so private-option swatches and labels stay
        // aligned with the PDP even for cart lines persisted before swatch fields.
        if ($scope !== null && is_array($item['offer'] ?? null)) {
            try {
                $snapshot = $this->registry->resolve(
                    OfferIdentity::fromArray($item['offer']),
                    $scope,
                    CartSelectionHash::normalizeSelection($selection),
                );
                $fromSnapshot = $this->normalizeOptions($snapshot->options);
                if ($fromSnapshot !== []) {
                    return $fromSnapshot;
                }
            } catch (\Throwable) {
                // Fall through to persisted / selection-code presentation.
            }
        }

        if ($existing !== []) {
            return $existing;
        }

        return $this->optionsFromSelection($selection);
    }

    /**
     * @param array<string, scalar|null> $selection
     * @return list<array{code:string,label:string,value:string,value_label:string}>
     */
    private function optionsFromSelection(array $selection): array
    {
        $options = [];
        foreach (CartSelectionHash::normalizeSelection($selection) as $code => $value) {
            $code = trim((string)$code);
            $value = trim((string)$value);
            if ($code === '' || $value === '') {
                continue;
            }
            if (class_exists(\Weline\Product\Helper\StorefrontCampaignEntry::class)
                && \Weline\Product\Helper\StorefrontCampaignEntry::isThemeSelectionCode($code)
            ) {
                $themeOption = \Weline\Product\Helper\StorefrontCampaignEntry::presentThemeOption($code, $value);
                if ($themeOption !== null) {
                    $options[] = $themeOption;
                }
                continue;
            }
            $options[] = [
                'code' => $code,
                'label' => $code,
                'value' => $value,
                'value_label' => $value,
            ];
        }

        return $options;
    }

    /**
     * @param list<mixed>|array<int|string, mixed> $options
     * @return list<array{code:string,label:string,value:string,value_label:string,swatch_image?:string,swatch_color?:string}>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $code = trim((string)($option['code'] ?? ''));
            $value = trim((string)($option['value'] ?? ''));
            if ($code === '' || $value === '') {
                continue;
            }
            if (class_exists(\Weline\Product\Helper\StorefrontCampaignEntry::class)
                && \Weline\Product\Helper\StorefrontCampaignEntry::isThemeSelectionCode($code)
            ) {
                $hint = trim((string)($option['value_label'] ?? ''));
                if ($hint === $value || $hint === $code) {
                    $hint = '';
                }
                $themeOption = \Weline\Product\Helper\StorefrontCampaignEntry::presentThemeOption($code, $value, $hint);
                if ($themeOption !== null) {
                    $normalized[] = $themeOption;
                }
                continue;
            }
            $label = trim((string)($option['label'] ?? ''));
            $valueLabel = trim((string)($option['value_label'] ?? ''));
            if ($valueLabel === '' || preg_match('/%[0-9A-Fa-f]{2}/', $valueLabel) === 1) {
                $valueLabel = \Weline\Product\Service\StorefrontEavLabelResolver::displayOptionToken(
                    $valueLabel !== '' ? $valueLabel : $value,
                );
            }
            if (preg_match('/%[0-9A-Fa-f]{2}/', $value) === 1
                && class_exists(\Weline\Product\Service\StorefrontEavLabelResolver::class)
            ) {
                // Keep stored selection identity; only beautify display label.
                $decodedValue = \Weline\Product\Service\StorefrontEavLabelResolver::displayOptionToken($value);
                if ($valueLabel === '' || $valueLabel === $value) {
                    $valueLabel = $decodedValue;
                }
            }
            $row = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
                'value' => $value,
                'value_label' => $valueLabel !== '' ? $valueLabel : $value,
            ];
            $swatchImage = trim((string)($option['swatch_image'] ?? ''));
            if ($swatchImage !== ''
                && !str_starts_with(strtolower($swatchImage), 'asset://')
                && !(
                    preg_match('#^[a-z][a-z0-9+.-]*:#i', $swatchImage) === 1
                    && preg_match('#^(https?:)?//#i', $swatchImage) !== 1
                )
            ) {
                $row['swatch_image'] = $swatchImage;
            }
            $swatchColor = trim((string)($option['swatch_color'] ?? ''));
            if ($swatchColor !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $swatchColor) === 1) {
                $row['swatch_color'] = $swatchColor;
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Storefront / mini-cart set img.src from this field — never emit FileManager asset://
     * or other non-browser-displayable schemes from persisted cart lines.
     */
    private function presentableImage(string $image): string
    {
        if ($image === '') {
            return '';
        }
        if (str_starts_with(strtolower($image), 'asset://')) {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $image) === 1
            && preg_match('#^(https?:)?//#i', $image) !== 1
        ) {
            return '';
        }

        return $image;
    }
}
