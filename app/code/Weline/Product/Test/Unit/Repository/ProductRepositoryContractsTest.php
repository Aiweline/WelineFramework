<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Weline\Product\Repository\ProductRepository;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllProductRepositoryContractsPass().
 */
final class ProductRepositoryContractsTest extends TestCase
{
    public function testAllProductRepositoryContractsPass(): void
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
            'AttributeValueBatchLookup::testFindByAttributeValueDelegatesToMultiCodeBatch' => function () {
            $this->assert_AttributeValueBatchLookup_testFindByAttributeValueDelegatesToMultiCodeBatch();
        },
            'AttributeValueBatchLookup::testPublishedOffersBySlugUsesOneShotAttributeBatch' => function () {
            $this->assert_AttributeValueBatchLookup_testPublishedOffersBySlugUsesOneShotAttributeBatch();
        },
            'AttributeValueBatchLookup::testPublishedOffersBySlugLightMatchSkipsFilteredCatalogBuild' => function () {
            $this->assert_AttributeValueBatchLookup_testPublishedOffersBySlugLightMatchSkipsFilteredCatalogBuild();
        },
            'AttributeValueBatchLookup::testListExplicitRowsPrefersOneShotWhenLocaleOrCodeBounded' => function () {
            $this->assert_AttributeValueBatchLookup_testListExplicitRowsPrefersOneShotWhenLocaleOrCodeBounded();
        },
            'ProductMapIdsByGlobalUuids::testRepositoryExposesBatchUuidToIdMap' => function () {
            $this->assert_ProductMapIdsByGlobalUuids_testRepositoryExposesBatchUuidToIdMap();
        },
        ];
    }

private function assert_AttributeValueBatchLookup_testFindByAttributeValueDelegatesToMultiCodeBatch(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Product/Repository/AttributeValueRepository.php');
    self::assertStringContainsString('function findEntityIdsByAttributeValues(', $source);
    self::assertStringContainsString("->where(AttributeValue::schema_fields_ATTRIBUTE_CODE, \$codes, 'IN')", $source);
    self::assertStringContainsString("->where(AttributeValue::schema_fields_STORE_ID, \$stores, 'IN')", $source);
    self::assertMatchesRegularExpression('/function findEntityIdsByAttributeValue\([\s\S]*?return \$this->findEntityIdsByAttributeValues\(/s', $source);
}

private function assert_AttributeValueBatchLookup_testPublishedOffersBySlugUsesOneShotAttributeBatch(): void
{
    $service = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString('findEntityIdsByAttributeValues(', $service);
    self::assertStringContainsString("['source_slug', 'slug']", $service);
    self::assertStringNotContainsString("foreach (['source_slug', 'slug'] as \$attributeCode)", $service);
}

private function assert_AttributeValueBatchLookup_testPublishedOffersBySlugLightMatchSkipsFilteredCatalogBuild(): void
{
    $service = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontCatalogViewService.php');
    $matchStart = strpos($service, 'private function matchPublishedProductIdBySlug(');
    self::assertNotFalse($matchStart);
    $nextPrivate = strpos($service, "\n    private function ", $matchStart + 1);
    self::assertNotFalse($nextPrivate);
    $matchBody = substr($service, (int) $matchStart, (int) $nextPrivate - (int) $matchStart);
    self::assertStringContainsString('listByIds(', $matchBody);
    self::assertStringContainsString('STATUS_PUBLISHED', $matchBody);
    self::assertStringContainsString('disambiguatePublishedProductIdBySlugEav(', $matchBody);
    self::assertStringNotContainsString('publishedOffersForProductIds(', $matchBody);
    self::assertStringNotContainsString('buildPublishedOffers(', $matchBody);
    self::assertStringNotContainsString('buildTargetedPublishedOffers(', $matchBody);
    $bySlugStart = strpos($service, 'function publishedOffersBySlug(');
    self::assertNotFalse($bySlugStart);
    $bySlugEnd = strpos($service, "\n    private function matchPublishedProductIdBySlug(", (int) $bySlugStart);
    self::assertNotFalse($bySlugEnd);
    $bySlugBody = substr($service, (int) $bySlugStart, (int) $bySlugEnd - (int) $bySlugStart);
    self::assertMatchesRegularExpression('/return\s+\$this->livePublishedOffersForProduct\(/', $bySlugBody);
    self::assertSame(1, preg_match_all('/\$this->livePublishedOffersForProduct\(/', $bySlugBody), 'PDP slug path must project the matched product once');
}

private function assert_AttributeValueBatchLookup_testListExplicitRowsPrefersOneShotWhenLocaleOrCodeBounded(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Product/Repository/AttributeValueRepository.php');
    self::assertStringContainsString('$maxEntitiesPerShot = ($attributeCodes !== null || $locales !== null) ? 256 : 8', $source);
    // Locale coercion must run before chunk sizing (storefront one-shot).
    $coercePos = strpos($source, 'shouldCoerceStorefrontLocales()');
    $chunkPos = strpos($source, '$maxEntitiesPerShot');
    self::assertNotFalse($coercePos);
    self::assertNotFalse($chunkPos);
    self::assertLessThan($chunkPos, $coercePos);
}

private function assert_ProductMapIdsByGlobalUuids_testRepositoryExposesBatchUuidToIdMap(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Repository/ProductRepository.php');
    self::assertStringContainsString('function mapIdsByGlobalUuids(', $source);
    self::assertStringContainsString("schema_fields_GLOBAL_PRODUCT_UUID", $source);
    self::assertStringContainsString("'IN'", $source);
    self::assertStringContainsString('Never falls back to website 0', $source);
    self::assertTrue(method_exists(ProductRepository::class, 'mapIdsByGlobalUuids'));
    self::assertTrue(method_exists(ProductRepository::class, 'findByGlobalUuid'));
}
}
