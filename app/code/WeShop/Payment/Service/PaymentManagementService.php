<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Payment\Model\PaymentMethodConfig;
use Weline\Payment\Service\PaymentScopeConfigService;

class PaymentManagementService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly ?PaymentDocumentationService $documentationService = null,
        private readonly ?PaymentScopeConfigService $scopeConfigService = null
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
            'scope' => $scope['scope'],
            'environment' => $scope['environment'],
        ]));
        $methods = $this->filterMethods($allMethods, $filters);
        $enabledMethods = array_values(array_filter($allMethods, static fn(array $method): bool => (bool) ($method['enabled'] ?? false)));
        $checkoutReadyMethods = array_values(array_filter($allMethods, static fn(array $method): bool => (bool) ($method['enabled'] ?? false)
            && (bool) ($method['is_configured'] ?? false)
            && (bool) ($method['has_documentation'] ?? false)
            && self::isConfigTestPassed($method)));
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
        throw new \LogicException((string) __('WeShop 支付普通配置请通过系统配置中心保存。'));
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
                        && self::isConfigTestPassed($method),
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

    private function getDocumentationService(): PaymentDocumentationService
    {
        return $this->documentationService ?? new PaymentDocumentationService();
    }

    private function getScopeConfigService(): PaymentScopeConfigService
    {
        return $this->scopeConfigService ?? new PaymentScopeConfigService();
    }

    /**
     * @param array<string, mixed> $method
     */
    private static function isConfigTestPassed(array $method): bool
    {
        $status = trim((string) ($method['config_test_status'] ?? ''));

        return $status === '' || $status === PaymentMethodConfig::TEST_STATUS_PASSED;
    }

}
