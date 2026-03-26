<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Model\FilterCollection;
use WeShop\Filters\Model\FilterRegistry;
use WeShop\Filters\Service\FilterCacheService;
use WeShop\Filters\Service\FilterService;
use WeShop\Filters\Service\FilterUrlService;
use Weline\Framework\Event\EventsManager;

class FilterServiceTest extends TestCase
{
    public function testGetFilterResultBuildsFilterOptionsWithoutAppliedParams(): void
    {
        $collection = new FilterCollection();
        $collection->addFilter($this->createBrandFilter());

        $registry = $this->createMock(FilterRegistry::class);
        $registry->expects($this->once())
            ->method('getForCategory')
            ->with(9)
            ->willReturn($collection);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->expects($this->atLeast(4))
            ->method('dispatch');

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->expects($this->once())
            ->method('getClearAllUrl')
            ->with(9)
            ->willReturn('/catalog/category/view?id=9');

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterResult(9, [1, 2, 3], [], false);

        $this->assertSame([1, 2, 3], $result->getProductIds());
        $this->assertSame([], $result->getAppliedFilters());
        $this->assertSame('/catalog/category/view?id=9', $result->getClearAllUrl());
        $this->assertCount(1, $result->getFilters());
        $this->assertSame('brand', $result->getFilters()[0]['code']);
    }

    public function testGetFilterResultAppliesFilterValuesAndBuildsAppliedChips(): void
    {
        $collection = new FilterCollection();
        $collection->addFilter($this->createBrandFilter());

        $registry = $this->createMock(FilterRegistry::class);
        $registry->method('getForCategory')->willReturn($collection);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch');

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->method('getClearAllUrl')->willReturn('/catalog/category/view?id=9');
        $urlService->expects($this->once())
            ->method('getRemoveFilterUrl')
            ->with('brand', 'nike')
            ->willReturn('/catalog/category/view?id=9&brand=adidas');

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterResult(9, [1, 2, 3], ['brand' => ['nike']], false);

        $this->assertSame([1, 3], $result->getProductIds());
        $this->assertCount(1, $result->getAppliedFilters());
        $this->assertSame('Nike', $result->getAppliedFilters()[0]['label']);
        $this->assertSame('/catalog/category/view?id=9&brand=adidas', $result->getAppliedFilters()[0]['remove_url']);
    }

    private function createBrandFilter(): FilterProviderInterface
    {
        return new class implements FilterProviderInterface {
            public function getCode(): string
            {
                return 'brand';
            }

            public function getName(): string
            {
                return 'Brand';
            }

            public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
            {
                $selected = $appliedFilters['brand'] ?? [];
                $selected = is_array($selected) ? $selected : [$selected];

                return [
                    ['value' => 'nike', 'label' => 'Nike', 'count' => 2, 'selected' => in_array('nike', $selected, true)],
                    ['value' => 'adidas', 'label' => 'Adidas', 'count' => 1, 'selected' => in_array('adidas', $selected, true)],
                ];
            }

            public function apply(array $productIds, array $filterValues): array
            {
                if (in_array('nike', $filterValues, true)) {
                    return [1, 3];
                }

                if (in_array('adidas', $filterValues, true)) {
                    return [2];
                }

                return $productIds;
            }

            public function getCounts(int $categoryId, array $productIds, array $appliedFilters = []): array
            {
                return ['nike' => 2, 'adidas' => 1];
            }

            public function getSortOrder(): int
            {
                return 10;
            }

            public function isEnabled(int $categoryId): bool
            {
                return true;
            }

            public function getDisplayType(): string
            {
                return 'list';
            }

            public function isCollapsed(): bool
            {
                return false;
            }

            public function getIcon(): ?string
            {
                return null;
            }

            public function getValueLabel(string $value): string
            {
                return ucfirst($value);
            }
        };
    }
}
