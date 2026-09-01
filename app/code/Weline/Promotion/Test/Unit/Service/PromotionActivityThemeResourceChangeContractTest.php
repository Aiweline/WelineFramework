<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionActivityThemeResourceChangeContractTest extends TestCase
{
    public function testSaveThemePublishesResourceChangeAndClearsLocalFpc(): void
    {
        $service = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php');
        $publisher = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PromotionActivityThemeResourceChangePublisher.php');
        $invalidator = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PromotionStorefrontCacheInvalidator.php');
        $doc = (string)file_get_contents(dirname(__DIR__, 3) . '/doc/event/resource_changed.md');
        $readme = (string)file_get_contents(dirname(__DIR__, 3) . '/doc/README.md');

        self::assertStringContainsString('promotion_activity_theme', $publisher);
        self::assertStringContainsString('w_changed', $publisher);
        self::assertStringContainsString('/promotion', $publisher);
        self::assertStringContainsString('publishUpsert', $service);
        self::assertStringContainsString('afterCommit', $service);
        self::assertStringContainsString('WriteIntentTransactionCoordinatorInterface', $service);
        self::assertStringContainsString('clearForTheme', $invalidator);
        self::assertStringContainsString('FullPageCacheCoordinator', $invalidator);
        self::assertStringContainsString('w_changed', $doc);
        self::assertStringContainsString('PromotionStorefrontCacheInvalidator', $doc);
        self::assertStringContainsString('resource_changed.md', $readme);
        self::assertStringNotContainsString('Weline_Cdn::clear', $publisher);
        self::assertStringNotContainsString('Weline_Cdn::clear', $service);
    }
}
