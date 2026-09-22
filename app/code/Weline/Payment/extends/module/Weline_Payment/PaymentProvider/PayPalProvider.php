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
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\ProviderError;
use Weline\Payment\Api\Data\QueryRequest;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Api\Data\ResumeRequest;
use Weline\Payment\Api\Data\TestConnectionRequest;
use Weline\Payment\Api\Data\VoidRequest;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Interface\ProviderConnectInterface;
use Weline\Payment\Interface\ProviderConnectPrepareInterface;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Interface\ProviderShipmentTrackingInterface;
use Weline\Payment\Service\PayPalApiClient;
use Weline\Payment\Service\PayPalPlatformCredentialService;
use Weline\Payment\Service\PayPalShipmentTrackingSyncService;
use Weline\Payment\Service\PaymentConfigValidationService;
use Weline\Payment\Service\PaymentRedirectUriCatalog;
use Weline\Payment\Service\PayPalOAuthService;
use Weline\Payment\Service\PayPalWebhookTransitionMapper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderShipment;

final class PayPalProvider implements ProviderInterface, ProviderConnectInterface, ProviderConnectPrepareInterface, ProviderShipmentTrackingInterface
{
    private ?PayPalApiClient $apiClient = null;

    public function getCode(): string
    {
        return 'paypal';
    }

    public function getProviderCode(): string
    {
        return 'paypal';
    }

    public function getProviderApiVersion(): string
    {
        return '2.0';
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
        // PayPal JS SDK / approve redirect / REST API hosts (live + sandbox).
        return [
            'script-src' => [
                'https://www.paypal.com',
                'https://www.sandbox.paypal.com',
                'https://www.paypalobjects.com',
            ],
            'frame-src' => [
                'https://www.paypal.com',
                'https://www.sandbox.paypal.com',
            ],
            'connect-src' => [
                'https://www.paypal.com',
                'https://www.sandbox.paypal.com',
                'https://api-m.paypal.com',
                'https://api-m.sandbox.paypal.com',
            ],
            'img-src' => [
                'https://www.paypalobjects.com',
                'https://www.paypal.com',
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
            // 快捷智能支付：PayPal 首发；其它网关声明同 key 即可进入壳列表。
            'express_checkout' => true,
            'express_modes' => ['redirect'],
            'express_next_action' => 'redirect',
            // CNY/CN：本站默认币种与收货国；Sandbox/部分商户可测，正式以 PayPal 商户能力为准。
            // 欧美主战场 + PayPal 官方可交易币（见 developer.paypal.com/reference/currency-codes）
            'supported_currencies' => [
                'USD', 'EUR', 'GBP', 'CAD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'MXN',
                'AUD', 'JPY', 'HKD', 'SGD', 'CNY',
            ],
            'supported_countries' => [
                'US', 'GB', 'DE', 'CA', 'AU', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'IE', 'PT',
                'SE', 'NO', 'DK', 'FI', 'CH', 'PL', 'MX', 'JP', 'HK', 'SG', 'CN', 'XZ',
            ],
            'supported_discount_actions' => ['discount_fixed_amount', 'discount_percentage', 'free_shipping'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array
    {
        return [
            'title' => (string) __('PayPal'),
            'description' => (string) __('使用 PayPal 账户或卡完成支付。'),
            'icon_url' => 'Weline_Payment::img/payment/paypal.svg',
            'icon' => 'Weline_Payment::img/payment/paypal.svg',
            'checkout_mode' => 'template',
            'checkout_template_code' => 'paypal',
            'config_template_code' => 'paypal',
        ];
    }

    public function startConnect(string $environment = 'sandbox', ?string $scope = null, array $context = []): array
    {
        return $this->oauth()->start($environment, $scope, $context);
    }

    public function completeConnect(array $params): array
    {
        return $this->oauth()->complete($params);
    }

    public function ownsOAuthState(string $state): bool
    {
        return $this->oauth()->ownsOAuthState($state);
    }

    public function suggestedRedirectUris(): array
    {
        return ObjectManager::getInstance(PaymentRedirectUriCatalog::class)->suggestedRedirectUris(null, $this->getCode());
    }

    public function connectConfigUrl(?string $scope = null, array $context = []): string
    {
        return $this->oauth()->configUrl($scope, $context);
    }

    public function testConnect(string $environment = 'sandbox', ?string $scope = null): array
    {
        return $this->oauth()->testConnection($environment, $scope);
    }

    public function revokeConnect(string $environment = 'sandbox', ?string $scope = null): void
    {
        $this->oauth()->revoke($environment, $scope);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ready:bool,redirect_url:?string,message:?string}
     */
    public function prepareConnectAuthorize(string $environment = 'sandbox', ?string $scope = null, array $context = []): array
    {
        unset($context);
        if (strtolower(trim($environment)) !== 'sandbox') {
            return ['ready' => true, 'redirect_url' => null, 'message' => null];
        }

        /** @var PayPalPlatformCredentialService $platform */
        $platform = ObjectManager::getInstance(PayPalPlatformCredentialService::class);
        if ($platform->hasPlatformSandboxCredentials() || !$platform->shouldUseBundledSandboxCredentials()) {
            return ['ready' => true, 'redirect_url' => null, 'message' => null];
        }

        return [
            'ready' => false,
            'redirect_url' => ObjectManager::getInstance(\Weline\Framework\Http\Url::class)
                ->getUrl('payment/backend/platform-sandbox-setup/index'),
            'message' => (string) __(
                '首次使用需完成 PayPal 沙箱平台应用一次性初始化（维护者操作，商户无需手填 Client ID/Secret）。'
            ),
        ];
    }

    private function oauth(): PayPalOAuthService
    {
        return ObjectManager::getInstance(PayPalOAuthService::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array
    {
        return [
            'client_id' => [
                'type' => 'text',
                'required' => true,
                'label' => 'Client ID',
            ],
            'client_secret' => [
                'type' => 'password',
                'required' => true,
                'label' => 'Client Secret',
            ],
            'return_url' => [
                'type' => 'text',
                'required' => false,
                'label' => 'Return URL',
                'readonly' => true,
            ],
            'cancel_url' => [
                'type' => 'text',
                'required' => false,
                'label' => 'Cancel URL',
                'readonly' => true,
            ],
            'webhook_id' => [
                'type' => 'text',
                'required' => false,
                'label' => 'Webhook ID',
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
                'disabled_reason_text' => (string) __('Payment amount must be greater than zero.'),
            ]);
        }

        $config = $this->resolveRuntimeConfig($request->getContext());
        if (!$this->hasRequiredCredentials($config)) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'config_incomplete',
                'disabled_reason_text' => (string) __('PayPal credentials are not configured for the current environment.'),
            ]);
        }

        if (!$this->contains($this->supportedList($config, 'supported_currencies'), $request->getCurrencyCode())) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'currency_not_supported',
                'disabled_reason_text' => (string) __('Currency is not supported by PayPal.'),
            ]);
        }

        $countryCode = $request->getCountryCode();
        if ($countryCode !== null
            && !$this->contains($this->supportedList($config, 'supported_countries'), $countryCode)
        ) {
            return AvailabilityResult::fromArray([
                'available' => false,
                'disabled_reason_code' => 'country_not_supported',
                'disabled_reason_text' => (string) __('Country is not supported by PayPal.'),
            ]);
        }

        return AvailabilityResult::fromArray([
            'available' => true,
            'sort_weight' => 20,
            'requires_terms' => true,
        ]);
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            $referenceId = $request->getAttemptCode() ?: $request->getIntentCode();
            [$paypalCurrency, $paypalAmountMinor] = $this->resolvePayPalOrderMoney(
                $request->getCurrencyCode(),
                $request->getAmountMinor(),
                $config,
            );
            $express = $this->isExpressContext($request->getContext());
            $ctx = $request->getContext();
            $noShipping = $express && (
                (array_key_exists('requires_shipping', $ctx) && $ctx['requires_shipping'] === false)
                || strtoupper(trim((string) ($ctx['shipping_preference'] ?? ''))) === 'NO_SHIPPING'
            );
            $order = $this->getApiClient()->createOrder(
                $config,
                $paypalCurrency,
                $paypalAmountMinor,
                $referenceId,
                $this->resolveShellUrl($request, PaymentOperationRequest::FIELD_RETURN_URL, 'return_url', $config),
                $this->resolveShellUrl($request, PaymentOperationRequest::FIELD_CANCEL_URL, 'cancel_url', $config),
                [
                    'express_checkout' => $express,
                    'shipping_preference' => $express
                        ? ($noShipping ? 'NO_SHIPPING' : 'GET_FROM_FILE')
                        : '',
                    'user_action' => $express ? 'CONTINUE' : 'PAY_NOW',
                ],
            );

            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_REQUIRES_ACTION,
                'action_type' => 'redirect',
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $order['order_id'],
                'message' => (string) __('Redirecting to PayPal checkout.'),
                'payload' => [
                    'redirect_url' => $order['approve_url'],
                    'order_id' => $order['order_id'],
                    'environment' => (string) ($config['environment'] ?? 'sandbox'),
                    'presentment_currency' => $request->getCurrencyCode(),
                    'paypal_currency' => $paypalCurrency,
                    'express_checkout' => $express,
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

    /**
     * PayPal REST 沙箱不接受 CNY；本地沙箱 E2E 将 CNY 按固定汇率折算为 USD 下单。
     *
     * @param array<string, mixed> $config
     * @return array{0:string,1:int}
     */
    private function resolvePayPalOrderMoney(string $currencyCode, int $amountMinor, array $config): array
    {
        $currency = strtoupper(trim($currencyCode));
        $environment = strtolower(trim((string) ($config['environment'] ?? 'sandbox')));
        if ($currency !== 'CNY' || $environment !== 'sandbox' || $amountMinor <= 0) {
            return [$currency, max(0, $amountMinor)];
        }

        return ['USD', max(1, (int) round($amountMinor / 7.2))];
    }

    public function resumePayment(ResumeRequest $request): PaymentResult
    {
        $orderId = trim((string) ($request->getProviderReference() ?? ''));
        if ($orderId === '') {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'message' => (string) __('PayPal order id is missing.'),
            ]);
        }

        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            $context = $request->getContext();
            $express = $this->isExpressContext($context);
            $prepareOnly = $express && (
                !empty($context['express_prepare_only'])
                || (
                    \Weline\Payment\Service\ExpressCheckoutOrchestrator::isExpressAwaitingConfirm($context)
                    && empty($context['express_confirm_capture'])
                )
            );
            $confirmCapture = !empty($context['express_confirm_capture'])
                || (!$express)
                || (!$prepareOnly && empty($context['express_prepare_only']));

            if ($express && $prepareOnly && empty($context['express_confirm_capture'])) {
                $order = $this->getApiClient()->getOrder($config, $orderId);
                $mapper = new \Weline\Payment\Service\PayPalExpressProfileMapper();
                $expressProfile = $mapper->fromOrderPayload($order);

                return PaymentResult::fromArray([
                    'status' => PaymentResult::STATUS_PROCESSING,
                    'intent_code' => $request->getIntentCode(),
                    'attempt_code' => $request->getAttemptCode(),
                    'provider_reference' => $orderId,
                    'message' => (string) __('PayPal express approved; awaiting merchant confirm.'),
                    'payload' => array_filter([
                        'order_id' => $orderId,
                        'order' => $order,
                        'express_checkout' => true,
                        'express_awaiting_confirm' => true,
                        'express_profile' => $expressProfile,
                    ], static fn (mixed $v): bool => $v !== null),
                ]);
            }

            if ($express && $confirmCapture && !empty($context['patch_amount_minor'])) {
                try {
                    $this->getApiClient()->patchOrder(
                        $config,
                        $orderId,
                        max(0, (int) $context['patch_amount_minor']),
                        strtoupper(trim((string) ($context['patch_currency'] ?? $request->getCurrencyCode()))),
                    );
                } catch (Throwable $patchError) {
                    return PaymentResult::fromArray([
                        'status' => PaymentResult::STATUS_FAILED,
                        'intent_code' => $request->getIntentCode(),
                        'attempt_code' => $request->getAttemptCode(),
                        'provider_reference' => $orderId,
                        'retryable' => true,
                        'message' => (string) __('PayPal amount update failed: %{1}', [$patchError->getMessage()]),
                    ]);
                }
            }

            $capture = $this->getApiClient()->captureOrder($config, $orderId);
            $status = strtoupper(trim((string) ($capture['status'] ?? '')));
            $expressProfile = null;
            if ($express) {
                $mapper = new \Weline\Payment\Service\PayPalExpressProfileMapper();
                $expressProfile = $mapper->fromOrderPayload($capture);
                if ($expressProfile === null) {
                    try {
                        $order = $this->getApiClient()->getOrder($config, $orderId);
                        $expressProfile = $mapper->fromOrderPayload($order);
                    } catch (Throwable) {
                        $expressProfile = null;
                    }
                }
            }

            if ($status === 'COMPLETED') {
                $captureId = $this->getApiClient()->extractCaptureId($capture);

                return PaymentResult::fromArray([
                    'status' => PaymentResult::STATUS_PAID,
                    'intent_code' => $request->getIntentCode(),
                    'attempt_code' => $request->getAttemptCode(),
                    'provider_reference' => $captureId !== '' ? $captureId : $orderId,
                    'message' => (string) __('PayPal payment completed.'),
                    'payload' => array_filter([
                        'capture_id' => $captureId,
                        'order_id' => $orderId,
                        'capture' => $capture,
                        'express_checkout' => $express,
                        'express_profile' => $expressProfile,
                    ], static fn (mixed $v): bool => $v !== null),
                ]);
            }

            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_PROCESSING,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $orderId,
                'message' => (string) __('PayPal payment is still processing.'),
                'payload' => array_filter([
                    'order_id' => $orderId,
                    'capture' => $capture,
                    'express_checkout' => $express,
                    'express_profile' => $expressProfile,
                ], static fn (mixed $v): bool => $v !== null),
            ]);
        } catch (Throwable $throwable) {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $orderId,
                'retryable' => true,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isExpressContext(array $context): bool
    {
        if (!empty($context['express_checkout'])) {
            return true;
        }
        $meta = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];

        return !empty($meta['express_checkout']);
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
            'message' => (string) __('PayPal payment cancelled.'),
            'payload' => [
                'cancel_reason' => $request->getCancelReason(),
            ],
        ]);
    }

    public function authorize(AuthorizeRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_UNSUPPORTED,
            'message' => (string) __('PayPal authorize is not supported in this integration.'),
        ]);
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_UNSUPPORTED,
            'message' => (string) __('PayPal capture is handled during resumePayment.'),
        ]);
    }

    public function void(VoidRequest $request): PaymentResult
    {
        return PaymentResult::fromArray([
            'status' => PaymentResult::STATUS_UNSUPPORTED,
            'message' => (string) __('PayPal void is not supported in this integration.'),
        ]);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            $context = $request->getContext();
            $reference = trim((string) ($request->getProviderReference() ?? $request->getTransactionCode() ?? ''));
            $captureId = $this->getApiClient()->resolveCaptureId($config, $reference, $context);
            [$paypalCurrency, $paypalAmountMinor] = $this->resolvePayPalOrderMoney(
                $request->getCurrencyCode(),
                $request->getAmountMinor(),
                $config,
            );
            $refund = $this->getApiClient()->refundCapture(
                $config,
                $captureId,
                $paypalCurrency,
                $paypalAmountMinor,
                (string) ($request->getIdempotencyKey() ?? $request->getRefundCode() ?? $captureId),
            );
            $status = strtoupper(trim((string) ($refund['raw']['status'] ?? '')));

            return RefundResult::fromArray([
                'status' => \in_array($status, ['COMPLETED', 'REFUNDED'], true)
                    ? RefundResult::STATUS_REFUNDED
                    : RefundResult::STATUS_PROCESSING,
                'refund_code' => $request->getRefundCode(),
                'transaction_code' => $request->getTransactionCode(),
                'provider_reference' => $refund['refund_id'] !== '' ? $refund['refund_id'] : $captureId,
                'message' => (string) __('PayPal refund submitted.'),
                'payload' => [
                    'capture_id' => $captureId,
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
        $orderId = trim((string) ($request->getProviderReference() ?? ''));
        if ($orderId === '') {
            return PaymentResult::fromArray([
                'status' => PaymentResult::STATUS_FAILED,
                'message' => (string) __('PayPal order id is missing.'),
            ]);
        }

        try {
            $config = $this->resolveRuntimeConfig($request->getContext());
            $order = $this->getApiClient()->getOrder($config, $orderId);
            $status = strtoupper(trim((string) ($order['status'] ?? '')));
            $captureId = $this->getApiClient()->extractCaptureId($order);

            return PaymentResult::fromArray([
                'status' => $status === 'COMPLETED'
                    ? PaymentResult::STATUS_PAID
                    : PaymentResult::STATUS_PROCESSING,
                'intent_code' => $request->getIntentCode(),
                'attempt_code' => $request->getAttemptCode(),
                'provider_reference' => $captureId !== '' ? $captureId : $orderId,
                'payload' => [
                    'capture_id' => $captureId,
                    'order_id' => $orderId,
                    'order' => $order,
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
        // Mechanism A (frozen): local verify only — zero outbound HTTP; never call remote webhook verify.
        $rawBody = $request->getRawBody();
        if ($rawBody === '') {
            return CallbackResult::fromArray([
                'verified' => false,
                'message' => 'missing raw body',
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

        $eventType = (string) ($payload['event_type'] ?? 'paypal.webhook.received');
        $headers = $request->getHeaders();
        $transmission = $this->extractPayPalTransmissionHeaders($headers);
        $context = $request->getData('context');
        $config = [];
        if (\is_array($context)) {
            $config = \is_array($context['config'] ?? null) ? $context['config'] : [];
            if ($config === [] && \is_array($context['runtime_config'] ?? null)) {
                $config = $context['runtime_config'];
            }
        }
        $webhookId = trim((string) (
            $config['webhook_id']
            ?? $request->getData('verification_secret')
            ?? ''
        ));
        $certPem = '';
        foreach ([
            $config['webhook_cert_pem'] ?? null,
            \is_array($context) ? ($context['webhook_cert_pem'] ?? null) : null,
            $request->getData('verification_cert_pem'),
        ] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));
            if ($candidate !== '') {
                $certPem = $candidate;
                break;
            }
        }
        $allowUnsigned = \is_array($context) && (
            !empty($context['allow_unsigned_webhook'])
            || !empty($config['allow_unsigned_webhook'])
            || (!empty($context['runtime_config']) && \is_array($context['runtime_config'])
                && !empty($context['runtime_config']['allow_unsigned_webhook']))
        );

        $hasTransmission = $transmission['transmission_sig'] !== ''
            && $transmission['transmission_id'] !== ''
            && $transmission['transmission_time'] !== '';

        if ($webhookId !== '' && $certPem !== '' && $hasTransmission) {
            if (!$this->verifyPayPalTransmissionLocally($rawBody, $transmission, $webhookId, $certPem)) {
                return CallbackResult::fromArray([
                    'verified' => false,
                    'message' => 'paypal webhook local signature verification failed',
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                ]);
            }

            return CallbackResult::fromArray([
                'verified' => true,
                'event_type' => $eventType,
                'provider_event_id' => $eventId,
            ]);
        }

        // Explicit DevRelay / test switch only — never soft-accept by event id in production.
        if ($allowUnsigned) {
            return CallbackResult::fromArray([
                'verified' => true,
                'event_type' => $eventType,
                'provider_event_id' => $eventId,
            ]);
        }

        return CallbackResult::fromArray([
            'verified' => false,
            'message' => 'paypal webhook verification materials missing (local-only; fail-closed)',
            'provider_event_id' => $eventId,
            'event_type' => $eventType,
        ]);
    }

    /**
     * Local PayPal transmission signature check (no HTTP).
     * Message = transmission_id|transmission_time|webhook_id|crc32(raw_body).
     *
     * @param array{auth_algo:string,cert_url:string,transmission_id:string,transmission_sig:string,transmission_time:string} $transmission
     */
    private function verifyPayPalTransmissionLocally(
        string $rawBody,
        array $transmission,
        string $webhookId,
        string $certPem,
    ): bool {
        $sigRaw = base64_decode($transmission['transmission_sig'], true);
        if ($sigRaw === false || $sigRaw === '' || $webhookId === '' || $certPem === '') {
            return false;
        }

        $crc = sprintf('%u', crc32($rawBody));
        $message = $transmission['transmission_id'] . '|'
            . $transmission['transmission_time'] . '|'
            . $webhookId . '|'
            . $crc;

        $publicKey = openssl_pkey_get_public($certPem);
        if ($publicKey === false) {
            return false;
        }

        $algo = strtoupper(str_replace(['_', '-'], '', $transmission['auth_algo']));
        $opensslAlgo = match ($algo) {
            'SHA256WITHRSA', 'SHA256WITHRSAENCRYPTION' => OPENSSL_ALGO_SHA256,
            'SHA1WITHRSA' => OPENSSL_ALGO_SHA1,
            default => OPENSSL_ALGO_SHA256,
        };

        return openssl_verify($message, $sigRaw, $publicKey, $opensslAlgo) === 1;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array{auth_algo:string,cert_url:string,transmission_id:string,transmission_sig:string,transmission_time:string}
     */
    private function extractPayPalTransmissionHeaders(array $headers): array
    {
        $pick = static function (array $headers, array $names): string {
            foreach ($names as $name) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp((string) $key, $name) === 0) {
                        return trim(\is_array($value) ? (string) ($value[0] ?? '') : (string) $value);
                    }
                }
            }

            return '';
        };

        return [
            'auth_algo' => $pick($headers, ['PAYPAL-AUTH-ALGO', 'Paypal-Auth-Algo', 'HTTP_PAYPAL_AUTH_ALGO']),
            'cert_url' => $pick($headers, ['PAYPAL-CERT-URL', 'Paypal-Cert-Url', 'HTTP_PAYPAL_CERT_URL']),
            'transmission_id' => $pick($headers, ['PAYPAL-TRANSMISSION-ID', 'Paypal-Transmission-Id', 'HTTP_PAYPAL_TRANSMISSION_ID']),
            'transmission_sig' => $pick($headers, ['PAYPAL-TRANSMISSION-SIG', 'Paypal-Transmission-Sig', 'HTTP_PAYPAL_TRANSMISSION_SIG']),
            'transmission_time' => $pick($headers, ['PAYPAL-TRANSMISSION-TIME', 'Paypal-Transmission-Time', 'HTTP_PAYPAL_TRANSMISSION_TIME']),
        ];
    }

    public function parseCallback(CallbackRequest $request): CallbackResult
    {
        $payload = $request->getPayload();
        if ($payload === [] && $request->getRawBody() !== '') {
            $decoded = json_decode($request->getRawBody(), true);
            $payload = \is_array($decoded) ? $decoded : [];
        }

        $resource = \is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $eventType = (string) ($payload['event_type'] ?? 'paypal.webhook.received');
        $mapper = new PayPalWebhookTransitionMapper();
        $statusTransition = $mapper->mapStatusTransition($eventType, $payload);
        $transactionCode = trim((string) (
            $resource['id']
            ?? $resource['supplementary_data']['related_ids']['order_id']
            ?? ''
        ));

        return CallbackResult::fromArray([
            'verified' => true,
            'event_type' => $eventType,
            'provider_event_id' => (string) ($payload['id'] ?? ''),
            'transaction_code' => $transactionCode,
            'status_transition' => $statusTransition,
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
            'code' => (string) ($error['code'] ?? 'paypal_provider_error'),
            'message' => (string) ($error['message'] ?? 'PayPal provider error.'),
            'retryable' => (bool) ($error['retryable'] ?? false),
            'user_visible' => (bool) ($error['user_visible'] ?? true),
            'provider_error_code' => (string) ($error['provider_error_code'] ?? ''),
            'details' => \is_array($error['details'] ?? null) ? $error['details'] : [],
        ]);
    }

    public function setApiClient(PayPalApiClient $apiClient): void
    {
        $this->apiClient = $apiClient;
    }

    private function getApiClient(): PayPalApiClient
    {
        return $this->apiClient ??= new PayPalApiClient();
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
            (string) ($config['environment'] ?? $context['environment'] ?? 'sandbox'),
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
        return trim((string) ($config['client_id'] ?? '')) !== ''
            && trim((string) ($config['client_secret'] ?? '')) !== '';
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
        $fromConfig = trim((string) ($config[$contextField] ?? ''));

        return $fromConfig !== '' ? $fromConfig : null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok:bool,message:string,capture_id?:string,tracking_number?:string,payload?:array<string,mixed>}
     */
    public function syncShipmentTracking(array $context): array
    {
        /** @var PayPalShipmentTrackingSyncService $sync */
        $sync = ObjectManager::getInstance(PayPalShipmentTrackingSyncService::class);

        $tracking = trim((string) ($context['tracking_number'] ?? ''));
        $carrier = trim((string) ($context['carrier'] ?? ''));
        $status = strtoupper(trim((string) ($context['tracking_status'] ?? 'SHIPPED'))) ?: 'SHIPPED';
        $orderUuid = trim((string) ($context['order_uuid'] ?? ''));

        $shipment = $context['shipment'] ?? null;
        if ($shipment instanceof OrderShipment) {
            if ($tracking === '') {
                $tracking = trim((string) $shipment->getData(OrderShipment::schema_fields_TRACKING_NUMBER));
            }
            if ($carrier === '') {
                $carrier = trim((string) $shipment->getData(OrderShipment::schema_fields_CARRIER));
            }
        }

        $order = $context['order'] ?? null;
        if ($orderUuid === '' && $order instanceof Order) {
            $orderUuid = trim((string) $order->getData(Order::schema_fields_ORDER_UUID));
        }

        if ($orderUuid !== '' && $tracking !== '') {
            return $sync->syncForOrder($orderUuid, $tracking, $carrier, $status);
        }

        return $sync->syncFromOrderShippedEvent([
            'order' => $order,
            'order_id' => (int) ($context['order_id'] ?? 0),
            'shipment' => $shipment,
            'tracking_number' => $tracking,
            'carrier' => $carrier,
        ]);
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
