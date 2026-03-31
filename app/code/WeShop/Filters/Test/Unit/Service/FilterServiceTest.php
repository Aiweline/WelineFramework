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

    public function testGetFilterResultBuildsFacetOptionsAgainstOtherAppliedFiltersScope(): void
    {
        $collection = new FilterCollection();
        $collection->addFilter($this->createBrandFilter());
        $collection->addFilter($this->createColorFilter());

        $registry = $this->createMock(FilterRegistry::class);
        $registry->method('getForCategory')->willReturn($collection);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch');

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->method('getClearAllUrl')->willReturn('/catalog/category/view?id=9');
        $urlService->method('getRemoveFilterUrl')->willReturnCallback(
            static fn (string $filterCode, string $value): string => "/catalog/category/view?id=9&remove={$filterCode}:{$value}"
        );

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterResult(9, [1, 2, 3], [
            'brand' => ['nike'],
            'color' => ['red'],
        ], false);

        $this->assertSame([1], $result->getProductIds());

        $filters = $result->getFilters();
        $brandFilter = array_values(array_filter(
            $filters,
            static fn (array $filter): bool => ($filter['code'] ?? '') === 'brand'
        ));

        $this->assertCount(1, $brandFilter);
        $brandOptions = $brandFilter[0]['options'] ?? [];
        $brandCounts = [];
        foreach ($brandOptions as $option) {
            $brandCounts[$option['value']] = [
                'count' => $option['count'],
                'selected' => $option['selected'],
            ];
        }

        $this->assertSame(1, $brandCounts['nike']['count']);
        $this->assertTrue($brandCounts['nike']['selected']);
        $this->assertSame(1, $brandCounts['adidas']['count']);
        $this->assertFalse($brandCounts['adidas']['selected']);
    }

    public function testGetFilterOptionsCollectsFiltersWhenRegistryMissesDynamicFilterInitially(): void
    {
        $dynamicFilter = $this->createMock(FilterProviderInterface::class);
        $dynamicFilter->expects($this->once())
            ->method('isEnabled')
            ->with(9)
            ->willReturn(true);
        $dynamicFilter->expects($this->once())
            ->method('getOptions')
            ->with(9, [1001], ['attr_material' => ['cotton']])
            ->willReturn([
                ['value' => 'cotton', 'label' => 'Cotton', 'count' => 1, 'selected' => true],
            ]);

        $collection = new FilterCollection();

        $registry = $this->createMock(FilterRegistry::class);
        $registry->expects($this->exactly(2))
            ->method('get')
            ->with('attr_material')
            ->willReturnOnConsecutiveCalls(null, $dynamicFilter);
        $registry->expects($this->once())
            ->method('getForCategory')
            ->with(9)
            ->willReturn($collection);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->expects($this->once())
            ->method('dispatch')
            ->with('WeShop_Filters::filters_collect', $this->isType('array'));

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterOptions('attr_material', 9, [1001], ['attr_material' => ['cotton']]);

        $this->assertCount(1, $result);
        $this->assertSame('cotton', $result[0]['value']);
    }

    public function testGetFilterResultExcludesCurrentFacetFromProviderScopeButKeepsSelectionState(): void
    {
        $collection = new FilterCollection();
        $brandFilter = new class implements FilterProviderInterface {
            public array $seenAppliedFilters = [];

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
                $this->seenAppliedFilters = $appliedFilters;

                return [
                    ['value' => 'nike', 'label' => 'Nike', 'count' => count($productIds), 'selected' => false],
                    ['value' => 'adidas', 'label' => 'Adidas', 'count' => count($productIds), 'selected' => false],
                ];
            }

            public function apply(array $productIds, array $filterValues): array
            {
                return $productIds;
            }

            public function getCounts(int $categoryId, array $productIds, array $appliedFilters = []): array
            {
                return [];
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
        $collection->addFilter($brandFilter);
        $collection->addFilter($this->createColorFilter());

        $registry = $this->createMock(FilterRegistry::class);
        $registry->method('getForCategory')->willReturn($collection);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch');

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->method('getClearAllUrl')->willReturn('/catalog/category/view?id=9');
        $urlService->method('getRemoveFilterUrl')->willReturn('/catalog/category/view?id=9');

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterResult(9, [1, 2, 3], [
            'brand' => ['nike'],
            'color' => ['red'],
        ], false);

        $this->assertArrayNotHasKey('brand', $brandFilter->seenAppliedFilters);
        $this->assertSame(['red'], $brandFilter->seenAppliedFilters['color'] ?? []);

        $filters = $result->getFilters();
        $brandData = array_values(array_filter(
            $filters,
            static fn (array $filter): bool => ($filter['code'] ?? '') === 'brand'
        ));

        $this->assertCount(1, $brandData);
        $nikeOption = array_values(array_filter(
            $brandData[0]['options'] ?? [],
            static fn (array $option): bool => ($option['value'] ?? '') === 'nike'
        ));
        $this->assertCount(1, $nikeOption);
        $this->assertTrue((bool) ($nikeOption[0]['selected'] ?? false));
    }

    private function createBrandFilter(): FilterProviderInterface
    {
        return $this->createMappedFilter('brand', 'Brand', [
            1 => 'nike',
            2 => 'adidas',
            3 => 'nike',
        ]);
    }

    private function createColorFilter(): FilterProviderInterface
    {
        return $this->createMappedFilter('color', 'Color', [
            1 => 'red',
            2 => 'red',
            3 => 'blue',
        ]);
    }

    /**
     * TDD: 测试 getFilterOptions 获取 filter 选项
     */
    public function testGetFilterOptionsReturnsOptionsFromProvider(): void
    {
        $collection = new FilterCollection();
        $brandFilter = $this->createBrandFilter();
        $collection->addFilter($brandFilter);

        $registry = $this->createMock(FilterRegistry::class);
        $registry->expects($this->once())
            ->method('get')
            ->with('brand')
            ->willReturn($brandFilter);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch');

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $options = $service->getFilterOptions('brand', 9, [1, 2, 3], []);

        $this->assertCount(2, $options);
        $values = array_column($options, 'value');
        $this->assertContains('nike', $values);
        $this->assertContains('adidas', $values);
    }

    /**
     * TDD: 测试 getAppliedFilters 获取已选 filters chips 数据
     */
    public function testGetFilterResultBuildsAppliedFiltersChipsWithRemoveUrls(): void
    {
        $collection = new FilterCollection();
        $collection->addFilter($this->createBrandFilter());
        $collection->addFilter($this->createColorFilter());

        $registry = $this->createMock(FilterRegistry::class);
        $registry->method('getForCategory')->willReturn($collection);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch');

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->method('getClearAllUrl')->willReturn('/catalog/category/view?id=9');
        $urlService->expects($this->exactly(2))
            ->method('getRemoveFilterUrl')
            ->willReturnCallback(static function (string $filterCode, string $value): string {
                return "/catalog/category/view?id=9&remove={$filterCode}:{$value}";
            });

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterResult(9, [1, 2, 3], [
            'brand' => ['nike'],
            'color' => ['red'],
        ], false);

        $applied = $result->getAppliedFilters();
        $this->assertCount(2, $applied);

        $brandChip = array_values(array_filter(
            $applied,
            static fn(array $f): bool => ($f['filter_code'] ?? '') === 'brand'
        ));
        $this->assertCount(1, $brandChip);
        $this->assertSame('Nike', $brandChip[0]['label']);
        $this->assertSame('nike', $brandChip[0]['value']);
        $this->assertStringContainsString('remove=brand:nike', $brandChip[0]['remove_url']);
    }

    /**
     * TDD: 测试 clearAllFilters URL 生成
     */
    public function testGetFilterResultReturnsClearAllUrlForNoAppliedFilters(): void
    {
        $collection = new FilterCollection();
        $collection->addFilter($this->createBrandFilter());

        $registry = $this->createMock(FilterRegistry::class);
        $registry->method('getForCategory')->willReturn($collection);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch');

        $cacheService = $this->createMock(FilterCacheService::class);
        $urlService = $this->createMock(FilterUrlService::class);
        $urlService->expects($this->once())
            ->method('getClearAllUrl')
            ->with(9)
            ->willReturn('/catalog/category/view?id=9&from_filters=1');

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterResult(9, [1, 2, 3], [], false);

        $this->assertSame([], $result->getAppliedFilters());
        $this->assertSame('/catalog/category/view?id=9&from_filters=1', $result->getClearAllUrl());
    }

    /**
     * TDD: 测试多选 filter 值构建多个 chips
     */
    public function testGetFilterResultBuildsMultipleChipsForMultiSelectFilter(): void
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
        $urlService->method('getRemoveFilterUrl')->willReturnCallback(
            static fn(string $filterCode, string $value): string =>
                "/catalog/category/view?id=9&remove={$filterCode}:{$value}"
        );

        $service = new FilterService($registry, $eventsManager, $cacheService, $urlService);
        $result = $service->getFilterResult(9, [1, 2, 3], [
            'brand' => ['nike', 'adidas'],
        ], false);

        $applied = $result->getAppliedFilters();
        $this->assertCount(2, $applied);

        $labels = array_column($applied, 'label');
        $this->assertContains('Nike', $labels);
        $this->assertContains('Adidas', $labels);
    }

    /**
     * @param array<int, string> $valueByProductId
     */
    private function createMappedFilter(string $code, string $name, array $valueByProductId): FilterProviderInterface
    {
        return new class($code, $name, $valueByProductId) implements FilterProviderInterface {
            /**
             * @param array<int, string> $valueByProductId
             */
            public function __construct(
                private readonly string $code,
                private readonly string $name,
                private readonly array $valueByProductId
            ) {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
            {
                $selected = $appliedFilters[$this->code] ?? [];
                $selected = is_array($selected) ? $selected : [$selected];
                $counts = [];

                foreach ($productIds as $productId) {
                    $value = $this->valueByProductId[$productId] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }

                ksort($counts);
                $options = [];
                foreach ($counts as $value => $count) {
                    $options[] = [
                        'value' => $value,
                        'label' => ucfirst($value),
                        'count' => $count,
                        'selected' => in_array($value, $selected, true),
                    ];
                }

                return $options;
            }

            public function apply(array $productIds, array $filterValues): array
            {
                return array_values(array_filter(
                    $productIds,
                    fn (int $productId): bool => in_array($this->valueByProductId[$productId] ?? null, $filterValues, true)
                ));
            }

            public function getCounts(int $categoryId, array $productIds, array $appliedFilters = []): array
            {
                $counts = [];
                foreach ($productIds as $productId) {
                    $value = $this->valueByProductId[$productId] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }

                return $counts;
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
