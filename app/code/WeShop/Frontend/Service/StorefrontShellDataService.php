<?php

declare(strict_types=1);

namespace WeShop\Frontend\Service;

use WeShop\Cart\Model\Cart;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Store\Model\Store;
use WeShop\Store\Service\StoreContextService;

class StorefrontShellDataService
{
    public function __construct(
        private readonly StoreContextService $storeContextService,
        private readonly CustomerContextInterface $customerContext,
        private readonly CartService $cartService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $store = $this->resolvePrimaryStore();
        $cartSummary = $this->buildCartSummary();

        return [
            'store_name' => $this->resolveStoreName($store),
            'store_currency' => $this->resolveStoreCurrency($store),
            'cart_count' => $cartSummary['cart_count'],
            'cart_total' => $cartSummary['cart_total'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePrimaryStore(): ?array
    {
        return $this->storeContextService->getCurrentStore();
    }

    private function resolveStoreName(?array $store): string
    {
        $name = trim((string) ($store[Store::schema_fields_NAME] ?? $store['name'] ?? ''));
        return $name !== '' ? $name : (string) __('WeShop');
    }

    private function resolveStoreCurrency(?array $store): string
    {
        $currency = strtoupper(trim((string) ($store[Store::schema_fields_CURRENCY] ?? $store['currency'] ?? '')));
        return $currency !== '' ? $currency : 'USD';
    }

    /**
     * @return array{cart_count:int,cart_total:float}
     */
    private function buildCartSummary(): array
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return [
                'cart_count' => 0,
                'cart_total' => 0.0,
            ];
        }

        $items = $this->cartService->getCartItems($customerId);
        $cartCount = 0;
        $cartTotal = 0.0;

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $quantity = (int) ($item[Cart::schema_fields_QUANTITY] ?? $item['quantity'] ?? $item['qty'] ?? 0);
            $price = (float) ($item[Cart::schema_fields_PRICE] ?? $item['price'] ?? 0);

            $cartCount += $quantity;
            $cartTotal += $price * $quantity;
        }

        return [
            'cart_count' => $cartCount,
            'cart_total' => $cartTotal,
        ];
    }
}
