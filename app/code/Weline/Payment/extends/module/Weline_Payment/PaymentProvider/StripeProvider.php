<?php

declare(strict_types=1);

namespace Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider;

use Throwable;
use Weline\Payment\Api\Data\AuthorizeRequest;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\AvailabilityResult;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\CallbackResult;
use Weline\Payment\Api\Data\CancelRequest;
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
use Weline\Payment\Service\PaymentConfigValidationService;
use Weline\Payment\Service\StripeApiClient;

final class StripeProvider implements ProviderInterface
{
    private ?StripeApiClient $apiClient = null;

    public function getCode(): string
    {
        return 'stripe';
    }

    public function getProviderCode(): string
    {
        return 'stripe';
    }

    public function getProviderApiVersion(): string
    {
        return '2024-06-20';
    }

    public function getWebhookSchemaVersion(): string
    {
        return '1.0';
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspDirectives(): array
    {
        // Stripe.js / Checkout / API（由 PaymentVendorsCsp 聚合为应用默认 CSP）。
        return [
            'script-src' => [
                'https://js.stripe.com',
            ],
            'frame-src' => [
                'https://js.stripe.com',
                'https://hooks.stripe.com',
                'https://checkout.stripe.com',
            ],
            'connect-src' => [
                'https://api.stripe.com',
                'https://js.stripe.com',
                'https://checkout.stripe.com',
            ],
            'img-src' => [
                'https://stripe.com',
                'https://q.stripe.com',
                'https://js.stripe.com',
            ],
        ];
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
            'authorize' => false,
            'capture' => false,
            'void' => false,
            'saved_instrument' => false,
            'offline_confirmation' => false,
            'express_checkout' => false,
            'supported_currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'HKD', 'SGD', 'CNY'],
            'supported_countries' => ['US', 'CA', 'GB', 'AU', 'SG', 'HK', 'FR', 'DE', 'NL', 'JP', 'BR', 'MX', 'CN'],
            'supported_discount_actions' => ['discount_fixed_amount', 'discount_percentage', 'free_shipping'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array
    {
        return [
            'title' => (string) __('Stripe'),
            'description' => (string) __('使用 Stripe Checkout 完成卡与本地支付方式。'),
            'icon_url' => 'Weline_Payment::img/payment/stripe.svg',
            'icon' => 'Weline_Payment::img/payment/stripe.svg',
            'checkout_mode' => 'template',
            'checkout_template_code' => 'stripe',
            'config_template_code' => 'stripe',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array
    {
        return [
            'secret_key' => [
                'type' => 'password',
                'required' => true,
                'label' => 'Secret Key',
            ],
            'webhook_secret' => [
                'type' => 'password',
                'required' => false,
                'label' => 'Webhook Signing Secret',
            ],
            'return_url' => [
                'type' => 'text',
                'required' => false,
                'label' => 'Success / Return URL',
                'readonly' => true,
            ],
            'cancel_url' => [
                'type' => 'text',
                'required' => false,
                'label' => 'Cancel URL',
                'readonly' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDynamicFormSchema(AvailabilityRequest $request): array
    {
        unset($request);

        return [];
    }

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResult
    {
        if ($request->getAmountMinor() <= 0) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'amount_required',
                'disabled_reason_text' => (string) __('Payment amount must be greater than zero.'),
            ]);
        }

        $config = $this->resolveRuntimeConfig($request->getContext());
        if (!$this->hasRequiredCredentials($config)) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'config_incomplete',
                'disabled_reason_text' => (string) __('Stripe secret key is not configured for the current environment.'),
            ]);
        }

        if (!$this->contains($this->supportedList($config, 'supported_currencies'), $request->getCurrencyCode())) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'currency_not_supported',
                'disabled_reason_text' => (string) __('Currency is not supported by Stripe.'),
            ]);
        }

        $countryCode = $request->getCountryCode();
        if ($countryCode !== null
            && !$this->contains($this->supportedList($config, 'supported_countries'), $countryCode)
        ) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'country_not_supported',
                'disabled_reason_text' => (string) __('Country is not supported by Stripe.'),
            ]);
        }

        return AvailabilityResult::fromArray([
            'available' => true,
            'sort_weight' => 25,
            'requires_terms' => true,
        ]);
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            $referenceId = $request->getAttemptCode() ?: $request->getIntentCode();
            $session = $this->getApiClient()->createCheckoutSession(
                $config,
                $request->getCurrencyCode(),
                $request->getAmountMinor(),
                $referenceId,
                [
                    'success_url' => $this->resolveShellUrl(
                        $request,
                        PaymentOperationRequest::FIELD_RETURN_URL,
                        'return_url',
                        $config,
                    ) ?? '',
                    'cancel_url' => $this->resolveShellUrl(
                        $request,
                        PaymentOperationRequest::FIELD_CANCEL_URL,
                        'cancel_url',
                        $config,
                    ) ?? '',
                    'metadata' => [
                        'intent_code' => $request->getIntentCode(),
                        'attempt_code' => $request->getAttemptCode(),
                    ],
                ],
            );

            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_REQUIRES_ACTION,
                'action_type' => 'redirect',
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $session['session_id'],
                'message' => (string) __('Redirecting to Stripe Checkout.'),
                'payload' => [
                    'redirect_url' => $session['url'],
                    'session_id' => $session['session_id'],
                    'payment_intent' => $session['payment_intent'],
                    'environment' => (string) ($config['environment'] ?? 'sandbox'),
                ],
            ]);
        } catch (Throwable $throwable) {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'retryable' => true,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function resumePayment(ResumeRequest $request): PaymentResult
    {
        $sessionId = trim((string) ($request->getProviderReference() ?? ''));
        if ($sessionId === '') {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'message' => (string) __('Stripe Checkout Session id is missing.'),
            ]);
        }

        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            $session = $this->getApiClient()->retrieveCheckoutSession($config, $sessionId);
            $paymentStatus = strtolower(trim((string) ($session['payment_status'] ?? '')));
            $status = strtolower(trim((string) ($session['status'] ?? '')));
            $paymentIntent = $this->getApiClient()->extractPaymentIntentId($session);
            $reference = $paymentIntent !== '' ? $paymentIntent : $sessionId;

            if ($paymentStatus === 'paid' || $status === 'complete') {
                return PaymentResult::fromArray([
                    'status' => PaymentResult::STATUS_PAID,
                    'intent_code' => $request->getIntentCode(),
                    'attempt_code' => $request->getAttemptCode(),
                    'provider_reference' => $reference,
                    'message' => (string) __('Stripe payment completed.'),
                    'payload' => [
                        'session_id' => $sessionId,
                        'payment_intent' => $paymentIntent,
                        'session' => $session,
                    ],
                ]);
            }

            if ($status === 'expired') {
                return PaymentResult::fromArray([
                    'status' => PaymentResult::STATUS_FAILED,
                    'action_type' => 'cancelled',
                    'intent_code' => $request->getIntentCode(),
                    'attempt_code' => $request->getAttemptCode(),
                    'provider_reference' => $reference,
                    'message' => (string) __('Stripe Checkout Session expired.'),
                    'payload' => [
                        'session_id' => $sessionId,
                        'session' => $session,
                    ],
                ]);
            }

            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_PROCESSING,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $reference,
                'message' => (string) __('Stripe payment is still processing.'),
                'payload' => [
                    'session_id' => $sessionId,
                    'payment_intent' => $paymentIntent,
                    'session' => $session,
                ],
            ]);
        } catch (Throwable $throwable) {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $sessionId,
                'retryable' => true,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function cancelPayment(CancelRequest $request): PaymentResult
    {
        $reference = trim((string) ($request->getProviderReference() ?? $request->getToken() ?? ''));

        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_FAILED,
            'action_type' => 'cancelled',
            'intent_code' => $request->getIntentCode(),
            'attempt_code' => $request->getAttemptCode(),
            'provider_reference' => $reference !== '' ? $reference : $request->getIntentCode(),
            'message' => (string) __('Stripe payment cancelled.'),
            'payload' => [
                'cancel_reason' => $request->getCancelReason(),
            ],
        ]);
    }

    public function authorize(AuthorizeRequest $request): PaymentResult
    {
        unset($request);

        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_UNSUPPORTED,
            'message' => (string) __('Stripe authorize is not supported in this Checkout integration.'),
        ]);
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        unset($request);

        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_UNSUPPORTED,
            'message' => (string) __('Stripe capture is handled by Checkout payment mode.'),
        ]);
    }

    public function void(VoidRequest $request): PaymentResult
    {
        unset($request);

        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_UNSUPPORTED,
            'message' => (string) __('Stripe void is not supported in this Checkout integration.'),
        ]);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            $context = $request->getContext();
            $reference = trim((string) ($request->getProviderReference() ?? $request->getTransactionCode() ?? ''));
            $paymentIntent = $this->resolvePaymentIntentId($config, $reference, $context);
            $refund = $this->getApiClient()->createRefund(
                $config,
                $paymentIntent,
                $request->getAmountMinor() > 0 ? $request->getAmountMinor() : null,
                (string) ($request->getIdempotencyKey() ?? $request->getRefundCode() ?? $paymentIntent),
            );
            $status = strtolower(trim((string) ($refund['raw']['status'] ?? '')));
            $refundStatus = match ($status) {
                'succeeded' => RefundResult::STATUS_REFUNDED,
                'failed', 'canceled' => RefundResult::STATUS_FAILED,
                default => RefundResult::STATUS_PROCESSING,
            };

            return RefundResult::fromArray([
                'status' => $refundStatus,
                'refund_code' => $request->getRefundCode(),
                'transaction_code' => $request->getTransactionCode(),
                'provider_reference' => $refund['refund_id'] !== '' ? $refund['refund_id'] : $paymentIntent,
                'message' => (string) __('Stripe refund submitted.'),
                'payload' => [
                    'payment_intent' => $paymentIntent,
                    'refund' => $refund['raw'],
                ],
            ]);
        } catch (Throwable $throwable) {
            return RefundResult::fromArray([
                'status' => RefundResult::STATUS_FAILED,
                'refund_code' => $request->getRefundCode(),
                'transaction_code' => $request->getTransactionCode(),
                'retryable' => true,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function query(QueryRequest $request): PaymentResult
    {
        $sessionId = trim((string) ($request->getProviderReference() ?? ''));
        if ($sessionId === '') {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'message' => (string) __('Stripe Checkout Session id is missing.'),
            ]);
        }

        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            // Query may hold either session id or payment_intent id.
            if (str_starts_with($sessionId, 'pi_')) {
                return PaymentResult::fromArray([
                    'status' => PaymentResult::STATUS_PAID,
                    'intent_code' => $request->getIntentCode(),
                    'attempt_code' => $request->getAttemptCode(),
                    'provider_reference' => $sessionId,
                    'payload' => ['payment_intent' => $sessionId],
                ]);
            }

            $session = $this->getApiClient()->retrieveCheckoutSession($config, $sessionId);
            $paymentStatus = strtolower(trim((string) ($session['payment_status'] ?? '')));
            $paymentIntent = $this->getApiClient()->extractPaymentIntentId($session);

            return PaymentResult::fromArray([
                'status' => $paymentStatus === 'paid'
                    ? PaymentResult::STATUS_PAID
                    : PaymentResult::STATUS_PROCESSING,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $paymentIntent !== '' ? $paymentIntent : $sessionId,
                'payload' => [
                    'session_id' => $sessionId,
                    'payment_intent' => $paymentIntent,
                    'session' => $session,
                ],
            ]);
        } catch (Throwable $throwable) {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function verifyCallback(CallbackRequest $request): CallbackResult
    {
        $rawBody = $request->getRawBody();
        if ($rawBody === '') {
            return CallbackResult::fromArray([
                'verified' => false,
                'message' => 'missing raw body',
            ]);
        }

        $signature = trim((string) ($request->getSignature() ?? ''));
        if ($signature === '') {
            $headers = $request->getHeaders();
            $signature = trim((string) (
                $headers['Stripe-Signature']
                ?? $headers['stripe-signature']
                ?? $headers['HTTP_STRIPE_SIGNATURE']
                ?? ''
            ));
        }

        $context = $request->getData('context');
        $config = \is_array($context) ? $this->resolveRuntimeConfig($context) : [];
        if ($config === []) {
            $config = [
                'webhook_secret' => (string) ($request->getData('verification_secret') ?? ''),
            ];
        } elseif (trim((string) ($config['webhook_secret'] ?? '')) === '') {
            $config['webhook_secret'] = (string) ($request->getData('verification_secret') ?? '');
        }

        if (!$this->getApiClient()->verifyWebhookSignature($config, $rawBody, $signature)) {
            return CallbackResult::fromArray([
                'verified' => false,
                'message' => 'invalid Stripe-Signature',
            ]);
        }

        $payload = $request->getPayload();
        if ($payload === []) {
            $decoded = json_decode($rawBody, true);
            $payload = \is_array($decoded) ? $decoded : [];
        }

        $eventId = trim((string) ($payload['id'] ?? ''));
        if ($eventId === '') {
            return CallbackResult::fromArray([
                'verified' => false,
                'message' => 'missing provider event id',
            ]);
        }

        return CallbackResult::fromArray([
            'verified' => true,
            'event_type' => (string) ($payload['type'] ?? 'stripe.webhook.received'),
            'provider_event_id' => $eventId,
        ]);
    }

    public function parseCallback(CallbackRequest $request): CallbackResult
    {
        $payload = $request->getPayload();
        if ($payload === [] && $request->getRawBody() !== '') {
            $decoded = json_decode($request->getRawBody(), true);
            $payload = \is_array($decoded) ? $decoded : [];
        }

        $eventType = (string) ($payload['type'] ?? 'stripe.webhook.received');
        $object = \is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $intentCode = trim((string) (
            $object['metadata']['intent_code']
            ?? $object['client_reference_id']
            ?? ''
        ));
        $transactionCode = trim((string) (
            $object['payment_intent']
            ?? $object['id']
            ?? ''
        ));
        if (\is_array($object['payment_intent'] ?? null)) {
            $transactionCode = trim((string) ($object['payment_intent']['id'] ?? $transactionCode));
        }

        return CallbackResult::fromArray([
            'verified' => true,
            'event_type' => $eventType,
            'provider_event_id' => (string) ($payload['id'] ?? ''),
            'intent_code' => $intentCode,
            'transaction_code' => $transactionCode,
            'status_transition' => $this->mapStatusTransition($eventType, $object),
            'schema_version' => '1',
        ]);
    }

    public function testConnection(TestConnectionRequest $request): PaymentResult
    {
        $config = $this->resolveEnvironmentConfig(
            $request->getConfig() !== [] ? $request->getConfig() : (array) ($request->getContext()['config'] ?? []),
            $request->getContext(),
            $request->getEnvironment(),
        );
        $result = $this->getApiClient()->testConnection($config);

        return PaymentResult::fromArray([
            'status' => !empty($result['success']) ? PaymentResult::STATUS_PAID : PaymentResult::STATUS_FAILED,
            'message' => (string) ($result['message'] ?? ''),
            'payload' => \is_array($result['details'] ?? null) ? $result['details'] : [],
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
            'code' => (string) ($error['code'] ?? 'stripe_provider_error'),
            'message' => (string) ($error['message'] ?? 'Stripe provider error.'),
            'retryable' => (bool) ($error['retryable'] ?? false),
            'user_visible' => (bool) ($error['user_visible'] ?? true),
            'provider_error_code' => (string) ($error['provider_error_code'] ?? ''),
            'details' => \is_array($error['details'] ?? null) ? $error['details'] : [],
        ]);
    }

    public function setApiClient(StripeApiClient $apiClient): void
    {
        $this->apiClient = $apiClient;
    }

    private function getApiClient(): StripeApiClient
    {
        return $this->apiClient ??= new StripeApiClient();
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveRuntimeConfig(array $context): array
    {
        $config = \is_array($context['runtime_config'] ?? null)
            ? $context['runtime_config']
            : (\is_array($context['config'] ?? null) ? $context['config'] : []);

        return $this->resolveEnvironmentConfig(
            $config,
            $context,
            (string) ($context['environment'] ?? $config['environment'] ?? 'sandbox'),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveEnvironmentConfig(array $config, array $context, string $environment): array
    {
        unset($context);

        return (new PaymentConfigValidationService())->resolveEnvironmentConfig($config, $environment);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function hasRequiredCredentials(array $config): bool
    {
        return trim((string) ($config['secret_key'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveShellUrl(
        PaymentRequest $request,
        string $requestField,
        string $contextField,
        array $config,
    ): ?string {
        $fromRequest = trim($request->getString($requestField));
        if ($fromRequest !== '') {
            return $fromRequest;
        }
        $context = $request->getContext();
        $fromContext = trim((string) ($context[$contextField] ?? ''));
        if ($fromContext !== '') {
            return $fromContext;
        }
        // Stripe docs use success_url; shell/config may store return_url or success_url.
        $fromConfig = trim((string) ($config[$contextField] ?? ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }
        if ($contextField === 'return_url') {
            $success = trim((string) ($config['success_url'] ?? ''));

            return $success !== '' ? $success : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function resolvePaymentIntentId(array $config, string $reference, array $context): string
    {
        $fromContext = trim((string) ($context['payment_intent'] ?? $context['payment_intent_id'] ?? ''));
        if ($fromContext !== '') {
            return $fromContext;
        }
        if (str_starts_with($reference, 'pi_')) {
            return $reference;
        }
        if (str_starts_with($reference, 'cs_')) {
            $session = $this->getApiClient()->retrieveCheckoutSession($config, $reference);
            $pi = $this->getApiClient()->extractPaymentIntentId($session);
            if ($pi !== '') {
                return $pi;
            }
        }

        return $reference;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function mapStatusTransition(string $eventType, array $object): string
    {
        $eventType = strtolower(trim($eventType));

        return match ($eventType) {
            'checkout.session.completed', 'payment_intent.succeeded' => 'paid',
            'payment_intent.payment_failed', 'checkout.session.expired' => 'failed',
            'charge.refunded', 'refund.created', 'refund.updated' => $this->mapRefundTransition($object),
            default => 'processing',
        };
    }

    /**
     * @param array<string, mixed> $object
     */
    private function mapRefundTransition(array $object): string
    {
        $status = strtolower(trim((string) ($object['status'] ?? '')));
        if ($status === 'succeeded' || !empty($object['refunded'])) {
            return 'refunded';
        }

        return 'processing';
    }

    /**
     * @param array<string, mixed> $config
     * @return string[]
     */
    private function supportedList(array $config, string $key): array
    {
        $defaults = $this->getCapabilities();
        $value = \is_array($config[$key] ?? null) ? $config[$key] : ($defaults[$key] ?? []);

        return \is_array($value) ? array_values(array_map('strtoupper', array_map('strval', $value))) : [];
    }

    /**
     * @param string[] $items
     */
    private function contains(array $items, string $needle): bool
    {
        return \in_array(strtoupper(trim($needle)), $items, true);
    }
}
