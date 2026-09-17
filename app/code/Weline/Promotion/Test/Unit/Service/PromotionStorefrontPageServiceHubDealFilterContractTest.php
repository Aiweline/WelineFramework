<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontPageServiceHubDealFilterContractTest extends TestCase
{
    public function testHubBuildUsesActiveThemeSelectionsAndDropsUnmarkedCatalogRows(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('listHubStorefrontProductIds', $src);
        self::assertStringContainsString('keepDealMarkedItemsOnly', $src);
        self::assertStringContainsString(
            '// Hub never shelves a generic catalog slice; only active-theme selections.',
            $src,
        );
        self::assertStringContainsString(
            '// Activity homepage must not show catalog rows without a live deal badge.',
            $src,
        );
        self::assertStringContainsString("\$pageType === 'index'", $src);
        self::assertStringContainsString('empty($item[\'has_deal\'])', $src);
    }
}
