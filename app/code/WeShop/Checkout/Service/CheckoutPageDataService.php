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
        $shippingMethods = $this->checkoutService->getCheckoutShippingMethods($customerId, [
            'area' => 'frontend',
        ]);
        if ($shippingMethods === []) {
            $shippingMethods = $this->shippingService->getAvailableShippingMethods();
        }
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
            'shipping_methods' => $this->mapShippingMethods($shippingMethods),
            'payment_methods' => $this->mapPaymentMethods(
                $this->checkoutService->getCheckoutPaymentMethods($customerId, [
                    'area' => 'frontend',
                    'currency' => 'USD',
                ])
            ),
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
        $position = 0;
        foreach ($methods as $index => $method) {
            $position++;
            $sortOrder = $position * 10;
            $isFirst = $position === 1;
            if (\is_array($method)) {
                $code = (string) ($method['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $name = (string) ($method['name'] ?? $method['title'] ?? $code);
                $result[] = [
                    'code' => $code,
                    'name' => $name,
                    'description' => (string) ($method['description'] ?? __('Available shipping option: %{1}', [$name])),
                    'price' => (float) ($method['price'] ?? 0),
                    'is_default' => (bool) ($method['is_default'] ?? $isFirst),
                    'sort_order' => (int) ($method['sort_order'] ?? $sortOrder),
                ];
                continue;
            }

            $code = (string) $index;
            $label = (string) $method;
            if ($code === '' || $label === '') {
                continue;
            }
            $result[] = [
                'code' => (string) $code,
                'name' => (string) $label,
                'description' => (string) __('Available shipping option: %{1}', [$label]),
                'price' => 0.0,
                'is_default' => $isFirst,
                'sort_order' => $sortOrder,
            ];
        }

        usort(
            $result,
            static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0))
        );

        if ($result !== []) {
            $hasDefault = false;
            foreach ($result as $method) {
                if (!empty($method['is_default'])) {
                    $hasDefault = true;
                    break;
                }
            }
            if (!$hasDefault) {
                $result[0]['is_default'] = true;
            }
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

    /**
     * @param array<int, mixed> $methods
     * @return array<int, array<string, mixed>>
     */
    protected function mapPaymentMethods(array $methods): array
    {
        $result = [];
        $hasExplicitDefault = false;

        foreach ($methods as $method) {
            if (!\is_array($method)) {
                continue;
            }

            $code = (string) ($method['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $flow = $this->resolvePaymentFlow($code);
            $isDefault = (bool) ($method['is_default'] ?? false);
            $hasExplicitDefault = $hasExplicitDefault || $isDefault;
            $config = \is_array($method['config'] ?? null) ? $method['config'] : [];
            $title = (string) ($method['title'] ?? $method['name'] ?? $code);

            $result[] = [
                'code' => $code,
                'title' => $title,
                'name' => $title,
                'description' => (string) ($method['description'] ?? ''),
                'is_default' => $isDefault,
                'sort_order' => (int) ($method['sort_order'] ?? 0),
                'icon' => (string) ($method['icon'] ?? ''),
                'flow' => $flow,
                'flow_label' => $this->resolvePaymentFlowLabel($flow),
                'badge' => $this->resolvePaymentBadge($code, $flow),
                'checkout_note' => $this->resolvePaymentCheckoutNote($code, $config, $method),
            ];
        }

        usort(
            $result,
            static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0))
        );

        if (!$hasExplicitDefault && $result !== []) {
            $result[0]['is_default'] = true;
        }

        return $result;
    }

    protected function resolvePaymentFlow(string $code): string
    {
        return match (strtolower($code)) {
            'paypal', 'alipay', 'wechatpay' => 'redirect',
            'manual_transfer' => 'offline',
            'cash_on_delivery' => 'offline_collection',
            default => 'direct',
        };
    }

    protected function resolvePaymentFlowLabel(string $flow): string
    {
        return match ($flow) {
            'redirect' => (string) __('Redirect after order placement'),
            'offline' => (string) __('Pay after order creation'),
            'offline_collection' => (string) __('Pay on delivery'),
            default => (string) __('Pay during checkout'),
        };
    }

    protected function resolvePaymentBadge(string $code, string $flow): string
    {
        return match (strtolower($code)) {
            'paypal' => (string) __('Popular'),
            'manual_transfer' => (string) __('Offline'),
            'cash_on_delivery' => (string) __('Doorstep'),
            default => $flow === 'redirect' ? (string) __('Redirect') : '',
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $method
     */
    protected function resolvePaymentCheckoutNote(string $code, array $config, array $method): string
    {
        $instructions = trim((string) ($config['instructions'] ?? ''));
        $referenceNote = trim((string) ($config['reference_note'] ?? ''));

        if ($instructions !== '' && $referenceNote !== '') {
            return $instructions . ' ' . $referenceNote;
        }

        if ($instructions !== '') {
            return $instructions;
        }

        return match (strtolower($code)) {
            'paypal' => (string) __('You will be redirected to PayPal after placing the order to complete payment securely.'),
            'cash_on_delivery' => (string) __('Prepare the order amount for the courier when your shipment arrives.'),
            default => trim((string) ($method['description'] ?? '')),
        };
    }
}
