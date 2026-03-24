<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
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
}
