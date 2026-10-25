<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Service\PayPalApiClient;
use Weline\Payment\Service\PayPalJsSdkUrlBuilder;

final class PayPalCardFieldsTest extends TestCase
{
    public function testCreateOrderSendsCardFieldsPaymentSourceAndDoesNotRequireApproveLink(): void
    {
        $seenBody = '';
        $client = new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$seenBody): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                self::assertSame('POST', $method);
                self::assertStringContainsString('/v2/checkout/orders', $url);
                $seenBody = (string) $body;

                return [
                    'status' => 201,
                    'body' => json_encode(['id' => 'ORDER-CARD-1', 'status' => 'CREATED']) ?: '{}',
                ];
            },
        );

        $order = $client->createOrder(
            [
                'environment' => 'sandbox',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'return_url' => 'https://example.test/payment/return',
                'cancel_url' => 'https://example.test/payment/cancel',
            ],
            'USD',
            2599,
            'REF-CARD-1',
            null,
            null,
            [
                'card_fields' => true,
                'locale' => 'en-US',
                'brand_name' => 'Weline Test',
            ],
        );

        self::assertSame('ORDER-CARD-1', $order['order_id']);
        self::assertSame('', $order['approve_url']);
        self::assertStringContainsString('"payment_source":{"card":', $seenBody);
        self::assertStringContainsString('"experience_context":', $seenBody);
        self::assertStringContainsString('"SCA_WHEN_REQUIRED"', $seenBody);
        self::assertStringNotContainsString('"application_context"', $seenBody);
    }

    public function testProviderCreatePaymentReturnsCardFieldsAction(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                self::assertStringContainsString('/v2/checkout/orders', $url);

                return [
                    'status' => 201,
                    'body' => json_encode(['id' => 'ORDER-CARD-2', 'status' => 'CREATED']) ?: '{}',
                ];
            },
        ));

        $result = $provider->createPayment(PaymentRequest::fromArray([
            'intent_code' => 'INT-CARD',
            'attempt_code' => 'ATT-CARD',
            'payable_type' => 'order',
            'payable_id' => 'ord-card',
            'method_code' => 'paypal',
            'amount_minor' => 2599,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'locale' => 'en-US',
                'client_action' => true,
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                    'card_fields_enabled' => '1',
                ],
            ],
        ]));

        self::assertSame(PaymentResult::STATUS_REQUIRES_ACTION, $result->getStatus());
        self::assertSame('card_fields', $result->getActionType());
        self::assertSame('ORDER-CARD-2', $result->getProviderReference());
        $payload = $result->getPayload();
        self::assertSame('card_fields', $payload['client_action']['type'] ?? null);
        self::assertSame('ORDER-CARD-2', $payload['client_action']['data']['order_id'] ?? null);
        self::assertStringContainsString('card-fields', (string) ($payload['client_action']['data']['sdk_src'] ?? ''));
        self::assertArrayNotHasKey('redirect_url', $payload);
    }

    public function testProviderFallsBackToRedirectWhenCardFieldsDisabled(): void
    {
        $seenBody = '';
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$seenBody): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }
                $seenBody = (string) $body;

                return [
                    'status' => 201,
                    'body' => json_encode([
                        'id' => 'ORDER-REDIRECT-1',
                        'links' => [
                            ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORDER-REDIRECT-1'],
                        ],
                    ], JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            },
        ));

        $result = $provider->createPayment(PaymentRequest::fromArray([
            'intent_code' => 'INT-FALLBACK',
            'attempt_code' => 'ATT-FALLBACK',
            'payable_type' => 'order',
            'payable_id' => 'ord-fallback',
            'method_code' => 'paypal',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'context' => [
                'environment' => 'sandbox',
                'client_action' => true,
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                    'card_fields_enabled' => '0',
                ],
            ],
        ]));

        self::assertSame('redirect', $result->getActionType());
        self::assertSame(
            'https://sandbox.paypal.com/checkoutnow?token=ORDER-REDIRECT-1',
            $result->getPayload()['redirect_url'] ?? null,
        );
        self::assertStringContainsString('"application_context":', $seenBody);
        self::assertStringNotContainsString('"payment_source":', $seenBody);
    }

    public function testProviderFallsBackToRedirectWhenPayPalCurrencyDiffers(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token-123']) ?: '{}'];
                }

                return [
                    'status' => 201,
                    'body' => json_encode([
                        'id' => 'ORDER-REDIRECT-2',
                        'links' => [
                            ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORDER-REDIRECT-2'],
                        ],
                    ], JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            },
        ));

        $result = $provider->createPayment(PaymentRequest::fromArray([
            'intent_code' => 'INT-CURRENCY',
            'attempt_code' => 'ATT-CURRENCY',
            'payable_type' => 'order',
            'payable_id' => 'ord-currency',
            'method_code' => 'paypal',
            'amount_minor' => 7100,
            'currency_code' => 'CNY',
            'context' => [
                'environment' => 'sandbox',
                'client_action' => true,
                'runtime_config' => [
                    'sandbox_client_id' => 'sb-client',
                    'sandbox_client_secret' => 'sb-secret',
                    'return_url' => 'https://example.test/payment/return',
                    'cancel_url' => 'https://example.test/payment/cancel',
                    'card_fields_enabled' => '1',
                ],
            ],
        ]));

        self::assertSame('redirect', $result->getActionType());
        self::assertNotEmpty($result->getPayload()['redirect_url'] ?? null);
    }

    public function testConfirmPaymentChecksOrderBindingBeforeIdempotentSuccess(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentService.php'
        );
        $methodStart = strpos($source, 'public function confirmPayment');
        $methodEnd = strpos($source, 'public function handleCallback', (int) $methodStart);
        self::assertNotFalse($methodStart);
        self::assertNotFalse($methodEnd);
        $method = substr($source, (int) $methodStart, (int) $methodEnd - (int) $methodStart);
        $orderCheck = strpos($method, 'payment_transaction_order_mismatch');
        $idempotentReturn = strpos($method, 'if ($transaction->isSuccess())');
        self::assertNotFalse($orderCheck);
        self::assertNotFalse($idempotentReturn);
        self::assertLessThan($idempotentReturn, $orderCheck);
    }

    public function testSdkUrlBuilderIncludesCardFieldsWhenRequested(): void
    {
        $url = (new PayPalJsSdkUrlBuilder())->build([
            'client_id' => 'client-id',
            'google_pay_enabled' => true,
            'apple_pay_enabled' => true,
        ], [
            'components' => 'buttons,card-fields,googlepay,applepay',
            'currency' => 'USD',
        ]);

        self::assertStringContainsString('card-fields', $url);
        self::assertStringContainsString('client-id=client-id', $url);
    }

    public function testSdkUrlBuilderSkipsFundingFlagsForCardFields(): void
    {
        // 沙箱/未开通 Google Pay 的商户传 enable-funding=googlepay 会直接 400；
        // card-fields 场景通过 skip_funding_flags 不声明钱包能力。
        $builder = new PayPalJsSdkUrlBuilder();
        $config = [
            'client_id' => 'client-id',
            'google_pay_enabled' => true,
            'apple_pay_enabled' => true,
        ];
        $context = [
            'components' => 'buttons,card-fields',
            'currency' => 'USD',
            'skip_funding_flags' => true,
        ];

        $url = $builder->build($config, $context);
        self::assertStringContainsString('card-fields', $url);
        self::assertStringNotContainsString('enable-funding', $url);
        self::assertStringNotContainsString('disable-funding', $url);

        $meta = $builder->walletMeta($config, $context);
        self::assertStringNotContainsString('enable-funding', $meta['js_sdk_src']);
    }
}
