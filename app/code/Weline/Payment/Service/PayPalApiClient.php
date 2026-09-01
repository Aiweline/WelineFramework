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
     * @return array{order_id:string,approve_url:string,raw:array<string,mixed>}
     */
    public function createOrder(
        array $config,
        string $currencyCode,
        int $amountMinor,
        string $referenceId,
        ?string $returnUrl = null,
        ?string $cancelUrl = null,
    ): array {
        $token = $this->fetchAccessToken($config);
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $referenceId,
                'amount' => [
                    'currency_code' => strtoupper($currencyCode),
                    'value' => $this->formatAmount($currencyCode, $amountMinor),
                ],
            ]],
            'application_context' => array_filter([
                'return_url' => $returnUrl ?: (string) ($config['return_url'] ?? ''),
                'cancel_url' => $cancelUrl ?: (string) ($config['cancel_url'] ?? ''),
                'user_action' => 'PAY_NOW',
            ]),
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
            throw new \RuntimeException($this->extractErrorMessage($response['body']));
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
            throw new \RuntimeException($this->extractErrorMessage($response['body']));
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
            throw new \RuntimeException($this->extractErrorMessage($response['body']));
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
            throw new \RuntimeException($this->extractErrorMessage($response['body']));
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
            throw new \RuntimeException($this->extractErrorMessage($response['body']));
        }

        $decoded = json_decode($response['body'], true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function fetchAccessToken(array $config): string
    {
        $oauthToken = trim((string) ($config['oauth_access_token'] ?? ''));
        if ($oauthToken !== '') {
            return $oauthToken;
        }

        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('PayPal client_id or client_secret is missing.');
        }

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
            throw new \RuntimeException($this->extractErrorMessage($response['body']));
        }

        $decoded = json_decode($response['body'], true);
        $token = trim((string) (\is_array($decoded) ? ($decoded['access_token'] ?? '') : ''));

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

    private function extractErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            return $body !== '' ? $body : 'PayPal request failed.';
        }

        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        foreach ((array) ($decoded['details'] ?? []) as $detail) {
            if (!\is_array($detail)) {
                continue;
            }
            $issue = trim((string) ($detail['issue'] ?? ''));
            $description = trim((string) ($detail['description'] ?? ''));
            if ($issue !== '' || $description !== '') {
                return trim($issue . ': ' . $description);
            }
        }

        return 'PayPal request failed.';
    }
}
