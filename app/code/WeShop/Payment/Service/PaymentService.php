<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentMethodConfig;
use Weline\Payment\Service\PaymentScopeConfigService;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class PaymentService
{
    private ?PaymentMethodLocalDescriptionService $localDescriptionService = null;
    private ?PaymentCatalogueService $catalogueService = null;
    private ?PaymentDocumentationService $documentationService = null;
    private ?PaymentScopeConfigService $scopeConfigService = null;

    public function __construct(
        ?PaymentMethodLocalDescriptionService $localDescriptionService = null,
        ?PaymentCatalogueService $catalogueService = null,
        ?PaymentDocumentationService $documentationService = null,
        ?PaymentScopeConfigService $scopeConfigService = null
    ) {
        $this->localDescriptionService = $localDescriptionService;
        $this->catalogueService = $catalogueService;
        $this->documentationService = $documentationService;
        $this->scopeConfigService = $scopeConfigService;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getMethodRegistry(): array
    {
        return $this->getCatalogueService()->getMethodRegistry();
    }

    public function processPayment(Order $order, string $paymentMethod, array $paymentData = []): array
    {
        $runtimeContext = $this->buildRuntimeContext($paymentData);
        $method = $this->requireMethod($paymentMethod, $runtimeContext);
        if (!$this->isEnabled($method)) {
            throw new \InvalidArgumentException((string) __('Unsupported payment method: %{1}', [$paymentMethod]));
        }
        if (!$this->isConfigured($method)) {
            throw new \InvalidArgumentException((string) __('Payment method %{1} is missing required configuration: %{2}', [
                $paymentMethod,
                implode(', ', (array) ($method['missing_config'] ?? [])),
            ]));
        }
        if (!$this->isConfigTestPassed($method)) {
            throw new \InvalidArgumentException((string) __('Payment method %{1} has not passed configuration testing for this scope.', [$paymentMethod]));
        }

        $provider = $this->resolveProvider($method);
        $providerContext = $this->buildProviderContext($method, $runtimeContext);
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

    public function getPaymentMethod(string $code, array $context = []): ?array
    {
        $registry = $this->getMethodRegistry();
        if (!isset($registry[$code])) {
            return null;
        }

        return $this->localizeMethod($this->applyRuntimeOverrides($this->normalizeMethod($registry[$code]), $context));
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
    public function getManagementPaymentMethods(array $filters = []): array
    {
        return $this->filterAndSortMethods(array_merge(['area' => 'backend'], $filters), false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPaymentProviders(): array
    {
        return $this->getCatalogueService()->getProviders();
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function getCountryOptions(string $locale = 'zh_Hans_CN'): array
    {
        return $this->getCatalogueService()->getCountryOptions($locale);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function filterAndSortMethods(array $context, bool $enabledOnly): array
    {
        $methods = [];
        foreach ($this->getMethodRegistry() as $method) {
            $method = $this->normalizeMethod($method);
            $method = $this->applyRuntimeOverrides($method, $context);
            $method = $this->localizeMethod($method, (string) ($context['locale'] ?? ''));
            if ($enabledOnly && (!$this->isEnabled($method) || !$this->isConfigured($method) || empty($method['has_documentation']) || !$this->isConfigTestPassed($method))) {
                continue;
            }
            if (!$this->matchesContext($method, $context)) {
                continue;
            }
            $methods[] = $method;
        }

        $country = strtoupper((string) ($context['country'] ?? $context['country_id'] ?? ''));
        usort($methods, function (array $left, array $right) use ($country): int {
            $leftScore = $this->resolveSortPopularity($left, $country);
            $rightScore = $this->resolveSortPopularity($right, $country);
            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            $sortOrder = ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0));
            if ($sortOrder !== 0) {
                return $sortOrder;
            }

            return strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
        });

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
    protected function requireMethod(string $paymentMethod, array $context = []): array
    {
        $method = $this->getPaymentMethod($paymentMethod, $context);
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
        $countryPopularity = $this->normalizeCountryPopularity((array) ($method['country_popularity'] ?? []));
        $countries = $this->normalizeCodes((array) ($method['countries'] ?? []), true);
        $countryTags = $this->normalizeCodes((array) ($method['country_tags'] ?? array_merge($countries, array_keys($countryPopularity))), true);
        $flow = (string) ($method['flow'] ?? '');
        $methodType = (string) ($method['method_type'] ?? '');
        $defaultTestStatus = $this->isStaticTestMethod($flow, $methodType)
            ? PaymentMethodConfig::TEST_STATUS_PASSED
            : PaymentMethodConfig::TEST_STATUS_UNTESTED;

        return [
            'code' => (string) ($method['code'] ?? ''),
            'provider_code' => (string) ($method['provider_code'] ?? ''),
            'provider_title' => (string) ($method['provider_title'] ?? ''),
            'title' => (string) ($method['title'] ?? ''),
            'description' => (string) ($method['description'] ?? ''),
            'provider' => (string) ($method['provider'] ?? ''),
            'enabled' => (bool) ($method['enabled'] ?? false),
            'is_default' => (bool) ($method['is_default'] ?? false),
            'sort_order' => (int) ($method['sort_order'] ?? 0),
            'popularity_score' => (int) ($method['popularity_score'] ?? 0),
            'icon' => (string) ($method['icon'] ?? ''),
            'checkout_note' => (string) ($method['checkout_note'] ?? ''),
            'method_type' => $methodType,
            'flow' => $flow,
            'areas' => $this->normalizeCodes((array) ($method['areas'] ?? []), false),
            'currencies' => $this->normalizeCodes((array) ($method['currencies'] ?? []), true),
            'countries' => $countries,
            'country_tags' => $countryTags,
            'country_popularity' => $countryPopularity,
            'config' => \is_array($method['config'] ?? null) ? $method['config'] : [],
            'config_fields' => \is_array($method['config_fields'] ?? null) ? $method['config_fields'] : [],
            'required_config' => array_values(array_map(static fn(mixed $value): string => (string) $value, (array) ($method['required_config'] ?? []))),
            'documentation_path' => (string) ($method['documentation_path'] ?? ''),
            'local_descriptions' => \is_array($method['local_descriptions'] ?? null) ? $method['local_descriptions'] : [],
            'missing_config' => [],
            'is_configured' => true,
            'environment' => 'sandbox',
            'has_documentation' => false,
            'documentation_valid' => false,
            'scope_type' => 'global',
            'scope_code' => 'default',
            'scope_key' => 'global:default',
            'config_test_status' => (string) ($method['config_test_status'] ?? $defaultTestStatus),
            'config_test_message' => (string) ($method['config_test_message'] ?? ''),
            'config_tested_at' => (string) ($method['config_tested_at'] ?? ''),
            'requires_remote_test' => (bool) ($method['requires_remote_test'] ?? !$this->isStaticTestMethod($flow, $methodType)),
        ];
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    protected function applyRuntimeOverrides(array $method, array $context = []): array
    {
        $code = (string) ($method['code'] ?? '');
        $override = $code !== '' ? $this->resolveMethodOverride($code, $context) : null;

        if (\is_array($override)) {
            foreach (['title', 'description', 'icon', 'checkout_note', 'provider_code', 'method_type', 'flow', 'documentation_path', 'scope_type', 'scope_code', 'config_test_status', 'config_test_message', 'config_tested_at'] as $key) {
                if (array_key_exists($key, $override)) {
                    $method[$key] = (string) $override[$key];
                }
            }
            foreach (['enabled', 'is_default', 'requires_remote_test'] as $key) {
                if (array_key_exists($key, $override)) {
                    $method[$key] = (bool) $override[$key];
                }
            }
            foreach (['sort_order', 'popularity_score'] as $key) {
                if (array_key_exists($key, $override)) {
                    $method[$key] = (int) $override[$key];
                }
            }
            foreach (['areas', 'currencies', 'countries', 'country_tags', 'required_config'] as $key) {
                if (\is_array($override[$key] ?? null)) {
                    $method[$key] = array_values($override[$key]);
                }
            }
            if (\is_array($override['country_popularity'] ?? null)) {
                $method['country_popularity'] = $this->normalizeCountryPopularity($override['country_popularity']);
            }
            if (\is_array($override['local_descriptions'] ?? null)) {
                $method['local_descriptions'] = $override['local_descriptions'];
            }
            if (\is_array($override['local_description'] ?? null)) {
                $method['local_descriptions'] = $override['local_description'];
            }
            if (\is_array($override['config'] ?? null)) {
                $method['config'] = array_replace((array) ($method['config'] ?? []), $override['config']);
                if (array_key_exists('sandbox', $override['config']) && !array_key_exists('environment', $override['config'])) {
                    $method['config']['environment'] = $this->toBool($override['config']['sandbox']) ? 'sandbox' : 'live';
                }
            }
            if (array_key_exists('environment', $override)) {
                $method['config']['environment'] = (string) $override['environment'];
            }
        }

        $scope = $this->getScopeConfigService()->resolveScope($context);
        if (empty($method['scope_type']) || empty($method['scope_code'])) {
            $method['scope_type'] = $scope['scope_type'];
            $method['scope_code'] = $scope['scope_code'];
        }
        $method['scope_key'] = $this->getScopeConfigService()->buildScopeKey((string) $method['scope_type'], (string) $method['scope_code']);

        $providers = $this->getPaymentProviders();
        $providerCode = (string) ($method['provider_code'] ?? '');
        if ($providerCode !== '' && isset($providers[$providerCode])) {
            $method['provider_title'] = (string) ($providers[$providerCode]['title'] ?? $providerCode);
        }

        $method = $this->applyEnvironmentConfig($method);
        $method['missing_config'] = $this->getMissingRequiredConfig($method);
        $method['is_configured'] = $method['missing_config'] === [];
        $method['has_documentation'] = $this->getDocumentationService()->hasDocumentation($method);
        $method['documentation_valid'] = (bool) $method['has_documentation'];

        return $method;
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    protected function localizeMethod(array $method, string $locale = ''): array
    {
        return $this->getLocalDescriptionService()->localize($method, $locale !== '' ? $locale : null);
    }

    protected function getLocalDescriptionService(): PaymentMethodLocalDescriptionService
    {
        if ($this->localDescriptionService === null) {
            $this->localDescriptionService = new PaymentMethodLocalDescriptionService();
        }

        return $this->localDescriptionService;
    }

    protected function getCatalogueService(): PaymentCatalogueService
    {
        if ($this->catalogueService === null) {
            $this->catalogueService = new PaymentCatalogueService();
        }

        return $this->catalogueService;
    }

    protected function getDocumentationService(): PaymentDocumentationService
    {
        if ($this->documentationService === null) {
            $this->documentationService = new PaymentDocumentationService();
        }

        return $this->documentationService;
    }

    protected function getScopeConfigService(): PaymentScopeConfigService
    {
        if ($this->scopeConfigService === null) {
            $this->scopeConfigService = new PaymentScopeConfigService();
        }

        return $this->scopeConfigService;
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
     * @return array<string, array<string, mixed>>
     */
    protected function getScopedMethodOverrides(array $context): array
    {
        $scope = $this->getScopeConfigService()->resolveScope($context);

        try {
            $dbOverrides = $this->getScopeConfigService()->getRuntimeOverridesForScope(
                $scope['scope_type'],
                $scope['scope_code'],
                $scope['environment']
            );
            if ($dbOverrides !== []) {
                return $dbOverrides;
            }
        } catch (\Throwable) {
            // The scoped DB table may not exist until setup:upgrade has run; Env fallback keeps admin readable.
        }

        try {
            $config = Env::getInstance()->getConfig('payment.method_scopes', []);
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($config)) {
            return [];
        }

        $scopeKey = $scope['scope_key'];
        $environment = $scope['environment'];
        $scoped = $config[$scopeKey][$environment] ?? $config[$scopeKey] ?? [];

        return \is_array($scoped) ? $scoped : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveMethodOverride(string $code, array $context): ?array
    {
        $legacy = $this->getMethodOverrides();
        $override = \is_array($legacy[$code] ?? null) ? $legacy[$code] : [];
        $scoped = $this->getScopedMethodOverrides($context);
        if (\is_array($scoped[$code] ?? null)) {
            $override = array_replace_recursive($override, $scoped[$code]);
        }

        return $override !== [] ? $override : null;
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
     */
    protected function isConfigTestPassed(array $method): bool
    {
        return (string) ($method['config_test_status'] ?? PaymentMethodConfig::TEST_STATUS_UNTESTED) === PaymentMethodConfig::TEST_STATUS_PASSED;
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function buildProviderContext(array $method, array $context = []): array
    {
        $config = \is_array($method['config'] ?? null) ? $method['config'] : [];

        return array_merge($context, [
            'payment_method' => $method,
            'config' => $config,
            'required_config' => array_values((array) ($method['required_config'] ?? [])),
            'missing_config' => array_values((array) ($method['missing_config'] ?? [])),
            'is_configured' => (bool) ($method['is_configured'] ?? true),
            'environment' => (string) ($method['environment'] ?? $config['environment'] ?? 'sandbox'),
            'sandbox' => (bool) ($config['sandbox'] ?? true),
            'scope_type' => (string) ($method['scope_type'] ?? 'global'),
            'scope_code' => (string) ($method['scope_code'] ?? 'default'),
            'scope_key' => (string) ($method['scope_key'] ?? 'global:default'),
            'config_test_status' => (string) ($method['config_test_status'] ?? PaymentMethodConfig::TEST_STATUS_UNTESTED),
            'config_tested_at' => (string) ($method['config_tested_at'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    protected function applyEnvironmentConfig(array $method): array
    {
        $config = \is_array($method['config'] ?? null) ? $method['config'] : [];
        $environment = strtolower(trim((string) ($config['environment'] ?? $method['environment'] ?? '')));
        if ($environment === '') {
            $environment = isset($config['sandbox']) && !$this->toBool($config['sandbox']) ? 'live' : 'sandbox';
        }
        $environment = $environment === 'live' ? 'live' : 'sandbox';

        foreach ($config as $key => $value) {
            if (!\is_string($key) || !str_starts_with($key, $environment . '_')) {
                continue;
            }
            $baseKey = substr($key, strlen($environment) + 1);
            if ($baseKey === '') {
                continue;
            }
            if (trim((string) $value) !== '' || !array_key_exists($baseKey, $config)) {
                $config[$baseKey] = $value;
            }
        }

        $config['environment'] = $environment;
        $config['sandbox'] = $environment !== 'live';
        $method['environment'] = $environment;
        $method['config'] = $config;

        return $method;
    }

    /**
     * @param array<string, mixed> $method
     */
    private function resolveSortPopularity(array $method, string $country): int
    {
        $countryPopularity = \is_array($method['country_popularity'] ?? null) ? $method['country_popularity'] : [];
        if ($country !== '' && isset($countryPopularity[$country])) {
            return (int) $countryPopularity[$country];
        }

        return (int) ($method['popularity_score'] ?? 0);
    }

    /**
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    private function buildRuntimeContext(array $paymentData): array
    {
        $context = [
            'payment_data' => $paymentData,
            'currency' => $paymentData['currency'] ?? null,
            'country' => $paymentData['country'] ?? $paymentData['country_id'] ?? null,
        ];

        foreach (['scope_type', 'scope_code', 'environment', 'website_id', 'store_id'] as $key) {
            if (array_key_exists($key, $paymentData)) {
                $context[$key] = $paymentData[$key];
            }
        }

        return $context;
    }

    private function isStaticTestMethod(string $flow, string $methodType): bool
    {
        $flow = strtolower($flow);
        $methodType = strtolower($methodType);

        return \in_array($flow, ['offline', 'event'], true)
            || \in_array($methodType, ['manual', 'offline', 'bank_transfer', 'credit'], true);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function normalizeCodes(array $values, bool $upper): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $code = trim((string) $value);
            if ($code === '') {
                continue;
            }
            $normalized[] = $upper ? strtoupper($code) : strtolower($code);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, int>
     */
    private function normalizeCountryPopularity(array $values): array
    {
        $normalized = [];
        foreach ($values as $country => $score) {
            $country = strtoupper((string) $country);
            if (strlen($country) !== 2) {
                continue;
            }
            $normalized[$country] = (int) $score;
        }

        return $normalized;
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
