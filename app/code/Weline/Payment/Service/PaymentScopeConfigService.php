<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

class PaymentScopeConfigService
{
    public const DEFAULT_SCOPE = 'default.default.default';
    public const DEFAULT_ENVIRONMENT = 'sandbox';
    public const MODULE_WELINE_PAYMENT = 'Weline_Payment';
    public const MODULE_WESHOP_PAYMENT = 'WeShop_Payment';

    private const SCOPE_PATTERN = '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+){0,2}$/';
    private const METHOD_CONFIG_PREFIX = 'payment/method/';
    private const LIST_KEYS = [
        'supported_currencies',
        'supported_countries',
        'currencies',
        'countries',
        'country_tags',
        'allowed_payable_types',
        'blocked_payable_types',
        'allowed_actor_types',
        'allowed_actor_ids',
        'required_business_tags',
        'blocked_business_tags',
        'required_config',
    ];

    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
        private ?SystemConfig $systemConfig = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array{scope: string, environment: string, scope_chain: array<int, string>, scope_key: string}
     */
    public function resolveScope(array $context = []): array
    {
        $scope = $this->resolveScopeStringFromContext($context);
        $environment = $this->normalizeEnvironment((string) ($context['environment'] ?? ''));

        return [
            'scope' => $scope,
            'environment' => $environment,
            'scope_chain' => $this->getScopeChain($scope),
            'scope_key' => $scope,
        ];
    }

    public function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === '') {
            return self::DEFAULT_SCOPE;
        }

        $scope = trim(preg_replace('/\.+/', '.', $scope) ?: $scope, '.');
        if ($scope === '') {
            return self::DEFAULT_SCOPE;
        }
        if (!preg_match(self::SCOPE_PATTERN, $scope)) {
            throw new \InvalidArgumentException((string) __('支付配置 scope 只能包含小写字母、数字、下划线、短横线，并且最多三个点分段。'));
        }

        $parts = explode('.', $scope);
        while (\count($parts) < 3) {
            $parts[] = 'default';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    public function normalizeEnvironment(string $environment): string
    {
        return strtolower(trim($environment)) === 'live' ? 'live' : self::DEFAULT_ENVIRONMENT;
    }

    /**
     * Payment method config is a complex payment business object, so it is read by exact scope.
     * The unified SystemConfig center owns inherited display and ordinary key/value config.
     *
     * @return array<int, string>
     */
    public function getScopeChain(string $scope): array
    {
        return [$this->normalizeScope($scope)];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRuntimeOverridesForScope(string $scope = self::DEFAULT_SCOPE, string $environment = self::DEFAULT_ENVIRONMENT): array
    {
        $scope = $this->normalizeScope($scope);
        $environment = $this->normalizeEnvironment($environment);

        $overrides = [];
        foreach ([self::MODULE_WELINE_PAYMENT, self::MODULE_WESHOP_PAYMENT] as $module) {
            foreach ($this->extractMethodConfigsFromModule($module, $scope) as $methodCode => $config) {
                $previousConfig = \is_array($overrides[$methodCode]['config'] ?? null)
                    ? $overrides[$methodCode]['config']
                    : [];
                $overrides[$methodCode] = $this->buildRuntimeOverride(
                    $methodCode,
                    array_replace($previousConfig, $config),
                    $scope,
                    $environment,
                    $module
                );
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function getRuntimeOverrideForMethod(
        string $methodCode,
        string $sourceModule = '',
        string $scope = self::DEFAULT_SCOPE,
        string $environment = self::DEFAULT_ENVIRONMENT,
        array $defaults = []
    ): array {
        $methodCode = $this->normalizeMethodCode($methodCode);
        if ($methodCode === '') {
            return [];
        }

        $scope = $this->normalizeScope($scope);
        $environment = $this->normalizeEnvironment($environment);
        $modules = array_values(array_unique(array_filter([
            self::MODULE_WELINE_PAYMENT,
            trim($sourceModule),
            self::MODULE_WESHOP_PAYMENT,
        ])));
        $config = $defaults;
        $sourceModules = [];

        foreach ($modules as $module) {
            $moduleConfigs = $this->extractMethodConfigsFromModule($module, $scope);
            if (!\is_array($moduleConfigs[$methodCode] ?? null)) {
                continue;
            }
            foreach ($moduleConfigs[$methodCode] as $key => $value) {
                $config[$key] = $value;
                $sourceModules[$key] = $module;
            }
        }

        if ($config === [] && $sourceModules === []) {
            return [];
        }

        $override = $this->buildRuntimeOverride($methodCode, $config, $scope, $environment, (string) end($sourceModules));
        $override['system_config_source_modules'] = array_values(array_unique(array_values($sourceModules)));

        return $override;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function extractMethodConfigsFromModule(string $module, string $scope): array
    {
        if ($module === '') {
            return [];
        }

        $prefix = self::METHOD_CONFIG_PREFIX;
        $methods = [];
        foreach ($this->getSystemConfigMap($module, SystemConfig::area_BACKEND, $scope) as $key => $value) {
            $key = trim((string) $key);
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $parts = explode('/', substr($key, \strlen($prefix)), 2);
            if (\count($parts) !== 2) {
                continue;
            }

            $methodCode = $this->normalizeMethodCode((string) $parts[0]);
            $configKey = trim((string) $parts[1]);
            if ($methodCode === '' || $configKey === '') {
                continue;
            }

            $methods[$methodCode][$configKey] = $this->normalizeConfigValue($configKey, $value);
        }

        return $methods;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRuntimeOverride(string $methodCode, array $config, string $scope, string $environment, string $sourceModule): array
    {
        foreach (['supported_currencies' => 'currencies', 'supported_countries' => 'countries'] as $from => $to) {
            if (\is_array($config[$from] ?? null) && !isset($config[$to])) {
                $config[$to] = $config[$from];
            }
        }

        return [
            'enabled' => $this->toBool($config['enabled'] ?? false),
            'is_default' => $this->toBool($config['is_default'] ?? $config['default'] ?? false),
            'sort_order' => (int) ($config['sort_order'] ?? 0),
            'environment' => $environment,
            'config' => array_replace($config, ['environment' => $environment, 'sandbox' => $environment !== 'live']),
            'scope' => $scope,
            'scope_key' => $scope,
            'scope_chain' => $this->getScopeChain($scope),
            'config_release_code' => (string) ($config['config_release_code'] ?? ''),
            'config_test_status' => (string) ($config['config_test_status'] ?? $config['test_status'] ?? ''),
            'config_test_message' => (string) ($config['config_test_message'] ?? $config['test_message'] ?? ''),
            'config_tested_at' => (string) ($config['config_tested_at'] ?? $config['tested_at'] ?? ''),
            'system_config_source_module' => $sourceModule,
            'method_code' => $methodCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSystemConfigMap(string $module, string $area, string $scope): array
    {
        try {
            return $this->getSystemConfig()->getConfigMapByModule($module, $area, $scope);
        } catch (\Throwable $throwable) {
            w_log_error('读取支付 SystemConfig 配置失败: ' . $module . ', ' . $throwable->getMessage());
            return [];
        }
    }

    private function normalizeConfigValue(string $key, mixed $value): mixed
    {
        if (\in_array($key, self::LIST_KEYS, true)) {
            return $this->normalizeListValue($value, \in_array($key, ['supported_currencies', 'supported_countries', 'currencies', 'countries', 'country_tags'], true));
        }

        if (\in_array($key, ['enabled', 'is_default', 'default', 'sandbox', 'allow_partial_refund', 'require_authenticated_actor'], true)) {
            return $this->toBool($value);
        }

        if (\in_array($key, ['sort_order', 'min_amount_minor', 'max_amount_minor'], true)) {
            return (int) $value;
        }

        if ($key === 'max_discount_ratio') {
            return (float) $value;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeListValue(mixed $value, bool $upper): array
    {
        $items = \is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
        if (!\is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $result[] = $upper ? strtoupper($item) : $item;
        }

        return array_values(array_unique($result));
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function normalizeMethodCode(string $methodCode): string
    {
        return strtolower(trim($methodCode));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveScopeStringFromContext(array $context): string
    {
        $directScope = (string) ($context['scope'] ?? $context['config_scope'] ?? '');
        if (trim($directScope) !== '') {
            return $this->normalizeScope($directScope);
        }

        $websiteCode = strtolower(trim((string) ($context['website_code'] ?? '')));
        $storeCode = strtolower(trim((string) ($context['store_code'] ?? '')));
        if ($websiteCode !== '') {
            return $this->normalizeScope($websiteCode . '.' . ($storeCode !== '' ? $storeCode : 'default') . '.default');
        }

        return self::DEFAULT_SCOPE;
    }

    private function getSystemConfig(): SystemConfig
    {
        if ($this->systemConfig === null) {
            $this->systemConfig = ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SystemConfig::class);
        }

        return $this->systemConfig;
    }
}
