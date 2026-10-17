<?php

declare(strict_types=1);

namespace Weline\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionProperty as ReflectionProperty;
use Weline\Filters\Service\StorefrontAttributeListingFilter;
use Weline\Filters\Service\StorefrontFacetPresentationPolicy;
use Weline\Filters\Service\StorefrontFacetTranslator;
use Weline\Filters\Service\StorefrontFilterPanelService;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Url;
use Weline\Product\Service\StorefrontCategoryListingFilter;
use Weline\Product\Service\StorefrontEavLabelResolver;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFiltersServiceContractsPass().
 */
final class FiltersServiceContractsTest extends TestCase
{
    public function testAllFiltersServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'StorefrontFilterPanelRefinement::testCrossDimensionEmptyIntersectionKeepsZeroAndDoesNotFallBackToFullCatalog' => function () {
            $this->assert_StorefrontFilterPanelRefinement_testCrossDimensionEmptyIntersectionKeepsZeroAndDoesNotFallBackToFullCatalog();
        },
            'StorefrontFilterPanelRefinement::testUnselectedZeroOptionsAreOmittedFromRefinementPayload' => function () {
            $this->assert_StorefrontFilterPanelRefinement_testUnselectedZeroOptionsAreOmittedFromRefinementPayload();
        },
            'StorefrontFilterPanelRefinement::testPriceOnlyRefinementStillEmitsAttributeDimensionCounts' => function () {
            $this->assert_StorefrontFilterPanelRefinement_testPriceOnlyRefinementStillEmitsAttributeDimensionCounts();
        },
            'StorefrontFilterPanelRefinement::testEmptyAttributesJsonEncodesAsObjectNotArray' => function () {
            $this->assert_StorefrontFilterPanelRefinement_testEmptyAttributesJsonEncodesAsObjectNotArray();
        },
            'StorefrontFilterPanelRefinement::testRefinementCacheKeyDiffersFromNeutralPanelKey' => function () {
            $this->assert_StorefrontFilterPanelRefinement_testRefinementCacheKeyDiffersFromNeutralPanelKey();
        },
            'StorefrontFilterPanelRefinement::testSourceForbidsWelineApiBusinessLogicForFacets' => function () {
            $this->assert_StorefrontFilterPanelRefinement_testSourceForbidsWelineApiBusinessLogicForFacets();
        },
        ];
    }

private function assert_StorefrontFilterPanelRefinement_testCrossDimensionEmptyIntersectionKeepsZeroAndDoesNotFallBackToFullCatalog(): void
{
    $offers = [
        $this->offer_StorefrontFilterPanelRefinement(1, 5000, '明制'),
        // 0-99, Ming
        $this->offer_StorefrontFilterPanelRefinement(2, 8000, '清制'),
        // 0-99, Qing
        $this->offer_StorefrontFilterPanelRefinement(3, 15000, '唐制'),
        // 100-299, Tang
        $this->offer_StorefrontFilterPanelRefinement(4, 35000, '唐制'),
        // 300-up, Tang
        $this->offer_StorefrontFilterPanelRefinement(5, 40000, '唐制'),
    ];
    $fullTang = 3;
    $facets = $this->service_StorefrontFilterPanelRefinement()->buildRefinementFacetCounts($offers, ['price' => '0-99', 'af_hanfu_chao_dai' => '唐制']);
    $tang = $this->findAttrOption_StorefrontFilterPanelRefinement($facets, 'hanfu_chao_dai', '唐制');
    self::assertNotNull($tang, 'Selected Tang must remain visible at count=0 (R1/R2)');
    self::assertTrue($tang['selected']);
    self::assertSame(0, $tang['count'], 'Tang under price 0-99 must be 0, not full-catalog ' . $fullTang);
    self::assertNotSame($fullTang, $tang['count']);
    $price099 = $this->findPriceOption_StorefrontFilterPanelRefinement($facets, '0-99');
    self::assertNotNull($price099, 'Selected price bucket must remain visible at count=0');
    self::assertTrue($price099['selected']);
    self::assertSame(0, $price099['count'], '0-99 under Tang must be 0 (no Tang in that bucket)');
    // Unselected dynasty under price 0-99 should still show reachable counts.
    $ming = $this->findAttrOption_StorefrontFilterPanelRefinement($facets, 'hanfu_chao_dai', '明制');
    self::assertNotNull($ming);
    self::assertSame(1, $ming['count']);
    self::assertFalse($ming['selected']);
}

private function assert_StorefrontFilterPanelRefinement_testUnselectedZeroOptionsAreOmittedFromRefinementPayload(): void
{
    $offers = [$this->offer_StorefrontFilterPanelRefinement(1, 5000, '明制'), $this->offer_StorefrontFilterPanelRefinement(2, 15000, '唐制')];
    $facets = $this->service_StorefrontFilterPanelRefinement()->buildRefinementFacetCounts($offers, ['price' => '0-99']);
    // Tang has 0 under 0-99 and is NOT selected → R3 omit.
    self::assertNull($this->findAttrOption_StorefrontFilterPanelRefinement($facets, 'hanfu_chao_dai', '唐制'));
    $ming = $this->findAttrOption_StorefrontFilterPanelRefinement($facets, 'hanfu_chao_dai', '明制');
    self::assertNotNull($ming);
    self::assertSame(1, $ming['count']);
}

private function assert_StorefrontFilterPanelRefinement_testPriceOnlyRefinementStillEmitsAttributeDimensionCounts(): void
{
    // Price-only must not yield empty attributes — soft listing needs specs on baseOffers.
    $offers = [
        $this->offer_StorefrontFilterPanelRefinement(1, 5000, '明制'),
        // in 0-99
        $this->offer_StorefrontFilterPanelRefinement(2, 15000, '唐制'),
        // 100-299 — outside price slice
        $this->offer_StorefrontFilterPanelRefinement(3, 35000, '唐制'),
    ];
    $facets = $this->service_StorefrontFilterPanelRefinement()->buildRefinementFacetCounts($offers, ['price' => '0-99']);
    self::assertIsArray($facets['attributes']);
    self::assertArrayHasKey('hanfu_chao_dai', $facets['attributes']);
    $ming = $this->findAttrOption_StorefrontFilterPanelRefinement($facets, 'hanfu_chao_dai', '明制');
    $tang = $this->findAttrOption_StorefrontFilterPanelRefinement($facets, 'hanfu_chao_dai', '唐制');
    self::assertNotNull($ming);
    self::assertSame(1, $ming['count']);
    self::assertNull($tang, 'Tang not in 0-99 and unselected → R3 omit');
    $json = (string) json_encode($facets, JSON_UNESCAPED_UNICODE);
    self::assertStringContainsString('"hanfu_chao_dai"', $json);
    self::assertStringNotContainsString('"attributes":[]', $json);
    $decoded = json_decode($json, false);
    self::assertIsObject($decoded->attributes);
}

private function assert_StorefrontFilterPanelRefinement_testEmptyAttributesJsonEncodesAsObjectNotArray(): void
{
    $facets = $this->service_StorefrontFilterPanelRefinement()->buildRefinementFacetCounts([], []);
    self::assertInstanceOf(\stdClass::class, $facets['attributes']);
    $json = (string) json_encode($facets, JSON_UNESCAPED_UNICODE);
    self::assertStringContainsString('"attributes":{}', $json);
    self::assertStringNotContainsString('"attributes":[]', $json);
    $decoded = json_decode($json, false);
    self::assertIsObject($decoded->attributes);
    self::assertSame([], (array) $decoded->attributes);
}

private function assert_StorefrontFilterPanelRefinement_testRefinementCacheKeyDiffersFromNeutralPanelKey(): void
{
    $service = $this->service_StorefrontFilterPanelRefinement();
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('buildPanelLogicalKey');
    $method->setAccessible(true);
    $offers = [$this->offer_StorefrontFilterPanelRefinement(1, 5000, '明制')];
    $query = ['price' => '0-99'];
    $neutral = $method->invoke($service, $offers, '/products', $query, 0, false, false);
    $refinement = $method->invoke($service, $offers, '/products', $query, 0, false, true);
    self::assertNotSame($neutral, $refinement);
}

private function assert_StorefrontFilterPanelRefinement_testSourceForbidsWelineApiBusinessLogicForFacets(): void
{
    $api = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code' . '/Frontend/view/statics/js/weline-api.js');
    self::assertStringNotContainsString('facet_counts', $api);
    self::assertStringNotContainsString('refinement', $api);
    self::assertStringNotContainsString('buildRefinementFacetCounts', $api);
}

/**
 * @return array{product_id:int,unit_price_minor:int,specifications:list<array{code:string,value:string}>}
 */
private function offer_StorefrontFilterPanelRefinement(int $id, int $minor, string $dynasty): array
{
    return ['product_id' => $id, 'unit_price_minor' => $minor, 'name' => 'Offer ' . $id, 'specifications' => [['code' => 'hanfu_chao_dai', 'value' => $dynasty]]];
}

/**
 * @param array{price:list<array<string,mixed>>,attributes:array<string,list<array<string,mixed>>>} $facets
 * @return array{value:string,count:int,selected:bool}|null
 */
private function findPriceOption_StorefrontFilterPanelRefinement(array $facets, string $value): ?array
{
    foreach ($facets['price'] as $option) {
        if ((string) ($option['value'] ?? '') === $value) {
            return $option;
        }
    }
    return null;
}

/**
 * @param array{price:list<array<string,mixed>>,attributes:array<string,list<array<string,mixed>>>|\stdClass} $facets
 * @return array{value:string,count:int,selected:bool}|null
 */
private function findAttrOption_StorefrontFilterPanelRefinement(array $facets, string $code, string $value): ?array
{
    $attributes = $facets['attributes'] ?? [];
    if ($attributes instanceof \stdClass) {
        $attributes = (array) $attributes;
    }
    if (!is_array($attributes)) {
        return null;
    }
    foreach ($attributes[$code] ?? [] as $option) {
        if (!is_array($option)) {
            continue;
        }
        if (strcasecmp((string) ($option['value'] ?? ''), $value) === 0) {
            return $option;
        }
    }
    return null;
}

private function service_StorefrontFilterPanelRefinement(): StorefrontFilterPanelService
{
    $service = (new ReflectionClass(StorefrontFilterPanelService::class))->newInstanceWithoutConstructor();
    // Prefer real finals via newInstanceWithoutConstructor: catalog/tree calls fail closed
    // into offer-projection counts (try/catch in buildPanelData).
    $pool = $this->createMock(CachePoolInterface::class);
    $pool->method('getCustom')->willReturn(null);
    $pool->method('set')->willReturn(true);
    $manager = $this->createMock(CacheManager::class);
    $manager->method('registerPolicy')->willReturnArgument(0);
    $manager->method('pool')->willReturn($pool);
    $generations = $this->createMock(NamespaceGenerationInterface::class);
    $generations->method('fingerprint')->willReturn('refinement-test-generation');
    $hotCache = new StorefrontScopeHotCache($manager, $generations);
    $attributes = $this->createMock(\Weline\Eav\Service\AttributeFilterService::class);
    $attributes->method('getFilterableAttributeMetadata')->willReturn([]);
    $attributes->method('getFilterableAttributes')->willReturn([]);
    $metadata = $this->createMock(\Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface::class);
    $metadata->method('catalog')->willReturn([]);
    $metadata->method('catalogForProduct')->willReturn([]);
    $entity = (new ReflectionClass(\Weline\Product\Model\ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
    $eavLabels = new StorefrontEavLabelResolver($metadata, $entity);
    $request = $this->createMock(\Weline\Framework\Http\Request::class);
    $request->method('getParam')->willReturn('');
    $preview = new \Weline\Theme\Service\PreviewRequestInspector($request);
    $url = $this->createMock(Url::class);
    $url->method('getFrontendUrl')->willReturnCallback(static function (string $route, array $params = []): string {
        $qs = $params === [] ? '' : '?' . http_build_query($params);
        return '/' . ltrim($route, '/') . $qs;
    });
    $map = ['attributes' => $attributes, 'attributeListing' => new StorefrontAttributeListingFilter(), 'listingFilter' => new StorefrontCategoryListingFilter(), 'tree' => (new ReflectionClass(\Weline\Product\Service\StorefrontCategoryTreeIndex::class))->newInstanceWithoutConstructor(), 'catalog' => (new ReflectionClass(\Weline\Product\Service\StorefrontCatalogViewService::class))->newInstanceWithoutConstructor(), 'categories' => (new ReflectionClass(\Weline\Product\Service\StorefrontCategoryViewService::class))->newInstanceWithoutConstructor(), 'categoryLinks' => (new ReflectionClass(\Weline\Product\Repository\CategoryLinkRepository::class))->newInstanceWithoutConstructor(), 'facetPresentation' => new StorefrontFacetPresentationPolicy(), 'eavLabels' => $eavLabels, 'facetTranslator' => new StorefrontFacetTranslator(), 'url' => $url, 'hotCache' => $hotCache, 'previewRequest' => $preview];
    foreach ($map as $prop => $value) {
        (new ReflectionProperty(StorefrontFilterPanelService::class, $prop))->setValue($service, $value);
    }
    return $service;
}
}
