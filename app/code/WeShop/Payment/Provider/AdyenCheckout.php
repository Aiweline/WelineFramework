<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use GuzzleHttp\Client;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Interface\PaymentConfigTesterInterface;

class AdyenCheckout implements PaymentProviderInterface, PaymentConfigTesterInterface
{
    use ProviderContextHelperTrait;

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 30, 'http_errors' => false]);
    }

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $this->requireConfigKeys($context, ['api_key', 'merchant_account', 'api_url', 'return_url'], 'Adyen');

        $config = $this->readConfig($context);
        $orderNumber = $this->readOrderNumber($order);
        $currency = strtoupper((string) ($paymentData['currency'] ?? $context['currency'] ?? 'USD'));
        $minorAmount = (int) round(((float) $this->readOrderAmount($order, $paymentData)) * 100);
        $idempotencyKey = substr(hash('sha256', 'adyen_checkout:' . $orderNumber), 0, 32);

        $response = $this->client->post((string) $config['api_url'], [
            'headers' => [
                'X-API-Key' => (string) $config['api_key'],
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
            ],
            'json' => [
                'merchantAccount' => (string) $config['merchant_account'],
                'reference' => $orderNumber,
                'amount' => [
                    'currency' => $currency,
                    'value' => max(1, $minorAmount),
                ],
                'returnUrl' => (string) $config['return_url'],
                'shopperReference' => (string) ($paymentData['customer_id'] ?? $paymentData['email'] ?? $orderNumber),
                'countryCode' => strtoupper((string) ($paymentData['country'] ?? $context['country'] ?? $context['country_id'] ?? '')),
                'channel' => 'Web',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Adyen payment request failed with HTTP ' . $statusCode . ': ' . substr($body, 0, 500));
        }

        $result = json_decode($body, true);
        if (!\is_array($result)) {
            throw new \RuntimeException('Adyen payment request returned invalid JSON.');
        }

        $action = \is_array($result['action'] ?? null) ? $result['action'] : [];
        $redirectUrl = (string) ($action['url'] ?? $action['paymentData'] ?? '');

        return [
            'status' => $this->mapResultCode((string) ($result['resultCode'] ?? '')),
            'requires_action' => $redirectUrl !== '',
            'redirect_url' => $redirectUrl,
            'provider_reference' => (string) ($result['pspReference'] ?? ''),
            'idempotency_key' => $idempotencyKey,
            'payment_params' => [
                'adyen_result' => $result,
                'order_reference' => $orderNumber,
            ],
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        $hmacKey = $this->readConfigString($context, 'webhook_hmac_key');
        if ($hmacKey === '') {
            return $callbackData !== [];
        }

        $signature = (string) ($callbackData['hmacSignature'] ?? $callbackData['additionalData']['hmacSignature'] ?? '');
        if ($signature === '') {
            return false;
        }

        $signingPayload = implode(':', [
            (string) ($callbackData['pspReference'] ?? ''),
            (string) ($callbackData['originalReference'] ?? ''),
            (string) ($callbackData['merchantAccountCode'] ?? ''),
            (string) ($callbackData['merchantReference'] ?? ''),
            (string) ($callbackData['amount']['value'] ?? ''),
            (string) ($callbackData['amount']['currency'] ?? ''),
            (string) ($callbackData['eventCode'] ?? ''),
            (string) ($callbackData['success'] ?? ''),
        ]);

        return hash_equals(base64_encode(hash_hmac('sha256', $signingPayload, pack('H*', $hmacKey), true)), $signature);
    }

    public function testConfig(array $config, array $context = []): array
    {
        $apiUrl = trim((string) ($config['api_url'] ?? ''));
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $merchantAccount = trim((string) ($config['merchant_account'] ?? ''));
        if ($apiUrl === '' || $apiKey === '' || $merchantAccount === '') {
            return ['success' => false, 'message' => 'Adyen api_url, api_key and merchant_account are required.'];
        }

        $testUrl = preg_replace('#/payments$#', '/paymentMethods', $apiUrl) ?: $apiUrl;
        $response = $this->client->post($testUrl, [
            'headers' => [
                'X-API-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'merchantAccount' => $merchantAccount,
                'countryCode' => (string) ($context['country'] ?? 'US'),
                'amount' => [
                    'currency' => (string) ($context['currency'] ?? 'USD'),
                    'value' => 100,
                ],
                'channel' => 'Web',
            ],
        ]);

        return [
            'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
            'message' => 'Adyen paymentMethods API returned HTTP ' . $response->getStatusCode() . '.',
            'details' => ['http_status' => $response->getStatusCode(), 'test_url' => $testUrl],
        ];
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        $resultCode = (string) ($context['resultCode'] ?? $context['result_code'] ?? '');
        if ($resultCode !== '') {
            return $this->mapResultCode($resultCode);
        }

        return 'unknown';
    }

    private function mapResultCode(string $resultCode): string
    {
        return match (strtoupper($resultCode)) {
            'AUTHORISED', 'CAPTURED', 'RECEIVED' => 'paid',
            'REFUSED', 'ERROR', 'CANCELLED' => 'failed',
            'PENDING', 'PRESENTTOSHOPPER', 'REDIRECTSHOPPER', 'IDENTIFYSHOPPER', 'CHALLENGESHOPPER' => 'pending',
            default => 'pending',
        };
    }
}
