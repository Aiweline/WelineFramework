<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use WeShop\Address\Service\AddressService;
use WeShop\Cart\Service\CartService;
use WeShop\Shipping\Service\ShippingService;
use Weline\I18n\Model\I18n;

class CheckoutPageDataService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AddressService $addressService,
        private readonly ShippingService $shippingService,
        private readonly CheckoutService $checkoutService,
        private readonly I18n $i18n
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $currentStep = 1): array
    {
        $items = $this->cartService->getCartItems($customerId);
        $cartItems = $this->mapCartItems($items);
        $totals = $this->cartService->calculateTotals($customerId);
        $savedAddresses = $this->mapSavedAddresses($this->addressService->getCustomerAddresses($customerId));
        $itemCount = array_reduce(
            $cartItems,
            static fn(int $count, array $item): int => $count + (int) ($item['qty'] ?? 0),
            0
        );

        return [
            'cart_items' => $cartItems,
            'cart_count' => $itemCount,
            'item_count' => $itemCount,
            'cart_total' => (float) ($totals['subtotal'] ?? 0),
            'shipping' => (float) ($totals['shipping'] ?? 0),
            'tax' => (float) ($totals['tax'] ?? 0),
            'cart_summary' => [
                'subtotal' => (float) ($totals['subtotal'] ?? 0),
                'shipping' => (float) ($totals['shipping'] ?? 0),
                'discount' => (float) ($totals['discount'] ?? 0),
                'tax' => (float) ($totals['tax'] ?? 0),
                'grand_total' => (float) ($totals['total'] ?? 0),
            ],
            'saved_addresses' => $savedAddresses,
            'shipping_addresses' => $savedAddresses,
            'shipping_methods' => $this->mapShippingMethods($this->shippingService->getAvailableShippingMethods()),
            'payment_methods' => $this->checkoutService->getCheckoutPaymentMethods($customerId, [
                'area' => 'frontend',
                'currency' => 'USD',
            ]),
            'current_step' => max(1, min(3, $currentStep)),
            'countries' => $this->buildCountries(),
            'states' => [],
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapCartItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $product = \is_array($item['product'] ?? null) ? $item['product'] : [];
            $qty = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            $result[] = [
                'item_id' => (int) ($item['item_id'] ?? $item['cart_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => (string) ($product['name'] ?? $item['product_name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item['image'] ?? ''),
                'price' => $price,
                'qty' => $qty,
                'row_total' => $price * $qty,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $addresses
     * @return array<int, array<string, mixed>>
     */
    protected function mapSavedAddresses(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $address) {
            if (!\is_array($address)) {
                continue;
            }

            $firstName = (string) ($address['firstname'] ?? '');
            $lastName = (string) ($address['lastname'] ?? '');
            $result[] = [
                'address_id' => (int) ($address['address_id'] ?? 0),
                'name' => trim($firstName . ' ' . $lastName),
                'firstname' => $firstName,
                'lastname' => $lastName,
                'street' => (string) ($address['street'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'state' => (string) ($address['region'] ?? ''),
                'region' => (string) ($address['region'] ?? ''),
                'postcode' => (string) ($address['postcode'] ?? ''),
                'telephone' => (string) ($address['telephone'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $methods
     * @return array<int, array<string, mixed>>
     */
    protected function mapShippingMethods(array $methods): array
    {
        $result = [];
        $sortOrder = 10;
        $isFirst = true;
        foreach ($methods as $code => $label) {
            $result[] = [
                'code' => (string) $code,
                'name' => (string) $label,
                'description' => (string) __('Available shipping option: %{1}', [$label]),
                'price' => 0.0,
                'is_default' => $isFirst,
                'sort_order' => $sortOrder,
            ];
            $sortOrder += 10;
            $isFirst = false;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function buildCountries(): array
    {
        $countries = [];
        foreach ($this->i18n->getCountries('en') as $code => $name) {
            $countries[] = [
                'code' => (string) $code,
                'name' => (string) $name,
            ];
        }

        return $countries;
    }
}
