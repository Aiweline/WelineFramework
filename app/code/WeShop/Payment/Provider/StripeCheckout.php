<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use GuzzleHttp\Client;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Interface\PaymentConfigTesterInterface;

class StripeCheckout implements PaymentProviderInterface, PaymentConfigTesterInterface
{
    use ProviderContextHelperTrait;

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 30, 'http_errors' => false]);
    }

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $this->requireConfigKeys($context, ['secret_key', 'success_url', 'cancel_url'], 'Stripe');

        $config = $this->readConfig($context);
        $orderNumber = $this->readOrderNumber($order);
        $amount = (int) round(((float) $this->readOrderAmount($order, $paymentData)) * 100);
        $currency = strtolower((string) ($paymentData['currency'] ?? $context['currency'] ?? 'usd'));
        $idempotencyKey = substr(hash('sha256', 'stripe_checkout:' . $orderNumber), 0, 32);

        $response = $this->client->post('https://api.stripe.com/v1/checkout/sessions', [
            'auth' => [(string) $config['secret_key'], ''],
            'headers' => [
                'Idempotency-Key' => $idempotencyKey,
            ],
            'form_params' => [
                'mode' => 'payment',
                'success_url' => (string) $config['success_url'],
                'cancel_url' => (string) $config['cancel_url'],
                'client_reference_id' => $orderNumber,
                'payment_method_types[0]' => 'card',
                'line_items[0][quantity]' => '1',
                'line_items[0][price_data][currency]' => $currency,
                'line_items[0][price_data][unit_amount]' => (string) max(1, $amount),
                'line_items[0][price_data][product_data][name]' => $this->readOrderSubject($order, $paymentData),
                'metadata[order_number]' => $orderNumber,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Stripe Checkout session creation failed with HTTP ' . $statusCode . ': ' . substr($body, 0, 500));
        }

        $result = json_decode($body, true);
        if (!\is_array($result) || trim((string) ($result['id'] ?? '')) === '') {
            throw new \RuntimeException('Stripe Checkout returned an invalid session response.');
        }

        return [
            'status' => 'pending',
            'requires_action' => true,
            'redirect_url' => (string) ($result['url'] ?? ''),
            'provider_reference' => (string) $result['id'],
            'idempotency_key' => $idempotencyKey,
            'payment_params' => [
                'stripe_session_id' => (string) $result['id'],
                'order_reference' => $orderNumber,
            ],
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        $webhookSecret = $this->readConfigString($context, 'webhook_secret');
        if ($webhookSecret === '') {
            return $callbackData !== [];
        }

        $payload = (string) ($callbackData['payload'] ?? '');
        $signature = (string) ($callbackData['stripe-signature'] ?? $callbackData['signature'] ?? '');
        if ($payload === '' || $signature === '') {
            return false;
        }

        return str_contains($signature, hash_hmac('sha256', $payload, $webhookSecret));
    }

    public function testConfig(array $config, array $context = []): array
    {
        $secretKey = trim((string) ($config['secret_key'] ?? ''));
        if ($secretKey === '') {
            return ['success' => false, 'message' => 'Stripe secret_key is required.'];
        }

        $response = $this->client->get('https://api.stripe.com/v1/account', [
            'auth' => [$secretKey, ''],
        ]);

        return [
            'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
            'message' => 'Stripe account API returned HTTP ' . $response->getStatusCode() . '.',
            'details' => ['http_status' => $response->getStatusCode()],
        ];
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        $sessionId = (string) ($context['stripe_session_id'] ?? $context['provider_reference'] ?? '');
        $secretKey = $this->readConfigString($context, 'secret_key');
        if ($sessionId === '' || $secretKey === '') {
            return 'unknown';
        }

        $response = $this->client->get('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId), [
            'auth' => [$secretKey, ''],
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return 'unknown';
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!\is_array($result)) {
            return 'unknown';
        }

        return match ((string) ($result['payment_status'] ?? '')) {
            'paid' => 'paid',
            'unpaid', 'no_payment_required' => 'pending',
            default => 'unknown',
        };
    }
}
