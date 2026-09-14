<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\TestConnectionRequest;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\StripeProvider;
use Weline\Payment\Service\StripeApiClient;

final class StripeProviderTest extends TestCase
{
    public function testSandboxCredentialsAreResolvedForAvailability(): void
    {
        $provider = new StripeProvider();
        $result = $provider->checkAvailability(AvailabilityRequest::fromArray([
            'payable_type' => 'order',
            'payable_id' => 'ord-1',
            'method_code' => 'stripe',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'country_code' => 'US',
            'context' => [
                'environment' => 'sandbox',
                'runtime_config' => [
                    'sandbox_secret_key' => 'sk_test_xxx',
                    'supported_currencies' => ['USD'],
                    'supported_countries' => ['US'],
                ],
            ],
        ]));

        self::assertTrue($result->isAvailable());
    }

    public function testCreatePaymentReturnsStripeRedirectAction(): void
    {
        $provider = new StripeProvider();
        $provider->setApiClient(new StripeApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                self::assertSame('POST', $method);
                self::assertStringContainsString('/v1/checkout/sessions', $url);
                self::assertNotNull($body);
                self::assertStringContainsString('mode=payment', (string) $body);

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'id' => 'cs_test_123',
                        'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                        'payment_intent' => 'pi_test_123',
                    ], JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            }
        ));

        $result = $provider->createPayment(PaymentRequest::fromArray([
            'intent_code' => 'INT-1',
            'attempt_code' => 'ATT-1',
            'payable_type' => 'order',
            'payable_id' => 'ord-1',
            'method_code' => 'stripe',
            'amount_minor' => 2599,
            'currency_code' => 'USD',
            'return_url' => 'https://example.test/payment/return',
            'cancel_url' => 'https://example.test/payment/cancel',
            'context' => [
                'environment' => 'sandbox',
                'runtime_config' => [
                    'sandbox_secret_key' => 'sk_test_xxx',
                ],
            ],
        ]));

        self::assertSame(PaymentResult::STATUS_REQUIRES_ACTION, $result->getStatus());
        self::assertSame('redirect', $result->getActionType());
        self::assertSame('cs_test_123', $result->getProviderReference());
        self::assertSame(
            'https://checkout.stripe.com/c/pay/cs_test_123',
            $result->getPayload()['redirect_url'] ?? null
        );
    }

    public function testConnectionUsesBalanceEndpoint(): void
    {
        $provider = new StripeProvider();
        $provider->setApiClient(new StripeApiClient(
            static function (string $method, string $url, array $headers, ?string $body): array {
                self::assertSame('GET', $method);
                self::assertStringContainsString('/v1/balance', $url);

                return ['status' => 200, 'body' => '{"object":"balance"}'];
            }
        ));

        $result = $provider->testConnection(TestConnectionRequest::fromArray([
            'provider_code' => 'stripe',
            'method_code' => 'stripe',
            'environment' => 'sandbox',
            'config' => [
                'sandbox_secret_key' => 'sk_test_xxx',
            ],
        ]));

        self::assertSame(PaymentResult::STATUS_PAID, $result->getStatus());
    }

    public function testVerifyCallbackAcceptsValidStripeSignature(): void
    {
        $provider = new StripeProvider();
        $raw = '{"id":"evt_1","type":"checkout.session.completed","data":{"object":{"id":"cs_1"}}}';
        $secret = 'whsec_test';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);

        $result = $provider->verifyCallback(CallbackRequest::fromArray([
            'provider_code' => 'stripe',
            'raw_body' => $raw,
            'signature' => 't=' . $timestamp . ',v1=' . $signature,
            'payload' => json_decode($raw, true),
            'verification_secret' => $secret,
        ]));

        self::assertTrue($result->isVerified());
        self::assertSame('evt_1', $result->getProviderEventId());
    }

    public function testParseCallbackMapsCheckoutCompletedToPaid(): void
    {
        $provider = new StripeProvider();
        $result = $provider->parseCallback(CallbackRequest::fromArray([
            'provider_code' => 'stripe',
            'payload' => [
                'id' => 'evt_2',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_2',
                        'payment_intent' => 'pi_2',
                        'client_reference_id' => 'ATT-9',
                        'metadata' => ['intent_code' => 'INT-9'],
                    ],
                ],
            ],
        ]));

        self::assertSame('paid', $result->getData('status_transition'));
        self::assertSame('INT-9', $result->getIntentCode());
        self::assertSame('pi_2', $result->getData('transaction_code'));
    }

    public function testCspDirectivesIncludeStripeHosts(): void
    {
        $provider = new StripeProvider();
        $csp = $provider->cspDirectives();
        self::assertContains('https://js.stripe.com', $csp['script-src'] ?? []);
        self::assertContains('https://api.stripe.com', $csp['connect-src'] ?? []);
        self::assertContains('https://checkout.stripe.com', $csp['frame-src'] ?? []);
    }
}
