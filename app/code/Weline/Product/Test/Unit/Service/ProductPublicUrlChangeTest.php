<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\ProductSearchProjectionStream;
use Weline\Product\Service\ProductSearchProjectionMutationCoordinator;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

final class ProductPublicUrlChangeTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
        parent::tearDown();
    }

    public function testWholeSaveKeepsOldSlugAndEmitsOneExistingProjectionEvent(): void
    {
        WelineEnv::setArea('cli');
        $captured = [];
        $events = $this->createMock(EventsManager::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, mixed &$data) use (&$captured, $events) {
            $captured[] = $data->toArray();
            return $events;
        });
        ObjectManager::setInstance(EventsManager::class, $events);
        $transactions = $this->createMock(TransactionCoordinatorInterface::class);
        $transactions->method('isActive')->willReturn(true);
        $stream = $this->createMock(ProductSearchProjectionStream::class);
        $stream->method('next')->willReturn(1);
        $websites = $this->createMock(WebsiteCatalogInterface::class);
        $websites->method('all')->willReturn([new WebsiteSummary(0, 'Default', 'default', 'https://example.test')]);
        $stores = $this->createMock(StoreCatalogInterface::class);
        $slug = 'old-slug';
        $snapshots = static function (int $websiteId, int $productId, ?int $storeId) use (&$slug): array {
            return [['loc' => 'https://example.test/product/' . $slug, 'store_id' => 0]];
        };
        $coordinator = new ProductSearchProjectionMutationCoordinator(
            $transactions, $stream, new ResourceChangeFactory(new ContextSnapshot()), $websites, $stores, $snapshots,
        );
        $connection = $this->createMock(ConnectionFactory::class);
        $coordinator->execute($connection, 0, 'product', 83, null, function () use (&$slug, $coordinator, $connection): void {
            $slug = 'new-slug';
            $coordinator->execute($connection, 0, 'product', 83, null, static fn(): bool => true);
        });
        self::assertCount(1, $captured, 'The admin save and its nested repository write describe one Product change.');
        self::assertSame('product_search_projection', $captured[0]['resource']['type']);
        self::assertSame(['https://example.test/product/old-slug'], $captured[0]['impact']['previous_urls']);
        self::assertSame(['https://example.test/product/new-slug'], $captured[0]['impact']['urls']);
        self::assertSame([0], $captured[0]['after']['store_ids']);
    }
}
