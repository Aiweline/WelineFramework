<?php

declare(strict_types=1);

namespace Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider;

use Throwable;
use Weline\Payment\Api\Data\AuthorizeRequest;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\AvailabilityResult;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\CallbackResult;
use Weline\Payment\Api\Data\CaptureRequest;
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

final class FakeProvider implements ProviderInterface
{
    public function getCode(): string
    {
        return 'fake_card';
    }

    public function getProviderCode(): string
    {
        return 'fake';
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
            'refund' => true,
            'partial_refund' => true,
            'authorize' => true,
            'capture' => true,
            'void' => true,
            'saved_instrument' => false,
            'offline_confirmation' => false,
            'supported_currencies' => ['CNY', 'USD', 'EUR', 'GBP', 'JPY', 'HKD', 'SGD', 'AUD', 'CAD'],
            'supported_countries' => ['CN', 'US', 'GB', 'DE', 'FR', 'JP', 'HK', 'SG', 'AU', 'CA'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array
    {
        return [
            'title' => 'Fake Card',
            'description' => 'Browser verification payment provider.',
            'checkout_template_code' => 'fake_card',
            'config_template_code' => 'fake_card',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array
    {
        return [
            'environment' => [
                'type' => 'select',
                'required' => true,
                'label' => 'Environment',
                'default' => 'sandbox',
                'options' => ['sandbox' => 'Sandbox'],
            ],
            'supported_currencies' => [
                'type' => 'multiselect',
                'required' => true,
                'label' => 'Supported currencies',
                'default' => ['CNY', 'USD', 'EUR'],
            ],
            'supported_countries' => [
                'type' => 'multiselect',
                'required' => false,
                'label' => 'Supported countries',
                'default' => ['CN', 'US'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDynamicFormSchema(AvailabilityRequest $request): array
    {
        return [
            'fake_result' => [
                'type' => 'select',
                'label' => 'Fake result',
                'default' => 'paid',
                'options' => [
                    'paid' => 'Paid',
                    'failed' => 'Failed',
                    'cancelled' => 'Cancelled',
                ],
            ],
        ];
    }

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResult
    {
        if ($request->getAmountMinor() <= 0) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'amount_required',
                'disabled_reason_text' => 'Payment amount must be greater than zero.',
            ]);
        }

        if (!$this->contains($this->getConfiguredList($request, 'supported_currencies', $this->getCapabilities()['supported_currencies']), $request->getCurrencyCode())) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'currency_not_supported',
                'disabled_reason_text' => 'Currency is not supported by Fake Card.',
            ]);
        }

        $countryCode = $request->getCountryCode();
        if ($countryCode !== null && !$this->contains($this->getConfiguredList($request, 'supported_countries', $this->getCapabilities()['supported_countries']), $countryCode)) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'country_not_supported',
                'disabled_reason_text' => 'Country is not supported by Fake Card.',
            ]);
        }

        return AvailabilityResult::fromArray([
            'available' => true,
            'sort_weight' => 10,
            'requires_terms' => true,
        ]);
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $outcome = $this->getRequestedOutcome($request);
        $referencePrefix = match ($outcome) {
            'failed' => 'FAKE-FAIL-',
            'cancelled' => 'FAKE-CANCEL-',
            default => 'FAKE-',
        };
        $baseResult = [
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference() ?: $referencePrefix . $request->getIntentCode(),
            'payload' => [
                'amount_minor' => $request->getAmountMinor(),
                'currency_code' => $request->getCurrencyCode(),
                'fake_result' => $outcome,
            ],
        ];

        if ($outcome === 'failed') {
            return PaymentResult::fromArray($baseResult + [
                'status' => PaymentResult::STATUS_FAILED,
                'retryable' => true,
                'message' => 'Fake payment failed.',
            ]);
        }

        if ($outcome === 'cancelled') {
            return PaymentResult::fromArray($baseResult + [
                'status' => PaymentResult::STATUS_FAILED,
                'action_type' => 'cancelled',
                'retryable' => true,
                'message' => 'Fake payment cancelled.',
            ]);
        }

        return PaymentResult::fromArray(array_replace($baseResult, [
            'status' => PaymentResult::STATUS_PAID,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference() ?: 'FAKE-' . $request->getIntentCode(),
            'message' => 'Fake payment completed.',
        ]));
    }

    public function resumePayment(ResumeRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_PAID,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference() ?: 'FAKE-' . $request->getIntentCode(),
            'message' => 'Fake payment resumed.',
        ]);
    }

    public function authorize(AuthorizeRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_AUTHORIZED,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference() ?: 'FAKE-AUTH-' . $request->getIntentCode(),
        ]);
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_CAPTURED,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference() ?: 'FAKE-CAPTURE-' . $request->getIntentCode(),
        ]);
    }

    public function void(VoidRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_FAILED,
            'action_type' => 'cancelled',
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference(),
            'message' => 'Fake payment cancelled.',
            'payload' => [
                'fake_result' => 'cancelled',
            ],
        ]);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return RefundResult::fromArray([
            'status' => RefundResult::STATUS_REFUNDED,
            'refund_code' => $request->getRefundCode(),
            'transaction_code' => $request->getTransactionCode() ?: $request->getProviderReference(),
            'provider_reference' => 'FAKE-REFUND-' . ($request->getRefundCode() ?: $request->getIntentCode()),
            'message' => 'Fake refund completed.',
            'payload' => [
                'amount_minor' => $request->getAmountMinor(),
                'currency_code' => $request->getCurrencyCode(),
                'reason_code' => $request->getReasonCode(),
            ],
        ]);
    }

    public function query(QueryRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_PAID,
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $request->getProviderReference() ?: 'FAKE-' . $request->getIntentCode(),
        ]);
    }

    public function verifyCallback(CallbackRequest $request): CallbackResult
    {
        return CallbackResult::fromArray([
            'verified' => true,
            'event_type' => 'fake.payment.updated',
            'provider_event_id' => 'FAKE-EVENT-' . date('YmdHis'),
        ]);
    }

    public function parseCallback(CallbackRequest $request): CallbackResult
    {
        $payload = $request->getPayload();

        return CallbackResult::fromArray([
            'verified' => true,
            'event_type' => (string) ($payload['event_type'] ?? 'fake.payment.updated'),
            'provider_event_id' => (string) ($payload['provider_event_id'] ?? 'FAKE-EVENT-' . date('YmdHis')),
            'intent_code' => (string) ($payload['intent_code'] ?? ''),
            'transaction_code' => (string) ($payload['transaction_no'] ?? $payload['provider_reference'] ?? ''),
            'status_transition' => (string) ($payload['status'] ?? 'paid'),
        ]);
    }

    public function testConnection(TestConnectionRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_PAID,
            'message' => 'Fake provider connection passed.',
            'payload' => [
                'scope' => $request->getScope(),
                'environment' => $request->getEnvironment(),
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
            'code' => (string) ($error['code'] ?? 'fake_provider_error'),
            'message' => (string) ($error['message'] ?? 'Fake provider error.'),
            'retryable' => (bool) ($error['retryable'] ?? false),
            'user_visible' => (bool) ($error['user_visible'] ?? false),
            'provider_error_code' => (string) ($error['provider_error_code'] ?? ''),
            'details' => \is_array($error['details'] ?? null) ? $error['details'] : [],
        ]);
    }

    /**
     * @param mixed $default
     * @return string[]
     */
    private function getConfiguredList(AvailabilityRequest $request, string $key, mixed $default): array
    {
        $context = $request->getContext();
        $config = \is_array($context['runtime_config'] ?? null) ? $context['runtime_config'] : [];
        $value = \is_array($config[$key] ?? null) ? $config[$key] : $default;

        return \is_array($value) ? array_values(array_map('strtoupper', array_map('strval', $value))) : [];
    }

    /**
     * @param string[] $items
     */
    private function contains(array $items, string $needle): bool
    {
        return \in_array(strtoupper($needle), $items, true);
    }

    private function getRequestedOutcome(PaymentRequest $request): string
    {
        $values = $request->getDynamicFormValues();
        $context = $request->getContext();
        $outcome = strtolower(trim((string) ($values['fake_result'] ?? $context['fake_result'] ?? 'paid')));

        return \in_array($outcome, ['paid', 'failed', 'cancelled'], true) ? $outcome : 'paid';
    }
}
