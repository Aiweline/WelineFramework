<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontPageServiceProductDedupeContractTest extends TestCase
{
    public function testLoadProductsByIdsDedupesCatalogOffersAndDoesNotUseDefaultTwelveLimit(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringNotContainsString(
            'publishedOffersForProductIds($productIds, max(12, count($productIds)))',
            $content,
        );
        self::assertStringContainsString('isset($byProductId[$productId])', $content);
        self::assertStringContainsString('foreach ($orderedIds as $productId)', $content);
        self::assertStringContainsString(
            '// Explicit theme selection must not fall back to a generic catalog page.',
            $content,
        );
        self::assertStringContainsString(
            'return $this->loadProductsByIds($productIds);',
            $content,
        );
    }

    public function testPromotionShelfUsesCatalogSummaryProjection(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('$catalog->publishedOfferSummaries(48)', $content);
        self::assertMatchesRegularExpression(
            '/publishedOffersForProductIds\(\s*\$orderedIds,\s*max\(count\(\$orderedIds\) \* 8, count\(\$orderedIds\)\),\s*false,\s*\)/s',
            $content,
        );
    }
}
