<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentMethodConfig;
use Weline\SystemConfig\Api\ConfigStore;

class PaymentMethodManager
{
    private const INTERNAL_PROVIDER_CONFIG_KEY = '_provider';
    private const SORT_STEP = 10;

    public function __construct(
        private readonly PaymentProviderScanner $providerScanner,
        private readonly ObjectManager $objectManager,
        private ?PaymentScopeConfigService $scopeConfigService = null,
        private ?PaymentMethodIconResolver $iconResolver = null,
        private ?ConfigStore $configStore = null,
    ) {
    }

    public function registerAllProviders(): int
    {
        $count = 0;
        foreach ($this->providerScanner->scanProviderDefinitions(true) as $definition) {
            $className = (string) ($definition['class_name'] ?? '');
            if ($className === '' || !class_exists($className)) {
                continue;
            }

            try {
                $provider = $this->objectManager->getInstance($className);
                if (!$provider instanceof ProviderInterface) {
                    continue;
                }

                $this->registerProvider($provider, $definition);
                $count++;
            } catch (\Throwable $throwable) {
                w_log_error('注册支付提供商失败: ' . $throwable->getMessage());
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function registerProvider(ProviderInterface $provider, array $definition = []): PaymentMethod
    {
        $code = $this->normalizeCode($provider->getCode());
        if ($code === '') {
            throw new \InvalidArgumentException((string) __('支付提供商 method_code 不能为空'));
        }

        $reflection = new \ReflectionClass($provider);
        $moduleName = (string) ($definition['source_module'] ?? '');
        if ($moduleName === '') {
            $moduleName = $this->getModuleNameFromClass($reflection->getName());
        }

        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class, [], false);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $code);
        $config = $paymentMethod->getId() ? $paymentMethod->getConfigData() : [];
        $providerMetadata = $this->buildProviderMetadata($provider, $definition, $code);
        $config[self::INTERNAL_PROVIDER_CONFIG_KEY] = $providerMetadata;

        $paymentMethod->setData(PaymentMethod::schema_fields_CODE, $code)
            ->setData(PaymentMethod::schema_fields_NAME, $this->resolveProviderName($provider, $providerMetadata))
            ->setData(PaymentMethod::schema_fields_PROVIDER_MODULE, $moduleName)
            ->setData(PaymentMethod::schema_fields_PROVIDER_CLASS, $reflection->getName())
            ->setConfigData($config);

        if (!$paymentMethod->getId()) {
            $paymentMethod->setData(PaymentMethod::schema_fields_IS_ACTIVE, 0)
                ->setData(PaymentMethod::schema_fields_SORT_ORDER, 0);
        }

        $paymentMethod->save();

        return $paymentMethod;
    }

    /**
     * @return PaymentMethod[]
     */
    public function getActiveMethods(array $context = []): array
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class, [], false);
        $methods = $paymentMethod
            ->order(PaymentMethod::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();

        if (\is_object($methods) && method_exists($methods, 'getItems')) {
            $methods = $methods->getItems();
        }
        if (!\is_array($methods)) {
            return [];
        }

        $methods = array_values(array_filter($methods, function (mixed $method) use ($context): bool {
            return $method instanceof PaymentMethod && $this->isMethodActiveForScope($method, $context);
        }));
        $methods = $this->uniqueMethodsByCode($methods);
        usort($methods, function (PaymentMethod $left, PaymentMethod $right) use ($context): int {
            $leftConfig = $this->getRuntimeConfig($left, $context);
            $rightConfig = $this->getRuntimeConfig($right, $context);
            $sort = ((int) ($leftConfig['sort_order'] ?? $left->getData(PaymentMethod::schema_fields_SORT_ORDER)))
                <=> ((int) ($rightConfig['sort_order'] ?? $right->getData(PaymentMethod::schema_fields_SORT_ORDER)));

            return $sort !== 0
                ? $sort
                : strcmp((string) $left->getData(PaymentMethod::schema_fields_CODE), (string) $right->getData(PaymentMethod::schema_fields_CODE));
        });

        return $methods;
    }

    /**
     * Admin method list for the current scope (includes disabled), ordered by scoped sort_order.
     *
     * @return PaymentMethod[]
     */
    public function listMethodsForAdmin(array $context = []): array
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class, [], false);
        $methods = $paymentMethod
            ->order(PaymentMethod::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();

        if (\is_object($methods) && method_exists($methods, 'getItems')) {
            $methods = $methods->getItems();
        }
        if (!\is_array($methods)) {
            return [];
        }

        $methods = array_values(array_filter(
            $methods,
            static fn(mixed $method): bool => $method instanceof PaymentMethod
        ));
        $methods = $this->uniqueMethodsByCode($methods);

        return $this->sortMethodsByScopedOrder($methods, $context);
    }

    /**
     * Persist drag order as payment/method/{code}/sort_order at the exact target scope.
     *
     * @param list<string> $orderedCodes
     * @return array{success:bool,sort_orders:array<string,int>,message:string}
     */
    public function reorderMethodsForScope(array $orderedCodes, array $context = []): array
    {
        $codes = [];
        foreach ($orderedCodes as $code) {
            $normalized = $this->normalizeCode((string)$code);
            if ($normalized === '' || isset($codes[$normalized])) {
                continue;
            }
            $codes[$normalized] = $normalized;
        }
        $ordered = array_values($codes);
        if ($ordered === []) {
            return [
                'success' => false,
                'sort_orders' => [],
                'message' => (string)__('支付方式排序列表不能为空'),
            ];
        }

        $scope = $this->getScopeConfigService()->resolveScope($context);
        $storageScope = (string)$scope['scope'];
        $sortOrders = [];
        $index = 0;
        foreach ($ordered as $code) {
            $method = $this->getMethodByCode($code);
            if (!$method instanceof PaymentMethod) {
                return [
                    'success' => false,
                    'sort_orders' => [],
                    'message' => (string)__('支付方式不存在: %{1}', [$code]),
                ];
            }

            $weight = $index * self::SORT_STEP;
            $module = trim((string)$method->getData(PaymentMethod::schema_fields_PROVIDER_MODULE));
            if ($module === '') {
                $module = PaymentScopeConfigService::MODULE_WELINE_PAYMENT;
            }

            $ok = $this->getConfigStore()->setScopedConfig(
                key: 'payment/method/' . $code . '/sort_order',
                value: $weight,
                module: $module,
                area: ConfigStore::area_BACKEND,
                scope: $storageScope,
                locale: ConfigStore::LOCALE_DEFAULT,
            );
            if (!$ok) {
                return [
                    'success' => false,
                    'sort_orders' => $sortOrders,
                    'message' => (string)__('保存支付方式排序失败: %{1}', [$code]),
                ];
            }

            $sortOrders[$code] = $weight;
            $index++;
        }

        return [
            'success' => true,
            'sort_orders' => $sortOrders,
            'message' => (string)__('支付方式排序已保存'),
        ];
    }

    /**
     * Scoped sort_order for display (falls back to method row).
     */
    public function getScopedSortOrder(PaymentMethod $method, array $context = []): int
    {
        $runtime = $this->getRuntimeConfig($method, $context);

        return (int)($runtime['sort_order'] ?? $method->getData(PaymentMethod::schema_fields_SORT_ORDER) ?? 0);
    }

    public function getMethodByCode(string $code): ?PaymentMethod
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class, [], false);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $this->normalizeCode($code));

        return $paymentMethod->getId() ? $paymentMethod : null;
    }

    /**
     * Resolve an immutable webhook binding without requiring the mutable
     * PaymentMethod row to remain present or active for the lifetime of the
     * provider's callback obligation.
     */
    public function resolveWebhookProvider(string $methodCode, string $providerCode): ?ProviderInterface
    {
        $methodCode = $this->normalizeCode($methodCode);
        $providerCode = $this->normalizeCode($providerCode);
        if ($methodCode === '' || $providerCode === '') {
            return null;
        }

        try {
            $route = $this->resolveProviderRoute($methodCode);
            $provider = $route['provider'] ?? null;
            if ($provider instanceof ProviderInterface
                && $this->normalizeCode($provider->getCode()) === $methodCode
                && $this->normalizeCode($provider->getProviderCode()) === $providerCode
            ) {
                return $provider;
            }
        } catch (\Throwable) {
            // Historical webhook endpoints must survive mutable method-row changes.
        }

        foreach ($this->providerScanner->getProviderInstances(true) as $provider) {
            if ($this->normalizeCode($provider->getCode()) === $methodCode
                && $this->normalizeCode($provider->getProviderCode()) === $providerCode
            ) {
                return $provider;
            }
        }

        return null;
    }

    public function getProviderInstance(PaymentMethod $paymentMethod, array $context = []): ?ProviderInterface
    {
        $className = (string) $paymentMethod->getData(PaymentMethod::schema_fields_PROVIDER_CLASS);
        if ($className === '') {
            return null;
        }

        if (!class_exists($className, false)) {
            $metadata = $this->getProviderMetadata($paymentMethod);
            $sourceFile = (string) ($metadata['source_file'] ?? '');
            if ($sourceFile !== '' && is_file($sourceFile)) {
                require_once $sourceFile;
            }
        }

        if (!class_exists($className)) {
            return null;
        }

        try {
            $provider = $this->objectManager->getInstance($className);
            if (!$provider instanceof ProviderInterface) {
                return null;
            }

            $methodCode = $this->normalizeCode((string) $paymentMethod->getData(PaymentMethod::schema_fields_CODE));
            $metadata = $this->getProviderMetadata($paymentMethod, $provider);
            if (($metadata['method_code'] ?? '') !== $methodCode) {
                w_log_error('支付提供商 method_code 绑定不一致: ' . $className);
                return null;
            }

            return $provider;
        } catch (\Throwable $throwable) {
            w_log_error('获取支付提供商实例失败: ' . $className . ', 错误: ' . $throwable->getMessage());
            return null;
        }
    }

    /**
     * @return array{method:PaymentMethod,provider:ProviderInterface,metadata:array<string,mixed>}
     */
    public function resolveProviderRoute(string $methodCode, array $context = []): array
    {
        $methodCode = $this->normalizeCode($methodCode);
        $paymentMethod = $this->getMethodByCode($methodCode);
        if (!$paymentMethod) {
            throw new \InvalidArgumentException(__('支付方式 %{code} 不存在', ['code' => $methodCode]));
        }

        $provider = $this->getProviderInstance($paymentMethod, $context);
        if (!$provider) {
            throw new \RuntimeException(__('支付提供商实例化失败'));
        }

        return [
            'method' => $paymentMethod,
            'provider' => $provider,
            'metadata' => $this->getProviderMetadata($paymentMethod, $provider),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderMetadata(PaymentMethod $paymentMethod, ?ProviderInterface $provider = null): array
    {
        $config = $paymentMethod->getConfigData();
        $metadata = \is_array($config[self::INTERNAL_PROVIDER_CONFIG_KEY] ?? null)
            ? $config[self::INTERNAL_PROVIDER_CONFIG_KEY]
            : [];

        if ($provider instanceof ProviderInterface) {
            $metadata = array_replace_recursive(
                $metadata,
                $this->buildProviderMetadata($provider, [
                    'source_module' => (string) $paymentMethod->getData(PaymentMethod::schema_fields_PROVIDER_MODULE),
                ])
            );
        }

        $methodCode = $this->normalizeCode((string) ($metadata['method_code'] ?? $paymentMethod->getData(PaymentMethod::schema_fields_CODE)));
        $providerCode = $this->normalizeCode((string) ($metadata['provider_code'] ?? $methodCode));
        $metadata['method_code'] = $methodCode;
        $metadata['provider_code'] = $providerCode;
        $metadata['binding_code'] = $providerCode . ':' . $methodCode;
        $metadata['ui_template_code'] = $this->normalizeCode((string) ($metadata['ui_template_code'] ?? $methodCode));
        $metadata['checkout_template_code'] = $this->normalizeCode((string) ($metadata['checkout_template_code'] ?? $metadata['ui_template_code']));
        $metadata['config_template_code'] = $this->normalizeCode((string) ($metadata['config_template_code'] ?? $methodCode));
        $checkoutMode = strtolower(trim((string) ($metadata['checkout_mode'] ?? '')));
        if (!\in_array($checkoutMode, ['shell', 'template', 'hybrid'], true)) {
            $checkoutMode = $metadata['checkout_template_code'] !== '' ? 'template' : 'shell';
        }
        $metadata['checkout_mode'] = $checkoutMode;
        $metadata['provider_api_version'] = (string) ($metadata['provider_api_version'] ?? '1.0');
        $metadata['webhook_schema_version'] = (string) ($metadata['webhook_schema_version'] ?? '1.0');
        $metadata['capabilities'] = \is_array($metadata['capabilities'] ?? null) ? $metadata['capabilities'] : [];

        return $metadata;
    }

    /**
     * Effective checkout/admin display metadata with config icon override applied.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getEffectiveDisplayMetadata(PaymentMethod $paymentMethod, array $context = [], ?ProviderInterface $provider = null): array
    {
        $provider ??= $this->getProviderInstance($paymentMethod, $context);
        $metadata = $this->getProviderMetadata($paymentMethod, $provider);
        $display = \is_array($metadata['display_metadata'] ?? null) ? $metadata['display_metadata'] : [];
        $runtime = $this->getRuntimeConfig($paymentMethod, $context);

        return $this->getIconResolver()->apply($display, $runtime);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeCapabilities(PaymentMethod $paymentMethod, array $context = [], ?array $metadata = null): array
    {
        $metadata ??= $this->getProviderMetadata($paymentMethod);
        $config = $this->getRuntimeConfig($paymentMethod, $context);

        return $this->narrowCapabilitiesByConfig(
            \is_array($metadata['capabilities'] ?? null) ? $metadata['capabilities'] : [],
            $config
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeConfig(PaymentMethod $paymentMethod, array $context = []): array
    {
        $config = $paymentMethod->getConfigData();
        $metadata = $this->getProviderMetadata($paymentMethod);
        $scope = $this->getScopeConfigService()->resolveScope($context);
        $override = $this->getScopeConfigService()->getRuntimeOverrideForMethod(
            (string) $paymentMethod->getData(PaymentMethod::schema_fields_CODE),
            (string) ($metadata['source_module'] ?? $paymentMethod->getData(PaymentMethod::schema_fields_PROVIDER_MODULE)),
            $scope['scope'],
            $scope['environment'],
            []
        );
        if (\is_array($override['config'] ?? null)) {
            $config = array_replace($config, $override['config']);
        }

        unset($config[self::INTERNAL_PROVIDER_CONFIG_KEY]);

        return $config;
    }

    public function isMethodActiveForScope(PaymentMethod $paymentMethod, array $context = []): bool
    {
        $metadata = $this->getProviderMetadata($paymentMethod);
        $scope = $this->getScopeConfigService()->resolveScope($context);
        $override = $this->getScopeConfigService()->getRuntimeOverrideForMethod(
            (string) $paymentMethod->getData(PaymentMethod::schema_fields_CODE),
            (string) ($metadata['source_module'] ?? $paymentMethod->getData(PaymentMethod::schema_fields_PROVIDER_MODULE)),
            $scope['scope'],
            $scope['environment']
        );
        if ($override === [] || empty($override['enabled'])) {
            return false;
        }

        $testStatus = trim((string) ($override['config_test_status'] ?? ''));

        return $testStatus === '' || $testStatus === PaymentMethodConfig::TEST_STATUS_PASSED;
    }

    private function getScopeConfigService(): PaymentScopeConfigService
    {
        if ($this->scopeConfigService === null) {
            $this->scopeConfigService = new PaymentScopeConfigService();
        }

        return $this->scopeConfigService;
    }

    private function getIconResolver(): PaymentMethodIconResolver
    {
        if ($this->iconResolver === null) {
            $this->iconResolver = new PaymentMethodIconResolver();
        }

        return $this->iconResolver;
    }

    private function getConfigStore(): ConfigStore
    {
        if ($this->configStore === null) {
            $this->configStore = $this->objectManager->getInstance(ConfigStore::class);
        }

        return $this->configStore;
    }

    /**
     * @param PaymentMethod[] $methods
     * @return PaymentMethod[]
     */
    private function sortMethodsByScopedOrder(array $methods, array $context): array
    {
        usort($methods, function (PaymentMethod $left, PaymentMethod $right) use ($context): int {
            $sort = $this->getScopedSortOrder($left, $context)
                <=> $this->getScopedSortOrder($right, $context);

            return $sort !== 0
                ? $sort
                : strcmp(
                    (string)$left->getData(PaymentMethod::schema_fields_CODE),
                    (string)$right->getData(PaymentMethod::schema_fields_CODE)
                );
        });

        return $methods;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildProviderMetadata(ProviderInterface $provider, array $definition = [], string $methodCode = ''): array
    {
        $methodCode = $this->normalizeCode($methodCode !== '' ? $methodCode : $provider->getCode());
        $displayMetadata = $this->providerArray($provider, 'getDisplayMetadata');
        $capabilities = $this->providerArray($provider, 'getCapabilities');
        $configSchema = $this->providerArray($provider, 'getConfigSchema');
        $providerIcon = $this->getIconResolver()->extractProviderIcon($displayMetadata);
        if ($providerIcon === '') {
            throw new \InvalidArgumentException((string) __(
                '支付提供商 %{1} 必须在 getDisplayMetadata() 中提供非空 icon_url（或 icon）。',
                [$methodCode !== '' ? $methodCode : $provider->getCode()]
            ));
        }

        $providerCode = $this->normalizeCode($this->providerString($provider, 'getProviderCode', $methodCode));
        $uiTemplateCode = $this->normalizeCode((string) ($displayMetadata['ui_template_code'] ?? $methodCode));
        $checkoutTemplateCode = $this->normalizeCode((string) ($displayMetadata['checkout_template_code'] ?? $uiTemplateCode));
        $configTemplateCode = $this->normalizeCode((string) ($displayMetadata['config_template_code'] ?? $methodCode));
        $checkoutMode = strtolower(trim((string) ($displayMetadata['checkout_mode'] ?? '')));
        if (!\in_array($checkoutMode, ['shell', 'template', 'hybrid'], true)) {
            $checkoutMode = $checkoutTemplateCode !== '' ? 'template' : 'shell';
        }

        return [
            'method_code' => $methodCode,
            'provider_code' => $providerCode,
            'binding_code' => $providerCode . ':' . $methodCode,
            'provider_api_version' => $this->providerString($provider, 'getProviderApiVersion', (string) ($capabilities['provider_api_version'] ?? '1.0')),
            'webhook_schema_version' => $this->providerString($provider, 'getWebhookSchemaVersion', (string) ($capabilities['webhook_schema_version'] ?? '1.0')),
            'ui_template_code' => $uiTemplateCode,
            'checkout_mode' => $checkoutMode,
            'checkout_template_code' => $checkoutTemplateCode,
            'config_template_code' => $configTemplateCode,
            'capabilities' => $capabilities,
            'display_metadata' => $displayMetadata,
            'config_schema' => $configSchema,
            'source_module' => (string) ($definition['source_module'] ?? ''),
            'source_file' => (string) ($definition['source_file'] ?? ''),
            'relative_path' => (string) ($definition['relative_path'] ?? ''),
            'file_path' => (string) ($definition['file_path'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveProviderName(ProviderInterface $provider, array $metadata): string
    {
        $display = \is_array($metadata['display_metadata'] ?? null) ? $metadata['display_metadata'] : [];
        foreach (['title', 'name', 'label'] as $key) {
            $value = trim((string) ($display[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $provider->getCode();
    }

    /**
     * @param array<string, mixed> $capabilities
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function narrowCapabilitiesByConfig(array $capabilities, array $config): array
    {
        foreach (['supported_currencies', 'currencies'] as $key) {
            if (\is_array($config[$key] ?? null)) {
                $capabilities[$key] = array_values($config[$key]);
            }
        }
        foreach (['supported_countries', 'countries'] as $key) {
            if (\is_array($config[$key] ?? null)) {
                $capabilities[$key] = array_values($config[$key]);
            }
        }

        // 方式级快捷支付开关（SystemConfig payment/method/{code}/express_enabled；缺省视为开启）
        $expressEnabled = $config['express_enabled'] ?? true;
        if ($expressEnabled === false || $expressEnabled === 0 || $expressEnabled === '0' || $expressEnabled === '') {
            unset($capabilities['express_checkout']);
        }

        return $capabilities;
    }

    /**
     * @return array<string, mixed>
     */
    private function providerArray(ProviderInterface $provider, string $method): array
    {
        try {
            $value = $provider->{$method}();
            return \is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function providerString(ProviderInterface $provider, string $method, string $default): string
    {
        try {
            $value = $provider->{$method}();
            return trim((string) $value) !== '' ? (string) $value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function getModuleNameFromClass(string $className): string
    {
        $parts = explode('\\', $className);

        return count($parts) >= 2 ? $parts[0] . '_' . $parts[1] : $className;
    }

    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    /**
     * @param PaymentMethod[] $methods
     * @return PaymentMethod[]
     */
    private function uniqueMethodsByCode(array $methods): array
    {
        $unique = [];
        foreach ($methods as $method) {
            if (!$method instanceof PaymentMethod) {
                continue;
            }
            $code = $this->normalizeCode((string) $method->getData(PaymentMethod::schema_fields_CODE));
            if ($code === '') {
                continue;
            }

            $current = $unique[$code] ?? null;
            if (!$current instanceof PaymentMethod || (int)$method->getId() > (int)$current->getId()) {
                $unique[$code] = $method;
            }
        }

        return array_values($unique);
    }
}
