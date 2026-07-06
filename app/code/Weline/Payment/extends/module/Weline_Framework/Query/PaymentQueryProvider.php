<?php
declare(strict_types=1);

namespace Weline\Payment\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\AvailabilityResult;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Service\PaymentMethodManager;

class PaymentQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly ObjectManager $objectManager
    ) {
    }

    public function getProviderName(): string
    {
        return 'payment';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getCheckoutPaymentMethods', 'getMethods', 'listMethods' => $this->getCheckoutPaymentMethods($params),
            'getAvailablePaymentMethods' => $this->getAvailablePaymentMethods($params),
            'getPaymentMethod' => $this->getPaymentMethod((string)($params['code'] ?? $params['method_code'] ?? ''), $params),
            'getPaymentMethodSummary' => $this->getPaymentMethodSummary($params),
            'getPaymentDashboardSummary' => $this->getPaymentDashboardSummary($params),
            'registerProviders' => $this->registerProviders(),
            default => throw new \InvalidArgumentException(
                (string)__('Payment query provider does not support operation: %{1}', [$operation])
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function getCheckoutPaymentMethods(array $params): array
    {
        $includeUnavailable = $this->boolParam($params, 'include_unavailable', false);
        $methods = [];
        foreach ($this->getActivePaymentMethods($params) as $method) {
            $item = $this->paymentMethodPayload($method, $params);
            if (!$includeUnavailable && empty($item['enabled'])) {
                continue;
            }

            $methods[] = $item;
        }

        usort($methods, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 100)) <=> ((int)($right['sort_order'] ?? 100)));

        return $methods;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function getAvailablePaymentMethods(array $params): array
    {
        $available = [];
        foreach ($this->getCheckoutPaymentMethods($params) as $method) {
            $available[(string)$method['code']] = (string)$method['label'];
        }

        return $available;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function getPaymentMethod(string $code, array $params): ?array
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return null;
        }

        $this->methodManager->registerAllProviders();
        $method = $this->methodManager->getMethodByCode($code);
        if (!$method instanceof PaymentMethod) {
            return null;
        }

        return $this->paymentMethodPayload($method, $params + ['include_unavailable' => true]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getPaymentMethodSummary(array $params): array
    {
        $registered = $this->methodManager->registerAllProviders();
        $rows = $this->getAllPaymentMethodRows();
        $active = $this->getActivePaymentMethods($params);
        $available = $this->getCheckoutPaymentMethods($params);

        return [
            'success' => true,
            'source' => 'Weline_Payment',
            'providers_registered' => $registered,
            'total_methods' => \count($rows),
            'active_methods' => \count($active),
            'available_methods' => \count($available),
            'methods' => array_values(array_map(
                fn(PaymentMethod $method): array => $this->paymentMethodPayload($method, $params + ['include_unavailable' => true]),
                $rows
            )),
            'message' => \count($available) > 0
                ? (string)__('已有 %{1} 个可用于结账的支付方式。', [\count($available)])
                : (string)__('暂无可用于结账的支付方式，请启用并配置至少一个 Weline_Payment 支付 Provider。'),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getPaymentDashboardSummary(array $params): array
    {
        $summary = $this->getPaymentMethodSummary($params);

        return [
            'success' => true,
            'source' => 'Weline_Payment',
            'health' => ((int)($summary['available_methods'] ?? 0)) > 0 ? 'ready' : 'needs_configuration',
            'total_methods' => $summary['total_methods'] ?? 0,
            'active_methods' => $summary['active_methods'] ?? 0,
            'available_methods' => $summary['available_methods'] ?? 0,
            'message' => $summary['message'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerProviders(): array
    {
        return [
            'success' => true,
            'source' => 'Weline_Payment',
            'providers_registered' => $this->methodManager->registerAllProviders(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return PaymentMethod[]
     */
    private function getActivePaymentMethods(array $context): array
    {
        $this->methodManager->registerAllProviders();

        return $this->methodManager->getActiveMethods($context);
    }

    /**
     * @return PaymentMethod[]
     */
    private function getAllPaymentMethodRows(): array
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $rows = $paymentMethod
            ->order(PaymentMethod::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();
        if (\is_object($rows) && method_exists($rows, 'getItems')) {
            $rows = $rows->getItems();
        }
        if (!\is_array($rows)) {
            return [];
        }

        $methods = array_values(array_filter($rows, static fn(mixed $row): bool => $row instanceof PaymentMethod));
        $unique = [];
        foreach ($methods as $method) {
            $code = strtolower(trim((string)$method->getData(PaymentMethod::schema_fields_CODE)));
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

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function paymentMethodPayload(PaymentMethod $method, array $context): array
    {
        $provider = $this->methodManager->getProviderInstance($method, $context);
        $metadata = $this->methodManager->getProviderMetadata($method, $provider);
        $display = \is_array($metadata['display_metadata'] ?? null) ? $metadata['display_metadata'] : [];
        $runtimeConfig = $this->methodManager->getRuntimeConfig($method, $context);
        $availability = $this->availability($method, $provider, $metadata, $runtimeConfig, $context);
        $code = (string)($metadata['method_code'] ?? $method->getData(PaymentMethod::schema_fields_CODE));
        $label = trim((string)($display['title'] ?? $display['label'] ?? $display['name'] ?? $method->getData(PaymentMethod::schema_fields_NAME) ?? $code));
        $description = trim((string)($display['description'] ?? ''));

        return [
            'code' => $code,
            'label' => $label !== '' ? $label : $code,
            'name' => $label !== '' ? $label : $code,
            'title' => $label !== '' ? $label : $code,
            'description' => $description,
            'enabled' => $availability->isAvailable(),
            'is_default' => $this->toBool($runtimeConfig['is_default'] ?? false),
            'sort_order' => $availability->getSortWeight() > 0
                ? $availability->getSortWeight()
                : (int)($runtimeConfig['sort_order'] ?? $method->getData(PaymentMethod::schema_fields_SORT_ORDER) ?? 100),
            'flow' => $this->paymentFlow($metadata),
            'source' => 'Weline_Payment',
            'provider_code' => (string)($metadata['provider_code'] ?? ''),
            'provider_module' => (string)$method->getData(PaymentMethod::schema_fields_PROVIDER_MODULE),
            'checkout_template_code' => (string)($metadata['checkout_template_code'] ?? $code),
            'config_template_code' => (string)($metadata['config_template_code'] ?? $code),
            'requires_terms' => $availability->requiresTerms(),
            'disabled_reason_code' => (string)($availability->getDisabledReasonCode() ?? ''),
            'disabled_reason' => (string)($availability->getDisabledReasonText() ?? ''),
            'dynamic_form_schema' => $availability->getDynamicFormSchema(),
            'capabilities' => \is_array($metadata['capabilities'] ?? null) ? $metadata['capabilities'] : [],
            'display_metadata' => $display,
            'runtime_config' => $runtimeConfig,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $runtimeConfig
     * @param array<string, mixed> $context
     */
    private function availability(
        PaymentMethod $method,
        ?ProviderInterface $provider,
        array $metadata,
        array $runtimeConfig,
        array $context
    ): AvailabilityResult {
        if (!$provider instanceof ProviderInterface) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'provider_unavailable',
                'disabled_reason_text' => (string)__('支付 Provider 实例不可用。'),
                'sort_weight' => (int)($runtimeConfig['sort_order'] ?? $method->getData(PaymentMethod::schema_fields_SORT_ORDER) ?? 100),
            ]);
        }

        $methodCode = (string)($metadata['method_code'] ?? $method->getData(PaymentMethod::schema_fields_CODE));
        $currencyCode = $this->currencyCode($context, $runtimeConfig, $metadata);

        try {
            return $provider->checkAvailability(AvailabilityRequest::fromArray([
                'payable_type' => (string)($context['payable_type'] ?? 'checkout'),
                'payable_id' => (string)($context['payable_id'] ?? $context['order_id'] ?? 'cart'),
                'method_code' => $methodCode,
                'scope' => (string)($context['scope'] ?? $runtimeConfig['scope'] ?? 'default.default.default'),
                'amount_minor' => $this->amountMinor($context, $currencyCode),
                'currency_code' => $currencyCode,
                'country_code' => (string)($context['country_code'] ?? $context['country'] ?? ''),
                'language_code' => (string)($context['language_code'] ?? $context['locale'] ?? ''),
                'business_tags' => \is_array($context['business_tags'] ?? null) ? $context['business_tags'] : [],
                'context' => $context + [
                    'runtime_config' => $runtimeConfig,
                    'payment_metadata' => $metadata,
                ],
            ]));
        } catch (\Throwable $throwable) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'availability_check_failed',
                'disabled_reason_text' => $throwable->getMessage(),
                'sort_weight' => (int)($runtimeConfig['sort_order'] ?? $method->getData(PaymentMethod::schema_fields_SORT_ORDER) ?? 100),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function paymentFlow(array $metadata): string
    {
        $display = \is_array($metadata['display_metadata'] ?? null) ? $metadata['display_metadata'] : [];
        $flow = strtolower(trim((string)($display['flow'] ?? '')));
        if ($flow !== '') {
            return $flow;
        }

        $capabilities = \is_array($metadata['capabilities'] ?? null) ? $metadata['capabilities'] : [];
        if (!empty($capabilities['offline_confirmation'])) {
            return 'offline_confirmation';
        }

        return 'provider_checkout';
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $runtimeConfig
     * @param array<string, mixed> $metadata
     */
    private function currencyCode(array $context, array $runtimeConfig, array $metadata): string
    {
        foreach (['currency_code', 'currency'] as $key) {
            $value = strtoupper(trim((string)($context[$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        $capabilities = \is_array($metadata['capabilities'] ?? null) ? $metadata['capabilities'] : [];
        $value = strtoupper(trim((string)($runtimeConfig['default_currency'] ?? $capabilities['default_currency'] ?? '')));
        if ($value !== '') {
            return $value;
        }

        try {
            $value = strtoupper(trim((string)\w_env('user.currency', '')));
            if ($value !== '') {
                return $value;
            }
        } catch (\Throwable) {
        }

        return 'CNY';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function amountMinor(array $context, string $currencyCode): int
    {
        if (isset($context['amount_minor']) && is_numeric($context['amount_minor'])) {
            return max(0, (int)$context['amount_minor']);
        }

        foreach (['grand_total', 'total_amount', 'amount', 'subtotal'] as $key) {
            if (isset($context[$key]) && is_numeric($context[$key])) {
                $minorUnit = \in_array(strtoupper($currencyCode), ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 1 : 100;
                return max(0, (int)round(((float)$context[$key]) * $minorUnit));
            }
        }

        return 0;
    }

    private function boolParam(array $params, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }

        return $this->toBool($params[$key]);
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (float)$value > 0;
        }

        return \in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDescriptor(): array
    {
        $commonReturns = ['type' => 'array'];

        return [
            'provider' => 'payment',
            'name' => __('Payment methods'),
            'description' => __('Weline_Payment payment method registry and checkout availability provider.'),
            'module' => 'Weline_Payment',
            'operations' => [
                [
                    'name' => 'getCheckoutPaymentMethods',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        ['name' => 'currency_code', 'type' => 'string', 'required' => false],
                        ['name' => 'country_code', 'type' => 'string', 'required' => false],
                        ['name' => 'amount_minor', 'type' => 'int', 'required' => false],
                        ['name' => 'include_unavailable', 'type' => 'bool', 'required' => false],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read checkout payment methods from Weline_Payment providers.',
                ],
                [
                    'name' => 'getAvailablePaymentMethods',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Read available checkout payment methods as code-label pairs.',
                ],
                [
                    'name' => 'getPaymentMethod',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        ['name' => 'code', 'type' => 'string', 'required' => true],
                    ],
                    'returns' => ['type' => 'array|null'],
                    'summary' => 'Read one payment method and its provider metadata.',
                ],
                [
                    'name' => 'getPaymentMethodSummary',
                    'frontend' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Read payment method readiness summary for SystemConfig adapters.',
                ],
                [
                    'name' => 'getPaymentDashboardSummary',
                    'frontend' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Read payment dashboard readiness summary.',
                ],
                [
                    'name' => 'registerProviders',
                    'frontend' => false,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Register payment providers discovered from module extensions.',
                ],
            ],
        ];
    }
}
