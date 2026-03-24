<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Payment\Provider\Alipay;

class AlipayTest extends TestCase
{
    public function testProcessPaymentBuildsSignedRedirectPayloadFromContext(): void
    {
        $provider = new class() extends Alipay {
            protected function signParameters(array $params, string $privateKey, string $signType): string
            {
                return 'signed-payload';
            }
        };
        $order = $this->createOrder('WS202603240001', 188.5);

        $result = $provider->processPayment($order, [
            'subject' => 'WeShop Order WS202603240001',
            'return_url' => 'https://shop.test/payment/return',
        ], [
            'payment_method' => [
                'code' => 'alipay',
                'config' => [
                    'sandbox' => true,
                    'app_id' => 'app-001',
                    'merchant_id' => 'merchant-001',
                    'private_key' => 'private-key',
                    'public_key' => 'public-key',
                    'notify_url' => 'https://shop.test/payment/callback?payment_method=alipay',
                ],
            ],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertTrue((bool) ($result['requires_action'] ?? false));
        $this->assertStringContainsString('openapi-sandbox.dl.alipaydev.com', $result['redirect_url'] ?? '');
        $this->assertSame('app-001', $result['payment_params']['app_id'] ?? '');
        $this->assertArrayHasKey('sign', $result['payment_params']);
        $this->assertSame('signed-payload', (string) ($result['payment_params']['sign'] ?? ''));
        $this->assertStringContainsString('WS202603240001', (string) ($result['payment_params']['biz_content'] ?? ''));
    }

    public function testHandleCallbackReturnsTrueWhenTradeStatusIsSuccessfulAndSignatureMatches(): void
    {
        $provider = new class() extends Alipay {
            protected function verifySignature(array $payload, string $publicKey, string $signType): bool
            {
                return true;
            }
        };
        $callbackData = [
            'app_id' => 'app-001',
            'merchant_id' => 'merchant-001',
            'out_trade_no' => 'WS202603240001',
            'trade_no' => '2026032400001',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '188.50',
            'sign' => 'signed-callback',
            'sign_type' => 'RSA2',
        ];

        $result = $provider->handleCallback($callbackData, [
            'payment_method' => [
                'code' => 'alipay',
                'config' => [
                    'public_key' => 'public-key',
                ],
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testQueryPaymentStatusMapsGatewayResponse(): void
    {
        $provider = new class() extends Alipay {
            public array $capturedParams = [];

            protected function signParameters(array $params, string $privateKey, string $signType): string
            {
                return 'signed-query';
            }

            protected function sendGatewayRequest(array $params, array $context): array
            {
                $this->capturedParams = $params;

                return [
                    'alipay_trade_query_response' => [
                        'code' => '10000',
                        'trade_status' => 'TRADE_SUCCESS',
                    ],
                ];
            }
        };

        $status = $provider->queryPaymentStatus('WS202603240001', [
            'payment_method' => [
                'code' => 'alipay',
                'config' => [
                    'sandbox' => true,
                    'app_id' => 'app-001',
                    'merchant_id' => 'merchant-001',
                    'private_key' => 'private-key',
                ],
            ],
        ]);

        $this->assertSame('paid', $status);
        $this->assertSame('alipay.trade.query', $provider->capturedParams['method'] ?? '');
        $this->assertSame('signed-query', $provider->capturedParams['sign'] ?? '');
        $this->assertStringContainsString('WS202603240001', (string) ($provider->capturedParams['biz_content'] ?? ''));
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
                return 101;
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

}
