<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use WeShop\Payment\Provider\Alipay;
use WeShop\Payment\Provider\CashOnDelivery;
use WeShop\B2B\Payment\Provider\CreditAccount;
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
            'b2b_credit_account' => [
                'code' => 'b2b_credit_account',
                'title' => (string) __('企业信用账户'),
                'description' => (string) __('订单计入企业信用额度，并在到期日前按发票付款。'),
                'provider' => CreditAccount::class,
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 15,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
                'config' => [
                    'instructions' => (string) __('仅适用于已开通有效信用额度的企业客户。'),
                ],
                'config_fields' => [
                    ['key' => 'instructions', 'label' => (string) __('说明'), 'type' => 'textarea'],
                ],
            ],
            'manual_transfer' => [
                'code' => 'manual_transfer',
                'title' => (string) __('银行转账'),
                'description' => (string) __('下单后通过银行转账付款。'),
                'provider' => ManualTransfer::class,
                'enabled' => true,
                'is_default' => true,
                'sort_order' => 10,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
                'config' => [
                    'instructions' => (string) __('请将订单金额转入配置的银行账户，并使用订单号作为付款备注。'),
                    'account_name' => '',
                    'bank_name' => '',
                    'account_number' => '',
                    'reference_note' => (string) __('请使用订单号作为付款备注。'),
                ],
                'config_fields' => [
                    ['key' => 'instructions', 'label' => (string) __('说明'), 'type' => 'textarea', 'help' => (string) __('订单创建后展示给客户。')],
                    ['key' => 'account_name', 'label' => (string) __('账户名称'), 'type' => 'text'],
                    ['key' => 'bank_name', 'label' => (string) __('开户银行'), 'type' => 'text'],
                    ['key' => 'account_number', 'label' => (string) __('银行账号'), 'type' => 'text'],
                    ['key' => 'reference_note', 'label' => (string) __('付款备注'), 'type' => 'text'],
                ],
            ],
            'cash_on_delivery' => [
                'code' => 'cash_on_delivery',
                'title' => (string) __('货到付款'),
                'description' => (string) __('配送送达时现金付款。'),
                'provider' => CashOnDelivery::class,
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 20,
                'icon' => '',
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
                'config' => [
                    'instructions' => (string) __('配送送达时向客户收款。'),
                    'fee' => '0',
                ],
                'config_fields' => [
                    ['key' => 'instructions', 'label' => (string) __('说明'), 'type' => 'textarea'],
                    ['key' => 'fee', 'label' => (string) __('货到付款手续费'), 'type' => 'number', 'step' => '0.01'],
                ],
            ],
            'paypal' => [
                'code' => 'paypal',
                'title' => (string) __('PayPal'),
                'description' => (string) __('使用 PayPal 账户在线支付。'),
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
                    ['key' => 'sandbox', 'label' => (string) __('沙箱模式'), 'type' => 'checkbox', 'help' => (string) __('生产 PayPal 凭据准备好之前保持开启。')],
                    ['key' => 'client_id', 'label' => (string) __('客户端 ID'), 'type' => 'text'],
                    ['key' => 'client_secret', 'label' => (string) __('客户端密钥'), 'type' => 'password'],
                    ['key' => 'merchant_email', 'label' => (string) __('商户邮箱'), 'type' => 'text'],
                    ['key' => 'webhook_id', 'label' => (string) __('Webhook ID'), 'type' => 'text'],
                ],
            ],
            'alipay' => [
                'code' => 'alipay',
                'title' => (string) __('支付宝'),
                'description' => (string) __('通过托管跳转结账使用支付宝付款。'),
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
                    ['key' => 'sandbox', 'label' => (string) __('沙箱模式'), 'type' => 'checkbox'],
                    ['key' => 'app_id', 'label' => (string) __('App ID'), 'type' => 'text'],
                    ['key' => 'merchant_id', 'label' => (string) __('商户 ID'), 'type' => 'text'],
                    ['key' => 'public_key', 'label' => (string) __('公钥'), 'type' => 'textarea'],
                    ['key' => 'private_key', 'label' => (string) __('私钥'), 'type' => 'textarea'],
                    ['key' => 'notify_url', 'label' => (string) __('通知地址'), 'type' => 'text'],
                    ['key' => 'return_url', 'label' => (string) __('返回地址'), 'type' => 'text'],
                    ['key' => 'product_code', 'label' => (string) __('产品编码'), 'type' => 'text'],
                    ['key' => 'timeout_express', 'label' => (string) __('支付超时时间'), 'type' => 'text'],
                    ['key' => 'sign_type', 'label' => (string) __('签名类型'), 'type' => 'text'],
                ],
                'required_config' => ['app_id', 'merchant_id', 'public_key', 'private_key'],
            ],
            'wechatpay' => [
                'code' => 'wechatpay',
                'title' => (string) __('微信支付'),
                'description' => (string) __('通过统一下单网关使用微信支付。'),
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
                    ['key' => 'sandbox', 'label' => (string) __('沙箱模式'), 'type' => 'checkbox'],
                    ['key' => 'app_id', 'label' => (string) __('App ID'), 'type' => 'text'],
                    ['key' => 'mch_id', 'label' => (string) __('商户号'), 'type' => 'text'],
                    ['key' => 'api_v3_key', 'label' => (string) __('API v3 密钥'), 'type' => 'password'],
                    ['key' => 'merchant_cert_path', 'label' => (string) __('商户证书路径'), 'type' => 'text'],
                    ['key' => 'notify_url', 'label' => (string) __('通知地址'), 'type' => 'text'],
                    ['key' => 'trade_type', 'label' => (string) __('交易类型'), 'type' => 'text'],
                    ['key' => 'sign_type', 'label' => (string) __('签名类型'), 'type' => 'text'],
                    ['key' => 'scene_info', 'label' => (string) __('场景信息'), 'type' => 'textarea'],
                    ['key' => 'spbill_create_ip', 'label' => (string) __('客户端 IP 覆盖'), 'type' => 'text'],
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
