<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutSessionFaultRecorder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;

final class CheckoutSessionFaultRecorderTest extends TestCase
{
    public function testSecondErrorOverwritesSameSessionWithoutInsert(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $recorder = new CheckoutSessionFaultRecorder($store);
        $fp = CheckoutSessionFaultRecorder::fingerprint([], 1, '', 'toc', 1, 1);
        $token = $recorder->ensureSession(null, $fp, ['browse' => true, 'currency' => 'USD']);
        $items = [['sku' => 'A', 'product_id' => 9, 'weight_minor' => 0, 'qty' => 1]];
        $address = ['country_code' => 'US', 'province' => 'NY', 'city' => 'New York', 'postal_code' => '10001'];
        $recorder->syncLoad($token, $address, $items, ['missing_weight' => true], [], false, '缺重');
        $again = $recorder->ensureSession($token, $fp, ['browse' => true, 'currency' => 'USD']);
        $recorder->syncLoad($again, $address, $items, ['missing_weight' => true, 'lane_count' => 1], [], false, '缺重二次');
        self::assertSame($token, $again);
        self::assertSame(1, $store->count());
        $error = $store->getErrorSnapshot($token);
        self::assertIsArray($error);
        self::assertSame('missing_weight', $error['code']);
        self::assertSame('缺重二次', $error['message']);
        self::assertSame(1, $error['snapshot']['quote_diagnostics']['lane_count'] ?? 0);
    }

    public function testRecoveryClearsErrorSnapshot(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $recorder = new CheckoutSessionFaultRecorder($store);
        $fp = 'fp-recover';
        $token = $recorder->ensureSession(null, $fp, ['browse' => true]);
        $items = [['sku' => 'A', 'product_id' => 1, 'weight_minor' => 0, 'qty' => 1]];
        $address = ['country_code' => 'US', 'province' => 'NY', 'city' => 'NYC', 'postal_code' => '10001'];
        $recorder->syncLoad($token, $address, $items, ['missing_weight' => true], [], false, '缺重');
        self::assertNotNull($store->getErrorSnapshot($token));
        $recorder->syncLoad(
            $token,
            $address,
            [['sku' => 'A', 'product_id' => 1, 'weight_minor' => 500, 'qty' => 1]],
            ['missing_weight' => false],
            [['code' => 'SEED_LANE_AMERICAS']],
            false,
            '',
        );
        self::assertNull($store->getErrorSnapshot($token));
        self::assertSame(1, $store->count());
    }

    public function testEmptyCartDoesNotWriteError(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $recorder = new CheckoutSessionFaultRecorder($store);
        $token = $recorder->ensureSession(null, 'fp-empty', ['browse' => true]);
        $recorder->syncLoad(
            $token,
            ['country_code' => 'US', 'province' => 'NY', 'city' => 'NYC', 'postal_code' => '10001'],
            [],
            ['missing_weight' => true],
            [],
            false,
            '缺重',
        );
        self::assertNull($store->getErrorSnapshot($token));
    }

    public function testFingerprintLookupReusesQuotedRow(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $recorder = new CheckoutSessionFaultRecorder($store);
        $fp = 'same-cart';
        $first = $recorder->ensureSession(null, $fp, ['browse' => true]);
        $second = $recorder->ensureSession('', $fp, ['browse' => true]);
        self::assertSame($first, $second);
        self::assertSame(1, $store->count());
    }
}
