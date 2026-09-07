<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\ProductAdminMediaPresenter;
use Weline\Product\Service\ProductAdminReadService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class ProductAdminReadServiceBatchSearchTest extends TestCase
{
    public function testSearchBatchesRelatedRowsAndPreservesProductAndOfferOrdering(): void
    {
        [$service, $queries] = $this->fixture();

        $rows = $service->search(1);

        self::assertSame([2, 1, 3], array_column($rows, 'product_id'));
        self::assertSame(['Beta', 'Alpha', ''], array_column($rows, 'name'));
        self::assertSame([
            ['B-FIRST', 'B-SECOND', 'B-THIRD'],
            ['FALLBACK-1'],
            ['FALLBACK-3'],
        ], array_column($rows, 'skus'));
        self::assertSame([3, 2, 0], array_column($rows, 'offer_count'));
        self::assertSame([[], [], []], array_column($rows, 'prices'));
        self::assertSame([null, null, null], array_column($rows, 'main_media'));
        self::assertSame([[], [], []], array_column($rows, 'selected_store_ids'));
        self::assertSame(['CODE-2', 'CODE-1', 'CODE-3'], array_column($rows, 'product_code'));

        // The actual repositories sort/decode the model rows before the actual service groups them.
        self::assertCount(1, $queries->reads['offer'] ?? [], 'Offers must be read in one batch for these products.');
        self::assertCount(1, $queries->reads['attribute_value'] ?? [], 'Attributes must be read in one batch.');
        self::assertEqualsCanonicalizing([1, 2, 3], $queries->reads['offer'][0]['product_id'][0]);
        self::assertEqualsCanonicalizing([1, 2, 3], $queries->reads['attribute_value'][0]['entity_id'][0]);
        self::assertSame(['product', '='], $queries->reads['attribute_value'][0]['entity_type']);
        self::assertSame([[0], 'IN'], $queries->reads['attribute_value'][0]['store_id']);
    }

    public function testEmptyProductsSkipRelatedReadsAndMissingStoreOverlayKeepsInheritance(): void
    {
        foreach ([[], [['product_id' => 0]]] as $products) {
            [$service, $queries] = $this->fixture($products);
            self::assertSame([], $service->search(1));
            self::assertSame([], $queries->reads['offer'] ?? []);
            self::assertSame([], $queries->reads['attribute_value'] ?? []);
        }

        [$service] = $this->fixture();
        self::assertSame([2, 1, 3], array_column($service->search(1, ['store_id' => 7]), 'product_id'));
    }

    public function testNameAndSkuFiltersKeepExistingFallbackSemantics(): void
    {
        [$service] = $this->fixture();

        self::assertSame([1], array_column($service->search(1, ['name' => ' ALPHA ', 'sku' => ' fallback-1 ']), 'product_id'));
        self::assertSame([2], array_column($service->search(1, ['sku' => 'b-second']), 'product_id'));
        self::assertSame([], $service->search(1, ['sku' => 'ignored-root-2']));
        self::assertSame([], $service->search(1, ['name' => 'not an attribute']));
        self::assertSame([3], array_column($service->search(1, ['sku' => 'fallback-3']), 'product_id'));
    }

    public function testMatchedProductsBatchPricesAndMediaWithoutChangingPayloads(): void
    {
        $media = static fn(int $id, int $product, int $store, int $position): array => [
            'media_id' => $id, 'product_id' => $product, 'store_id' => $store, 'position' => $position,
            'path' => 'https://images.example.test/product-' . $product . '-' . $id . '.jpg',
        ];
        $firstProductMedia = $media(110, 1, 0, 1);
        $secondProductMedia = $media(220, 2, 0, 1);
        [$service, $queries] = $this->fixture(relatedRows: [
            'price' => [
                ['offer_id' => 22, 'store_id' => 0, 'currency' => 'USD', 'amount_minor' => 3000],
                ['offer_id' => 11, 'store_id' => 0, 'currency' => 'EUR', 'amount_minor' => 9999, 'scope_state' => 'cleared'],
                ['offer_id' => 21, 'store_id' => 0, 'currency' => 'USD', 'amount_minor' => 2300, 'version' => 4],
                ['offer_id' => 12, 'store_id' => 0, 'currency' => 'USD', 'amount_minor' => 8888, 'cleared' => 1],
                ['offer_id' => 21, 'store_id' => 0, 'currency' => 'EUR', 'amount_minor' => 1500],
                ['offer_id' => 21, 'store_id' => 7, 'currency' => 'USD', 'amount_minor' => 1],
                ['offer_id' => 91, 'store_id' => 0, 'currency' => 'USD', 'amount_minor' => 2],
            ],
            'media' => [
                $media(222, 2, 0, 2), $media(219, 2, 7, 0), $media(221, 2, 0, 1),
                $media(111, 1, 0, 2), $secondProductMedia, $firstProductMedia, $media(900, 9, 0, 0),
            ],
        ]);

        $rows = $service->search(1);
        self::assertSame([2, 1, 3], array_column($rows, 'product_id'));
        self::assertSame([
            [
                ['store_id' => 0, 'offer_id' => 21, 'currency' => 'EUR', 'amount_minor' => 1500, 'scope_state' => 'explicit', 'cleared' => false, 'version' => 1],
                ['store_id' => 0, 'offer_id' => 21, 'currency' => 'USD', 'amount_minor' => 2300, 'scope_state' => 'explicit', 'cleared' => false, 'version' => 4],
                ['store_id' => 0, 'offer_id' => 22, 'currency' => 'USD', 'amount_minor' => 3000, 'scope_state' => 'explicit', 'cleared' => false, 'version' => 1],
            ],
            [
                ['store_id' => 0, 'offer_id' => 11, 'currency' => 'EUR', 'amount_minor' => null, 'scope_state' => 'cleared', 'cleared' => true, 'version' => 1],
                ['store_id' => 0, 'offer_id' => 12, 'currency' => 'USD', 'amount_minor' => null, 'scope_state' => 'cleared', 'cleared' => true, 'version' => 1],
            ],
            [],
        ], array_column($rows, 'prices'));
        self::assertSame([
            $secondProductMedia + ['display_url' => $secondProductMedia['path']],
            $firstProductMedia + ['display_url' => $firstProductMedia['path']],
            null,
        ], array_column($rows, 'main_media'));

        self::assertCount(1, $queries->reads['price'] ?? [], 'Matched offers must use one price read in this batch.');
        self::assertCount(1, $queries->reads['media'] ?? [], 'Matched products must use one media read in this batch.');
        self::assertSame(1, $queries->storeListCalls, 'Store listings must be shared within this search.');
        self::assertEqualsCanonicalizing([11, 12, 21, 22, 23], $queries->reads['price'][0]['offer_id'][0]);
        self::assertSame([[0], 'IN'], $queries->reads['price'][0]['store_id']);
        self::assertEqualsCanonicalizing([1, 2, 3], $queries->reads['media'][0]['product_id'][0]);
    }

    public function testNoMatchSkipsSecondaryReadsAndMatchesAcrossBatchesListStoresOnce(): void
    {
        $products = $offers = $prices = $media = [];
        foreach (range(1, 205) as $id) {
            $products[] = ['product_id' => $id, 'updated_at' => '2026-09-06 12:00:00'];
            $offers[] = ['product_id' => $id, 'offer_id' => $id * 10, 'sku' => 'MATCH-' . $id];
            $prices[] = ['offer_id' => $id * 10, 'store_id' => 0, 'currency' => 'USD', 'amount_minor' => $id * 100];
            $media[] = ['media_id' => $id, 'product_id' => $id, 'store_id' => 0, 'position' => 0, 'path' => 'https://images.example.test/' . $id . '.jpg'];
        }
        [$service, $queries] = $this->fixture($products, [
            'offer' => $offers, 'attribute_value' => [], 'price' => $prices, 'media' => $media,
        ]);

        self::assertSame([], $service->search(1, ['sku' => 'not-matching']));
        self::assertSame([], $queries->reads['price'] ?? []);
        self::assertSame([], $queries->reads['media'] ?? []);
        self::assertSame(0, $queries->storeListCalls);

        $rows = $service->search(1, ['sku' => 'MATCH-']);
        self::assertSame(range(205, 1), array_column($rows, 'product_id'));
        self::assertSame(range(205, 1), array_column(array_column($rows, 'main_media'), 'media_id'));
        self::assertSame(array_map(static fn(int $id): array => [[
            'store_id' => 0, 'offer_id' => $id * 10, 'currency' => 'USD', 'amount_minor' => $id * 100,
            'scope_state' => 'explicit', 'cleared' => false, 'version' => 1,
        ]], range(205, 1)), array_column($rows, 'prices'));

        self::assertCount(2, $queries->reads['price'] ?? [], 'Crossing a 200-product batch must use two price reads.');
        self::assertCount(2, $queries->reads['media'] ?? [], 'Crossing a 200-product batch must use two media reads.');
        self::assertSame(1, $queries->storeListCalls, 'All matching batches belong to one search.');
        self::assertSame(array_map(static fn(int $id): int => $id * 10, range(1, 205)), array_merge(...array_map(
            static fn(array $query): array => $query['offer_id'][0],
            $queries->reads['price'],
        )));
        self::assertSame(range(1, 205), array_merge(...array_map(
            static fn(array $query): array => $query['product_id'][0],
            $queries->reads['media'],
        )));
    }

    public function testSelectionRowsPreserveFilteredOrderingAcrossBatchesWithoutPresentationReads(): void
    {
        $products = $offers = $attributes = $prices = $media = [];
        $matchingIds = [1, 199, 201, 205];
        foreach (range(1, 205) as $id) {
            $candidate = in_array($id, $matchingIds, true) || ($id >= 2 && $id <= 8);
            $updatedAt = $id === 199 ? '2026-09-04 12:00:00'
                : ($id === 1 ? '2026-09-05 12:00:00' : '2026-09-06 12:00:00');
            $products[] = [
                'product_id' => $id, 'product_code' => $id === 4 ? 'OTHER' : 'KEEP-' . $id,
                'product_type' => $id === 5 ? 'virtual' : 'simple',
                'status' => $id === 6 ? 'draft' : 'published',
                'owner_website_id' => $id === 7 ? 9 : 1,
                'sku' => 'MATCH-' . $id, 'name' => 'Keep candidate', 'updated_at' => $updatedAt,
            ];
            $offers[] = ['product_id' => $id, 'offer_id' => $id * 10,
                'sku' => $id === 1 ? '0' : ($id === 3 ? 'OTHER' : 'MATCH-' . $id)];
            $attributes[] = [
                'entity_type' => 'product', 'entity_id' => $id, 'attribute_code' => 'name',
                'locale' => 'en_US', 'store_id' => 0, 'value_type' => 'string',
                'value_string' => $candidate && $id !== 2 ? 'Keep candidate' : 'Rejected',
                'cleared' => $id === 8 ? 1 : 0, 'is_required' => 0,
            ];
            $prices[] = ['offer_id' => $id * 10, 'store_id' => 0, 'currency' => 'USD', 'amount_minor' => $id * 100];
            $media[] = ['media_id' => $id, 'product_id' => $id, 'store_id' => 0, 'position' => 0,
                'path' => 'https://images.example.test/' . $id . '.jpg'];
        }
        $related = ['offer' => $offers, 'attribute_value' => $attributes, 'price' => $prices, 'media' => $media];
        $filters = ['store_id' => 7, 'name' => ' KEEP ', 'sku' => ' match-', 'product_code' => ' keep-',
            'type' => ' SIMPLE ', 'status' => ' PUBLISHED ', 'owner_website_id' => 1];
        $expected = [
            ['product_id' => 205, 'updated_at' => '2026-09-06 12:00:00'],
            ['product_id' => 201, 'updated_at' => '2026-09-06 12:00:00'],
            ['product_id' => 1, 'updated_at' => '2026-09-05 12:00:00'],
            ['product_id' => 199, 'updated_at' => '2026-09-04 12:00:00'],
        ];
        $project = static fn(array $rows): array => array_map(static fn(array $row): array => [
            'product_id' => $row['product_id'], 'updated_at' => $row['updated_at'],
        ], $rows);
        [$baseline, $baselineQueries] = $this->fixture($products, $related);
        self::assertSame($expected, $project($baseline->search(1, $filters)));
        self::assertCount(2, $baselineQueries->reads['price'] ?? []);
        self::assertCount(2, $baselineQueries->reads['media'] ?? []);
        self::assertSame(1, $baselineQueries->storeListCalls);

        [$service, $queries] = $this->fixture($products, $related);
        // The old reader is a real consumption baseline, so RED exposes its detail reads.
        $rows = $service instanceof \Weline\Product\Api\ProductSelectionReadInterface
            ? $service->searchSelectionRows(1, $filters)
            : $project($service->search(1, $filters));
        self::assertSame($expected, $rows);
        self::assertCount(2, $queries->reads['offer'] ?? []);
        self::assertCount(2, $queries->reads['attribute_value'] ?? []);
        self::assertSame(range(1, 205), array_merge(...array_map(
            static fn(array $query): array => $query['product_id'][0], $queries->reads['offer'],
        )));
        self::assertSame(range(1, 205), array_merge(...array_map(
            static fn(array $query): array => $query['entity_id'][0], $queries->reads['attribute_value'],
        )));
        self::assertCount(205, $queries->reads['store_product'] ?? [], 'Store selection checks remain necessary.');
        self::assertSame(['price' => 0, 'media' => 0, 'display_stores' => 0], [
            'price' => count($queries->reads['price'] ?? []),
            'media' => count($queries->reads['media'] ?? []),
            'display_stores' => $queries->storeListCalls,
        ], 'Selecting identifiers must not hydrate price, media, or display-store payloads.');
    }

    public function testPromotionSelectionUsesRealReaderAndKeepsDateLimitAndStoreScopeWithoutPresentationReads(): void
    {
        $now = date('Y-m-d H:i:s');
        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $old = date('Y-m-d H:i:s', strtotime('-15 days'));
        $products = $offers = $media = [];
        foreach ([1 => $now, 2 => $now, 3 => $old, 4 => '', 5 => 'invalid-date', 6 => $yesterday, 7 => $now] as $id => $updatedAt) {
            $products[] = ['product_id' => $id, 'status' => $id === 7 ? 'draft' : 'published', 'updated_at' => $updatedAt];
            $offers[] = ['product_id' => $id, 'offer_id' => $id * 10, 'sku' => 'MATCH-' . $id];
            $media[] = ['media_id' => $id, 'product_id' => $id, 'store_id' => 0, 'position' => 0,
                'path' => 'https://images.example.test/' . $id . '.jpg'];
        }
        [$reader, $queries] = $this->fixture($products, ['offer' => $offers, 'attribute_value' => [], 'media' => $media]);
        $stores = $this->createMock(StoreCatalogInterface::class);
        $stores->expects(self::exactly(2))->method('byCode')->with(1, 'request-store')->willReturn(
            new \Weline\Websites\Api\Catalog\Data\StoreSummary(7, 1, 'request-store', 'Request store', 'inherit', false, true, 'active', null),
        );
        $promotion = new \Weline\Promotion\Service\PromotionThemeProductService(
            $this->createStub(\Weline\Promotion\Model\PromotionActivityThemeProduct::class), $stores,
        );
        $readerClass = \Weline\Product\Api\ProductAdminReadInterface::class;
        $saved = \Weline\Framework\Manager\ObjectManager::getInstances()[$readerClass] ?? null;
        \Weline\Framework\Manager\ObjectManager::setInstance($readerClass, $reader);
        try {
            foreach ([2 => [2, 1], 10 => [2, 1, 6]] as $limit => $expectedIds) {
                $theme = ['id' => 11, 'website_id' => 1, 'store_code' => '', 'channel_code' => 'theme-channel',
                    'product_pick_mode' => 'filter', 'product_filter_json' => json_encode([
                        'status' => 'published', 'sku' => 'MATCH-', 'new_within_days' => 7, 'limit' => $limit,
                    ], JSON_THROW_ON_ERROR)];
                self::assertSame($expectedIds, $promotion->resolveStorefrontProductIds($theme, [
                    'website_id' => 99, 'store_code' => 'request-store', 'channel_code' => 'request-channel',
                ]));
            }
            self::assertCount(14, $queries->reads['store_product'] ?? []);
            foreach ($queries->reads['store_product'] as $query) {
                self::assertSame([7, '='], $query['store_id'], 'The resolved store scope must reach the real reader.');
            }
            self::assertSame(['price' => 0, 'media' => 0, 'display_stores' => 0], [
                'price' => count($queries->reads['price'] ?? []),
                'media' => count($queries->reads['media'] ?? []),
                'display_stores' => $queries->storeListCalls,
            ], 'Promotion identifier selection must not hydrate product detail payloads.');
        } finally {
            if ($saved !== null) {
                \Weline\Framework\Manager\ObjectManager::setInstance($readerClass, $saved);
            } else {
                \Weline\Framework\Manager\ObjectManager::removeInstance($readerClass);
            }
        }
    }

    public function testNameSkuAndStatusRejectionsSkipIdentityReadsInBothSearchModes(): void
    {
        $identityReads = 0;
        $identities = $this->identityReaderRecordingReads($identityReads);
        $products = [
            ['product_id' => 1, 'global_product_uuid' => '00000000-0000-4000-8000-000000000001',
                'name' => 'Not an attribute', 'sku' => 'FALLBACK-1', 'status' => 'draft'],
            ['product_id' => 2, 'global_product_uuid' => '00000000-0000-4000-8000-000000000002',
                'name' => 'Not an attribute', 'sku' => 'IGNORED-ROOT-2', 'status' => 'draft'],
        ];
        foreach (['search', 'searchSelectionRows'] as $method) {
            foreach ([
                ['sku' => 'not-matching'],
                ['name' => 'not an attribute'],
                ['name' => 'cleared name must be ignored'],
                ['name' => 'store overlay must not leak'],
                ['name' => 'not the first locale'],
                ['status' => ' PUBLISHED '],
            ] as $filters) {
                [$service] = $this->fixture($products, identities: $identities);
                self::assertSame([], $service->$method(1, $filters));
            }
        }
        self::assertSame(0, $identityReads, 'Rejected base rows must not issue per-product identity queries.');
    }

    public function testMatchingBaseFiltersStillReadAuthoritativeIdentityFields(): void
    {
        foreach (['search', 'searchSelectionRows'] as $method) {
            $identityReads = 0;
            $identities = $this->identityReaderRecordingReads($identityReads);
            [$service] = $this->fixture([
                ['product_id' => 1, 'global_product_uuid' => '00000000-0000-4000-8000-000000000001',
                    'sku' => 'FALLBACK-1', 'status' => 'draft', 'product_code' => 'LOCAL-CODE',
                    'product_type' => 'simple', 'owner_website_id' => 1],
            ], identities: $identities);
            try {
                $service->$method(1, [
                    'name' => ' ALPHA ', 'sku' => ' fallback-1 ', 'status' => ' DRAFT ',
                    'product_code' => 'LOCAL-CODE', 'type' => 'simple', 'owner_website_id' => 1,
                ]);
                self::fail('A matching local row must still read its authoritative identity.');
            } catch (\RuntimeException $error) {
                self::assertSame('identity-read-observed', $error->getMessage());
            }
            self::assertSame(1, $identityReads);
        }
    }

    public function testSelectionRowsSkipIdentityReadsWhenOnlyBaseFiltersAreUsed(): void
    {
        $identityReads = 0;
        $identities = $this->identityReaderRecordingReads($identityReads);
        [$service] = $this->fixture([
            ['product_id' => 1, 'global_product_uuid' => '00000000-0000-4000-8000-000000000001',
                'sku' => 'SKU-1', 'status' => 'published', 'updated_at' => '2026-09-06 12:00:00'],
        ], relatedRows: [
            'offer' => [['product_id' => 1, 'offer_id' => 10, 'sku' => 'SKU-1']],
            'attribute_value' => [],
        ], identities: $identities);

        self::assertSame(
            [['product_id' => 1, 'updated_at' => '2026-09-06 12:00:00']],
            $service->searchSelectionRows(1, ['status' => 'published']),
        );
        self::assertSame(0, $identityReads, 'Base-only selection rows do not need authoritative identity fields.');
    }

    private function identityReaderRecordingReads(int &$identityReads): \Weline\Product\Service\ProductIdentityV2Service
    {
        return new \Weline\Product\Service\ProductIdentityV2Service(
            $this->uninitialized(ConnectionFactory::class),
            $this->createStub(DatabaseTransactionRunnerInterface::class),
            productRegistryFactory: static function () use (&$identityReads): \Weline\Product\Model\ProductIdentityRegistry {
                ++$identityReads;
                // Observe the real service's DB boundary without querying a configured business database.
                throw new \RuntimeException('identity-read-observed');
            },
        );
    }

    /** @return array{ProductAdminReadService, BatchSearchReadLog} */
    private function fixture(?array $products = null, array $relatedRows = [], ?\Weline\Product\Service\ProductIdentityV2Service $identities = null): array
    {
        $queries = new BatchSearchReadLog();
        $products ??= [
            ['product_id' => 2, 'product_code' => 'CODE-2', 'sku' => 'IGNORED-ROOT-2', 'updated_at' => '2026-09-06 12:00:00'],
            ['product_id' => 0, 'sku' => 'INVALID'],
            ['product_id' => 1, 'product_code' => 'CODE-1', 'sku' => ' FALLBACK-1 ', 'updated_at' => '2026-09-06 12:00:00'],
            ['product_id' => 3, 'product_code' => 'CODE-3', 'sku' => 'FALLBACK-3', 'name' => 'Not an attribute', 'updated_at' => '2026-09-05 12:00:00'],
        ];
        $offers = [
            ['offer_id' => 23, 'product_id' => 2, 'sku' => ' B-THIRD '],
            ['offer_id' => 12, 'product_id' => 1, 'sku' => '0'],
            ['offer_id' => 21, 'product_id' => 2, 'sku' => 'B-FIRST'],
            ['offer_id' => 11, 'product_id' => 1, 'sku' => '   '],
            ['offer_id' => 22, 'product_id' => 2, 'sku' => 'B-SECOND'],
            ['offer_id' => 91, 'product_id' => 9, 'sku' => 'UNREQUESTED'],
        ];
        $attribute = static fn(int $id, string $locale, string $value, int $cleared = 0, int $store = 0): array => [
            'entity_type' => 'product', 'entity_id' => $id, 'attribute_code' => 'name',
            'locale' => $locale, 'store_id' => $store, 'value_type' => 'string',
            'value_string' => $value, 'cleared' => $cleared, 'is_required' => 0,
        ];
        $attributes = [
            $attribute(2, 'zz', 'Not the first locale'),
            $attribute(1, '', 'Cleared name must be ignored', 1),
            $attribute(2, 'en_US', ' Beta '),
            $attribute(1, 'en_US', ' Alpha '),
            $attribute(1, '', 'Store overlay must not leak', 0, 7),
            $attribute(9, '', 'Unrequested product'),
        ];
        $factory = static fn(string $entity, array $rows): \Closure => static fn(int $websiteId): AbstractWebsiteShardModel
            => new BatchSearchQueryModel($entity, $rows, $queries);
        $provisioner = new ProductShardProvisioner(
            $this->createStub(\Weline\Product\Model\ProductShardRegistry::class),
            $this->createStub(\Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface::class),
        );
        $stores = $this->createStub(StoreCatalogInterface::class);
        $stores->method('byWebsite')->willReturnCallback(static function (int $websiteId) use ($queries): array {
            $queries->storeListCalls++;
            return [];
        });
        $dependencies = [
            'identities' => $identities ?? $this->uninitialized(\Weline\Product\Service\ProductIdentityV2Service::class),
            'products' => new ProductRepository($provisioner, modelFactory: $factory('product', $products)),
            'offers' => new OfferRepository($provisioner, modelFactory: $factory('offer', $relatedRows['offer'] ?? $offers)),
            'attributes' => new AttributeValueRepository($provisioner, modelFactory: $factory('attribute_value', $relatedRows['attribute_value'] ?? $attributes)),
            'prices' => new PriceRepository($provisioner, modelFactory: $factory('price', $relatedRows['price'] ?? [])),
            'media' => new MediaRepository(
                $provisioner,
                $this->uninitialized(ConnectionFactory::class),
                $this->createStub(DatabaseTransactionRunnerInterface::class),
                modelFactory: $factory('media', $relatedRows['media'] ?? []),
            ),
            'storeProducts' => new StoreProductRepository($provisioner, modelFactory: $factory('store_product', [])),
            'storeCatalog' => $stores,
            'mediaPresenter' => new ProductAdminMediaPresenter($this->createStub(FileAssetManagerInterface::class)),
        ];
        $arguments = [];
        foreach ((new ReflectionClass(ProductAdminReadService::class))->getConstructor()->getParameters() as $parameter) {
            if (array_key_exists($parameter->getName(), $dependencies)) {
                $arguments[] = $dependencies[$parameter->getName()];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                // These unrelated services are never used by search with the fixture's empty global UUIDs.
                $arguments[] = $this->uninitialized((string)$parameter->getType());
            }
        }
        return [new ProductAdminReadService(...$arguments), $queries];
    }

    private function uninitialized(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

final class BatchSearchReadLog
{
    /** @var array<string, list<array<string, array{mixed, string}>>> */
    public array $reads = [];
    public int $storeListCalls = 0;
}

/** Query boundary only: Service and repository production methods are not replaced. */
final class BatchSearchQueryModel extends AbstractWebsiteShardModel
{
    private array $conditions = [];
    private array $found = [];

    public function __construct(private string $entity, private array $fixtureRows, private BatchSearchReadLog $queries)
    {
    }

    public static function entityCode(): string
    {
        return 'batch_search_fixture';
    }

    public function clear(bool $with_query = true): static
    {
        $this->conditions = [];
        $this->found = [];
        return $this;
    }

    public function where($field, $value, $operator = '='): static
    {
        $this->conditions[preg_replace('/^main_table\./', '', (string)$field)] = [$value, strtoupper((string)$operator)];
        return $this;
    }

    public function select(): static
    {
        $this->queries->reads[$this->entity][] = $this->conditions;
        return $this;
    }

    public function fetchArray(): array
    {
        return array_values(array_filter($this->fixtureRows, function (array $row): bool {
            foreach ($this->conditions as $field => [$value, $operator]) {
                $actual = $row[$field] ?? null;
                if ($operator === 'IN' ? !in_array($actual, (array)$value, false) : $actual != $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function fetchIterator(): \Generator
    {
        yield from $this->fetchArray();
    }

    public function find(): static
    {
        $this->queries->reads[$this->entity][] = $this->conditions;
        $this->found = $this->fetchArray()[0] ?? [];
        return $this;
    }

    public function fetch(): static
    {
        return $this;
    }

    public function getId(mixed $default = null)
    {
        return $this->found['id'] ?? $default;
    }

    public function __call($method, $args)
    {
        if (in_array($method, ['fields', 'order', 'limit'], true)) {
            return $this;
        }
        throw new \LogicException('Unexpected fixture query operation: ' . $method);
    }
}
