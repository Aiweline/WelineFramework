<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Api\SearchFacetCapableFilterInterface;
use WeShop\Search\Api\SearchEngineInterface;
use WeShop\Search\Model\SearchHistory;
use WeShop\Search\Service\SearchService;

class SearchServiceTest extends TestCase
{
    public function testGetSearchSuggestionsNormalizesStringSuggestionsFromRemoteEngine(): void
    {
        $engine = $this->createMock(SearchEngineInterface::class);
        $engine->expects($this->once())
            ->method('getSuggestions')
            ->with('lamp', 3)
            ->willReturn(['Desk Lamp', 'DL-001']);

        $history = $this->createMock(SearchHistory::class);

        $service = new class($engine, $history) extends SearchService {
            public function __construct(
                private readonly ?SearchEngineInterface $engine,
                private readonly SearchHistory $history
            ) {
            }

            protected function createEngine(string $scope): ?SearchEngineInterface
            {
                return $this->engine;
            }

            protected function getSearchHistoryModel(): SearchHistory
            {
                return $this->history;
            }
        };

        $result = $service->getSearchSuggestions('lamp', 3);

        $this->assertSame('Desk Lamp', $result[0]['text']);
        $this->assertSame('fa-search', $result[0]['icon']);
        $this->assertSame('/search?q=Desk+Lamp', $result[0]['url']);
        $this->assertSame('DL-001', $result[1]['text']);
    }

    public function testGetFacetProvidersKeepsRegisteredProvidersWhenDynamicAttributeDiscoveryFails(): void
    {
        $provider = new class implements FilterProviderInterface, SearchFacetCapableFilterInterface {
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
                return [];
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
                return $value;
            }

            public function getSearchFacetDefinition(int $categoryId, array $context = []): ?array
            {
                return ['code' => 'brand', 'type' => 'term'];
            }

            public function normalizeSearchFacetBuckets(array $buckets, array $appliedFilters = [], array $context = []): array
            {
                return $buckets;
            }
        };

        $service = new class($provider) extends SearchService {
            public function __construct(private readonly object $provider)
            {
            }

            protected function getBaseFacetProviders(int $categoryId): array
            {
                return ['brand' => $this->provider];
            }

            protected function getDynamicFilterableAttributeMetadata(string $entityCode): array
            {
                throw new \RuntimeException('metadata unavailable');
            }
        };

        $reflection = new \ReflectionMethod(SearchService::class, 'getFacetProviders');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($service, 14);

        $this->assertCount(1, $result);
        $this->assertSame('brand', $result[0]->getCode());
    }
}
