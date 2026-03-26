<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Api\SearchDocumentExtenderInterface;
use WeShop\Search\Api\SearchDocumentProviderInterface;
use WeShop\Search\Api\SearchWritableEngineInterface;
use WeShop\Search\Service\SearchDocumentExtenderRegistry;
use WeShop\Search\Service\SearchDocumentProviderRegistry;
use WeShop\Search\Service\SearchIndexer;

class SearchIndexerTest extends TestCase
{
    public function testRebuildConfiguresIndexAndUpsertsNormalizedDocuments(): void
    {
        $provider = $this->createMock(SearchDocumentProviderInterface::class);
        $provider->method('getDocumentType')->willReturn('product');
        $provider->method('getProviderCode')->willReturn('product');
        $provider->method('getDocumentId')->willReturn('product_15');
        $provider->expects($this->once())
            ->method('getBatchDocuments')
            ->willReturn([['entity_id' => 15, 'name' => 'Travel Bag']]);

        $registry = $this->createMock(SearchDocumentProviderRegistry::class);
        $registry->expects($this->once())
            ->method('getAllProviders')
            ->willReturn(['product' => $provider]);
        $registry->expects($this->once())
            ->method('getMergedIndexConfiguration')
            ->willReturn(['searchable_fields' => ['name']]);

        $extender = $this->createMock(SearchDocumentExtenderInterface::class);
        $extender->expects($this->once())
            ->method('extendDocument')
            ->with(
                $this->callback(fn (array $document): bool => $document['document_id'] === 'product_15'),
                $this->callback(fn (array $context): bool => $context['provider_code'] === 'product' && $context['entity_id'] === 15)
            )
            ->willReturnCallback(static function (array $document): array {
                $document['eav_search_text'] = 'travel bag';

                return $document;
            });

        $extenderRegistry = $this->createMock(SearchDocumentExtenderRegistry::class);
        $extenderRegistry->expects($this->once())
            ->method('getMergedIndexConfiguration')
            ->willReturn(['searchable_fields' => ['eav_search_text']]);
        $extenderRegistry->expects($this->once())
            ->method('getExtendersForProvider')
            ->with('product')
            ->willReturn([$extender]);

        $engine = $this->createMock(SearchWritableEngineInterface::class);
        $engine->expects($this->once())
            ->method('configureIndex')
            ->with([
                'searchable_fields' => ['name', 'eav_search_text'],
                'filterable_fields' => [],
                'sortable_fields' => [],
            ])
            ->willReturn(true);
        $engine->expects($this->once())
            ->method('upsertDocuments')
            ->with($this->callback(function (array $documents): bool {
                return $documents[0]['document_id'] === 'product_15'
                    && $documents[0]['document_type'] === 'product'
                    && $documents[0]['entity_id'] === 15
                    && $documents[0]['eav_search_text'] === 'travel bag';
            }))
            ->willReturn(true);

        $indexer = new class($registry, $extenderRegistry, $engine) extends SearchIndexer {
            public function __construct(
                SearchDocumentProviderRegistry $providerRegistry,
                SearchDocumentExtenderRegistry $extenderRegistry,
                private readonly SearchWritableEngineInterface $engine
            ) {
                parent::__construct($providerRegistry, $extenderRegistry);
            }

            protected function createEngine(string $scope = 'default'): ?\WeShop\Search\Api\SearchEngineInterface
            {
                return $this->engine;
            }
        };

        $this->assertTrue($indexer->rebuild(null, false));
    }

    public function testIndexEntityAndDeleteEntityUseResolvedProvider(): void
    {
        $provider = $this->createMock(SearchDocumentProviderInterface::class);
        $provider->method('getDocumentType')->willReturn('product');
        $provider->method('getProviderCode')->willReturn('product');
        $provider->method('getDocumentByEntityId')->with(15)->willReturn(['entity_id' => 15, 'name' => 'Travel Bag']);
        $provider->method('getDocumentId')->with(15)->willReturn('product_15');

        $registry = $this->createMock(SearchDocumentProviderRegistry::class);
        $registry->method('getProvider')->with('product')->willReturn($provider);
        $registry->method('getMergedIndexConfiguration')->willReturn([]);

        $extenderRegistry = $this->createMock(SearchDocumentExtenderRegistry::class);
        $extenderRegistry->method('getMergedIndexConfiguration')->willReturn([]);
        $extenderRegistry->method('getExtendersForProvider')->with('product')->willReturn([]);

        $engine = $this->createMock(SearchWritableEngineInterface::class);
        $engine->expects($this->once())
            ->method('configureIndex')
            ->with([
                'searchable_fields' => [],
                'filterable_fields' => [],
                'sortable_fields' => [],
            ])
            ->willReturn(true);
        $engine->expects($this->once())
            ->method('upsertDocuments')
            ->with($this->callback(fn (array $documents): bool => $documents[0]['document_id'] === 'product_15'))
            ->willReturn(true);
        $engine->expects($this->once())
            ->method('deleteDocuments')
            ->with(['product_15'])
            ->willReturn(true);

        $indexer = new class($registry, $extenderRegistry, $engine) extends SearchIndexer {
            public function __construct(
                SearchDocumentProviderRegistry $providerRegistry,
                SearchDocumentExtenderRegistry $extenderRegistry,
                private readonly SearchWritableEngineInterface $engine
            ) {
                parent::__construct($providerRegistry, $extenderRegistry);
            }

            protected function createEngine(string $scope = 'default'): ?\WeShop\Search\Api\SearchEngineInterface
            {
                return $this->engine;
            }
        };

        $this->assertTrue($indexer->indexEntity('product', 15));
        $this->assertTrue($indexer->deleteEntity('product', 15));
    }
}
