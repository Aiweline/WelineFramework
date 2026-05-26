<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use GuzzleHttp\Client;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Interface\PaymentConfigTesterInterface;

class GenericHostedRedirect implements PaymentProviderInterface, PaymentConfigTesterInterface
{
    use ProviderContextHelperTrait;

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 30, 'http_errors' => false]);
    }

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $this->requireConfigKeys($context, ['api_url', 'api_key', 'merchant_id', 'return_url', 'notify_url'], 'Hosted payment provider');

        $config = $this->readConfig($context);
        $method = $this->readPaymentMethod($context);
        $orderNumber = $this->readOrderNumber($order);
        $amount = $this->readOrderAmount($order, $paymentData);
        $currency = strtoupper((string) ($paymentData['currency'] ?? $context['currency'] ?? $config['currency'] ?? 'USD'));
        $idempotencyKey = $this->buildIdempotencyKey((string) ($method['code'] ?? 'hosted'), $orderNumber);

        $payload = [
            'merchant_id' => (string) $config['merchant_id'],
            'reference' => $orderNumber,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $this->readOrderSubject($order, $paymentData),
            'return_url' => (string) $config['return_url'],
            'notify_url' => (string) $config['notify_url'],
            'metadata' => [
                'payment_method' => (string) ($method['code'] ?? ''),
                'environment' => (string) ($context['environment'] ?? $config['environment'] ?? 'sandbox'),
            ],
        ];

        if (\is_array($paymentData['customer'] ?? null)) {
            $payload['customer'] = $paymentData['customer'];
        }

        $response = $this->client->post((string) $config['api_url'], [
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $config['api_key'],
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Hosted payment API request failed with HTTP ' . $statusCode . ': ' . substr($body, 0, 500));
        }

        $result = json_decode($body, true);
        if (!\is_array($result)) {
            throw new \RuntimeException('Hosted payment API returned invalid JSON.');
        }

        $providerReference = (string) ($result['id'] ?? $result['payment_id'] ?? $result['reference'] ?? '');
        $redirectUrl = $this->extractRedirectUrl($result);
        $status = $this->mapStatus((string) ($result['status'] ?? ($redirectUrl !== '' ? 'pending' : 'created')));

        return [
            'status' => $status,
            'requires_action' => $redirectUrl !== '',
            'redirect_url' => $redirectUrl,
            'provider_reference' => $providerReference,
            'idempotency_key' => $idempotencyKey,
            'payment_params' => [
                'provider_response' => $result,
                'order_reference' => $orderNumber,
            ],
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        $secret = $this->readConfigString($context, 'webhook_secret');
        if ($secret !== '' && !$this->verifySignature($callbackData, $secret)) {
            return false;
        }

        return $callbackData !== [];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $testUrl = trim((string) ($config['test_url'] ?? $config['healthcheck_url'] ?? ''));
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($testUrl === '') {
            return [
                'success' => false,
                'message' => 'This hosted provider requires test_url or healthcheck_url before it can be enabled.',
            ];
        }

        $headers = ['Accept' => 'application/json'];
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        $response = $this->client->get($testUrl, ['headers' => $headers]);

        return [
            'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
            'message' => 'Hosted provider test URL returned HTTP ' . $response->getStatusCode() . '.',
            'details' => ['http_status' => $response->getStatusCode(), 'test_url' => $testUrl],
        ];
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        $queryUrl = $this->readConfigString($context, 'query_url');
        $apiKey = $this->readConfigString($context, 'api_key');
        if ($queryUrl === '' || $apiKey === '') {
            return 'unknown';
        }

        $response = $this->client->get(str_replace('{reference}', rawurlencode($orderNumber), $queryUrl), [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
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

        return $this->mapStatus((string) ($result['status'] ?? 'unknown'));
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function extractRedirectUrl(array $result): string
    {
        foreach (['redirect_url', 'payment_url', 'checkout_url', 'url'] as $key) {
            $url = trim((string) ($result[$key] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        $nextAction = $result['next_action'] ?? [];
        if (\is_array($nextAction)) {
            $url = trim((string) ($nextAction['redirect_url'] ?? $nextAction['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        $links = $result['links'] ?? [];
        if (\is_array($links)) {
            foreach ($links as $link) {
                if (!\is_array($link)) {
                    continue;
                }
                $rel = strtolower((string) ($link['rel'] ?? ''));
                if (\in_array($rel, ['checkout', 'approve', 'payer-action', 'redirect'], true)) {
                    $url = trim((string) ($link['href'] ?? $link['url'] ?? ''));
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }

        return '';
    }

    protected function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID', 'SUCCEEDED', 'SUCCESS', 'SUCCESSFUL', 'CAPTURED', 'COMPLETED' => 'paid',
            'FAILED', 'DECLINED', 'ERROR' => 'failed',
            'CANCELED', 'CANCELLED', 'VOIDED', 'EXPIRED' => 'cancelled',
            'REFUNDED', 'REFUND' => 'refunded',
            'UNKNOWN' => 'unknown',
            default => 'pending',
        };
    }

    /**
     * @param array<string, mixed> $callbackData
     */
    protected function verifySignature(array $callbackData, string $secret): bool
    {
        $signature = (string) ($callbackData['signature'] ?? $callbackData['x-signature'] ?? '');
        $payload = (string) ($callbackData['payload'] ?? '');
        if ($signature === '' || $payload === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    protected function buildIdempotencyKey(string $methodCode, string $orderNumber): string
    {
        return substr(hash('sha256', $methodCode . ':' . $orderNumber), 0, 32);
    }
}
