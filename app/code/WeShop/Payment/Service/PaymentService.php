<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Framework\App\Env;
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
                'config' => [
                    'instructions' => (string) __('Please transfer the order amount to the configured bank account and use the order number as the payment reference.'),
                    'account_name' => '',
                    'bank_name' => '',
                    'account_number' => '',
                    'reference_note' => (string) __('Use the order number as the payment reference.'),
                ],
                'config_fields' => [
                    ['key' => 'instructions', 'label' => (string) __('Instructions'), 'type' => 'textarea', 'help' => (string) __('Shown to the customer after the order is created.')],
                    ['key' => 'account_name', 'label' => (string) __('Account Name'), 'type' => 'text'],
                    ['key' => 'bank_name', 'label' => (string) __('Bank Name'), 'type' => 'text'],
                    ['key' => 'account_number', 'label' => (string) __('Account Number'), 'type' => 'text'],
                    ['key' => 'reference_note', 'label' => (string) __('Reference Note'), 'type' => 'text'],
                ],
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
                'config' => [
                    'instructions' => (string) __('Collect the payment from the customer when the shipment is delivered.'),
                    'fee' => '0',
                ],
                'config_fields' => [
                    ['key' => 'instructions', 'label' => (string) __('Instructions'), 'type' => 'textarea'],
                    ['key' => 'fee', 'label' => (string) __('COD Fee'), 'type' => 'number', 'step' => '0.01'],
                ],
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
                'config' => [
                    'sandbox' => true,
                    'client_id' => '',
                    'client_secret' => '',
                    'merchant_email' => '',
                    'webhook_id' => '',
                ],
                'config_fields' => [
                    ['key' => 'sandbox', 'label' => (string) __('Sandbox Mode'), 'type' => 'checkbox', 'help' => (string) __('Keep enabled until the production PayPal credentials are ready.')],
                    ['key' => 'client_id', 'label' => (string) __('Client ID'), 'type' => 'text'],
                    ['key' => 'client_secret', 'label' => (string) __('Client Secret'), 'type' => 'password'],
                    ['key' => 'merchant_email', 'label' => (string) __('Merchant Email'), 'type' => 'text'],
                    ['key' => 'webhook_id', 'label' => (string) __('Webhook ID'), 'type' => 'text'],
                ],
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
                'config' => [
                    'sandbox' => true,
                    'app_id' => '',
                    'merchant_id' => '',
                    'public_key' => '',
                    'private_key' => '',
                ],
                'config_fields' => [
                    ['key' => 'sandbox', 'label' => (string) __('Sandbox Mode'), 'type' => 'checkbox'],
                    ['key' => 'app_id', 'label' => (string) __('App ID'), 'type' => 'text'],
                    ['key' => 'merchant_id', 'label' => (string) __('Merchant ID'), 'type' => 'text'],
                    ['key' => 'public_key', 'label' => (string) __('Public Key'), 'type' => 'textarea'],
                    ['key' => 'private_key', 'label' => (string) __('Private Key'), 'type' => 'textarea'],
                ],
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
                'config' => [
                    'sandbox' => true,
                    'app_id' => '',
                    'mch_id' => '',
                    'api_v3_key' => '',
                    'merchant_cert_path' => '',
                ],
                'config_fields' => [
                    ['key' => 'sandbox', 'label' => (string) __('Sandbox Mode'), 'type' => 'checkbox'],
                    ['key' => 'app_id', 'label' => (string) __('App ID'), 'type' => 'text'],
                    ['key' => 'mch_id', 'label' => (string) __('Merchant ID'), 'type' => 'text'],
                    ['key' => 'api_v3_key', 'label' => (string) __('API v3 Key'), 'type' => 'password'],
                    ['key' => 'merchant_cert_path', 'label' => (string) __('Merchant Certificate Path'), 'type' => 'text'],
                ],
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

        return $this->applyRuntimeOverrides($this->normalizeMethod($registry[$code]));
    }

    public function getAvailablePaymentMethods(array $context = []): array
    {
        return $this->filterAndSortMethods($context, false);
    }

    public function getCheckoutPaymentMethods(array $context = []): array
    {
        return $this->filterAndSortMethods($context, true);
    }

    public function getManagementPaymentMethods(): array
    {
        return $this->filterAndSortMethods(['area' => 'backend'], false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function filterAndSortMethods(array $context, bool $enabledOnly): array
    {
        $methods = [];
        foreach ($this->getMethodRegistry() as $method) {
            $method = $this->normalizeMethod($method);
            $method = $this->applyRuntimeOverrides($method);
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
            'config' => \is_array($method['config'] ?? null) ? $method['config'] : [],
            'config_fields' => \is_array($method['config_fields'] ?? null) ? $method['config_fields'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    protected function applyRuntimeOverrides(array $method): array
    {
        $code = (string) ($method['code'] ?? '');
        if ($code === '') {
            return $method;
        }

        $override = $this->getMethodOverrides()[$code] ?? null;
        if (!\is_array($override)) {
            return $method;
        }

        foreach (['title', 'description', 'icon'] as $key) {
            if (array_key_exists($key, $override)) {
                $method[$key] = (string) $override[$key];
            }
        }
        foreach (['enabled', 'is_default'] as $key) {
            if (array_key_exists($key, $override)) {
                $method[$key] = (bool) $override[$key];
            }
        }
        if (array_key_exists('sort_order', $override)) {
            $method['sort_order'] = (int) $override['sort_order'];
        }
        foreach (['areas', 'currencies', 'countries'] as $key) {
            if (\is_array($override[$key] ?? null)) {
                $method[$key] = array_values($override[$key]);
            }
        }
        if (\is_array($override['config'] ?? null)) {
            $method['config'] = array_replace((array) ($method['config'] ?? []), $override['config']);
        }

        return $method;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getMethodOverrides(): array
    {
        try {
            $config = Env::getInstance()->getConfig('payment.methods', []);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $method
     */
    protected function isEnabled(array $method): bool
    {
        return (bool) ($method['enabled'] ?? false);
    }
}
