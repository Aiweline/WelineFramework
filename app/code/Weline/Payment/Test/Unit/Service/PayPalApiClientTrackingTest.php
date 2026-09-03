<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PayPalApiClient;

final class PayPalApiClientTrackingTest extends TestCase
{
    public function testAddTrackingBatchPostsTrackersPayload(): void
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
