<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PayPalApiClient;

final class PayPalApiClientTrackingTest extends TestCase
{
    public function testAddOrderTrackingPostsOrdersV2Track(): void
    {
        $seenUrl = '';
        $seenBody = '';
        $client = new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$seenUrl, &$seenBody): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token']) ?: '{}'];
                }
                self::assertSame('POST', $method);
                $seenUrl = $url;
                $seenBody = (string) $body;

                return ['status' => 201, 'body' => json_encode(['id' => 'ORDER-1', 'status' => 'COMPLETED']) ?: '{}'];
            },
        );

        $client->addOrderTracking(
            [
                'environment' => 'sandbox',
                'client_id' => 'id',
                'client_secret' => 'secret',
            ],
            '5O190127TN364715T',
            [
                'capture_id' => '8MC585209K746392H',
                'tracking_number' => 'SF1234567890',
                'carrier' => 'SF_EXPRESS',
            ],
        );

        self::assertStringContainsString('/v2/checkout/orders/5O190127TN364715T/track', $seenUrl);
        self::assertStringContainsString('8MC585209K746392H', $seenBody);
        self::assertStringContainsString('SF1234567890', $seenBody);
    }

    public function testAddTrackingBatchPostsLegacyTrackersPayload(): void
    {
        $seenBody = '';
        $client = new PayPalApiClient(
            static function (string $method, string $url, array $headers, ?string $body) use (&$seenBody): array {
                if (str_contains($url, '/v1/oauth2/token')) {
                    return ['status' => 200, 'body' => json_encode(['access_token' => 'token']) ?: '{}'];
                }
                self::assertSame('POST', $method);
                self::assertStringContainsString('/v1/shipping/trackers-batch', $url);
                $seenBody = (string) $body;

                return ['status' => 200, 'body' => json_encode(['tracker_identifiers' => []]) ?: '{}'];
            },
        );

        $client->addTrackingBatch(
            [
                'environment' => 'sandbox',
                'client_id' => 'id',
                'client_secret' => 'secret',
            ],
            [[
                'transaction_id' => 'CAPTURE-1',
                'tracking_number' => 'SF1234567890',
                'status' => 'SHIPPED',
                'carrier' => 'OTHER',
                'carrier_name_other' => 'SF',
            ]],
        );

        self::assertStringContainsString('CAPTURE-1', $seenBody);
        self::assertStringContainsString('SF1234567890', $seenBody);
    }
}
