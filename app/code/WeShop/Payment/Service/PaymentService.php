<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use WeShop\Payment\Provider\Alipay;
use WeShop\Payment\Provider\CashOnDelivery;
use WeShop\Payment\Provider\ManualTransfer;
use WeShop\Payment\Provider\PayPal;
use WeShop\Payment\Provider\WeChatPay;

class PaymentService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getMethodRegistry(): array
    {
        return [
            'manual_transfer' => [
                'code' => 'manual_transfer',
                'title' => (string) __('Manual Transfer'),
                'description' => (string) __('Pay by bank transfer after the order is created.'),
                'provider' => ManualTransfer::class,
                'enabled' => true,
                'is_default' => true,
                'sort_order' => 10,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
            ],
            'cash_on_delivery' => [
                'code' => 'cash_on_delivery',
                'title' => (string) __('Cash on Delivery'),
                'description' => (string) __('Pay in cash when the shipment is delivered.'),
                'provider' => CashOnDelivery::class,
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 20,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
            ],
            'paypal' => [
                'code' => 'paypal',
                'title' => (string) __('PayPal'),
                'description' => (string) __('Pay online with your PayPal account.'),
                'provider' => PayPal::class,
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 30,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => ['USD', 'EUR', 'GBP'],
                'countries' => [],
            ],
            'alipay' => [
                'code' => 'alipay',
                'title' => (string) __('Alipay'),
                'description' => (string) __('Reserved for future Alipay gateway rollout.'),
                'provider' => Alipay::class,
                'enabled' => false,
                'is_default' => false,
                'sort_order' => 40,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => ['CNY'],
                'countries' => ['CN'],
            ],
            'wechatpay' => [
                'code' => 'wechatpay',
                'title' => (string) __('WeChat Pay'),
                'description' => (string) __('Reserved for future WeChat Pay gateway rollout.'),
                'provider' => WeChatPay::class,
                'enabled' => false,
                'is_default' => false,
                'sort_order' => 50,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => ['CNY'],
                'countries' => ['CN'],
            ],
        ];
    }

    public function processPayment(Order $order, string $paymentMethod, array $paymentData = []): array
    {
        $method = $this->requireMethod($paymentMethod);
        if (!$this->isEnabled($method)) {
            throw new \InvalidArgumentException((string) __('Unsupported payment method: %{1}', [$paymentMethod]));
        }

        $provider = $this->resolveProvider($method);
        $result = $provider->processPayment($order, $paymentData);

        return array_merge($method, $result, [
            'payment_method' => $method['code'],
            'payment_method_title' => $method['title'],
        ]);
    }

    public function handleCallback(string $paymentMethod, array $callbackData): bool
    {
        $method = $this->requireMethod($paymentMethod);
        $provider = $this->resolveProvider($method);

        return $provider->handleCallback($callbackData);
    }

    public function queryPaymentStatus(string $paymentMethod, string $orderNumber): string
    {
        $method = $this->requireMethod($paymentMethod);
        $provider = $this->resolveProvider($method);

        return $provider->queryPaymentStatus($orderNumber);
    }

    public function getPaymentMethod(string $code): ?array
    {
        $registry = $this->getMethodRegistry();
        if (!isset($registry[$code])) {
            return null;
        }

        return $this->normalizeMethod($registry[$code]);
    }

    public function getAvailablePaymentMethods(array $context = []): array
    {
        return $this->filterAndSortMethods($context, false);
    }

    public function getCheckoutPaymentMethods(array $context = []): array
    {
        return $this->filterAndSortMethods($context, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function filterAndSortMethods(array $context, bool $enabledOnly): array
    {
        $methods = [];
        foreach ($this->getMethodRegistry() as $method) {
            $method = $this->normalizeMethod($method);
            if ($enabledOnly && !$this->isEnabled($method)) {
                continue;
            }
            if (!$this->matchesContext($method, $context)) {
                continue;
            }
            $methods[] = $method;
        }

        usort($methods, static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0)));

        return $methods;
    }

    /**
     * @param array<string, mixed> $method
     */
    protected function resolveProvider(array $method): PaymentProviderInterface
    {
        $providerClass = (string) ($method['provider'] ?? '');
        if ($providerClass === '' || !class_exists($providerClass)) {
            throw new \InvalidArgumentException((string) __('Payment provider is not configured for %{1}.', [$method['code'] ?? '']));
        }

        $provider = ObjectManager::getInstance($providerClass);
        if (!$provider instanceof PaymentProviderInterface) {
            throw new \InvalidArgumentException((string) __('Payment provider is invalid for %{1}.', [$method['code'] ?? '']));
        }

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireMethod(string $paymentMethod): array
    {
        $method = $this->getPaymentMethod($paymentMethod);
        if ($method === null) {
            throw new \InvalidArgumentException((string) __('Unsupported payment method: %{1}', [$paymentMethod]));
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $context
     */
    protected function matchesContext(array $method, array $context): bool
    {
        $area = strtolower((string) ($context['area'] ?? 'frontend'));
        $areas = array_map(static fn(mixed $value): string => strtolower((string) $value), (array) ($method['areas'] ?? []));
        if ($areas !== [] && !in_array($area, $areas, true)) {
            return false;
        }

        $currency = strtoupper((string) ($context['currency'] ?? ''));
        $currencies = array_map(static fn(mixed $value): string => strtoupper((string) $value), (array) ($method['currencies'] ?? []));
        if ($currency !== '' && $currencies !== [] && !in_array($currency, $currencies, true)) {
            return false;
        }

        $country = strtoupper((string) ($context['country'] ?? $context['country_id'] ?? ''));
        $countries = array_map(static fn(mixed $value): string => strtoupper((string) $value), (array) ($method['countries'] ?? []));
        if ($country !== '' && $countries !== [] && !in_array($country, $countries, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    protected function normalizeMethod(array $method): array
    {
        return [
            'code' => (string) ($method['code'] ?? ''),
            'title' => (string) ($method['title'] ?? ''),
            'description' => (string) ($method['description'] ?? ''),
            'provider' => (string) ($method['provider'] ?? ''),
            'enabled' => (bool) ($method['enabled'] ?? false),
            'is_default' => (bool) ($method['is_default'] ?? false),
            'sort_order' => (int) ($method['sort_order'] ?? 0),
            'icon' => (string) ($method['icon'] ?? ''),
            'areas' => array_values(array_map(static fn(mixed $value): string => (string) $value, (array) ($method['areas'] ?? []))),
            'currencies' => array_values(array_map(static fn(mixed $value): string => strtoupper((string) $value), (array) ($method['currencies'] ?? []))),
            'countries' => array_values(array_map(static fn(mixed $value): string => strtoupper((string) $value), (array) ($method['countries'] ?? []))),
        ];
    }

    /**
     * @param array<string, mixed> $method
     */
    protected function isEnabled(array $method): bool
    {
        return (bool) ($method['enabled'] ?? false);
    }
}
