<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Review\Api\ReviewSeoFactsInterface;
use Weline\Review\Service\ReviewService;

final class ReviewAggregatesForExternalUuidsContractTest extends TestCase
{
    public function testInterfaceAndServiceExposeBatchAggregates(): void
    {
        $iface = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/ReviewSeoFactsInterface.php');
        self::assertStringContainsString('function aggregatesForExternalUuids(', $iface);
        self::assertStringContainsString('@return array<string, array{review_count:int, average_rating:float}>', $iface);

        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ReviewService.php');
        self::assertStringContainsString('public function aggregatesForExternalUuids(', $source);
        self::assertStringContainsString("->group(ProductReview::schema_fields_ENTITY_UUID)", $source);
        self::assertStringContainsString("ProductReview::STATUS_APPROVED", $source);
        self::assertTrue(method_exists(ReviewService::class, 'aggregatesForExternalUuids'));
        self::assertTrue(method_exists(ReviewSeoFactsInterface::class, 'aggregatesForExternalUuids'));
    }
}
