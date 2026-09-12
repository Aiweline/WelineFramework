<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Extends\Module\Weline_Dropship\DropshipProvider\FakeDropshipProvider;
use Weline\Dropship\Interface\DropshipWarehouseProviderInterface;
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
        self::assertTrue(!empty($p->getCapabilities()['warehouse']));
        self::assertTrue(!empty($p->getCapabilities()['browse']));
        self::assertTrue(!empty($p->getCapabilities()['browse_country_filter']));
        $items = $p->searchProducts(['category_id' => 'fake-apparel', 'locale' => 'zh_Hans_CN']);
        self::assertCount(1, $items);
        self::assertSame('fake-apparel', $items[0]->categoryId);
        self::assertSame('演示服装商品', $items[0]->title);
        self::assertArrayNotHasKey('fake_category', $items[0]->suggestedEav);
        $en = $p->searchProducts(['locale' => 'en_US']);
        self::assertSame('Fake Product', $en[0]->title);
        $catsZh = $p->listCategories(['locale' => 'zh_Hans_CN']);
        self::assertSame('服饰', $catsZh[1]['name'] ?? '');
        self::assertInstanceOf(DropshipWebhookProviderInterface::class, $p);
        self::assertInstanceOf(DropshipWarehouseProviderInterface::class, $p);
    }

    public function testParseWebhookReturnsStandardFulfillmentDto(): void
    {
        $p = new FakeDropshipProvider();
        $parsed = $p->parseWebhook([], json_encode([
            'type' => 'SHIPPED',
            'id' => 'FAKE-EVT-1',
            'external_order_id' => 'FAKE-ORD-1',
            'order_uuid' => 'u-1',
            'tracking' => ['number' => 'TN-9', 'carrier' => 'FakePost'],
            'status' => 'shipped',
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($parsed['ok']);
        self::assertSame('SHIPPED', $parsed['event']);
        self::assertSame('FAKE-EVT-1', $parsed['external_id']);
        self::assertSame('FAKE-ORD-1', $parsed['fulfillment']['external_order_id']);
        self::assertSame('TN-9', $parsed['fulfillment']['tracking_number']);
        self::assertSame('FakePost', $parsed['fulfillment']['carrier']);
        self::assertSame('shipped', $parsed['fulfillment']['status']);
    }

    public function testParseWebhookNormalizesTrackingAlias(): void
    {
        $p = new FakeDropshipProvider();
        $parsed = $p->parseWebhook([], json_encode([
            'event' => 'order.updated',
            'external_order_id' => 'FAKE-ORD-2',
            'tracking_number' => 'TN-2',
            'carrier' => 'Demo',
            'status' => 'shipped',
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($parsed['ok']);
        self::assertSame('FAKE-ORD-2', $parsed['external_id']);
        self::assertSame('FAKE-ORD-2', $parsed['fulfillment']['external_order_id']);
        self::assertSame('TN-2', $parsed['fulfillment']['tracking_number']);
        self::assertSame('Demo', $parsed['fulfillment']['carrier']);
    }
}
