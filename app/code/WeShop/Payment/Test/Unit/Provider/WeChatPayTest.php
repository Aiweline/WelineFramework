<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Payment\Provider\WeChatPay;

class WeChatPayTest extends TestCase
{
    public function testProcessPaymentReturnsRedirectUrlFromUnifiedOrderResponse(): void
    {
        $provider = new class() extends WeChatPay {
            public array $capturedRequest = [];

            protected function requestUnifiedOrder(array $request, array $context): array
            {
                $this->capturedRequest = $request;

                return [
                    'return_code' => 'SUCCESS',
                    'result_code' => 'SUCCESS',
                    'prepay_id' => 'wx-prepay-001',
                    'mweb_url' => 'https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id=wx-prepay-001',
                ];
            }
        };

        $order = $this->createOrder('WS202603240002', 99.9);
        $result = $provider->processPayment($order, [
            'client_ip' => '203.0.113.10',
        ], [
            'payment_method' => [
                'code' => 'wechatpay',
                'config' => [
                    'sandbox' => true,
                    'app_id' => 'wx-app-001',
                    'mch_id' => 'mch-001',
                    'api_v3_key' => 'secret-key',
                    'notify_url' => 'https://shop.test/payment/callback?payment_method=wechatpay',
                    'trade_type' => 'MWEB',
                ],
            ],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertTrue((bool) ($result['requires_action'] ?? false));
        $this->assertSame('https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id=wx-prepay-001', $result['redirect_url'] ?? '');
        $this->assertSame('wx-app-001', $provider->capturedRequest['appid'] ?? '');
        $this->assertSame('MWEB', $provider->capturedRequest['trade_type'] ?? '');
        $this->assertSame('203.0.113.10', $provider->capturedRequest['spbill_create_ip'] ?? '');
        $this->assertArrayHasKey('sign', $provider->capturedRequest);
    }

    public function testHandleCallbackParsesRawXmlAndValidatesSignature(): void
    {
        $provider = new WeChatPay();
        $payload = [
            'appid' => 'wx-app-001',
            'mch_id' => 'mch-001',
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'out_trade_no' => 'WS202603240002',
            'transaction_id' => 'wx-order-001',
            'nonce_str' => 'nonce-001',
        ];
        $payload['sign'] = $this->signWeChatPayload($payload, 'secret-key');

        $result = $provider->handleCallback([
            'raw_body' => $this->buildXml($payload),
        ], [
            'payment_method' => [
                'code' => 'wechatpay',
                'config' => [
                    'api_v3_key' => 'secret-key',
                ],
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testQueryPaymentStatusMapsTradeStateFromGatewayResponse(): void
    {
        $provider = new class() extends WeChatPay {
            public array $capturedRequest = [];

            protected function requestOrderQuery(array $request, array $context): array
            {
                $this->capturedRequest = $request;

                return [
                    'return_code' => 'SUCCESS',
                    'result_code' => 'SUCCESS',
                    'trade_state' => 'SUCCESS',
                ];
            }
        };

        $status = $provider->queryPaymentStatus('WS202603240002', [
            'payment_method' => [
                'code' => 'wechatpay',
                'config' => [
                    'sandbox' => true,
                    'app_id' => 'wx-app-001',
                    'mch_id' => 'mch-001',
                    'api_v3_key' => 'secret-key',
                ],
            ],
        ]);

        $this->assertSame('paid', $status);
        $this->assertSame('WS202603240002', $provider->capturedRequest['out_trade_no'] ?? '');
        $this->assertArrayHasKey('sign', $provider->capturedRequest);
    }

    private function createOrder(string $incrementId, float $total): Order
    {
        return new class($incrementId, $total) extends Order {
            public function __construct(
                private readonly string $incrementId,
                private readonly float $total
            ) {
            }

            public function getIncrementId(): string
            {
                return $this->incrementId;
            }

            public function getId(mixed $default = 0)
            {
                return 102;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return match ($key) {
                    self::schema_fields_total => $this->total,
                    default => $index,
                };
            }
        };
    }

    /**
     * @param array<string, string> $payload
     */
    private function buildXml(array $payload): string
    {
        $xml = '<xml>';
        foreach ($payload as $key => $value) {
            $xml .= sprintf('<%1$s><![CDATA[%2$s]]></%1$s>', $key, $value);
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signWeChatPayload(array $payload, string $apiKey): string
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

        return strtoupper(md5(implode('&', $pairs)));
    }
}
