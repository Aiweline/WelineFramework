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
                'description' => (string) __('Pay with Alipay through a hosted redirect checkout.'),
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
                    'notify_url' => '',
                    'return_url' => '',
                    'product_code' => 'FAST_INSTANT_TRADE_PAY',
                    'timeout_express' => '30m',
                    'sign_type' => 'RSA2',
                ],
                'config_fields' => [
                    ['key' => 'sandbox', 'label' => (string) __('Sandbox Mode'), 'type' => 'checkbox'],
                    ['key' => 'app_id', 'label' => (string) __('App ID'), 'type' => 'text'],
                    ['key' => 'merchant_id', 'label' => (string) __('Merchant ID'), 'type' => 'text'],
                    ['key' => 'public_key', 'label' => (string) __('Public Key'), 'type' => 'textarea'],
                    ['key' => 'private_key', 'label' => (string) __('Private Key'), 'type' => 'textarea'],
                    ['key' => 'notify_url', 'label' => (string) __('Notify URL'), 'type' => 'text'],
                    ['key' => 'return_url', 'label' => (string) __('Return URL'), 'type' => 'text'],
                    ['key' => 'product_code', 'label' => (string) __('Product Code'), 'type' => 'text'],
                    ['key' => 'timeout_express', 'label' => (string) __('Payment Timeout'), 'type' => 'text'],
                    ['key' => 'sign_type', 'label' => (string) __('Signature Type'), 'type' => 'text'],
                ],
                'required_config' => ['app_id', 'merchant_id', 'public_key', 'private_key'],
            ],
            'wechatpay' => [
                'code' => 'wechatpay',
                'title' => (string) __('WeChat Pay'),
                'description' => (string) __('Pay with WeChat Pay through the unified order gateway.'),
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
                    'notify_url' => '',
                    'trade_type' => 'MWEB',
                    'sign_type' => 'MD5',
                    'scene_info' => '{"h5_info":{"type":"Wap"}}',
                    'spbill_create_ip' => '',
                ],
                'config_fields' => [
                    ['key' => 'sandbox', 'label' => (string) __('Sandbox Mode'), 'type' => 'checkbox'],
                    ['key' => 'app_id', 'label' => (string) __('App ID'), 'type' => 'text'],
                    ['key' => 'mch_id', 'label' => (string) __('Merchant ID'), 'type' => 'text'],
                    ['key' => 'api_v3_key', 'label' => (string) __('API v3 Key'), 'type' => 'password'],
                    ['key' => 'merchant_cert_path', 'label' => (string) __('Merchant Certificate Path'), 'type' => 'text'],
                    ['key' => 'notify_url', 'label' => (string) __('Notify URL'), 'type' => 'text'],
                    ['key' => 'trade_type', 'label' => (string) __('Trade Type'), 'type' => 'text'],
                    ['key' => 'sign_type', 'label' => (string) __('Signature Type'), 'type' => 'text'],
                    ['key' => 'scene_info', 'label' => (string) __('Scene Info'), 'type' => 'textarea'],
                    ['key' => 'spbill_create_ip', 'label' => (string) __('Client IP Override'), 'type' => 'text'],
                ],
                'required_config' => ['app_id', 'mch_id', 'api_v3_key'],
            ],
        ];
    }

    public function processPayment(Order $order, string $paymentMethod, array $paymentData = []): array
    {
        $method = $this->requireMethod($paymentMethod);
        if (!$this->isEnabled($method)) {
            throw new \InvalidArgumentException((string) __('Unsupported payment method: %{1}', [$paymentMethod]));
        }
        if (!$this->isConfigured($method)) {
            throw new \InvalidArgumentException((string) __('Payment method %{1} is missing required configuration: %{2}', [
                $paymentMethod,
                implode(', ', (array) ($method['missing_config'] ?? [])),
            ]));
        }

        $provider = $this->resolveProvider($method);
        $providerContext = $this->buildProviderContext($method, [
            'payment_data' => $paymentData,
        ]);
        $result = $provider->processPayment($order, $paymentData, $providerContext);

        return array_merge($method, $result, [
            'payment_method' => $method['code'],
            'payment_method_title' => $method['title'],
        ]);
    }

    public function handleCallback(string $paymentMethod, array $callbackData, array $context = []): bool
    {
        $method = $this->requireMethod($paymentMethod);
        $provider = $this->resolveProvider($method);

        return $provider->handleCallback($callbackData, $this->buildProviderContext($method, array_merge($context, [
            'callback_data' => $callbackData,
        ])));
    }

    public function queryPaymentStatus(string $paymentMethod, string $orderNumber, array $context = []): string
    {
        $method = $this->requireMethod($paymentMethod);
        $provider = $this->resolveProvider($method);

        return $provider->queryPaymentStatus($orderNumber, $this->buildProviderContext($method, $context));
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
            if ($enabledOnly && (!$this->isEnabled($method) || !$this->isConfigured($method))) {
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
            'required_config' => array_values(array_map(static fn(mixed $value): string => (string) $value, (array) ($method['required_config'] ?? []))),
            'missing_config' => [],
            'is_configured' => true,
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
            $method['missing_config'] = $this->getMissingRequiredConfig($method);
            $method['is_configured'] = $method['missing_config'] === [];

            return $method;
        }

        $override = $this->getMethodOverrides()[$code] ?? null;
        if (!\is_array($override)) {
            $method['missing_config'] = $this->getMissingRequiredConfig($method);
            $method['is_configured'] = $method['missing_config'] === [];

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
        if (\is_array($override['required_config'] ?? null)) {
            $method['required_config'] = array_values($override['required_config']);
        }
        if (\is_array($override['config'] ?? null)) {
            $method['config'] = array_replace((array) ($method['config'] ?? []), $override['config']);
        }

        $method['missing_config'] = $this->getMissingRequiredConfig($method);
        $method['is_configured'] = $method['missing_config'] === [];

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

    /**
     * @param array<string, mixed> $method
     * @return array<int, string>
     */
    protected function getMissingRequiredConfig(array $method): array
    {
        $config = \is_array($method['config'] ?? null) ? $method['config'] : [];
        $missing = [];
        foreach ((array) ($method['required_config'] ?? []) as $key) {
            if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
                $missing[] = (string) $key;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $method
     */
    protected function isConfigured(array $method): bool
    {
        return (bool) ($method['is_configured'] ?? true);
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function buildProviderContext(array $method, array $context = []): array
    {
        return array_merge($context, [
            'payment_method' => $method,
            'config' => \is_array($method['config'] ?? null) ? $method['config'] : [],
            'required_config' => array_values((array) ($method['required_config'] ?? [])),
            'missing_config' => array_values((array) ($method['missing_config'] ?? [])),
            'is_configured' => (bool) ($method['is_configured'] ?? true),
            'sandbox' => (bool) (($method['config']['sandbox'] ?? false)),
        ]);
    }
}
