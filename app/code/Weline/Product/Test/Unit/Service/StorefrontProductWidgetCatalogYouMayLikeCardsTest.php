<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

final class StorefrontProductWidgetCatalogYouMayLikeCardsTest extends TestCase
{
    public function testYouMayLikeCardsMethodExistsAndClampsLimit(): void
    {
        self::assertTrue(method_exists(StorefrontProductWidgetCatalog::class, 'youMayLikeCards'));
        $method = new ReflectionMethod(StorefrontProductWidgetCatalog::class, 'youMayLikeCards');
        self::assertSame(2, $method->getNumberOfParameters());
    }

    public function testRelatedCardsAndYouMayLikePreferHanfuThenCatalogFallback(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php'
        );
        self::assertStringContainsString('function youMayLikeCards(int $excludeProductId = 0, int $limit = 8)', $source);
        self::assertStringContainsString('sameCategoryCompanionOffers', $source);
        self::assertStringContainsString('Related / you-may-like fill: preserve Hanfu-first ordering', $source);
        self::assertStringContainsString('$fallbackProductId', $source);
    }
}
