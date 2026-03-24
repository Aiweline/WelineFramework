<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;

class Alipay implements PaymentProviderInterface
{
    use ProviderContextHelperTrait;

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $this->requireConfigKeys($context, ['app_id', 'merchant_id', 'private_key', 'public_key'], 'Alipay');

        $method = $this->readPaymentMethod($context);
        $code = (string) ($method['code'] ?? 'alipay');
        $appId = $this->readConfigString($context, 'app_id');
        $merchantId = $this->readConfigString($context, 'merchant_id');
        $signType = strtoupper($this->readConfigString($context, 'sign_type', 'RSA2'));
        $productCode = $this->readConfigString($context, 'product_code', 'FAST_INSTANT_TRADE_PAY');
        $timeoutExpress = $this->readConfigString($context, 'timeout_express', '30m');

        $bizContent = [
            'out_trade_no' => $this->readOrderNumber($order),
            'total_amount' => $this->readOrderAmount($order, $paymentData),
            'subject' => $this->readOrderSubject($order, $paymentData),
            'product_code' => $productCode,
            'seller_id' => $merchantId,
            'timeout_express' => $timeoutExpress,
        ];

        $params = [
            'app_id' => $appId,
            'method' => 'alipay.trade.page.pay',
            'charset' => 'utf-8',
            'sign_type' => $signType,
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->resolveNotifyUrl($code, $paymentData, $context),
            'return_url' => $this->resolveReturnUrl($code, $paymentData, $context),
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        $params['sign'] = $this->signParameters($params, $this->readConfigString($context, 'private_key'), $signType);

        return [
            'status' => 'pending',
            'requires_action' => true,
            'redirect_url' => $this->resolveGatewayUrl($context) . '?' . http_build_query($params),
            'payment_url' => $this->resolveGatewayUrl($context),
            'payment_params' => $params,
            'gateway' => 'alipay',
            'sandbox' => $this->readConfigBool($context, 'sandbox', true),
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        $payload = $this->normalizeCallbackPayload($callbackData);
        if ($payload === []) {
            return false;
        }

        $publicKey = $this->readConfigString($context, 'public_key');
        $sign = (string) ($payload['sign'] ?? '');
        if ($publicKey !== '' && $sign !== '' && !$this->verifySignature($payload, $publicKey, (string) ($payload['sign_type'] ?? 'RSA2'))) {
            return false;
        }

        $status = strtoupper((string) ($payload['trade_status'] ?? $payload['status'] ?? ''));

        return \in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS', 'PAID'], true);
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        $inlineStatus = strtoupper((string) ($context['trade_status'] ?? $context['status'] ?? ''));
        if ($inlineStatus !== '') {
            return $this->mapTradeStatus($inlineStatus);
        }

        $this->requireConfigKeys($context, ['app_id', 'merchant_id', 'private_key'], 'Alipay');

        $params = [
            'app_id' => $this->readConfigString($context, 'app_id'),
            'method' => 'alipay.trade.query',
            'charset' => 'utf-8',
            'sign_type' => strtoupper($this->readConfigString($context, 'sign_type', 'RSA2')),
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'out_trade_no' => $orderNumber,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        $params['sign'] = $this->signParameters($params, $this->readConfigString($context, 'private_key'), (string) $params['sign_type']);

        $response = $this->sendGatewayRequest($params, $context);
        $tradeStatus = strtoupper((string) ($response['alipay_trade_query_response']['trade_status'] ?? $response['trade_status'] ?? ''));

        return $this->mapTradeStatus($tradeStatus);
    }

    /**
     * @param array<string, mixed> $callbackData
     * @return array<string, mixed>
     */
    protected function normalizeCallbackPayload(array $callbackData): array
    {
        $payload = $callbackData;
        $rawBody = trim((string) ($callbackData['raw_body'] ?? ''));
        if ($rawBody !== '' && $payload === []) {
            parse_str($rawBody, $payload);
        }

        unset($payload['raw_body'], $payload['content_type']);

        return $payload;
    }

    protected function resolveGatewayUrl(array $context): string
    {
        return $this->readConfigBool($context, 'sandbox', true)
            ? 'https://openapi-sandbox.dl.alipaydev.com/gateway.do'
            : 'https://openapi.alipay.com/gateway.do';
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function signParameters(array $params, string $privateKey, string $signType): string
    {
        $content = $this->buildSignContent($params);
        $signature = '';
        $algorithm = strtoupper($signType) === 'RSA2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $keyResource = openssl_pkey_get_private($this->normalizePemKey($privateKey, 'PRIVATE'));
        if ($keyResource === false || !openssl_sign($content, $signature, $keyResource, $algorithm)) {
            throw new \InvalidArgumentException((string) __('Unable to sign the Alipay request with the configured private key.'));
        }

        return base64_encode($signature);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function verifySignature(array $payload, string $publicKey, string $signType): bool
    {
        $signature = base64_decode((string) ($payload['sign'] ?? ''), true);
        if ($signature === false || $signature === '') {
            return false;
        }

        $content = $this->buildSignContent($payload);
        $algorithm = strtoupper($signType) === 'RSA2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $keyResource = openssl_pkey_get_public($this->normalizePemKey($publicKey, 'PUBLIC'));
        if ($keyResource === false) {
            return false;
        }

        return openssl_verify($content, $signature, $keyResource, $algorithm) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function buildSignContent(array $payload): string
    {
        unset($payload['sign'], $payload['sign_type']);
        ksort($payload);

        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $pairs[] = $key . '=' . (string) $value;
        }

        return implode('&', $pairs);
    }

    protected function normalizePemKey(string $key, string $type): string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return $trimmed;
        }
        if (str_contains($trimmed, 'BEGIN')) {
            return $trimmed;
        }

        $wrapped = chunk_split(preg_replace('/\s+/', '', $trimmed) ?: $trimmed, 64, "\n");

        return "-----BEGIN {$type} KEY-----\n{$wrapped}-----END {$type} KEY-----";
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function sendGatewayRequest(array $params, array $context): array
    {
        $url = $this->resolveGatewayUrl($context) . '?' . http_build_query($params);
        if (!function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPGET => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!\is_string($response) || trim($response) === '') {
            return [];
        }

        $decoded = json_decode($response, true);

        return \is_array($decoded) ? $decoded : [];
    }

    protected function mapTradeStatus(string $tradeStatus): string
    {
        return match ($tradeStatus) {
            'TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS', 'PAID' => 'paid',
            'WAIT_BUYER_PAY', 'PENDING' => 'pending',
            'TRADE_CLOSED', 'FAILED', 'CLOSED' => 'failed',
            'REFUND_SUCCESS', 'REFUNDED' => 'refunded',
            default => 'pending',
        };
    }
}
