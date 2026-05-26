<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Model\Product;
use WeShop\Product\Observer\ProductGeoFeedObserver;
use Weline\Framework\Event\Event;

class ProductGeoFeedObserverTest extends TestCase
{
    public function testSaveDispatchesProductUpdateToEnabledGeoFeeds(): void
    {
        $feed = new ProductGeoFeedModelStub([
            ['id' => 7],
        ]);
        $dispatcher = new ProductGeoFeedDispatcherStub();
        $observer = new ProductGeoFeedObserver($feed, $dispatcher);
        $event = new Event('WeShop_Product::product_save_after', [
            'product' => [
                Product::schema_fields_ID => 42,
                Product::schema_fields_name => 'Summer Dress',
                Product::schema_fields_short_description => 'Lightweight dress.',
                Product::schema_fields_description => '<p>Made for summer.</p>',
                Product::schema_fields_sku => 'DRESS-001',
                Product::schema_fields_spu => 'SPU-DRESS',
                Product::schema_fields_price => 28.5,
                Product::schema_fields_stock => 3,
                Product::schema_fields_status => 1,
                Product::schema_fields_HANDLE => 'summer-dress',
                Product::schema_fields_image => '/media/dress.jpg',
            ],
        ]);

        $observer->execute($event);

        self::assertSame([7], $dispatcher->updatedFeedIds);
        self::assertSame('product', $dispatcher->updatedItemType);
        self::assertSame(42, $dispatcher->updatedItemId);
        self::assertSame('Summer Dress', $dispatcher->updatedData['title']);
        self::assertStringEndsWith('/product/summer-dress', $dispatcher->updatedData['url']);
        self::assertSame('DRESS-001', $dispatcher->updatedData['metadata']['sku']);
        self::assertSame('https://schema.org/InStock', $dispatcher->updatedData['metadata']['availability']);
        self::assertSame(1, $dispatcher->updatedData['is_published']);
    }

    public function testDeleteDispatchesProductDeleteToEnabledGeoFeeds(): void
    {
        $feed = new ProductGeoFeedModelStub([
            ['id' => 8],
            ['id' => 9],
        ]);
        $dispatcher = new ProductGeoFeedDispatcherStub();
        $observer = new ProductGeoFeedObserver($feed, $dispatcher);
        $event = new Event('WeShop_Product::product_delete_after', [
            'product_id' => 42,
        ]);

        $observer->execute($event);

        self::assertSame([8, 9], $dispatcher->deletedFeedIds);
        self::assertSame('product', $dispatcher->deletedItemType);
        self::assertSame(42, $dispatcher->deletedItemId);
    }
}

final class ProductGeoFeedModelStub
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function reset(): self
    {
        return $this;
    }

    public function where(mixed ...$args): self
    {
        return $this;
    }

    public function select(): self
    {
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchArray(): array
    {
        return $this->rows;
    }
}

final class ProductGeoFeedDispatcherStub
{
    /** @var int[] */
    public array $updatedFeedIds = [];
    public string $updatedItemType = '';
    public int $updatedItemId = 0;
    /** @var array<string, mixed> */
    public array $updatedData = [];
    /** @var int[] */
    public array $deletedFeedIds = [];
    public string $deletedItemType = '';
    public int $deletedItemId = 0;

    /**
     * @param int[] $feedIds
     * @param array<string, mixed> $itemData
     */
    public function dispatchFeedItemUpdateToFeeds(array $feedIds, string $itemType, int $itemId, array $itemData = []): void
    {
        $this->updatedFeedIds = $feedIds;
        $this->updatedItemType = $itemType;
        $this->updatedItemId = $itemId;
        $this->updatedData = $itemData;
    }

    /**
     * @param int[] $feedIds
     */
    public function dispatchFeedItemDeleteFromFeeds(array $feedIds, string $itemType, int $itemId): void
    {
        $this->deletedFeedIds = $feedIds;
        $this->deletedItemType = $itemType;
        $this->deletedItemId = $itemId;
    }
}
