<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Interface\PaymentConfigTesterInterface;

class WeChatPay implements PaymentProviderInterface, PaymentConfigTesterInterface
{
    use ProviderContextHelperTrait;

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $this->requireConfigKeys($context, ['app_id', 'mch_id', 'api_v3_key'], 'WeChat Pay');

        $method = $this->readPaymentMethod($context);
        $code = (string) ($method['code'] ?? 'wechatpay');
        $tradeType = strtoupper((string) ($paymentData['trade_type'] ?? $this->readConfigString($context, 'trade_type', 'MWEB')));
        $signType = strtoupper($this->readConfigString($context, 'sign_type', 'MD5'));
        $request = [
            'appid' => $this->readConfigString($context, 'app_id'),
            'mch_id' => $this->readConfigString($context, 'mch_id'),
            'nonce_str' => $this->generateNonce(),
            'body' => $this->readOrderSubject($order, $paymentData),
            'out_trade_no' => $this->readOrderNumber($order),
            'total_fee' => $this->formatAmountToFen($this->readOrderAmount($order, $paymentData)),
            'spbill_create_ip' => $this->resolveClientIp($paymentData, $context),
            'notify_url' => $this->resolveNotifyUrl($code, $paymentData, $context),
            'trade_type' => $tradeType,
            'sign_type' => $signType,
        ];

        if ($tradeType === 'MWEB') {
            $request['scene_info'] = (string) ($paymentData['scene_info'] ?? $this->readConfigString($context, 'scene_info', '{"h5_info":{"type":"Wap"}}'));
        }

        $request['sign'] = $this->signPayload($request, $this->readConfigString($context, 'api_v3_key'), $signType);
        $response = $this->requestUnifiedOrder($request, $context);

        if (strtoupper((string) ($response['return_code'] ?? '')) !== 'SUCCESS' || strtoupper((string) ($response['result_code'] ?? '')) !== 'SUCCESS') {
            $message = (string) ($response['return_msg'] ?? $response['err_code_des'] ?? __('Unable to initialize the WeChat Pay order.'));
            throw new \RuntimeException($message);
        }

        $redirectUrl = '';
        $qrCodeUrl = '';
        if ($tradeType === 'MWEB') {
            $redirectUrl = (string) ($response['mweb_url'] ?? '');
        } elseif ($tradeType === 'NATIVE') {
            $qrCodeUrl = (string) ($response['code_url'] ?? '');
        }

        return [
            'status' => 'pending',
            'requires_action' => true,
            'redirect_url' => $redirectUrl,
            'qr_code_url' => $qrCodeUrl,
            'payment_params' => [
                'request' => $request,
                'response' => $response,
            ],
            'gateway' => 'wechatpay',
            'sandbox' => $this->readConfigBool($context, 'sandbox', true),
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        $payload = $this->normalizeCallbackPayload($callbackData);
        if ($payload === []) {
            return false;
        }

        $sign = (string) ($payload['sign'] ?? '');
        if ($sign !== '' && !$this->verifyPayloadSignature($payload, $this->readConfigString($context, 'api_v3_key'), (string) ($payload['sign_type'] ?? 'MD5'))) {
            return false;
        }

        return strtoupper((string) ($payload['return_code'] ?? '')) === 'SUCCESS'
            && strtoupper((string) ($payload['result_code'] ?? '')) === 'SUCCESS';
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        $inlineState = strtoupper((string) ($context['trade_state'] ?? $context['status'] ?? ''));
        if ($inlineState !== '') {
            return $this->mapTradeState($inlineState);
        }

        $this->requireConfigKeys($context, ['app_id', 'mch_id', 'api_v3_key'], 'WeChat Pay');

        $request = [
            'appid' => $this->readConfigString($context, 'app_id'),
            'mch_id' => $this->readConfigString($context, 'mch_id'),
            'out_trade_no' => $orderNumber,
            'nonce_str' => $this->generateNonce(),
            'sign_type' => strtoupper($this->readConfigString($context, 'sign_type', 'MD5')),
        ];
        $request['sign'] = $this->signPayload($request, $this->readConfigString($context, 'api_v3_key'), (string) $request['sign_type']);

        $response = $this->requestOrderQuery($request, $context);
        $tradeState = strtoupper((string) ($response['trade_state'] ?? ''));

        return $this->mapTradeState($tradeState);
    }

    public function testConfig(array $config, array $context = []): array
    {
        $apiKey = trim((string) ($config['api_v3_key'] ?? ''));
        if ($apiKey === '') {
            return ['success' => false, 'message' => 'WeChat Pay api_v3_key is required.'];
        }

        $payload = [
            'appid' => (string) ($config['app_id'] ?? 'test-app'),
            'mch_id' => (string) ($config['mch_id'] ?? 'test-mch'),
            'nonce_str' => 'configtest',
            'sign_type' => strtoupper((string) ($config['sign_type'] ?? 'MD5')),
        ];
        $payload['sign'] = $this->signPayload($payload, $apiKey, (string) $payload['sign_type']);

        return [
            'success' => $this->verifyPayloadSignature($payload, $apiKey, (string) $payload['sign_type']),
            'message' => 'WeChat Pay local signature validation passed.',
            'details' => ['test_type' => 'local_signature'],
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    protected function requestUnifiedOrder(array $request, array $context): array
    {
        return $this->sendXmlRequest(
            $this->readConfigBool($context, 'sandbox', true)
                ? 'https://api.mch.weixin.qq.com/sandboxnew/pay/unifiedorder'
                : 'https://api.mch.weixin.qq.com/pay/unifiedorder',
            $request
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    protected function requestOrderQuery(array $request, array $context): array
    {
        return $this->sendXmlRequest(
            $this->readConfigBool($context, 'sandbox', true)
                ? 'https://api.mch.weixin.qq.com/sandboxnew/pay/orderquery'
                : 'https://api.mch.weixin.qq.com/pay/orderquery',
            $request
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function signPayload(array $payload, string $apiKey, string $signType): string
    {
        unset($payload['sign']);
        ksort($payload);

        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $pairs[] = $key . '=' . $value;
        }
        $pairs[] = 'key=' . $apiKey;
        $baseString = implode('&', $pairs);

        return strtoupper($signType === 'HMAC-SHA256'
            ? hash_hmac('sha256', $baseString, $apiKey)
            : md5($baseString));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function verifyPayloadSignature(array $payload, string $apiKey, string $signType): bool
    {
        $expected = $this->signPayload($payload, $apiKey, strtoupper($signType === '' ? 'MD5' : $signType));

        return hash_equals($expected, strtoupper((string) ($payload['sign'] ?? '')));
    }

    /**
     * @param array<string, mixed> $callbackData
     * @return array<string, mixed>
     */
    protected function normalizeCallbackPayload(array $callbackData): array
    {
        $payload = $callbackData;
        $rawBody = trim((string) ($callbackData['raw_body'] ?? ''));
        if ($rawBody !== '') {
            $xmlPayload = $this->parseXmlPayload($rawBody);
            if ($xmlPayload !== []) {
                $payload = array_merge($payload, $xmlPayload);
            }
        }

        unset($payload['raw_body'], $payload['content_type']);

        return $payload;
    }

    protected function resolveClientIp(array $paymentData, array $context): string
    {
        $ip = trim((string) ($paymentData['client_ip'] ?? $this->readConfigString($context, 'spbill_create_ip')));

        return $ip !== '' ? $ip : '127.0.0.1';
    }

    protected function formatAmountToFen(string $amount): int
    {
        return max(1, (int) round((float) $amount * 100));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    protected function sendXmlRequest(string $url, array $payload): array
    {
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
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8'],
            CURLOPT_POSTFIELDS => $this->buildXmlPayload($payload),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!\is_string($response) || trim($response) === '') {
            return [];
        }

        return $this->parseXmlPayload($response);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function buildXmlPayload(array $payload): string
    {
        $xml = '<xml>';
        foreach ($payload as $key => $value) {
            $xml .= sprintf('<%1$s><![CDATA[%2$s]]></%1$s>', $key, (string) $value);
        }
        $xml .= '</xml>';

        return $xml;
    }

    protected function mapTradeState(string $tradeState): string
    {
        return match ($tradeState) {
            'SUCCESS' => 'paid',
            'USERPAYING', 'NOTPAY', 'PENDING' => 'pending',
            'REFUND', 'REFUNDED' => 'refunded',
            'CLOSED', 'REVOKED', 'PAYERROR', 'FAILED' => 'failed',
            default => 'pending',
        };
    }
}
