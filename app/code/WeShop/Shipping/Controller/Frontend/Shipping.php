<?php

declare(strict_types=1);

namespace WeShop\Shipping\Controller\Frontend;

use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Shipping\Service\ShippingService;
use Weline\Framework\Manager\ObjectManager;

class Shipping extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Shipping::templates/Frontend/Shipping/index.phtml';

    protected ?string $layoutType = 'page';

    public function __construct(
        private readonly ShippingService $shippingService,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function index(): string
    {
        $customer = $this->customerSession->getCustomer();
        $context = $this->buildContext($customer);

        $shippingMethods = $this->shippingService->getCheckoutShippingMethods($context);
        $availableMethods = $this->shippingService->getAvailableShippingMethods($context);

        $this->assign('page_title', (string) __('Shipping Methods'));
        $this->assign('customer', $customer);
        $this->assign('shipping_methods', $shippingMethods);
        $this->assign('available_methods', $availableMethods);
        $this->assign('currency', strtoupper((string) ($_SERVER['WELINE_USER_CURRENCY'] ?? 'USD')));

        return $this->fetch(self::CONTENT_TEMPLATE);
    }

    public function calculate(): string
    {
        try {
            $shippingMethod = (string) $this->request->getParam('shipping_method', '');
            if ($shippingMethod === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('Shipping method is required.'),
                ]);
            }

            $customer = $this->customerSession->getCustomer();
            $context = $this->buildContext($customer);
            $context['shipping_method'] = $shippingMethod;

            $fee = $this->shippingService->calculateShipping($context, $shippingMethod);

            return $this->jsonResponse([
                'success' => true,
                'message' => __('Shipping fee calculated successfully.'),
                'data' => [
                    'shipping_method' => $shippingMethod,
                    'shipping_fee' => $fee,
                    'formatted_fee' => $this->formatPrice($fee),
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param object|null $customer
     * @return array<string, mixed>
     */
    protected function buildContext(?object $customer): array
    {
        $context = [
            'area' => 'frontend',
            'currency' => strtoupper((string) ($_SERVER['WELINE_USER_CURRENCY'] ?? 'USD')),
        ];

        if ($customer && $customer->getId()) {
            $context['customer_id'] = (int) $customer->getId();
        }

        $shippingAddress = $this->request->getParam('shipping_address', []);
        if (\is_array($shippingAddress) && $shippingAddress !== []) {
            $context['shipping_address'] = $shippingAddress;
            $context['country'] = (string) ($shippingAddress['country_id'] ?? $shippingAddress['country'] ?? '');
            $context['region'] = (string) ($shippingAddress['region'] ?? '');
            $context['city'] = (string) ($shippingAddress['city'] ?? '');
        }

        $cartItems = $this->request->getParam('cart_items', []);
        if (\is_array($cartItems) && $cartItems !== []) {
            $context['items'] = $cartItems;
        }

        $subtotal = $this->request->getParam('subtotal');
        if ($subtotal !== null) {
            $context['subtotal'] = (float) $subtotal;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $data
     * @return string
     */
    protected function jsonResponse(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function formatPrice(float $price, string $currency = ''): string
    {
        $currency = $currency ?: strtoupper((string) ($_SERVER['WELINE_USER_CURRENCY'] ?? 'USD'));
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter(\Locale::getDefault() ?: 'en_US', \NumberFormatter::CURRENCY);
            return $formatter->formatCurrency($price, $currency);
        }
        return sprintf('%s %.2f', $currency, $price);
    }
}
