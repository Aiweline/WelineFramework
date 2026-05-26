<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentMethodConfig;
use Weline\Payment\Service\PaymentConfigValidationService;
use Weline\Payment\Service\PaymentScopeConfigService;

class PaymentManagementService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly ?PaymentDocumentationService $documentationService = null,
        private readonly ?PaymentScopeConfigService $scopeConfigService = null,
        private readonly ?PaymentConfigValidationService $configValidationService = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getManagementData(array $filters = []): array
    {
        $scope = $this->getScopeConfigService()->resolveScope($filters);
        $allMethods = $this->addDocumentation($this->paymentService->getManagementPaymentMethods([
            'country' => (string) ($filters['country'] ?? ''),
            'currency' => (string) ($filters['currency'] ?? ''),
            'scope_type' => $scope['scope_type'],
            'scope_code' => $scope['scope_code'],
            'environment' => $scope['environment'],
        ]));
        $methods = $this->filterMethods($allMethods, $filters);
        $enabledMethods = array_values(array_filter($allMethods, static fn(array $method): bool => (bool) ($method['enabled'] ?? false)));
        $checkoutReadyMethods = array_values(array_filter($allMethods, static fn(array $method): bool => (bool) ($method['enabled'] ?? false)
            && (bool) ($method['is_configured'] ?? false)
            && (bool) ($method['has_documentation'] ?? false)
            && (string) ($method['config_test_status'] ?? '') === PaymentMethodConfig::TEST_STATUS_PASSED));
        $defaultMethod = null;
        foreach ($allMethods as $method) {
            if ((bool) ($method['is_default'] ?? false)) {
                $defaultMethod = $method;
                break;
            }
        }

        return [
            'methods' => $methods,
            'all_methods' => $allMethods,
            'providers' => $this->paymentService->getPaymentProviders(),
            'countries' => $this->paymentService->getCountryOptions(),
            'filters' => array_merge($filters, $scope),
            'scope' => $scope,
            'stats' => [
                'total_methods' => count($allMethods),
                'filtered_methods' => count($methods),
                'enabled_methods' => count($enabledMethods),
                'checkout_ready_methods' => count($checkoutReadyMethods),
                'reserved_methods' => count($allMethods) - count($enabledMethods),
                'default_method_code' => (string) ($defaultMethod['code'] ?? ''),
                'default_method_title' => (string) ($defaultMethod['title'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $scope = $this->getScopeConfigService()->resolveScope($payload);
        $methods = $this->addDocumentation($this->paymentService->getManagementPaymentMethods([
            'scope_type' => $scope['scope_type'],
            'scope_code' => $scope['scope_code'],
            'environment' => $scope['environment'],
        ]));
        $inputMethods = \is_array($payload['methods'] ?? null) ? $payload['methods'] : [];
        $defaultMethod = (string) ($payload['default_method'] ?? '');
        $testMethod = trim((string) ($payload['test_method'] ?? ''));
        $normalized = [];
        $blockedWithoutDocs = [];
        $blockedWithoutTests = [];
        $testResult = null;

        foreach ($methods as $method) {
            $code = (string) ($method['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $methodInput = \is_array($inputMethods[$code] ?? null) ? $inputMethods[$code] : [];
            $enabled = $this->toBool($methodInput['enabled'] ?? $method['enabled'] ?? false);
            $hasDocumentation = array_key_exists('has_documentation', $method) ? (bool) $method['has_documentation'] : true;
            if ($enabled && !$hasDocumentation) {
                $enabled = false;
                $blockedWithoutDocs[] = $code;
            }

            $previousConfig = \is_array($method['config'] ?? null) ? $method['config'] : [];
            $config = $this->normalizeConfig(
                \is_array($methodInput['config'] ?? null) ? $methodInput['config'] : [],
                \is_array($method['config_fields'] ?? null) ? $method['config_fields'] : [],
                $previousConfig
            );
            $config['environment'] = $scope['environment'];

            $configChanged = $this->configFingerprint($config) !== $this->configFingerprint($previousConfig);
            $testStatus = (string) ($method['config_test_status'] ?? PaymentMethodConfig::TEST_STATUS_UNTESTED);
            $testMessage = (string) ($method['config_test_message'] ?? '');
            $testedAt = (string) ($method['config_tested_at'] ?? '');

            if ($configChanged && $testMethod !== $code) {
                $testStatus = PaymentMethodConfig::TEST_STATUS_UNTESTED;
                $testMessage = (string) __('Configuration changed after the last test.');
                $testedAt = '';
            }

            if ($testMethod === $code) {
                $testResult = $this->runConfigTest(array_merge($method, ['config' => $config]), $config, $scope);
                $testStatus = (string) $testResult['status'];
                $testMessage = (string) $testResult['message'];
                $testedAt = (string) $testResult['tested_at'];
                if (!$testResult['success']) {
                    $enabled = false;
                }
            }

            if ($enabled && $testStatus !== PaymentMethodConfig::TEST_STATUS_PASSED) {
                $enabled = false;
                $blockedWithoutTests[] = $code;
            }

            $normalized[$code] = [
                'enabled' => $enabled,
                'sort_order' => (int) ($methodInput['sort_order'] ?? $method['sort_order'] ?? 0),
                'popularity_score' => (int) ($methodInput['popularity_score'] ?? $method['popularity_score'] ?? 0),
                'is_default' => false,
                'environment' => $scope['environment'],
                'config' => $config,
                'scope_type' => $scope['scope_type'],
                'scope_code' => $scope['scope_code'],
                'scope_key' => $scope['scope_key'],
                'config_test_status' => $testStatus,
                'config_test_message' => $testMessage,
                'config_tested_at' => $testedAt,
            ];
        }

        if ($blockedWithoutDocs !== []) {
            throw new \InvalidArgumentException((string) __('以下支付方式缺少完整配置文档，不能启用：%{1}', [implode(', ', $blockedWithoutDocs)]));
        }
        if ($blockedWithoutTests !== []) {
            throw new \InvalidArgumentException((string) __('以下支付方式还没有通过当前 scope 的配置测试，不能启用：%{1}', [implode(', ', $blockedWithoutTests)]));
        }

        $enabledCodes = array_keys(array_filter($normalized, static fn(array $method): bool => (bool) ($method['enabled'] ?? false)));
        if ($defaultMethod === '' || !\in_array($defaultMethod, $enabledCodes, true)) {
            $defaultMethod = $enabledCodes[0] ?? '';
        }
        if ($defaultMethod !== '' && isset($normalized[$defaultMethod])) {
            $normalized[$defaultMethod]['is_default'] = true;
        }

        $this->persistMethodConfig($normalized, $scope);

        return [
            'saved_method_count' => count($normalized),
            'default_method' => $defaultMethod,
            'blocked_without_docs' => $blockedWithoutDocs,
            'blocked_without_tests' => $blockedWithoutTests,
            'scope' => $scope,
            'test_result' => $testResult,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    protected function addDocumentation(array $methods): array
    {
        foreach ($methods as &$method) {
            if (!\is_array($method)) {
                continue;
            }
            $method['documentation'] = $this->getDocumentationService()->getDocumentation($method);
        }
        unset($method);

        return $methods;
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    protected function filterMethods(array $methods, array $filters): array
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $provider = strtolower(trim((string) ($filters['provider'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));

        return array_values(array_filter($methods, function (array $method) use ($search, $provider, $status): bool {
            if ($provider !== '' && strtolower((string) ($method['provider_code'] ?? '')) !== $provider) {
                return false;
            }

            if ($status !== '') {
                $matchesStatus = match ($status) {
                    'enabled' => (bool) ($method['enabled'] ?? false),
                    'disabled' => !(bool) ($method['enabled'] ?? false),
                    'configured' => (bool) ($method['is_configured'] ?? false),
                    'missing_config' => !(bool) ($method['is_configured'] ?? false),
                    'missing_doc' => !(bool) ($method['has_documentation'] ?? false),
                    'test_passed' => (string) ($method['config_test_status'] ?? '') === PaymentMethodConfig::TEST_STATUS_PASSED,
                    'test_failed' => (string) ($method['config_test_status'] ?? '') === PaymentMethodConfig::TEST_STATUS_FAILED,
                    'untested' => (string) ($method['config_test_status'] ?? PaymentMethodConfig::TEST_STATUS_UNTESTED) === PaymentMethodConfig::TEST_STATUS_UNTESTED,
                    'checkout_ready' => (bool) ($method['enabled'] ?? false)
                        && (bool) ($method['is_configured'] ?? false)
                        && (bool) ($method['has_documentation'] ?? false)
                        && (string) ($method['config_test_status'] ?? '') === PaymentMethodConfig::TEST_STATUS_PASSED,
                    default => true,
                };
                if (!$matchesStatus) {
                    return false;
                }
            }

            if ($search === '') {
                return true;
            }

            return str_contains($this->getDocumentationService()->getSearchText($method), $search);
        }));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    protected function normalizeConfig(array $input, array $fields, array $defaults): array
    {
        $config = $defaults;
        foreach ($fields as $field) {
            if (!\is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            $hasInput = array_key_exists($key, $input);
            $value = $hasInput ? $input[$key] : ($defaults[$key] ?? '');
            if ($type === 'password' && trim((string) $value) === '' && array_key_exists($key, $defaults)) {
                $config[$key] = $defaults[$key];
                continue;
            }

            $config[$key] = match ($type) {
                'checkbox' => $this->toBool($value),
                'number' => is_numeric($value) ? (string) $value : '0',
                'select' => $this->normalizeSelectValue($value, \is_array($field['options'] ?? null) ? $field['options'] : []),
                default => trim((string) $value),
            };
        }

        return $config;
    }

    protected function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function normalizeSelectValue(mixed $value, array $options): string
    {
        $value = trim((string) $value);
        if ($options === [] || array_key_exists($value, $options)) {
            return $value;
        }

        return (string) array_key_first($options);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @param array<string, string> $scope
     */
    protected function persistMethodConfig(array $config, array $scope): void
    {
        $environment = (string) ($scope['environment'] ?? 'sandbox');
        $scopeKey = (string) ($scope['scope_key'] ?? 'global:default');

        try {
            $scopedConfig = Env::getInstance()->getConfig('payment.method_scopes', []);
        } catch (\Throwable) {
            $scopedConfig = [];
        }
        if (!\is_array($scopedConfig)) {
            $scopedConfig = [];
        }

        $scopedConfig[$scopeKey][$environment] = $config;
        Env::getInstance()->setConfig('payment.method_scopes', $scopedConfig);

        try {
            foreach ($config as $methodCode => $methodConfig) {
                if (!\is_array($methodConfig)) {
                    continue;
                }
                $this->getScopeConfigService()->saveProfile(
                    (string) $methodCode,
                    (string) ($scope['scope_type'] ?? 'global'),
                    (string) ($scope['scope_code'] ?? 'default'),
                    $environment,
                    [
                        'enabled' => (bool) ($methodConfig['enabled'] ?? false),
                        'is_default' => (bool) ($methodConfig['is_default'] ?? false),
                        'sort_order' => (int) ($methodConfig['sort_order'] ?? 0),
                        'config' => \is_array($methodConfig['config'] ?? null) ? $methodConfig['config'] : [],
                        'test_status' => (string) ($methodConfig['config_test_status'] ?? PaymentMethodConfig::TEST_STATUS_UNTESTED),
                        'test_message' => (string) ($methodConfig['config_test_message'] ?? ''),
                        'tested_at' => (string) ($methodConfig['config_tested_at'] ?? ''),
                    ]
                );
            }
        } catch (\Throwable) {
            // Env storage keeps the admin flow readable until setup:upgrade creates the Weline scope table.
        }
    }

    private function getDocumentationService(): PaymentDocumentationService
    {
        return $this->documentationService ?? new PaymentDocumentationService();
    }

    private function getScopeConfigService(): PaymentScopeConfigService
    {
        return $this->scopeConfigService ?? new PaymentScopeConfigService();
    }

    private function getConfigValidationService(): PaymentConfigValidationService
    {
        return $this->configValidationService ?? new PaymentConfigValidationService();
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $config
     * @param array<string, string> $scope
     * @return array{success: bool, status: string, message: string, missing_config: array<int, string>, details: array<string, mixed>, tested_at: string}
     */
    protected function runConfigTest(array $method, array $config, array $scope): array
    {
        $provider = $this->resolveProviderInstance($method);

        return $this->getConfigValidationService()->validateMethod($method, $config, $provider, [
            'scope_type' => (string) ($scope['scope_type'] ?? 'global'),
            'scope_code' => (string) ($scope['scope_code'] ?? 'default'),
            'scope_key' => (string) ($scope['scope_key'] ?? 'global:default'),
            'environment' => (string) ($scope['environment'] ?? 'sandbox'),
            'require_documentation' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $method
     */
    private function resolveProviderInstance(array $method): ?object
    {
        $providerClass = (string) ($method['provider'] ?? '');
        if ($providerClass === '' || !class_exists($providerClass)) {
            return null;
        }

        try {
            return ObjectManager::getInstance($providerClass);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configFingerprint(array $config): string
    {
        ksort($config);

        return hash('sha256', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
