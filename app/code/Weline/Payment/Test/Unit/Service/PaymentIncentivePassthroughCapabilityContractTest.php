<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Service\PayPalApiClient;

final class PaymentIncentivePassthroughCapabilityContractTest extends TestCase
{
    public function testPayPalDeclaresBreakdownCapabilitiesDistinctFromDiscountActions(): void
    {
        $caps = (new PayPalProvider())->getCapabilities();
        self::assertTrue(!empty($caps['amount_breakdown']));
        self::assertTrue(!empty($caps['discount_passthrough']));
        self::assertContains('paypal_breakdown', $caps['passthrough_formats'] ?? []);
        self::assertIsArray($caps['supported_discount_actions'] ?? null);
        self::assertNotSame(
            $caps['supported_discount_actions'],
            $caps['passthrough_formats'] ?? null,
            'supported_discount_actions must not be reused as passthrough formats',
        );
    }

    public function testFakeDeclaresShellEchoPassthrough(): void
    {
        $caps = (new FakeProvider())->getCapabilities();
        self::assertTrue(!empty($caps['amount_breakdown']));
        self::assertTrue(!empty($caps['discount_passthrough']));
        self::assertContains('shell_echo', $caps['passthrough_formats'] ?? []);
    }

    public function testPayPalApiClientFormatsBreakdownInCreateOrderPayload(): void
    {
        $captured = null;
        $client = new PayPalApiClient(static function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
        ) use (&$captured): array {
            if (str_contains($url, '/v1/oauth2/token')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: '{}'];
            }
            $captured = json_decode((string) $body, true);

            return [
                'status' => 201,
                'body' => json_encode([
                    'id' => 'ORDER-1',
                    'links' => [['rel' => 'approve', 'href' => 'https://example.test/approve']],
                ]) ?: '{}',
            ];
        });

        $client->createOrder(
            [
                'environment' => 'sandbox',
                'client_id' => 'id',
                'client_secret' => 'sec',
                'return_url' => 'https://example.test/return',
                'cancel_url' => 'https://example.test/cancel',
            ],
            'USD',
            9000,
            'ref-1',
            'https://example.test/return',
            'https://example.test/cancel',
            [
                'amount_breakdown' => [
                    'currency_code' => 'USD',
                    'value_minor' => 9000,
                    'item_total_minor' => 10000,
                    'shipping_minor' => 0,
                    'handling_minor' => 0,
                    'tax_total_minor' => 0,
                    'insurance_minor' => 0,
                    'shipping_discount_minor' => 0,
                    'discount_minor' => 1000,
                    'description' => '券;激励',
                ],
            ],
        );

        self::assertIsArray($captured);
        $amount = $captured['purchase_units'][0]['amount'] ?? null;
        self::assertIsArray($amount);
        self::assertSame('90.00', $amount['value']);
        self::assertArrayHasKey('breakdown', $amount);
        self::assertSame('100.00', $amount['breakdown']['item_total']['value']);
        self::assertSame('10.00', $amount['breakdown']['discount']['value']);
    }

    public function testFakeCreatePaymentEchoesBreakdown(): void
    {
        $provider = new FakeProvider();
        $result = $provider->createPayment(\Weline\Payment\Api\Data\PaymentRequest::fromArray([
            \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_INTENT_CODE => 'I1',
            \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_ATTEMPT_CODE => 'A1',
            \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_AMOUNT_MINOR => 9500,
            \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_CURRENCY_CODE => 'CNY',
            \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_CONTEXT => [
                'amount_breakdown' => [
                    'currency_code' => 'CNY',
                    'value_minor' => 9500,
                    'item_total_minor' => 10000,
                    'discount_minor' => 500,
                    'shipping_minor' => 0,
                    'tax_total_minor' => 0,
                    'handling_minor' => 0,
                    'insurance_minor' => 0,
                    'shipping_discount_minor' => 0,
                ],
                'discount_lines' => [[
                    'source_type' => 'payment_method_incentive',
                    'amount_minor' => -500,
                ]],
            ],
        ]));

        $payload = $result->getPayload();
        self::assertIsArray($payload);
        self::assertArrayHasKey('echo_breakdown', $payload);
        self::assertSame(9500, $payload['echo_breakdown']['value_minor']);
        self::assertSame(500, $payload['echo_breakdown']['discount_minor']);
    }

    public function testEventAndConfigTemplatesDocumented(): void
    {
        $root = dirname(__DIR__, 3);
        $eventXml = (string) file_get_contents($root . '/etc/event.xml');
        self::assertStringContainsString('Weline_Payment::checkout::available_methods::enrich', $eventXml);
        self::assertStringContainsString('CheckoutAvailableMethodsIncentiveEnrichObserver', $eventXml);

        $paypal = (string) file_get_contents(
            $root . '/extends/module/Weline_SystemConfig/Config/backend/paypal.phtml'
        );
        $fake = (string) file_get_contents(
            $root . '/extends/module/Weline_SystemConfig/Config/backend/fake_card.phtml'
        );
        self::assertStringContainsString('payment/method/paypal/incentive_enabled', $paypal);
        self::assertStringContainsString('payment/method/fake_card/incentive_enabled', $fake);

        $eventDoc = $root . '/doc/event/checkout-available-methods-enrich.md';
        self::assertFileExists($eventDoc);

        $ledger = (string) file_get_contents($root . '/Model/PaymentLedger.php');
        self::assertStringContainsString("TYPE_DISCOUNT = 'discount'", $ledger);

        $eventPhp = (string) file_get_contents($root . '/event.php');
        self::assertStringContainsString(
            'Weline_Payment::checkout::available_methods::enrich',
            $eventPhp,
            'event.php 规约必须登记 enrich 事件，否则 event:rebuild 后 live registry 缺失',
        );
        self::assertStringContainsString('checkout-available-methods-enrich.md', $eventPhp);

        $quoteSrc = (string) file_get_contents($root . '/Service/PaymentMethodIncentiveQuoteService.php');
        self::assertStringContainsString("__('减 %{1}'", $quoteSrc);
        self::assertStringNotContainsString("__('减 %1'", $quoteSrc);
    }
}
