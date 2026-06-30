<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Model\Feed;
use Weline\Geo\Service\FeedGeneratorService;

class FeedGeneratorServiceMetadataTest extends TestCase
{
    public function testJsonFeedPreservesGeoMetadataExtension(): void
    {
        $feed = $this->getMockBuilder(Feed::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getConfigArray'])
            ->getMock();
        $feed->method('getData')->willReturnCallback(static function (string $key = ''): string {
            return match ($key) {
                Feed::schema_fields_FEED_NAME => 'Products',
                Feed::schema_fields_FEED_URL => '/geo-feed.json',
                default => '',
            };
        });
        $feed->method('getConfigArray')->willReturn(['description' => 'Product feed']);

        $json = (new FeedGeneratorServiceMetadataProxy())->renderJson($feed, [
            [
                'url' => 'https://shop.test/product/summer-dress',
                'title' => 'Summer Dress',
                'content' => 'Lightweight dress.',
                'metadata' => json_encode([
                    'type' => 'product',
                    'sku' => 'DRESS-001',
                    'image' => 'https://shop.test/media/dress.jpg',
                ]),
                'published_at' => 1710000000,
                'updated_at' => 1710000000,
            ],
        ]);

        $payload = json_decode($json, true);

        self::assertSame('https://shop.test/media/dress.jpg', $payload['items'][0]['image']);
        self::assertSame('product', $payload['items'][0]['_weline_geo']['type']);
        self::assertSame('DRESS-001', $payload['items'][0]['_weline_geo']['sku']);
    }
}

final class FeedGeneratorServiceMetadataProxy extends FeedGeneratorService
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function renderJson(Feed $feed, array $items): string
    {
        return $this->generateJsonFeed($feed, $items);
    }
}
