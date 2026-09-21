<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * PayPal REST API 轻量客户端（OAuth、下单、捕获、查询）。
 */
final class PayPalApiClient
{
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';
    private const LIVE_BASE = 'https://api-m.paypal.com';

    /** @var callable|null */
    private $httpHandler;

    /**
     * @param callable(string,string,array<string,string>,?string): array{status:int,body:string}|null $httpHandler
     */
    public function __construct(?callable $httpHandler = null)
    {
        $this->httpHandler = $httpHandler;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function testConnection(array $config): array
    {
        try {
            $token = $this->fetchAccessToken($config);
            if ($token === '') {
                return ['success' => false, 'message' => 'PayPal OAuth token is empty.'];
            }

            return [
                'success' => true,
                'message' => 'PayPal connection passed.',
                'details' => [
                    'environment' => (string) ($config['environment'] ?? 'sandbox'),
                    'base_url' => $this->baseUrl($config),
                ],
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array{
     *   shipping_preference?:string,
     *   user_action?:string,
     *   express_checkout?:bool
     * } $options
     * @return array{order_id:string,approve_url:string,raw:array<string,mixed>}
     */
    public function createOrder(
        array $config,
        string $currencyCode,
        int $amountMinor,
        string $referenceId,
        ?string $returnUrl = null,
        ?string $cancelUrl = null,
        array $options = [],
    ): array {
        $token = $this->fetchAccessToken($config);
        $shippingPreference = strtoupper(trim((string) ($options['shipping_preference'] ?? '')));
        $userAction = strtoupper(trim((string) ($options['user_action'] ?? 'PAY_NOW'))) ?: 'PAY_NOW';
        // Express / 快捷智能支付：由 PayPal 收集或确认收货地址。
        if ($shippingPreference === '' && !empty($options['express_checkout'])) {
            $shippingPreference = 'GET_FROM_FILE';
        }
        $applicationContext = array_filter([
            'return_url' => $this->normalizeCallbackUrl(
                $returnUrl ?: (string) ($config['return_url'] ?? ''),
                $config,
            ),
            'cancel_url' => $this->normalizeCallbackUrl(
                $cancelUrl ?: (string) ($config['cancel_url'] ?? ''),
                $config,
            ),
            'user_action' => $userAction,
            'shipping_preference' => $shippingPreference !== '' ? $shippingPreference : null,
        ]);
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $referenceId,
                'amount' => [
                    'currency_code' => strtoupper($currencyCode),
                    'value' => $this->formatAmount($currencyCode, $amountMinor),
                ],
            ]],
            'application_context' => $applicationContext,
        ];

        $response = $this->request(
            $config,
            'POST',
            '/v2/checkout/orders',
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'PayPal-Request-Id: ' . $referenceId,
            ],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('PayPal create order response is invalid.');
        }

        $orderId = trim((string) ($decoded['id'] ?? ''));
        if ($orderId === '') {
            throw new \RuntimeException('PayPal order id is missing.');
        }

        $approveUrl = $this->extractLink($decoded, 'approve');
        if ($approveUrl === '') {
            throw new \RuntimeException('PayPal approve link is missing.');
        }

        return [
            'order_id' => $orderId,
            'approve_url' => $approveUrl,
            'raw' => $decoded,
        ];
    }

    /**
     * Patch purchase unit amount before capture (express review total changes).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function patchOrder(
        array $config,
        string $orderId,
        int $amountMinor,
        string $currencyCode,
    ): array {
        $token = $this->fetchAccessToken($config);
        $currency = strtoupper(trim($currencyCode));
        $body = [[
            'op' => 'replace',
            'path' => "/purchase_units/@reference_id=='default'/amount",
            'value' => [
                'currency_code' => $currency,
                'value' => $this->formatAmount($currency, $amountMinor),
            ],
        ]];
        // PayPal default reference_id may be custom — also try first unit via get+patch with known id.
        $existing = $this->getOrder($config, $orderId);
        $units = is_array($existing['purchase_units'] ?? null) ? $existing['purchase_units'] : [];
        $refId = trim((string) ($units[0]['reference_id'] ?? 'default'));
        if ($refId === '') {
            $refId = 'default';
        }
        $body[0]['path'] = "/purchase_units/@reference_id=='" . $refId . "'/amount";

        $response = $this->request(
            $config,
            'PATCH',
            '/v2/checkout/orders/' . rawurlencode($orderId),
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : ['status' => $response['status']];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function captureOrder(array $config, string $orderId): array
    {
        $token = $this->fetchAccessToken($config);
        $response = $this->request(
            $config,
            'POST',
            '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            '{}',
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(
        array $config,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): array {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        if ($clientId === '' || $code === '') {
            throw new \RuntimeException('PayPal OAuth client credentials or authorization code is missing.');
        }

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $body['code_verifier'] = $codeVerifier;
        }

        $response = $this->request(
            $config,
            'POST',
            '/v1/oauth2/token',
            [
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            http_build_query($body),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function fetchMerchantPayerId(array $config): string
    {
        $accessToken = trim((string) ($config['oauth_access_token'] ?? ''));
        if ($accessToken === '') {
            return '';
        }

        $response = $this->request(
            $config,
            'GET',
            '/v1/identity/oauth2/userinfo?schema=paypalv1.1',
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            null,
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return '';
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['payer_id'] ?? $decoded['user_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $config
     * @return array{refund_id:string,raw:array<string,mixed>}
     */
    public function refundCapture(
        array $config,
        string $captureId,
        string $currencyCode,
        int $amountMinor,
        string $referenceId,
    ): array {
        $token = $this->fetchAccessToken($config);
        $payload = [
            'amount' => [
                'currency_code' => strtoupper($currencyCode),
                'value' => $this->formatAmount($currencyCode, $amountMinor),
            ],
        ];

        $response = $this->request(
            $config,
            'POST',
            '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'PayPal-Request-Id: ' . $referenceId,
            ],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('PayPal refund response is invalid.');
        }

        $refundId = trim((string) ($decoded['id'] ?? ''));

        return [
            'refund_id' => $refundId,
            'raw' => $decoded,
        ];
    }

    /**
     * @param array<string, mixed> $orderOrCapture
     */
    public function extractCaptureId(array $orderOrCapture): string
    {
        foreach ((array) ($orderOrCapture['purchase_units'] ?? []) as $unit) {
            if (!\is_array($unit)) {
                continue;
            }
            foreach ((array) ($unit['payments']['captures'] ?? []) as $capture) {
                if (!\is_array($capture)) {
                    continue;
                }
                $captureId = trim((string) ($capture['id'] ?? ''));
                if ($captureId !== '') {
                    return $captureId;
                }
            }
        }

        $direct = trim((string) ($orderOrCapture['id'] ?? ''));
        if ($direct !== '' && str_starts_with(strtoupper($direct), 'CAPTURE')) {
            return $direct;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolveCaptureId(array $config, string $reference, array $context = []): string
    {
        $captureId = trim((string) ($context['capture_id'] ?? $context['payload']['capture_id'] ?? ''));
        if ($captureId !== '') {
            return $captureId;
        }

        $capturePayload = $context['capture'] ?? $context['payload']['capture'] ?? null;
        if (\is_array($capturePayload)) {
            $captureId = $this->extractCaptureId($capturePayload);
            if ($captureId !== '') {
                return $captureId;
            }
        }

        if ($reference === '') {
            throw new \RuntimeException('PayPal capture id is missing.');
        }

        if (str_starts_with(strtoupper($reference), 'CAPTURE')) {
            return $reference;
        }

        try {
            $order = $this->getOrder($config, $reference);
            $captureId = $this->extractCaptureId($order);
            if ($captureId !== '') {
                return $captureId;
            }
        } catch (\Throwable) {
            // transaction_no 可能已是 capture id
        }

        if ($reference !== '') {
            return $reference;
        }

        throw new \RuntimeException('PayPal capture id is missing.');
    }

    /**
     * Orders v2 package tracking — POST /v2/checkout/orders/{order_id}/track
     * Preferred for Checkout Orders integrations (no Dashboard "Shipping" feature toggle).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $tracker capture_id + tracking_number + carrier (+ optional notify_payer/items)
     * @return array<string, mixed>
     * @see https://developer.paypal.com/docs/tracking/orders-api/integrate/
     */
    public function addOrderTracking(array $config, string $checkoutOrderId, array $tracker): array
    {
        $checkoutOrderId = trim($checkoutOrderId);
        $captureId = trim((string) ($tracker['capture_id'] ?? $tracker['transaction_id'] ?? ''));
        $trackingNumber = trim((string) ($tracker['tracking_number'] ?? ''));
        if ($checkoutOrderId === '' || $captureId === '' || $trackingNumber === '') {
            throw new \InvalidArgumentException('PayPal order tracking requires checkout order id, capture_id and tracking_number.');
        }

        $body = [
            'capture_id' => $captureId,
            'tracking_number' => $trackingNumber,
            'notify_payer' => !empty($tracker['notify_payer']) || !empty($tracker['notify_buyer']),
        ];
        $carrier = trim((string) ($tracker['carrier'] ?? ''));
        if ($carrier !== '') {
            $body['carrier'] = $carrier;
        }
        $carrierOther = trim((string) ($tracker['carrier_name_other'] ?? ''));
        if ($carrierOther !== '') {
            $body['carrier_name_other'] = $carrierOther;
        }
        if (\is_array($tracker['items'] ?? null) && $tracker['items'] !== []) {
            $body['items'] = $tracker['items'];
        }

        $token = $this->fetchAccessToken($config);
        $response = $this->request(
            $config,
            'POST',
            '/v2/checkout/orders/' . rawurlencode($checkoutOrderId) . '/track',
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        // Idempotent same capture+tracking may return 200; create returns 201.
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : ['http_status' => $response['status']];
    }

    /**
     * Legacy Add Tracking — POST /v1/shipping/trackers-batch
     * Restricted scope; often absent from new App Features UI. Prefer addOrderTracking for Orders v2.
     *
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $trackers
     * @return array<string, mixed>
     */
    public function addTrackingBatch(array $config, array $trackers): array
    {
        $trackers = array_values(array_filter(
            $trackers,
            static fn(mixed $row): bool => \is_array($row)
                && trim((string) ($row['transaction_id'] ?? '')) !== ''
                && trim((string) ($row['tracking_number'] ?? '')) !== '',
        ));
        if ($trackers === []) {
            throw new \InvalidArgumentException('PayPal trackers payload is empty.');
        }

        $token = $this->fetchAccessToken($config);
        $response = $this->request(
            $config,
            'POST',
            '/v1/shipping/trackers-batch',
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            json_encode(['trackers' => $trackers], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function getOrder(array $config, string $orderId): array
    {
        $token = $this->fetchAccessToken($config);
        $response = $this->request(
            $config,
            'GET',
            '/v2/checkout/orders/' . rawurlencode($orderId),
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            null,
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Verify webhook authenticity via POST /v1/notifications/verify-webhook-signature.
     *
     * @param array<string, mixed> $config must include webhook_id + client credentials
     * @param array<string, string> $transmissionHeaders PayPal-Transmission-* (+ Auth-Algo, Cert-Url)
     * @see https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature_post
     */
    public function verifyWebhookSignature(
        array $config,
        string $rawBody,
        array $transmissionHeaders,
    ): bool {
        $webhookId = trim((string) ($config['webhook_id'] ?? ''));
        if ($webhookId === '' || $rawBody === '') {
            return false;
        }

        $authAlgo = trim((string) ($transmissionHeaders['auth_algo'] ?? ''));
        $certUrl = trim((string) ($transmissionHeaders['cert_url'] ?? ''));
        $transmissionId = trim((string) ($transmissionHeaders['transmission_id'] ?? ''));
        $transmissionSig = trim((string) ($transmissionHeaders['transmission_sig'] ?? ''));
        $transmissionTime = trim((string) ($transmissionHeaders['transmission_time'] ?? ''));
        if ($authAlgo === '' || $certUrl === '' || $transmissionId === '' || $transmissionSig === '' || $transmissionTime === '') {
            return false;
        }

        $webhookEvent = json_decode($rawBody, true);
        if (!\is_array($webhookEvent)) {
            return false;
        }

        $token = $this->fetchAccessToken($config);
        $response = $this->request(
            $config,
            'POST',
            '/v1/notifications/verify-webhook-signature',
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            json_encode([
                'auth_algo' => $authAlgo,
                'cert_url' => $certUrl,
                'transmission_id' => $transmissionId,
                'transmission_sig' => $transmissionSig,
                'transmission_time' => $transmissionTime,
                'webhook_id' => $webhookId,
                'webhook_event' => $webhookEvent,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return false;
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded)
            && strtoupper(trim((string) ($decoded['verification_status'] ?? ''))) === 'SUCCESS';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function fetchAccessToken(array $config): string
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        if ($clientId !== '' && $clientSecret !== '') {
            // 服务端 REST（下单/捕获）优先 client_credentials；商户 OAuth token 会过期且非此场景必需。
            return $this->fetchClientCredentialsToken($config, $clientId, $clientSecret);
        }

        $oauthToken = trim((string) ($config['oauth_access_token'] ?? ''));
        if ($oauthToken !== '') {
            return $oauthToken;
        }

        throw new \RuntimeException('PayPal client_id or client_secret is missing.');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function fetchClientCredentialsToken(array $config, string $clientId, string $clientSecret): string
    {
        $response = $this->request(
            $config,
            'POST',
            '/v1/oauth2/token',
            [
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'grant_type=client_credentials',
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        $token = trim((string) (\is_array($decoded) ? ($decoded['access_token'] ?? '') : ''));
        if ($token === '') {
            throw new \RuntimeException('PayPal access token is missing.');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $headers
     * @return array{status:int,body:string}
     */
    private function request(array $config, string $method, string $path, array $headers, ?string $body): array
    {
        $url = rtrim($this->baseUrl($config), '/') . $path;
        if ($this->httpHandler !== null) {
            return ($this->httpHandler)($method, $url, array_fill_keys($headers, ''), $body);
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required for PayPal provider.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize PayPal HTTP client.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new \RuntimeException($error !== '' ? $error : 'PayPal HTTP request failed.');
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function baseUrl(array $config): string
    {
        $environment = strtolower(trim((string) ($config['environment'] ?? 'sandbox')));

        return $environment === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
    }

    private function formatAmount(string $currencyCode, int $amountMinor): string
    {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($currencyCode === 'JPY') {
            return (string) max(0, $amountMinor);
        }

        return number_format(max(0, $amountMinor) / 100, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractLink(array $payload, string $rel): string
    {
        foreach ((array) ($payload['links'] ?? []) as $link) {
            if (!\is_array($link)) {
                continue;
            }
            if (strcasecmp((string) ($link['rel'] ?? ''), $rel) === 0) {
                return trim((string) ($link['href'] ?? ''));
            }
        }

        return '';
    }

    private function extractErrorMessage(string $body, int $httpStatus = 0): string
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            return $body !== '' ? $body : 'PayPal request failed.';
        }

        $message = trim((string) ($decoded['message'] ?? ''));
        $detailText = '';

        foreach (['details', 'errors'] as $key) {
            foreach ((array) ($decoded[$key] ?? []) as $detail) {
                if (!\is_array($detail)) {
                    continue;
                }
                $issue = trim((string) ($detail['issue'] ?? $detail['name'] ?? ''));
                $description = trim((string) ($detail['description'] ?? $detail['message'] ?? ''));
                if ($issue !== '' || $description !== '') {
                    $detailText = trim($issue . ($description !== '' ? ': ' . $description : ''));
                    break 2;
                }
            }
        }

        $base = $message !== '' ? $message : ($detailText !== '' ? $detailText : 'PayPal request failed.');
        if ($httpStatus === 403 && (stripos($base, 'NOT_AUTHORIZED') !== false || stripos($base, 'insufficient permissions') !== false)) {
            return $base . '（若走旧接口 /v1/shipping/trackers-batch：该权限通常不在 App Features 勾选页，需 PayPal 支持加 scope。本站 Checkout 应优先用 Orders v2 /v2/checkout/orders/{id}/track，见后台 PayPal「发货物流回传」与 doc/payment-methods/paypal/paypal.md）';
        }

        return $base;
    }

    /**
     * PayPal 要求完整 https URL；配置里 cancel_url 常为站内相对路径。
     *
     * @param array<string, mixed> $config
     */
    private function normalizeCallbackUrl(string $url, array $config): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = trim((string) ($config['return_url'] ?? ''));
        if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
            return $url;
        }

        $parts = parse_url($base);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . (str_starts_with($url, '/') ? $url : '/' . $url);
    }
}
