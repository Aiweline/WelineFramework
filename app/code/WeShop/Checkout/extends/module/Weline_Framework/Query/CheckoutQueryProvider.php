<?php
declare(strict_types=1);

namespace WeShop\Checkout\Extends\Module\Weline_Framework\Query;

use WeShop\Cart\Service\CartIdentityService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Framework\App\State;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CheckoutQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CheckoutPageDataService $checkoutPageDataService,
        private readonly CheckoutService $checkoutService,
        private readonly CartIdentityService $cartIdentityService,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutIdentityService $checkoutIdentityService
    ) {
    }

    public function getProviderName(): string
    {
        return 'checkout';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'methods' => $this->methods($this->normalizeFormParams($params)),
            'placeOrder' => $this->placeOrder($this->normalizeFormParams($params)),
            default => throw new \InvalidArgumentException('Checkout query provider does not support operation: ' . $operation),
        };
    }

    private function methods(array $form): array
    {
        $cartCustomerId = $this->cartIdentityService->getCartCustomerId();
        $authenticatedCustomerId = $this->cartIdentityService->getAuthenticatedCustomerId();
        $checkoutIdentity = $this->resolveCheckoutIdentity($form, $cartCustomerId, $authenticatedCustomerId);
        $isGuestCheckout = !empty($checkoutIdentity['is_guest_checkout']);

        $data = $this->checkoutPageDataService->buildDynamicMethodData($cartCustomerId, [
            'shipping_address_id' => $isGuestCheckout ? 0 : (int)($form['shipping_address_id'] ?? 0),
            'shipping_address' => $this->readShippingAddress($form),
            'shipping_method' => (string)($form['shipping_method'] ?? ''),
            'payment_method' => (string)($form['payment_method'] ?? ''),
            'order_id' => (int)(($form['order_id'] ?? $form['retry_order_id'] ?? 0)),
            'checkout_mode' => (string)($checkoutIdentity['checkout_mode'] ?? CheckoutIdentityService::MODE_GUEST),
            'is_guest_checkout' => $isGuestCheckout,
            'guest_email' => (string)($checkoutIdentity['guest_email'] ?? ''),
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
        ]);

        return $this->success('Checkout methods refreshed.', $data);
    }

    private function placeOrder(array $form): array
    {
        $cartCustomerId = $this->cartIdentityService->getCartCustomerId();
        $authenticatedCustomerId = $this->cartIdentityService->getAuthenticatedCustomerId();
        $checkoutIdentity = $this->resolveCheckoutIdentity($form, $cartCustomerId, $authenticatedCustomerId);
        $isGuestCheckout = !empty($checkoutIdentity['is_guest_checkout']);
        $guestEmail = (string)($checkoutIdentity['guest_email'] ?? $this->readGuestEmail($form));
        $orderCustomerId = $isGuestCheckout ? $this->cartIdentityService->getGuestCartCustomerId() : $cartCustomerId;

        $checkoutData = [
            'customer_id' => $orderCustomerId,
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
            'checkout_mode' => (string)($checkoutIdentity['checkout_mode'] ?? CheckoutIdentityService::MODE_GUEST),
            'is_guest_checkout' => $isGuestCheckout,
            'guest_email' => $guestEmail,
            'order_id' => (int)(($form['order_id'] ?? $form['retry_order_id'] ?? 0)),
            'shipping_address_id' => $isGuestCheckout ? 0 : (int)($form['shipping_address_id'] ?? 0),
            'billing_address_id' => (int)($form['billing_address_id'] ?? 0),
            'shipping_address' => $this->readShippingAddress($form),
            'shipping_method' => (string)($form['shipping_method'] ?? ''),
            'payment_method' => (string)($form['payment_method'] ?? ''),
            'notification_channels' => $this->readNotificationChannels($form),
            'currency' => State::getCurrency(),
            'client_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ];

        $result = $this->checkoutService->placeOrder($checkoutData);
        $this->customerSession->set('weshop_checkout_last_order_context', [
            'order_id' => (int)($result['order_id'] ?? 0),
            'order_increment_id' => (string)($result['order_increment_id'] ?? ''),
            'customer_id' => $orderCustomerId,
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
            'checkout_mode' => (string)($checkoutIdentity['checkout_mode'] ?? CheckoutIdentityService::MODE_GUEST),
            'is_guest_checkout' => $isGuestCheckout,
            'guest_email' => $guestEmail,
            'shipping_address' => $checkoutData['shipping_address'],
            'shipping_method' => $checkoutData['shipping_method'],
            'payment_method' => is_array($result['payment_method'] ?? null) ? $result['payment_method'] : [],
            'notification_channels' => $checkoutData['notification_channels'],
            'cart_summary' => is_array($result['order_summary'] ?? null) ? $result['order_summary'] : [],
            'is_retry_payment' => (bool)($result['is_retry_payment'] ?? false),
        ]);
        $this->customerSession->getSession()->save();

        return $this->success('Order submitted successfully.', [
            'order_id' => (int)($result['order_id'] ?? 0),
            'increment_id' => (string)($result['order_increment_id'] ?? ''),
            'payment' => is_array($result['payment'] ?? null) ? $result['payment'] : [],
            'payment_method' => is_array($result['payment_method'] ?? null) ? $result['payment_method'] : [],
            'redirect_url' => (string)(($result['payment']['redirect_url'] ?? $result['payment']['payment_url'] ?? '')),
            'is_retry_payment' => (bool)($result['is_retry_payment'] ?? false),
        ]);
    }

    private function normalizeFormParams(array $params): array
    {
        $form = $params['form'] ?? $params['payload'] ?? $params;
        return is_array($form) ? $form : [];
    }

    private function readShippingAddress(array $form): array
    {
        $shippingAddress = $form['shipping_address'] ?? $form['shipping'] ?? [];
        $shippingAddress = is_array($shippingAddress) ? $shippingAddress : [];
        $guestEmail = $this->readGuestEmail($form);
        if ($guestEmail !== '' && empty($shippingAddress['email'])) {
            $shippingAddress['email'] = $guestEmail;
        }

        return $shippingAddress;
    }

    private function readGuestEmail(array $form): string
    {
        $email = trim((string)($form['guest_email'] ?? $form['email'] ?? ''));
        if ($email === '' && is_array($form['shipping_address'] ?? null)) {
            $email = trim((string)($form['shipping_address']['email'] ?? ''));
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * @return array<int, string>
     */
    private function readNotificationChannels(array $form): array
    {
        $channels = $form['notification_channels'] ?? $form['notification_channels[]'] ?? [];
        if (!is_array($channels)) {
            $channels = $channels === null || $channels === '' ? [] : [$channels];
        }

        $normalized = [];
        foreach ($channels as $channel) {
            $channel = strtolower(trim((string)$channel));
            if ($channel !== '') {
                $normalized[] = $channel;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function resolveCheckoutIdentity(array $form, int $cartCustomerId, int $authenticatedCustomerId): array
    {
        return $this->checkoutIdentityService->resolve([
            'checkout_mode' => (string)($form['checkout_mode'] ?? ''),
            'customer_id' => $cartCustomerId,
            'cart_customer_id' => $cartCustomerId,
            'authenticated_customer_id' => $authenticatedCustomerId,
            'guest_email' => $this->readGuestEmail($form),
            'guest_allowed' => true,
            'customer_allowed' => $authenticatedCustomerId > 0,
        ]);
    }

    private function success(string $message, array $data = []): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data] + $data;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'checkout',
            'name' => 'Frontend checkout worker API',
            'description' => 'Checkout dynamic method refresh and order placement through Weline.Api.',
            'module' => 'WeShop_Checkout',
            'operations' => [
                [
                    'name' => 'methods',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'form' => ['type' => 'map'],
                        'payload' => ['type' => 'map'],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Refresh checkout shipping/payment methods and cart summary',
                ],
                [
                    'name' => 'placeOrder',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 10,
                    'params' => [
                        'form' => ['type' => 'map'],
                        'payload' => ['type' => 'map'],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Place order from current cart',
                ],
            ],
        ];
    }
}
