<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Repository\AttributeValueRepository;

/**
 * Search keyword projection must stream EAV into a text map with HARD_LIMIT-sized chunks.
 */
final class ProductSearchProjectionTextMapContractTest extends TestCase
{
    public function testCollectLocalizedAttributeTextsUsesTextMapAndBoundedChunk(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductSearchProjectionService.php',
        );

        self::assertStringContainsString('listLocalizedTextMap(', $source);
        self::assertStringContainsString('boundedEntityChunkSize(', $source);
        self::assertStringNotContainsString(
            'array_chunk($entityIds, 48)',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            '/collectLocalizedAttributeTexts\([\s\S]*?listExplicitRows\(/',
            $source,
        );
        self::assertSame(100, AttributeValueRepository::boundedEntityChunkSize(4));
    }
}
