<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Model\FilterRegistry;
use WeShop\Filters\Service\FilterCacheService;
use WeShop\Filters\Service\FilterCountService;
use WeShop\Filters\Service\FilterService;
use Weline\Framework\Event\EventsManager;

class FilterCountServiceTest extends TestCase
{
    public function testGetCountsCollectsFiltersBeforeReadingRegistry(): void
    {
        $filter = $this->createMock(FilterProviderInterface::class);
        $filter->expects($this->once())
            ->method('isEnabled')
            ->with(9)
            ->willReturn(true);
        $filter->expects($this->once())
            ->method('getCounts')
            ->with(9, [1001, 1002], ['brand' => ['nike']])
            ->willReturn(['cotton' => 1]);

        $registry = $this->createMock(FilterRegistry::class);
        $registry->expects($this->once())
            ->method('get')
            ->with('attr_material')
            ->willReturn($filter);

        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->expects($this->once())
            ->method('dispatch')
            ->with('WeShop_Filters::filter_counts_collect', $this->isType('array'));

        $cacheService = $this->createMock(FilterCacheService::class);
        $filterService = $this->createMock(FilterService::class);
        $filterService->expects($this->once())
            ->method('collectFilters')
            ->with(9, [1001, 1002]);

        $service = new FilterCountService($registry, $eventsManager, $cacheService, $filterService);
        $counts = $service->getCounts('attr_material', 9, [1001, 1002], [
            'brand' => ['nike'],
            'attr_material' => ['cotton'],
        ]);

        $this->assertSame(['cotton' => 1], $counts);
    }
}
