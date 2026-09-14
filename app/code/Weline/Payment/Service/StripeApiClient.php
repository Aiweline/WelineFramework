<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * Stripe REST 轻量客户端（Checkout Session、Refund、Webhook 验签）。
 */
final class StripeApiClient
{
    private const API_BASE = 'https://api.stripe.com';

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
     * @return array{success:bool,message:string,details?:array<string,mixed>}
     */
    public function testConnection(array $config): array
    {
        try {
            $secret = $this->secretKey($config);
            if ($secret === '') {
                return ['success' => false, 'message' => 'Stripe secret key is empty.'];
            }

            $response = $this->request($config, 'GET', '/v1/balance', [
                'Authorization: Bearer ' . $secret,
            ], null);

            if ($response['status'] < 200 || $response['status'] >= 300) {
                return [
                    'success' => false,
                    'message' => $this->extractErrorMessage($response['body'], $response['status']),
                ];
            }

            return [
                'success' => true,
                'message' => 'Stripe connection passed.',
                'details' => [
                    'environment' => (string) ($config['environment'] ?? 'sandbox'),
                    'base_url' => self::API_BASE,
                ],
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array{
     *   success_url?:string,
     *   cancel_url?:string,
     *   customer_email?:string,
     *   metadata?:array<string,string>
     * } $options
     * @return array{session_id:string,url:string,payment_intent:string,raw:array<string,mixed>}
     */
    public function createCheckoutSession(
        array $config,
        string $currencyCode,
        int $amountMinor,
        string $clientReferenceId,
        array $options = [],
    ): array {
        $secret = $this->requireSecretKey($config);
        $currency = strtolower(trim($currencyCode));
        $successUrl = trim((string) ($options['success_url'] ?? $config['success_url'] ?? $config['return_url'] ?? ''));
        $cancelUrl = trim((string) ($options['cancel_url'] ?? $config['cancel_url'] ?? ''));
        if ($successUrl === '' || $cancelUrl === '') {
            throw new \RuntimeException('Stripe success_url and cancel_url are required.');
        }

        $fields = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $clientReferenceId,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][unit_amount]' => (string) max(0, $amountMinor),
            'line_items[0][price_data][product_data][name]' => 'Order ' . $clientReferenceId,
        ];

        $email = trim((string) ($options['customer_email'] ?? ''));
        if ($email !== '') {
            $fields['customer_email'] = $email;
        }

        $metadata = \is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        foreach ($metadata as $key => $value) {
            $metaKey = trim((string) $key);
            if ($metaKey === '') {
                continue;
            }
            $fields['metadata[' . $metaKey . ']'] = (string) $value;
        }

        $response = $this->request(
            $config,
            'POST',
            '/v1/checkout/sessions',
            [
                'Authorization: Bearer ' . $secret,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            http_build_query($fields),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Stripe create Checkout Session response is invalid.');
        }

        $sessionId = trim((string) ($decoded['id'] ?? ''));
        $url = trim((string) ($decoded['url'] ?? ''));
        if ($sessionId === '' || $url === '') {
            throw new \RuntimeException('Stripe Checkout Session id or url is missing.');
        }

        return [
            'session_id' => $sessionId,
            'url' => $url,
            'payment_intent' => $this->extractPaymentIntentId($decoded),
            'raw' => $decoded,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(array $config, string $sessionId): array
    {
        $secret = $this->requireSecretKey($config);
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new \RuntimeException('Stripe Checkout Session id is missing.');
        }

        $response = $this->request(
            $config,
            'GET',
            '/v1/checkout/sessions/' . rawurlencode($sessionId),
            [
                'Authorization: Bearer ' . $secret,
            ],
            null,
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Stripe retrieve Checkout Session response is invalid.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{refund_id:string,raw:array<string,mixed>}
     */
    public function createRefund(
        array $config,
        string $paymentIntentId,
        ?int $amountMinor = null,
        ?string $idempotencyKey = null,
    ): array {
        $secret = $this->requireSecretKey($config);
        $paymentIntentId = trim($paymentIntentId);
        if ($paymentIntentId === '') {
            throw new \RuntimeException('Stripe PaymentIntent id is missing for refund.');
        }

        $fields = ['payment_intent' => $paymentIntentId];
        if ($amountMinor !== null && $amountMinor > 0) {
            $fields['amount'] = (string) $amountMinor;
        }

        $headers = [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $idempotencyKey = trim((string) $idempotencyKey);
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $response = $this->request(
            $config,
            'POST',
            '/v1/refunds',
            $headers,
            http_build_query($fields),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], $response['status']));
        }

        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Stripe refund response is invalid.');
        }

        return [
            'refund_id' => trim((string) ($decoded['id'] ?? '')),
            'raw' => $decoded,
        ];
    }

    /**
     * Verify Stripe-Signature header (v1 HMAC).
     *
     * @param array<string, mixed> $config
     */
    public function verifyWebhookSignature(array $config, string $rawBody, string $signatureHeader, int $toleranceSeconds = 300): bool
    {
        $secret = trim((string) ($config['webhook_secret'] ?? ''));
        if ($secret === '' || $rawBody === '' || $signatureHeader === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if ($toleranceSeconds > 0 && abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function extractPaymentIntentId(array $session): string
    {
        $pi = $session['payment_intent'] ?? null;
        if (\is_string($pi)) {
            return trim($pi);
        }
        if (\is_array($pi)) {
            return trim((string) ($pi['id'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $headers
     * @return array{status:int,body:string}
     */
    private function request(array $config, string $method, string $path, array $headers, ?string $body): array
    {
        unset($config);
        $url = rtrim(self::API_BASE, '/') . $path;
        if ($this->httpHandler !== null) {
            return ($this->httpHandler)($method, $url, array_fill_keys($headers, ''), $body);
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required for Stripe provider.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize Stripe HTTP client.');
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
            throw new \RuntimeException($error !== '' ? $error : 'Stripe HTTP request failed.');
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function secretKey(array $config): string
    {
        return trim((string) ($config['secret_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requireSecretKey(array $config): string
    {
        $secret = $this->secretKey($config);
        if ($secret === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        return $secret;
    }

    private function extractErrorMessage(string $body, int $httpStatus = 0): string
    {
        $decoded = json_decode($body, true);
        if (\is_array($decoded)) {
            $error = \is_array($decoded['error'] ?? null) ? $decoded['error'] : $decoded;
            $message = trim((string) ($error['message'] ?? ''));
            $code = trim((string) ($error['code'] ?? $error['type'] ?? ''));
            if ($message !== '') {
                return $code !== '' ? $code . ': ' . $message : $message;
            }
        }

        if ($body !== '') {
            return $body;
        }

        return $httpStatus > 0
            ? 'Stripe request failed with HTTP ' . $httpStatus . '.'
            : 'Stripe request failed.';
    }
}
