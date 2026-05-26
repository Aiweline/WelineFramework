<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use GuzzleHttp\Client;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Interface\PaymentConfigTesterInterface;

class PayPal implements PaymentProviderInterface, PaymentConfigTesterInterface
{
    use ProviderContextHelperTrait;

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 30, 'http_errors' => false]);
    }

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $this->requireConfigKeys($context, ['client_id', 'client_secret', 'return_url', 'cancel_url'], 'PayPal');

        $orderReference = $this->readOrderNumber($order);
        $config = $this->readConfig($context);
        $accessToken = $this->getAccessToken($context);
        $baseUrl = $this->baseUrl($context);
        $currency = strtoupper((string) ($paymentData['currency'] ?? $context['currency'] ?? 'USD'));
        $amount = $this->readOrderAmount($order, $paymentData);
        $idempotencyKey = substr(hash('sha256', 'paypal:' . $orderReference), 0, 32);

        $response = $this->client->post($baseUrl . '/v2/checkout/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'PayPal-Request-Id' => $idempotencyKey,
            ],
            'json' => [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $orderReference,
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => $amount,
                        ],
                        'description' => $this->readOrderSubject($order, $paymentData),
                    ],
                ],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                            'landing_page' => 'LOGIN',
                            'user_action' => 'PAY_NOW',
                            'return_url' => (string) $config['return_url'],
                            'cancel_url' => (string) $config['cancel_url'],
                        ],
                    ],
                ],
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('PayPal order creation failed with HTTP ' . $statusCode . ': ' . substr($body, 0, 500));
        }

        $result = json_decode($body, true);
        if (!\is_array($result) || trim((string) ($result['id'] ?? '')) === '') {
            throw new \RuntimeException('PayPal order creation returned an invalid response.');
        }

        return [
            'status' => $this->mapStatus((string) ($result['status'] ?? 'CREATED')),
            'requires_action' => true,
            'redirect_url' => $this->extractApprovalUrl($result),
            'provider_reference' => (string) $result['id'],
            'paypal_order_id' => (string) $result['id'],
            'idempotency_key' => $idempotencyKey,
            'payment_params' => [
                'intent' => 'CAPTURE',
                'order_reference' => $orderReference,
                'paypal_order_id' => (string) $result['id'],
            ],
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        $paypalOrderId = (string) ($callbackData['token'] ?? $callbackData['paypal_order_id'] ?? '');
        if ($paypalOrderId === '') {
            return false;
        }

        $response = $this->client->post($this->baseUrl($context) . '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken($context),
                'Content-Type' => 'application/json',
            ],
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        $result = json_decode((string) $response->getBody(), true);

        return \is_array($result) && strtoupper((string) ($result['status'] ?? '')) === 'COMPLETED';
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = $this->getAccessToken(array_merge($context, [
            'config' => $config,
            'environment' => (string) ($config['environment'] ?? $context['environment'] ?? 'sandbox'),
        ]));

        return [
            'success' => $token !== '',
            'message' => 'PayPal OAuth token request passed.',
            'details' => ['environment' => (string) ($config['environment'] ?? $context['environment'] ?? 'sandbox')],
        ];
    }

    public function queryPaymentStatus(string $orderReference, array $context = []): string
    {
        $paypalOrderId = (string) ($context['paypal_order_id'] ?? $context['provider_reference'] ?? '');
        if ($paypalOrderId === '') {
            return 'unknown';
        }

        $response = $this->client->get($this->baseUrl($context) . '/v2/checkout/orders/' . rawurlencode($paypalOrderId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken($context),
                'Accept' => 'application/json',
            ],
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return 'unknown';
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!\is_array($result)) {
            return 'unknown';
        }

        return $this->mapStatus((string) ($result['status'] ?? ''));
    }

    private function getAccessToken(array $context): string
    {
        $this->requireConfigKeys($context, ['client_id', 'client_secret'], 'PayPal');
        $config = $this->readConfig($context);
        $response = $this->client->post($this->baseUrl($context) . '/v1/oauth2/token', [
            'auth' => [(string) $config['client_id'], (string) $config['client_secret']],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('PayPal OAuth token request failed with HTTP ' . $statusCode . ': ' . substr($body, 0, 500));
        }

        $result = json_decode($body, true);
        $token = \is_array($result) ? trim((string) ($result['access_token'] ?? '')) : '';
        if ($token === '') {
            throw new \RuntimeException('PayPal OAuth token response did not contain access_token.');
        }

        return $token;
    }

    private function baseUrl(array $context): string
    {
        return ((string) ($context['environment'] ?? 'sandbox')) === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractApprovalUrl(array $result): string
    {
        $links = \is_array($result['links'] ?? null) ? $result['links'] : [];
        foreach (['payer-action', 'approve'] as $targetRel) {
            foreach ($links as $link) {
                if (!\is_array($link)) {
                    continue;
                }
                if ((string) ($link['rel'] ?? '') === $targetRel) {
                    return (string) ($link['href'] ?? '');
                }
            }
        }

        return '';
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED' => 'paid',
            'APPROVED', 'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED' => 'pending',
            'VOIDED', 'CANCELLED' => 'cancelled',
            default => 'pending',
        };
    }
}
