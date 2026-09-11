<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Extends\Module\Weline_Dropship\DropshipProvider\FakeDropshipProvider;
use Weline\Dropship\Interface\DropshipWebhookProviderInterface;

final class FakeDropshipProviderTest extends TestCase
{
    public function testCodeEqualsSourceMarkerContract(): void
    {
        $p = new FakeDropshipProvider();
        self::assertSame('fake', $p->getCode());
        self::assertTrue($p->probeConnection()['ok']);
        self::assertNotEmpty($p->searchProducts([]));
        self::assertTrue($p->createFulfillment(['order_uuid' => 'u1'])['ok']);
        self::assertTrue(!empty($p->getCapabilities()['webhook']));
        self::assertInstanceOf(DropshipWebhookProviderInterface::class, $p);
    }

    public function testParseWebhookAlignsWithCjPayloadKeys(): void
    {
        $p = new FakeDropshipProvider();
        $parsed = $p->parseWebhook([], json_encode([
            'type' => 'SHIPPED',
            'orderId' => 'FAKE-ORD-1',
            'trackNumber' => 'TN-9',
            'logisticName' => 'FakePost',
            'orderStatus' => 'shipped',
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($parsed['ok']);
        self::assertSame('SHIPPED', $parsed['event']);
        self::assertSame('FAKE-ORD-1', $parsed['external_id']);
        self::assertSame('FAKE-ORD-1', $parsed['payload']['orderId']);
        self::assertSame('TN-9', $parsed['payload']['trackNumber']);
    }

    public function testParseWebhookNormalizesTrackingAlias(): void
    {
        $p = new FakeDropshipProvider();
        $parsed = $p->parseWebhook([], json_encode([
            'event' => 'order.updated',
            'external_order_id' => 'FAKE-ORD-2',
            'tracking' => ['number' => 'TN-2'],
            'carrier' => 'Demo',
            'status' => 'shipped',
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($parsed['ok']);
        self::assertSame('FAKE-ORD-2', $parsed['external_id']);
        self::assertSame('FAKE-ORD-2', $parsed['payload']['orderId']);
        self::assertSame('TN-2', $parsed['payload']['trackNumber']);
        self::assertSame('Demo', $parsed['payload']['logisticName']);
        self::assertSame('shipped', $parsed['payload']['orderStatus']);
    }
}
