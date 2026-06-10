<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentMethodConfig;

class PaymentMethodManager
{
    private const INTERNAL_PROVIDER_CONFIG_KEY = '_provider';

    public function __construct(
        private readonly PaymentProviderScanner $providerScanner,
        private readonly ObjectManager $objectManager,
        private ?PaymentScopeConfigService $scopeConfigService = null
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
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
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
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
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

    public function getMethodByCode(string $code): ?PaymentMethod
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $this->normalizeCode($code));

        return $paymentMethod->getId() ? $paymentMethod : null;
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
        $metadata['provider_api_version'] = (string) ($metadata['provider_api_version'] ?? '1.0');
        $metadata['webhook_schema_version'] = (string) ($metadata['webhook_schema_version'] ?? '1.0');
        $metadata['capabilities'] = \is_array($metadata['capabilities'] ?? null) ? $metadata['capabilities'] : [];

        return $metadata;
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

        $providerCode = $this->normalizeCode($this->providerString($provider, 'getProviderCode', $methodCode));
        $uiTemplateCode = $this->normalizeCode((string) ($displayMetadata['ui_template_code'] ?? $methodCode));
        $checkoutTemplateCode = $this->normalizeCode((string) ($displayMetadata['checkout_template_code'] ?? $uiTemplateCode));
        $configTemplateCode = $this->normalizeCode((string) ($displayMetadata['config_template_code'] ?? $methodCode));

        return [
            'method_code' => $methodCode,
            'provider_code' => $providerCode,
            'binding_code' => $providerCode . ':' . $methodCode,
            'provider_api_version' => $this->providerString($provider, 'getProviderApiVersion', (string) ($capabilities['provider_api_version'] ?? '1.0')),
            'webhook_schema_version' => $this->providerString($provider, 'getWebhookSchemaVersion', (string) ($capabilities['webhook_schema_version'] ?? '1.0')),
            'ui_template_code' => $uiTemplateCode,
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
}
