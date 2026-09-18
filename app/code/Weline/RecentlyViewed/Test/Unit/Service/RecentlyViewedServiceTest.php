<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Product\Service\ProductCardRenderer;
use Weline\RecentlyViewed\Service\RecentlyViewedSessionStore;
use Weline\RecentlyViewed\Service\RecentlyViewedService;

final class RecentlyViewedServiceTest extends TestCase
{
    public function testRecordKeepsMruOrderAndDedupes(): void
    {
        $store = new class extends RecentlyViewedSessionStore {
            /** @var list<int> */
            private array $ids = [];

            public function listIds(): array
            {
                return $this->ids;
            }

            public function saveIds(array $ids): void
            {
                $this->ids = array_values($ids);
            }

            public function record(int $productId): void
            {
                $productId = max(0, $productId);
                if ($productId <= 0) {
                    return;
                }
                $ids = array_values(array_filter(
                    $this->listIds(),
                    static fn(int $id): bool => $id !== $productId,
                ));
                array_unshift($ids, $productId);
                $this->saveIds($ids);
            }
        };

        $service = new RecentlyViewedService($store);
        $service->record(10);
        $service->record(20);
        $service->record(10);

        self::assertSame([10, 20], $service->listIds(6));
        self::assertSame([20], $service->listIds(6, 10));
    }

    public function testNormalizeOfferSlugPrefersSlugOverNumericProductId(): void
    {
        $service = new RecentlyViewedService(new RecentlyViewedSessionStore());
        $normalize = new ReflectionMethod(RecentlyViewedService::class, 'normalizeOfferSlug');
        $normalize->setAccessible(true);

        $withSlug = $normalize->invoke($service, [
            'product_id' => 113,
            'name' => 'Demo',
            'slug' => 'hanfu-demo-slug',
            'unit_price_minor' => 12800,
            'currency' => 'USD',
        ]);
        $cardWithSlug = ProductCardRenderer::fromStorefrontOffer($withSlug, 0);
        self::assertSame('hanfu-demo-slug', $cardWithSlug['slug'] ?? null);
        self::assertStringContainsString('product/hanfu-demo-slug', (string)($cardWithSlug['url'] ?? ''));
        self::assertStringNotContainsString('product/113', (string)($cardWithSlug['url'] ?? ''));
        self::assertSame('USD', $cardWithSlug['currency'] ?? null);

        $fromSourceSlug = $normalize->invoke($service, [
            'product_id' => 117,
            'name' => 'Source',
            'source_slug' => 'from-source-slug',
            'unit_price_minor' => 9900,
            'currency' => 'EUR',
        ]);
        $cardFromSource = ProductCardRenderer::fromStorefrontOffer($fromSourceSlug, 0);
        self::assertSame('from-source-slug', $cardFromSource['slug'] ?? null);
        self::assertStringContainsString('product/from-source-slug', (string)($cardFromSource['url'] ?? ''));
        self::assertSame('EUR', $cardFromSource['currency'] ?? null);

        $fallback = $normalize->invoke($service, [
            'product_id' => 99,
            'name' => 'No slug',
            'unit_price_minor' => 100,
            'currency' => 'USD',
        ]);
        $cardFallback = ProductCardRenderer::fromStorefrontOffer($fallback, 0);
        self::assertSame('', $cardFallback['slug'] ?? null);
        self::assertStringContainsString('product/99', (string)($cardFallback['url'] ?? ''));
    }

    public function testCardMappingKeepsOfferCurrencyNotDefaultCny(): void
    {
        $service = new RecentlyViewedService(new RecentlyViewedSessionStore());
        $normalize = new ReflectionMethod(RecentlyViewedService::class, 'normalizeOfferSlug');
        $normalize->setAccessible(true);

        $offer = $normalize->invoke($service, [
            'product_id' => 42,
            'name' => 'USD priced hanfu',
            'slug' => 'usd-priced-hanfu',
            'unit_price_minor' => 2750,
            'currency' => 'USD',
            'sellable' => true,
            'global_offer_uuid' => 'offer-usd-42',
        ]);
        $card = ProductCardRenderer::fromStorefrontOffer($offer, 0);

        self::assertSame('USD', $card['currency'] ?? null);
        self::assertSame(27.5, (float)($card['price'] ?? 0));
        self::assertNotSame('CNY', $card['currency'] ?? 'CNY');
    }

    public function testServiceSourceUsesFromStorefrontOffer(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/RecentlyViewedService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('ProductCardRenderer::fromStorefrontOffer', $source);
        self::assertStringContainsString('normalizeOfferSlug', $source);
        self::assertStringNotContainsString('private function mapOffer', $source);
    }
}
