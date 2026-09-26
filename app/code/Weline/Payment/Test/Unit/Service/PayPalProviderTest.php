<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\TestConnectionRequest;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Service\PayPalApiClient;

final class PayPalProviderTest extends TestCase
{
    public function testSandboxCredentialsAreResolvedForAvailability(): void
    {
        $provider = new PayPalProvider();
        $result = $provider->checkAvailability(AvailabilityRequest::fromArray([
            'payable_type' => 'order',
            'payable_id' => 'ord-1',
            'method_code' => 'paypal',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'country_code' => 'US',
            'context' => [
                'environment' => 'sandbox',
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                    'supported_currencies' => ['USD'],
                    'supported_countries' => ['US'],
                ],
            ],
        ]));

        self::assertTrue($result->isAvailable());
    }

    public function testCreatePaymentReturnsPayPalRedirectAction(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }

                self::assertSame('POST', $method);
                self::assertStringContainsString('/v2/checkout/orders', $url);

                return [
                    'status' => 201,
                    'body' => json_encode([
                        'id' => 'ORDER-123',
                        'links' => [
                            ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORDER-123'],
                        ],
                    ], JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            }
        ));

        $result = $provider->createPayment(PaymentRequest::fromArray([
            'intent_code' => 'INT-1',
            'attempt_code' => 'ATT-1',
            'payable_type' => 'order',
            'payable_id' => 'ord-1',
            'method_code' => 'paypal',
            'amount_minor' => 2599,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                ],
            ],
        ]));

        self::assertSame(PaymentResult::STATUS_REQUIRES_ACTION, $result->getStatus());
        self::assertSame('redirect', $result->getActionType());
        self::assertSame('ORDER-123', $result->getProviderReference());
        self::assertSame(
            'https://sandbox.paypal.com/checkoutnow?token=ORDER-123',
            $result->getPayload()['redirect_url'] ?? null,
        );
    }

    public function testExpressCreatePaymentUsesContinueAndOptionalNoShipping(): void
    {
        $seenBodies = [];
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$seenBodies): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                if (str_contains($url, '/v2/checkout/orders') && $method === 'POST') {
                    $seenBodies[] = (string) $body;

                    return [
                        'status' => 201,
                        'body' => json_encode([
                            'id' => 'ORDER-EX',
                            'links' => [
                                ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORDER-EX'],
                            ],
                        ], JSON_UNESCAPED_SLASHES) ?: '{}',
                    ];
                }

                throw new \RuntimeException('Unexpected PayPal URL: ' . $url);
            }
        ));

        $provider->createPayment(PaymentRequest::fromArray([
            'intent_code' => 'INT-EX',
            'attempt_code' => 'ATT-EX',
            'payable_type' => 'order',
            'payable_id' => 'ord-ex',
            'method_code' => 'paypal',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'express_checkout' => true,
                'requires_shipping' => false,
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                ],
            ],
        ]));

        self::assertNotEmpty($seenBodies);
        self::assertStringContainsString('"user_action":"CONTINUE"', $seenBodies[0]);
        self::assertStringContainsString('"shipping_preference":"NO_SHIPPING"', $seenBodies[0]);
        self::assertStringNotContainsString('"user_action":"PAY_NOW"', $seenBodies[0]);
    }

    public function testExpressResumePrepareOnlyDoesNotCapture(): void
    {
        $captured = false;
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                if (str_contains($url, '/capture')) {
                    $captured = true;

                    return ['status' => 201, 'body' => json_encode(['status' => 'COMPLETED', 'id' => 'CAP']) ?: '{}'];
                }
                if (str_contains($url, '/v2/checkout/orders/ORDER-PREP') && $method === 'GET') {
                    return [
                        'status' => 200,
                        'body' => json_encode([
                            'id' => 'ORDER-PREP',
                            'status' => 'APPROVED',
                            'payer' => ['email_address' => 'buyer@example.com'],
                            'purchase_units' => [[
                                'shipping' => [
                                    'name' => ['full_name' => 'Ada'],
                                    'address' => [
                                        'address_line_1' => '1 St',
                                        'country_code' => 'US',
                                    ],
                                ],
                            ]],
                        ]) ?: '{}',
                    ];
                }

                throw new \RuntimeException('Unexpected PayPal URL: ' . $url);
            }
        ));

        $result = $provider->resumePayment(\Weline\Payment\Api\Data\ResumeRequest::fromArray([
            'intent_code' => 'INT-1',
            'attempt_code' => 'ATT-1',
            'method_code' => 'paypal',
            'provider_reference' => 'ORDER-PREP',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'express_checkout' => true,
                'express_prepare_only' => true,
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                ],
            ],
        ]));

        self::assertFalse($captured);
        self::assertSame(PaymentResult::STATUS_PROCESSING, $result->getStatus());
        self::assertTrue((bool) ($result->getPayload()['express_awaiting_confirm'] ?? false));
        self::assertIsArray($result->getPayload()['express_profile'] ?? null);
    }

    public function testExpressConfirmSkipsPatchWhenAmountUnchanged(): void
    {
        $patched = false;
        $captured = false;
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$patched, &$captured): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                if ($method === 'PATCH') {
                    $patched = true;

                    return ['status' => 422, 'body' => json_encode([
                        'message' => 'The requested action could not be performed, semantically incorrect, or failed business validation.',
                    ]) ?: '{}'];
                }
                if (str_contains($url, '/capture')) {
                    $captured = true;

                    return ['status' => 201, 'body' => json_encode([
                        'id' => 'ORDER-SKIP',
                        'status' => 'COMPLETED',
                        'purchase_units' => [[
                            'payments' => ['captures' => [['id' => 'CAP-SKIP', 'status' => 'COMPLETED']]],
                        ]],
                    ]) ?: '{}'];
                }
                if (str_contains($url, '/v2/checkout/orders/ORDER-SKIP') && $method === 'GET') {
                    return ['status' => 200, 'body' => json_encode(['id' => 'ORDER-SKIP', 'status' => 'APPROVED']) ?: '{}'];
                }

                throw new \RuntimeException('Unexpected PayPal URL: ' . $url);
            }
        ));

        // Provider already shows 323.53 USD with a breakdown that sums to it —
        // re-confirm must capture directly, not patch (PayPal rejects the stale patch).
        $result = $provider->resumePayment(\Weline\Payment\Api\Data\ResumeRequest::fromArray([
            'intent_code' => 'INT-SKIP',
            'attempt_code' => 'ATT-SKIP',
            'method_code' => 'paypal',
            'provider_reference' => 'ORDER-SKIP',
            'amount_minor' => 32353,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'express_checkout' => true,
                'express_confirm_capture' => true,
                'patch_amount_minor' => 32353,
                'patch_currency' => 'USD',
                'payload' => [
                    'order' => [
                        'purchase_units' => [[
                            'reference_id' => 'ATT-SKIP',
                            'amount' => [
                                'currency_code' => 'USD',
                                'value' => '323.53',
                                'breakdown' => [
                                    'item_total' => ['currency_code' => 'USD', 'value' => '323.53'],
                                    'shipping' => ['currency_code' => 'USD', 'value' => '122.38'],
                                    'discount' => ['currency_code' => 'USD', 'value' => '122.38'],
                                ],
                            ],
                        ]],
                    ],
                ],
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                ],
            ],
        ]));

        self::assertFalse($patched, 'unchanged presentment must not trigger PATCH');
        self::assertTrue($captured);
        self::assertSame(PaymentResult::STATUS_PAID, $result->getStatus());
    }

    public function testExpressConfirmStillPatchesWhenAmountChanged(): void
    {
        $patchBodies = [];
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$patchBodies): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                if ($method === 'PATCH') {
                    $patchBodies[] = (string) $body;

                    return ['status' => 200, 'body' => '{"id":"ORDER-PATCH"}'];
                }
                if (str_contains($url, '/v2/checkout/orders/ORDER-PATCH') && $method === 'GET') {
                    return ['status' => 200, 'body' => json_encode([
                        'id' => 'ORDER-PATCH',
                        'status' => 'APPROVED',
                        'purchase_units' => [[
                            'reference_id' => 'ATT-PATCH',
                            'amount' => ['currency_code' => 'USD', 'value' => '200.00'],
                        ]],
                    ]) ?: '{}'];
                }
                if (str_contains($url, '/capture')) {
                    return ['status' => 201, 'body' => json_encode([
                        'id' => 'ORDER-PATCH',
                        'status' => 'COMPLETED',
                        'purchase_units' => [[
                            'payments' => ['captures' => [['id' => 'CAP-PATCH', 'status' => 'COMPLETED']]],
                        ]],
                    ]) ?: '{}'];
                }

                throw new \RuntimeException('Unexpected PayPal URL: ' . $url);
            }
        ));

        $result = $provider->resumePayment(\Weline\Payment\Api\Data\ResumeRequest::fromArray([
            'intent_code' => 'INT-PATCH',
            'attempt_code' => 'ATT-PATCH',
            'method_code' => 'paypal',
            'provider_reference' => 'ORDER-PATCH',
            'amount_minor' => 30000,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'express_checkout' => true,
                'express_confirm_capture' => true,
                'patch_amount_minor' => 30000,
                'patch_currency' => 'USD',
                'payload' => [
                    'order' => [
                        'purchase_units' => [[
                            'reference_id' => 'ATT-PATCH',
                            'amount' => ['currency_code' => 'USD', 'value' => '200.00'],
                        ]],
                    ],
                ],
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                ],
            ],
        ]));

        self::assertCount(1, $patchBodies);
        self::assertStringContainsString('300.00', $patchBodies[0]);
        self::assertSame(PaymentResult::STATUS_PAID, $result->getStatus());
    }

    /**
     * 回归：回跳上下文没带支付商订单快照（读不到支付商侧金额）时，
     * 绝不能假设「支付商侧金额 == 本次应付金额」而跳过 patch。
     * 旧实现把 presentment.minor 兜底成目标金额，needsPatch 恒为 false，
     * 结果按支付商侧旧金额 capture —— 实测订单 204/206：订单总额 442.97，实扣 323.53。
     */
    public function testExpressConfirmPatchesWhenProviderSideAmountUnknown(): void
    {
        $patchBodies = [];
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$patchBodies): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                if ($method === 'PATCH') {
                    $patchBodies[] = (string) $body;

                    return ['status' => 204, 'body' => ''];
                }
                if (str_contains($url, '/v2/checkout/orders/ORDER-NOPRESENT') && $method === 'GET') {
                    // 支付商侧仍是下单时的旧金额 323.53
                    return ['status' => 200, 'body' => json_encode([
                        'id' => 'ORDER-NOPRESENT',
                        'status' => 'APPROVED',
                        'purchase_units' => [[
                            'reference_id' => 'ATT-NOPRESENT',
                            'amount' => ['currency_code' => 'USD', 'value' => '323.53'],
                        ]],
                    ]) ?: '{}'];
                }
                if (str_contains($url, '/capture')) {
                    return ['status' => 201, 'body' => json_encode([
                        'id' => 'ORDER-NOPRESENT',
                        'status' => 'COMPLETED',
                        'purchase_units' => [[
                            'payments' => ['captures' => [['id' => 'CAP-NOPRESENT', 'status' => 'COMPLETED']]],
                        ]],
                    ]) ?: '{}'];
                }

                throw new \RuntimeException('Unexpected PayPal URL: ' . $url);
            }
        ));

        $result = $provider->resumePayment(\Weline\Payment\Api\Data\ResumeRequest::fromArray([
            'intent_code' => 'INT-NOPRESENT',
            'attempt_code' => 'ATT-NOPRESENT',
            'method_code' => 'paypal',
            'provider_reference' => 'ORDER-NOPRESENT',
            'amount_minor' => 44297,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'express_checkout' => true,
                'express_confirm_capture' => true,
                'patch_amount_minor' => 44297,
                'patch_currency' => 'USD',
                // 故意不带 payload：模拟 dispatcher 未把支付商快照注入上下文
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                ],
            ],
        ]));

        self::assertCount(1, $patchBodies, '读不到支付商侧金额时必须 patch，不能默认跳过');
        self::assertStringContainsString('442.97', $patchBodies[0]);
        self::assertSame(PaymentResult::STATUS_PAID, $result->getStatus());
    }

    public function testTestConnectionUsesSandboxEnvironment(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                self::assertStringContainsString('api-m.sandbox.paypal.com', $url);

                return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
            }
        ));

        $result = $provider->testConnection(TestConnectionRequest::fromArray([
            'provider_code' => 'paypal',
            'method_code' => 'paypal',
            'environment' => 'sandbox',
            'config' => [
                'sandbox_client_id' => 'sb-client',
                'sandbox_client_secret' => 'sb-secret',
                'return_url' => 'https://example.test/payment/return',
                'cancel_url' => 'https://example.test/payment/cancel',
            ],
        ]));

        self::assertTrue($result->isSuccessful());
        self::assertSame('sandbox', $result->getPayload()['environment'] ?? null);
    }

    public function testRefundUsesCaptureIdFromOrderPayload(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                if (str_contains($url, '/v2/payments/captures/CAP-123/refund')) {
                    self::assertSame('POST', $method);

                    return [
                        'status' => 201,
                        'body' => json_encode(['id' => 'REF-123', 'status' => 'COMPLETED']) ?: '{}',
                    ];
                }
                if (str_contains($url, '/v2/checkout/orders/ORDER-123')) {
                    return [
                        'status' => 200,
                        'body' => json_encode([
                            'id' => 'ORDER-123',
                            'status' => 'COMPLETED',
                            'purchase_units' => [[
                                'payments' => [
                                    'captures' => [['id' => 'CAP-123']],
                                ],
                            ]],
                        ]) ?: '{}',
                    ];
                }

                throw new \RuntimeException('Unexpected PayPal URL: ' . $url);
            }
        ));

        $result = $provider->refund(\Weline\Payment\Api\Data\RefundRequest::fromArray([
            'refund_code' => 'RF-1',
            'transaction_code' => 'ORDER-123',
            'method_code' => 'paypal',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'provider_reference' => 'ORDER-123',
            'context' => [
                'environment' => 'sandbox',
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                ],
            ],
        ]));

        self::assertSame(\Weline\Payment\Api\Data\RefundResult::STATUS_REFUNDED, $result->getStatus());
        self::assertSame('REF-123', $result->getProviderReference());
    }

    public function testSandboxCnyRefundConvertsToUsdCaptureAmount(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                if (str_contains($url, '/v2/payments/captures/CAP-CNY/refund')) {
                    self::assertSame('POST', $method);
                    $payload = json_decode((string)$body, true);
                    self::assertIsArray($payload);
                    self::assertSame('USD', $payload['amount']['currency_code'] ?? null);
                    self::assertSame('26.90', $payload['amount']['value'] ?? null);

                    return [
                        'status' => 201,
                        'body' => json_encode(['id' => 'REF-CNY', 'status' => 'COMPLETED']) ?: '{}',
                    ];
                }

                throw new \RuntimeException('Unexpected PayPal URL: ' . $url);
            }
        ));

        $result = $provider->refund(\Weline\Payment\Api\Data\RefundRequest::fromArray([
            'refund_code' => 'RF-CNY',
            'transaction_code' => 'CAP-CNY',
            'method_code' => 'paypal',
            'amount_minor' => 19370,
            'currency_code' => 'CNY',
            'provider_reference' => 'CAP-CNY',
            'context' => [
                'environment' => 'sandbox',
                'capture_id' => 'CAP-CNY',
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                ],
            ],
        ]));

        self::assertSame(\Weline\Payment\Api\Data\RefundResult::STATUS_REFUNDED, $result->getStatus());
        self::assertSame('REF-CNY', $result->getProviderReference());
    }
}
