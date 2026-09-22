<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

/**
 * D5 contract: public cardsByIds for curated product_id lists (video-carousel).
 *
 * StorefrontCatalogViewService is final (cannot double); assert signature + source shape.
 */
final class StorefrontProductWidgetCatalogCardsByIdsTest extends TestCase
{
    public function testCardsByIdsMethodSignatureIsPublic(): void
    {
        $method = new ReflectionMethod(StorefrontProductWidgetCatalog::class, 'cardsByIds');
        self::assertTrue($method->isPublic());
        self::assertSame(2, $method->getNumberOfParameters());
        self::assertSame('productIds', $method->getParameters()[0]->getName());
        self::assertSame('limit', $method->getParameters()[1]->getName());
        self::assertTrue($method->getParameters()[1]->isDefaultValueAvailable());
        self::assertSame(12, $method->getParameters()[1]->getDefaultValue());
        self::assertSame('array', (string)$method->getReturnType());
    }

    public function testCardsByIdsSourceReusesOffersAndMapOfferWithoutHanfuGate(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php',
        );
        self::assertStringContainsString(
            'function cardsByIds(array $productIds, int $limit = 12): array',
            $source,
        );

        $start = strpos($source, 'function cardsByIds(array $productIds, int $limit = 12): array');
        self::assertNotFalse($start);
        $end = strpos($source, 'function bestSellerCards(', $start);
        self::assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        self::assertStringContainsString('publishedOffersForProductIds', $body);
        self::assertStringContainsString('$this->mapOffer(', $body);
        self::assertStringContainsString('withReviewAggregates', $body);
        self::assertStringContainsString('foreach ($orderedIds as $productId)', $body);
        self::assertStringContainsString('max(1, min(24, $limit))', $body);
        self::assertStringNotContainsString('isHanfuOffer', $body);
        self::assertStringNotContainsString('HANFU_SKU_PREFIX', $body);
    }

    public function testCardsByIdsFiltersInvalidIdsAndDedupesBeforeFetch(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php',
        );
        $start = strpos($source, 'function cardsByIds(array $productIds, int $limit = 12): array');
        self::assertNotFalse($start);
        $end = strpos($source, 'function bestSellerCards(', $start);
        self::assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        self::assertStringContainsString('if ($productId <= 0 || isset($seen[$productId]))', $body);
        self::assertStringContainsString('if (!isset($offerByProductId[$productId]))', $body);
        self::assertStringContainsString('if ($orderedIds === [])', $body);
        self::assertStringContainsString('return [];', $body);
    }
}
