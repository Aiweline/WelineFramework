<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionThemeDealDiscountSyncProviderContractTest extends TestCase
{
    public function testSyncServiceConsumesMarketingProviderAndDoesNotTouchRuleModel(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionThemeDealDiscountSyncService.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringNotContainsString('Weline\\Marketing\\Model\\Rule\\Rule', $content);
        self::assertStringContainsString('ExternalDealDiscountProviderInterface', $content);
        self::assertStringContainsString('RuntimeProviderResolver', $content);
        self::assertStringContainsString('provider->upsert(new ExternalDealDiscountRequest(', $content);
        self::assertStringContainsString("SOURCE_TYPE = 'promotion_activity_theme'", $content);
    }
}
