<?php

declare(strict_types=1);

namespace WeShop\Payment\Extends\Module\Weline_Payment\PaymentProvider;

use Throwable;
use WeShop\Order\Model\Order;
use WeShop\Payment\Provider\CashOnDelivery;
use Weline\Payment\Api\Data\AuthorizeRequest;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\AvailabilityResult;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\CallbackResult;
use Weline\Payment\Api\Data\CaptureRequest;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\ProviderError;
use Weline\Payment\Api\Data\QueryRequest;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Api\Data\ResumeRequest;
use Weline\Payment\Api\Data\TestConnectionRequest;
use Weline\Payment\Api\Data\VoidRequest;
use Weline\Payment\Interface\ProviderInterface;

final class CashOnDeliveryProvider implements ProviderInterface
{
    private const METHOD_CODE = 'cash_on_delivery';
    private const PROVIDER_CODE = 'manual';

    public function getCode(): string
    {
        return self::METHOD_CODE;
    }

    public function getProviderCode(): string
    {
        return self::PROVIDER_CODE;
    }

    public function getProviderApiVersion(): string
    {
        return '1.0';
    }

    public function getWebhookSchemaVersion(): string
    {
        return '1.0';
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return [
            'payment' => true,
            'refund' => false,
            'partial_refund' => false,
            'authorize' => false,
            'capture' => false,
            'void' => false,
            'saved_instrument' => false,
            'offline_confirmation' => true,
            'supported_currencies' => [],
            'supported_countries' => ['AE', 'SA', 'KW', 'QA', 'OM', 'BH', 'IN', 'ID', 'TH', 'VN', 'PH'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array
    {
        return [
            'title' => (string) __('货到付款'),
            'description' => (string) __('配送送达时现场收款，由 WeShop 旧货到付款 Provider 适配到 Weline_Payment。'),
            'checkout_template_code' => self::METHOD_CODE,
            'config_template_code' => self::METHOD_CODE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array
    {
        return [
            'instructions' => [
                'type' => 'textarea',
                'value_type' => 'string',
                'label' => (string) __('说明'),
                'default' => (string) __('配送送达时向客户收款。'),
            ],
            'fee' => [
                'type' => 'number',
                'value_type' => 'float',
                'label' => (string) __('货到付款手续费'),
                'default' => '0',
            ],
            'supported_countries' => [
                'type' => 'multiselect',
                'value_type' => 'json',
                'label' => (string) __('支持国家'),
                'default' => $this->getCapabilities()['supported_countries'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDynamicFormSchema(AvailabilityRequest $request): array
    {
        return [];
    }

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResult
    {
        if ($request->getAmountMinor() <= 0) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'amount_required',
                'disabled_reason_text' => (string) __('支付金额必须大于 0。'),
            ]);
        }

        $config = $this->getRuntimeConfig($request->getContext());
        if (array_key_exists('enabled', $config) && !$this->toBool($config['enabled'])) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'method_disabled',
                'disabled_reason_text' => (string) __('当前 scope 未启用货到付款。'),
            ]);
        }

        $countryCode = $request->getCountryCode();
        $supportedCountries = $this->getConfiguredList($config, 'supported_countries', $this->getCapabilities()['supported_countries']);
        if ($countryCode !== null && $supportedCountries !== [] && !\in_array($countryCode, $supportedCountries, true)) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'country_not_supported',
                'disabled_reason_text' => (string) __('当前国家不支持货到付款。'),
            ]);
        }

        $supportedCurrencies = $this->getConfiguredList($config, 'supported_currencies', []);
        if ($supportedCurrencies !== [] && !\in_array($request->getCurrencyCode(), $supportedCurrencies, true)) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'currency_not_supported',
                'disabled_reason_text' => (string) __('当前币种不支持货到付款。'),
            ]);
        }

        return AvailabilityResult::fromArray([
            'available' => true,
            'sort_weight' => 220,
            'requires_terms' => true,
        ]);
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $legacyResult = $this->runLegacyProcessPayment($request);
        } catch (Throwable $throwable) {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'retryable' => false,
                'message' => $throwable->getMessage(),
            ]);
        }

        $redirectUrl = (string) ($legacyResult['redirect_url'] ?? '');

        return PaymentResult::fromArray([
            'status' => $this->mapPaymentStatus((string) ($legacyResult['status'] ?? PaymentResult::STATUS_PENDING)),
            'action_type' => $redirectUrl !== '' ? 'redirect' : null,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference() ?: self::METHOD_CODE . '-' . $request->getIntentCode(),
            'message' => (string) ($legacyResult['instructions'] ?? __('配送送达时向客户收款。')),
            'payload' => array_merge($legacyResult, [
                'legacy_provider_class' => CashOnDelivery::class,
                'adapter' => self::class,
            ]),
        ]);
    }

    public function resumePayment(ResumeRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_PENDING,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference(),
            'message' => (string) __('货到付款等待线下收款确认。'),
        ]);
    }

    public function authorize(AuthorizeRequest $request): PaymentResult
    {
        return $this->unsupportedPaymentResult($request, (string) __('货到付款不支持授权操作。'));
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        return $this->unsupportedPaymentResult($request, (string) __('货到付款不支持捕获操作。'));
    }

    public function void(VoidRequest $request): PaymentResult
    {
        return $this->unsupportedPaymentResult($request, (string) __('货到付款不支持 void 操作。'));
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return RefundResult::fromArray([
            'status' => RefundResult::STATUS_UNSUPPORTED,
            'refund_code' => $request->getRefundCode(),
            'transaction_code' => $request->getTransactionCode(),
            'provider_reference' => $request->getProviderReference(),
            'retryable' => false,
            'message' => (string) __('货到付款不支持 Provider 侧退款。'),
        ]);
    }

    public function query(QueryRequest $request): PaymentResult
    {
        $status = $this->legacyProvider()->queryPaymentStatus(
            $request->getPayableId() !== '' ? $request->getPayableId() : $request->getIntentCode(),
            $this->buildLegacyContext($request)
        );

        return PaymentResult::fromArray([
            'status' => $this->mapPaymentStatus($status),
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference(),
        ]);
    }

    public function verifyCallback(CallbackRequest $request): CallbackResult
    {
        $verified = $this->legacyProvider()->handleCallback($request->getPayload(), [
            'callback_data' => $request->getPayload(),
            'provider_code' => $request->getProviderCode(),
        ]);

        return CallbackResult::fromArray([
            'verified' => $verified,
            'event_type' => 'cash_on_delivery.callback',
            'provider_event_id' => (string) ($request->getPayload()['event_id'] ?? ''),
            'message' => $verified ? (string) __('货到付款回调已验证。') : (string) __('货到付款回调验证失败。'),
        ]);
    }

    public function parseCallback(CallbackRequest $request): CallbackResult
    {
        $payload = $request->getPayload();
        $verified = $this->legacyProvider()->handleCallback($payload, [
            'callback_data' => $payload,
            'provider_code' => $request->getProviderCode(),
        ]);

        return CallbackResult::fromArray([
            'verified' => $verified,
            'event_type' => (string) ($payload['event_type'] ?? 'cash_on_delivery.callback'),
            'provider_event_id' => (string) ($payload['event_id'] ?? ''),
            'intent_code' => (string) ($payload['intent_code'] ?? ''),
            'transaction_code' => (string) ($payload['transaction_code'] ?? $payload['order_number'] ?? ''),
            'status_transition' => $verified ? $this->mapPaymentStatus((string) ($payload['status'] ?? 'pending')) : PaymentResult::STATUS_FAILED,
        ]);
    }

    public function testConnection(TestConnectionRequest $request): PaymentResult
    {
        $result = $this->legacyProvider()->testConfig($request->getConfig(), [
            'scope' => $request->getScope(),
            'environment' => $request->getEnvironment(),
        ]);

        return PaymentResult::fromArray([
            'status' => (bool) ($result['success'] ?? false) ? PaymentResult::STATUS_PAID : PaymentResult::STATUS_FAILED,
            'message' => (string) ($result['message'] ?? __('货到付款静态配置校验完成。')),
            'payload' => [
                'scope' => $request->getScope(),
                'environment' => $request->getEnvironment(),
                'details' => \is_array($result['details'] ?? null) ? $result['details'] : [],
            ],
        ]);
    }

    /**
     * @param Throwable|array<string, mixed> $error
     */
    public function normalizeError(Throwable|array $error): ProviderError
    {
        if ($error instanceof Throwable) {
            return ProviderError::fromThrowable($error);
        }

        return ProviderError::fromArray([
            'code' => (string) ($error['code'] ?? 'cash_on_delivery_error'),
            'message' => (string) ($error['message'] ?? __('货到付款 Provider 处理失败。')),
            'retryable' => (bool) ($error['retryable'] ?? false),
            'user_visible' => (bool) ($error['user_visible'] ?? true),
            'provider_error_code' => (string) ($error['provider_error_code'] ?? ''),
            'details' => \is_array($error['details'] ?? null) ? $error['details'] : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runLegacyProcessPayment(PaymentRequest $request): array
    {
        $context = $request->getContext();
        $legacyOrder = $context['legacy_order'] ?? null;
        if (!$legacyOrder instanceof Order) {
            return [
                'status' => PaymentResult::STATUS_PENDING,
                'requires_action' => false,
                'redirect_url' => '',
                'instructions' => (string) ($this->getRuntimeConfig($context)['instructions'] ?? __('配送送达时向客户收款。')),
                'amount_minor' => $request->getAmountMinor(),
                'currency' => $request->getCurrencyCode(),
            ];
        }

        return $this->legacyProvider()->processPayment(
            $legacyOrder,
            array_merge($request->getDynamicFormValues(), [
                'amount' => number_format($request->getAmountMinor() / 100, 2, '.', ''),
                'currency' => $request->getCurrencyCode(),
            ]),
            $this->buildLegacyContext($request)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLegacyContext(PaymentOperationRequest $request): array
    {
        $context = $request->getContext();
        $config = $this->getRuntimeConfig($context);

        return array_merge($context, [
            'payment_method' => [
                'code' => self::METHOD_CODE,
                'provider_code' => self::PROVIDER_CODE,
                'config' => $config,
            ],
            'config' => $config,
            'scope' => $request->getScope(),
            'currency' => $request->getCurrencyCode(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function getRuntimeConfig(array $context): array
    {
        $config = \is_array($context['config'] ?? null) ? $context['config'] : [];
        $runtimeConfig = \is_array($context['runtime_config'] ?? null) ? $context['runtime_config'] : [];

        return array_merge($config, $runtimeConfig);
    }

    /**
     * @param array<string, mixed> $config
     * @param string[] $default
     * @return string[]
     */
    private function getConfiguredList(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? $config['payment/method/' . self::METHOD_CODE . '/' . $key] ?? $default;
        if (\is_string($value)) {
            $value = explode(',', $value);
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => strtoupper(trim((string) $item)),
            $value
        ))));
    }

    private function mapPaymentStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid', 'success', 'complete', 'completed' => PaymentResult::STATUS_PAID,
            'authorized' => PaymentResult::STATUS_AUTHORIZED,
            'captured' => PaymentResult::STATUS_CAPTURED,
            'processing' => PaymentResult::STATUS_PROCESSING,
            'requires_action' => PaymentResult::STATUS_REQUIRES_ACTION,
            'failed', 'failure', 'cancelled', 'canceled', 'rejected' => PaymentResult::STATUS_FAILED,
            default => PaymentResult::STATUS_PENDING,
        };
    }

    private function unsupportedPaymentResult(PaymentOperationRequest $request, string $message): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_UNSUPPORTED,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference(),
            'retryable' => false,
            'message' => $message,
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function legacyProvider(): CashOnDelivery
    {
        return new CashOnDelivery();
    }
}
