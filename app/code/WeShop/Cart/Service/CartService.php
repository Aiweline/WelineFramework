<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use Weline\Cart\Api\CartTrashInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Model\Cart;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\Product\OptionId as ProductOptionId;

class CartService implements CartTrashInterface
{
    public function __construct(
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function getCartItems(int $customerId): array
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $items = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_IS_TRASHED, 0)
            ->select()
            ->fetchArray();

        return $this->attachProductsToItems(\is_array($items) ? $items : []);
    }

    /**
     * @return array{subtotal: float, tax: float|int, shipping: float|int, discount: float|int, total: float}
     */
    public function calculateTotals(int $customerId): array
    {
        $items = $this->getCartItems($customerId);

        $subtotal = 0.0;
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $price = (float) ($item[Cart::schema_fields_PRICE] ?? 0);
            $quantity = (int) ($item[Cart::schema_fields_QUANTITY] ?? 1);
            $subtotal += $price * $quantity;
        }

        $totals = [
            'subtotal' => $subtotal,
            'tax' => 0,
            'shipping' => 0,
            'discount' => 0,
            'total' => $subtotal,
        ];

        $eventData = [
            'customer_id' => $customerId,
            'items' => $items,
            'totals' => &$totals,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::totals_collect', $eventData);

        $totals['total'] = $totals['subtotal']
            + $totals['tax']
            + $totals['shipping']
            - $totals['discount'];

        $eventData = [
            'customer_id' => $customerId,
            'totals' => $totals,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::totals_collected', $eventData);

        return $totals;
    }

    public function addToCart(int $customerId, int $productId, int $quantity = 1, ?float $price = null): Cart
    {
        $eventData = [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::add_to_cart_before', $eventData);

        $productId = $this->resolveActiveProductId($productId, $price);
        $productSnapshot = $this->loadProductSnapshot($productId);
        if ($productSnapshot === [] || !$this->isProductEnabled($productSnapshot)) {
            throw new \Exception((string) __('Product does not exist or is unavailable.'));
        }
        if ($price === null) {
            $price = (float) ($productSnapshot['price'] ?? 0);
        }

        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $existing = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            $newQuantity = (int) $existing->getData(Cart::schema_fields_QUANTITY) + $quantity;
            $existing->setData(Cart::schema_fields_QUANTITY, $newQuantity)
                ->setData(Cart::schema_fields_IS_TRASHED, 0)
                ->setData(Cart::schema_fields_TRASHED_AT, null)
                ->setData(Cart::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
            $this->applyProductSnapshot($existing, $productSnapshot);
            $existing->save();
            $cart = $existing;
        } else {
            $cart->clearData()
                ->setData(Cart::schema_fields_CUSTOMER_ID, $customerId)
                ->setData(Cart::schema_fields_PRODUCT_ID, $productId)
                ->setData(Cart::schema_fields_QUANTITY, $quantity)
                ->setData(Cart::schema_fields_PRICE, $price)
                ->setData(Cart::schema_fields_IS_TRASHED, 0)
                ->setData(Cart::schema_fields_TRASHED_AT, null)
                ->setData(Cart::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                ->setData(Cart::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
            $this->applyProductSnapshot($cart, $productSnapshot);
            $saveResult = $cart->save();

            if (!$cart->getId() && \is_numeric($saveResult) && (int) $saveResult > 0) {
                $cart->setId((int) $saveResult);
            }

            if (!$cart->getId()) {
                $persistedRow = $this->findCartItemRow($customerId, $productId);
                $persistedId = \is_array($persistedRow) ? (int) ($persistedRow[Cart::schema_fields_ID] ?? 0) : 0;
                if ($persistedId > 0) {
                    $cart->setId($persistedId);
                    foreach ($persistedRow as $field => $value) {
                        $cart->setData($field, $value);
                    }
                }
            }
        }

        $eventData = [
            'cart' => $cart,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::add_to_cart_after', $eventData);

        return $cart;
    }

    public function updateCart(int $cartId, int $quantity, int $customerId = 0): bool
    {
        if ($quantity <= 0) {
            return $this->removeFromCart($cartId, $customerId);
        }

        $eventData = [
            'cart_id' => $cartId,
            'quantity' => $quantity,
            'customer_id' => $customerId,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::update_cart_before', $eventData);

        $cart = $this->loadOwnedCart($cartId, $customerId, '更新');
        if ((int) $cart->getData(Cart::schema_fields_IS_TRASHED) === 1) {
            throw new \Exception(__('购物车项目已在垃圾箱中'));
        }

        $cart->setData(Cart::schema_fields_QUANTITY, $quantity)
            ->setData(Cart::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $eventData = [
            'cart' => $cart,
            'cart_id' => $cartId,
            'quantity' => $quantity,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::update_cart_after', $eventData);

        return true;
    }

    public function removeFromCart(int $cartId, int $customerId = 0): bool
    {
        $eventData = [
            'cart_id' => $cartId,
            'customer_id' => $customerId,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::remove_from_cart_before', $eventData);

        $cart = $this->loadOwnedCart($cartId, $customerId, '移除');
        if ((int) $cart->getData(Cart::schema_fields_IS_TRASHED) !== 1) {
            $now = date('Y-m-d H:i:s');
            $cart->setData(Cart::schema_fields_IS_TRASHED, 1)
                ->setData(Cart::schema_fields_TRASHED_AT, $now)
                ->setData(Cart::schema_fields_UPDATED_AT, $now)
                ->save();
        }

        $eventData = [
            'cart_id' => $cartId,
            'customer_id' => $customerId,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::remove_from_cart_after', $eventData);

        return true;
    }

    public function moveToTrash(int $cartId, int $customerId = 0): bool
    {
        return $this->removeFromCart($cartId, $customerId);
    }

    public function restoreFromTrash(int $cartId, int $customerId = 0): bool
    {
        $cart = $this->loadOwnedCart($cartId, $customerId, '恢复');
        if ((int) $cart->getData(Cart::schema_fields_IS_TRASHED) !== 1) {
            return true;
        }

        $cart->setData(Cart::schema_fields_IS_TRASHED, 0)
            ->setData(Cart::schema_fields_TRASHED_AT, null)
            ->setData(Cart::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return true;
    }

    /**
     * @return array<int, mixed>
     */
    public function getTrashItems(int $customerId, int $limit = 10): array
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $query = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_IS_TRASHED, 1)
            ->order(Cart::schema_fields_TRASHED_AT, 'DESC');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $items = $query->select()->fetchArray();

        return $this->attachProductsToItems(\is_array($items) ? $items : []);
    }

    public function getTrashItemCount(int $customerId): int
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $items = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_IS_TRASHED, 1)
            ->select()
            ->fetchArray();

        return \is_array($items) ? \count($items) : 0;
    }

    public function clearCart(int $customerId): bool
    {
        $eventData = [
            'customer_id' => $customerId,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::clear_before', $eventData);

        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->delete();

        $eventData = [
            'customer_id' => $customerId,
        ];
        $this->getEventsManager()->dispatch('WeShop_Cart::clear_after', $eventData);

        return true;
    }

    public function getCartItemCount(int $customerId): int
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $items = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_IS_TRASHED, 0)
            ->select()
            ->fetchArray();

        $count = 0;
        foreach (\is_array($items) ? $items : [] as $item) {
            $count += (int) ($item[Cart::schema_fields_QUANTITY] ?? 1);
        }

        return $count;
    }

    public function findCartItemId(int $customerId, int $productId): int
    {
        $row = $this->findCartItemRow($customerId, $productId);

        return \is_array($row) ? (int) ($row[Cart::schema_fields_ID] ?? 0) : 0;
    }

    protected function getEventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function findCartItemRow(int $customerId, int $productId): array
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $row = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetchArray();

        return \is_array($row) ? $row : [];
    }

    private function loadOwnedCart(int $cartId, int $customerId, string $actionLabel): Cart
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        $cart->load($cartId);

        if (!$cart->getId()) {
            throw new \Exception(__('购物车项目不存在'));
        }

        if ($customerId > 0 && (int) $cart->getData(Cart::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('无权%{1}此购物车项目', $actionLabel));
        }

        return $cart;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    private function attachProductsToItems(array $items): array
    {
        $productIds = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $productId = (int) ($item[Cart::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }

        $products = [];
        if ($productIds !== []) {
            $productRows = w_query('product', 'getProductByIds', [
                'product_ids' => \array_values(\array_unique($productIds)),
            ]);
            if (\is_array($productRows)) {
                foreach ($productRows as $product) {
                    $productId = (int) ($product['product_id'] ?? 0);
                    if ($productId > 0) {
                        $products[$productId] = $product;
                    }
                }
            }
        }

        $items = $this->repairMissingProductItems($items, $products);

        foreach ($items as &$item) {
            if (!\is_array($item)) {
                continue;
            }

            $productId = (int) ($item[Cart::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId > 0 && isset($products[$productId])) {
                $item['product'] = $products[$productId];
                $item['original_price'] = (float) ($products[$productId]['original_price'] ?? $item['original_price'] ?? $item[Cart::schema_fields_PRICE] ?? 0);
                $item['special_price'] = $products[$productId]['special_price'] ?? null;
                $item['has_discount'] = (bool) ($products[$productId]['has_discount'] ?? false);
                $item['discount_amount'] = (float) ($products[$productId]['discount_amount'] ?? 0);
                $item['discount_percent'] = (int) ($products[$productId]['discount_percent'] ?? 0);
            }
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<int, mixed> $items
     * @param array<int, array<string, mixed>> $products
     * @return array<int, mixed>
     */
    private function repairMissingProductItems(array $items, array &$products): array
    {
        foreach ($items as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $productId = (int) ($item[Cart::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId <= 0 || isset($products[$productId])) {
                continue;
            }

            $replacementProduct = $this->resolveReplacementProductSnapshot(
                $productId,
                (float) ($item[Cart::schema_fields_PRICE] ?? 0)
            );
            if ($replacementProduct === []) {
                continue;
            }

            $replacementId = (int) ($replacementProduct['product_id'] ?? 0);
            if ($replacementId <= 0) {
                continue;
            }

            $products[$replacementId] = $replacementProduct;
            $products[$productId] = $replacementProduct;
            $items[$index] = $this->rebaseCartItemProduct($item, $replacementProduct);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $replacementProduct
     * @return array<string, mixed>
     */
    private function rebaseCartItemProduct(array $item, array $replacementProduct): array
    {
        $cartId = (int) ($item[Cart::schema_fields_ID] ?? $item['cart_id'] ?? 0);
        $customerId = (int) ($item[Cart::schema_fields_CUSTOMER_ID] ?? 0);
        $replacementId = (int) ($replacementProduct['product_id'] ?? 0);
        if ($cartId <= 0 || $customerId <= 0 || $replacementId <= 0) {
            return $this->applyProductSnapshotToItem($item, $replacementProduct);
        }

        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        $existing = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_PRODUCT_ID, $replacementId)
            ->find()
            ->fetch();

        $currentQty = (int) ($item[Cart::schema_fields_QUANTITY] ?? $item['quantity'] ?? 1);
        if ($existing && $existing->getId() && (int) $existing->getId() !== $cartId) {
            $newQuantity = (int) $existing->getData(Cart::schema_fields_QUANTITY) + $currentQty;
            $existing->setData(Cart::schema_fields_QUANTITY, $newQuantity)
                ->setData(Cart::schema_fields_IS_TRASHED, 0)
                ->setData(Cart::schema_fields_TRASHED_AT, null)
                ->setData(Cart::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
            $this->applyProductSnapshot($existing, $replacementProduct);
            $existing->save();

            /** @var Cart $oldCart */
            $oldCart = ObjectManager::getInstance(Cart::class);
            $oldCart->clear()
                ->where(Cart::schema_fields_ID, $cartId)
                ->delete();

            $item[Cart::schema_fields_ID] = (int) $existing->getId();
            $item[Cart::schema_fields_QUANTITY] = $newQuantity;
        } else {
            /** @var Cart $currentCart */
            $currentCart = ObjectManager::getInstance(Cart::class);
            $currentCart->load($cartId);
            if ($currentCart->getId()) {
                $currentCart
                    ->setData(Cart::schema_fields_PRODUCT_ID, $replacementId)
                    ->setData(Cart::schema_fields_IS_TRASHED, 0)
                    ->setData(Cart::schema_fields_TRASHED_AT, null)
                    ->setData(Cart::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
                $this->applyProductSnapshot($currentCart, $replacementProduct);
                $currentCart->save();
            }
        }

        $item[Cart::schema_fields_PRODUCT_ID] = $replacementId;
        $item['product_id'] = $replacementId;

        return $this->applyProductSnapshotToItem($item, $replacementProduct);
    }

    private function resolveActiveProductId(int $productId, ?float $expectedPrice = null): int
    {
        $product = $this->loadProductSnapshot($productId);
        if ($product !== [] && $this->isProductEnabled($product)) {
            return $productId;
        }

        $replacementId = $this->resolveReplacementProductId($productId, $expectedPrice);
        return $replacementId > 0 ? $replacementId : $productId;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveReplacementProductSnapshot(int $productId, ?float $expectedPrice = null): array
    {
        $replacementId = $this->resolveReplacementProductId($productId, $expectedPrice);
        if ($replacementId <= 0 || $replacementId === $productId) {
            return [];
        }

        return $this->loadProductSnapshot($replacementId);
    }

    private function resolveReplacementProductId(int $productId, ?float $expectedPrice = null): int
    {
        $signature = $this->getProductOptionSignature($productId);
        if (($signature['signature'] ?? '') === '' || ($signature['option_ids'] ?? []) === []) {
            return 0;
        }

        /** @var ProductOptionId $optionModel */
        $optionModel = ObjectManager::getInstance(ProductOptionId::class);
        $query = $optionModel->clear()
            ->where(ProductOptionId::schema_fields_OPTION_ID, $signature['option_ids'], 'in');
        if ((int) ($signature['parent_product_id'] ?? 0) > 0) {
            $query->where(ProductOptionId::schema_fields_PARENT_PRODUCT_ID, (int) $signature['parent_product_id']);
        }

        $rows = $query->select()->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return 0;
        }

        $candidateParts = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $candidateProductId = (int) ($row[ProductOptionId::schema_fields_PRODUCT_ID] ?? 0);
            if ($candidateProductId <= 0 || $candidateProductId === $productId) {
                continue;
            }

            $candidateParts[$candidateProductId][] = $this->optionSignaturePart($row);
        }

        $candidateIds = $this->filterCandidateProductIdsBySignature(
            \array_map('intval', \array_keys($candidateParts)),
            (string) $signature['signature']
        );

        if ($candidateIds === []) {
            return 0;
        }

        return $this->chooseReplacementProductId($candidateIds, $expectedPrice);
    }

    /**
     * @param array<int, int> $candidateIds
     * @return array<int, int>
     */
    private function filterCandidateProductIdsBySignature(array $candidateIds, string $expectedSignature): array
    {
        if ($candidateIds === [] || $expectedSignature === '') {
            return [];
        }

        /** @var ProductOptionId $optionModel */
        $optionModel = ObjectManager::getInstance(ProductOptionId::class);
        $rows = $optionModel->clear()
            ->where(ProductOptionId::schema_fields_PRODUCT_ID, \array_values(\array_unique($candidateIds)), 'in')
            ->select()
            ->fetchArray();

        $fullParts = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $candidateProductId = (int) ($row[ProductOptionId::schema_fields_PRODUCT_ID] ?? 0);
            if ($candidateProductId > 0) {
                $fullParts[$candidateProductId][] = $this->optionSignaturePart($row);
            }
        }

        $matchedIds = [];
        foreach ($fullParts as $candidateProductId => $parts) {
            $parts = \array_values(\array_unique(\array_filter($parts)));
            \sort($parts, \SORT_STRING);
            if (\implode('|', $parts) === $expectedSignature) {
                $matchedIds[] = (int) $candidateProductId;
            }
        }

        return $matchedIds;
    }

    /**
     * @param array<int, int> $candidateIds
     */
    private function chooseReplacementProductId(array $candidateIds, ?float $expectedPrice = null): int
    {
        $productRows = w_query('product', 'getProductByIds', [
            'product_ids' => \array_values(\array_unique($candidateIds)),
        ]);
        if (!\is_array($productRows) || $productRows === []) {
            return 0;
        }

        $firstEnabledId = 0;
        foreach ($productRows as $product) {
            if (!\is_array($product) || !$this->isProductEnabled($product)) {
                continue;
            }

            $productId = (int) ($product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            if ($firstEnabledId === 0) {
                $firstEnabledId = $productId;
            }

            if ($expectedPrice !== null && $expectedPrice > 0) {
                $price = (float) ($product['price'] ?? 0);
                if (\abs($price - $expectedPrice) < 0.01) {
                    return $productId;
                }
            }
        }

        return $firstEnabledId;
    }

    /**
     * @return array{parent_product_id: int, option_ids: array<int, int>, signature: string}
     */
    private function getProductOptionSignature(int $productId): array
    {
        /** @var ProductOptionId $optionModel */
        $optionModel = ObjectManager::getInstance(ProductOptionId::class);
        $rows = $optionModel->clear()
            ->where(ProductOptionId::schema_fields_PRODUCT_ID, $productId)
            ->select()
            ->fetchArray();

        $parts = [];
        $optionIds = [];
        $parentProductId = 0;
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ($parentProductId <= 0) {
                $parentProductId = (int) ($row[ProductOptionId::schema_fields_PARENT_PRODUCT_ID] ?? 0);
            }

            $part = $this->optionSignaturePart($row);
            if ($part !== '') {
                $parts[] = $part;
            }

            $optionId = (int) ($row[ProductOptionId::schema_fields_OPTION_ID] ?? 0);
            if ($optionId > 0) {
                $optionIds[] = $optionId;
            }
        }

        $parts = \array_values(\array_unique(\array_filter($parts)));
        \sort($parts, \SORT_STRING);

        return [
            'parent_product_id' => $parentProductId,
            'option_ids' => \array_values(\array_unique($optionIds)),
            'signature' => \implode('|', $parts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function optionSignaturePart(array $row): string
    {
        $attributeId = (int) ($row[ProductOptionId::schema_fields_ATTRIBUTE_ID] ?? 0);
        $optionId = (int) ($row[ProductOptionId::schema_fields_OPTION_ID] ?? 0);
        if ($attributeId <= 0 || $optionId <= 0) {
            return '';
        }

        return $attributeId . ':' . $optionId;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProductSnapshot(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        try {
            $product = w_query('product', 'getProductById', [
                'product_id' => $productId,
            ]);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($product) ? $product : [];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function isProductEnabled(array $product): bool
    {
        $status = $product['status'] ?? $product[Product::schema_fields_status] ?? null;

        return $status === 1 || $status === '1' || $status === true || $status === 'enabled';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function applyProductSnapshot(Cart $cart, array $product): Cart
    {
        return $cart
            ->setData(Cart::schema_fields_PRODUCT_NAME, (string) ($product['name'] ?? ''))
            ->setData(Cart::schema_fields_PRODUCT_IMAGE, (string) ($product['image'] ?? ''))
            ->setData(Cart::schema_fields_PRODUCT_SKU, (string) ($product['sku'] ?? ''));
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function applyProductSnapshotToItem(array $item, array $product): array
    {
        $item[Cart::schema_fields_PRODUCT_NAME] = (string) ($product['name'] ?? '');
        $item[Cart::schema_fields_PRODUCT_IMAGE] = (string) ($product['image'] ?? '');
        $item[Cart::schema_fields_PRODUCT_SKU] = (string) ($product['sku'] ?? '');
        $item['product'] = $product;

        return $item;
    }
}
