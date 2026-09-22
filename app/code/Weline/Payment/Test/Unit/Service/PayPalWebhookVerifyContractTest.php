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
    public function testVerifyCallbackSourceHasNoOutboundVerify(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php'
        );
        $start = strpos($src, 'public function verifyCallback');
        self::assertNotFalse($start);
        $end = strpos($src, 'public function parseCallback', $start);
        self::assertNotFalse($end);
        $method = substr($src, $start, $end - $start);
        // Strip comments so the frozen-name mention in docs does not trip the assertion.
        $codeOnly = preg_replace('~//.*$~m', '', $method) ?? $method;
        $codeOnly = preg_replace('~/\*.*?\*/~s', '', $codeOnly) ?? $codeOnly;
        self::assertStringNotContainsString('verifyWebhookSignature', $codeOnly);
        self::assertStringNotContainsString('getApiClient()', $codeOnly);
        self::assertStringNotContainsString('->request(', $codeOnly);
        self::assertStringContainsString('verifyPayPalTransmissionLocally', $codeOnly);
        self::assertStringContainsString('fail-closed', $method);
        self::assertStringContainsString('allow_unsigned_webhook', $codeOnly);
    }

    public function testVerifyRejectsWhenWebhookIdSetButLocalMaterialsMissing(): void
    {
        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(static function (): array {
            self::fail('verifyCallback must not call PayPalApiClient HTTP');
        }));
        $req = CallbackRequest::fromArray([
            'raw_body' => json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR),
            'payload' => ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'],
            'headers' => [
                'PAYPAL-TRANSMISSION-ID' => 'tid',
                'PAYPAL-TRANSMISSION-SIG' => base64_encode('sig'),
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
                'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
                'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert.pem',
            ],
            'context' => ['config' => [
                'webhook_id' => 'WHID',
                'client_id' => 'id',
                'client_secret' => 'sec',
                'environment' => 'sandbox',
            ]],
        ]);
        self::assertFalse($provider->verifyCallback($req)->isVerified());
    }

    public function testVerifyFailClosedWithoutMaterialsAndWithoutAllowFlag(): void
    {
        $provider = new PayPalProvider();
        $req = CallbackRequest::fromArray([
            'raw_body' => json_encode(['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR),
            'payload' => ['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'],
            'headers' => [],
            'context' => ['config' => []],
        ]);
        $result = $provider->verifyCallback($req);
        self::assertFalse($result->isVerified());
        self::assertStringContainsString(
            'fail-closed',
            $result->getString(\Weline\Payment\Api\Data\CallbackResult::FIELD_MESSAGE)
        );
    }

    public function testVerifyAllowsExplicitUnsignedTestSwitch(): void
    {
        $provider = new PayPalProvider();
        $req = CallbackRequest::fromArray([
            'raw_body' => json_encode(['id' => 'WH-2b', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR),
            'payload' => ['id' => 'WH-2b', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'],
            'headers' => [],
            'context' => [
                'allow_unsigned_webhook' => true,
                'config' => [],
            ],
        ]);
        self::assertTrue($provider->verifyCallback($req)->isVerified());
    }

    public function testVerifyAcceptsValidLocalCertSignature(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'paypal-test'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($x509);
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $privatePem);

        $rawBody = json_encode(['id' => 'WH-LOCAL', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR);
        $webhookId = 'WHID-LOCAL';
        $transmissionId = 'tid-local';
        $transmissionTime = '2026-01-01T00:00:00Z';
        $crc = sprintf('%u', crc32($rawBody));
        $message = $transmissionId . '|' . $transmissionTime . '|' . $webhookId . '|' . $crc;
        $ok = openssl_sign($message, $signature, $privatePem, OPENSSL_ALGO_SHA256);
        self::assertTrue($ok);

        $provider = new PayPalProvider();
        $provider->setApiClient(new PayPalApiClient(static function (): array {
            self::fail('local verify must not HTTP');
        }));
        $req = CallbackRequest::fromArray([
            'raw_body' => $rawBody,
            'payload' => ['id' => 'WH-LOCAL', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'],
            'headers' => [
                'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
                'PAYPAL-CERT-URL' => 'https://unused.example/cert.pem',
                'PAYPAL-TRANSMISSION-ID' => $transmissionId,
                'PAYPAL-TRANSMISSION-SIG' => base64_encode($signature),
                'PAYPAL-TRANSMISSION-TIME' => $transmissionTime,
            ],
            'context' => [
                'config' => [
                    'webhook_id' => $webhookId,
                    'webhook_cert_pem' => $certPem,
                ],
            ],
        ]);
        self::assertTrue($provider->verifyCallback($req)->isVerified());
    }

    public function testApiClientVerifyPostsOfficialPath(): void
    {
        // ApiClient remote verify remains available for offline tooling; Provider verifyCallback must not call it.
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
