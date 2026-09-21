<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Service\PayPalApiClient;

final class PayPalWebhookVerifyContractTest extends TestCase
{
    public function testVerifyRejectsWhenWebhookIdSetButHeadersMissing(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(static fn (): array => ['status' => 500, 'body' => '{}']));
        $req = CallbackRequest::fromArray([
            'raw_body' => json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR),
            'payload' => ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'],
            'headers' => [],
            'context' => ['config' => [
                'webhook_id' => 'WHID',
                'client_id' => 'id',
                'client_secret' => 'sec',
                'environment' => 'sandbox',
            ]],
        ]);
        self::assertFalse($provider->verifyCallback($req)->isVerified());
    }

    public function testVerifyAcceptsUnsignedLocalInjectWithoutWebhookId(): void
    {
        $provider = new PayPalProvider();
        $req = CallbackRequest::fromArray([
            'raw_body' => json_encode(['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR),
            'payload' => ['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'],
            'headers' => [],
            'context' => ['config' => []],
        ]);
        self::assertTrue($provider->verifyCallback($req)->isVerified());
    }

    public function testApiClientVerifyPostsOfficialPath(): void
    {
        $seen = '';
        $client = new PayPalApiClient(static function (string $method, string $url, array $headers, ?string $body) use (&$seen): array {
            if (str_contains($url, '/oauth2/token')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 't'], JSON_THROW_ON_ERROR)];
            }
            $seen = $url;

            return ['status' => 200, 'body' => json_encode(['verification_status' => 'SUCCESS'], JSON_THROW_ON_ERROR)];
        });
        self::assertTrue($client->verifyWebhookSignature(
            ['environment' => 'sandbox', 'client_id' => 'i', 'client_secret' => 's', 'webhook_id' => 'WID'],
            '{"id":"WH-3"}',
            [
                'auth_algo' => 'SHA256withRSA',
                'cert_url' => 'https://api.sandbox.paypal.com/cert.pem',
                'transmission_id' => 'tid',
                'transmission_sig' => 'sig',
                'transmission_time' => '2026-01-01T00:00:00Z',
            ],
        ));
        self::assertStringContainsString('/v1/notifications/verify-webhook-signature', $seen);
    }
}
