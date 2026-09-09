<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontPageServiceTitleContractTest extends TestCase
{
    public function testBuildReadsPageTitleFromThemePagePayload(): void
    {
        $themeService = dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php';
        $storefront = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        self::assertFileExists($themeService);
        self::assertFileExists($storefront);

        $themeContent = (string) file_get_contents($themeService);
        $storefrontContent = (string) file_get_contents($storefront);

        self::assertStringContainsString("'page_title' => (string)\$copy['page_title']", $themeContent);
        self::assertStringContainsString("\$themePage['page_title'] ?? \$themePage['title']", $storefrontContent);
        self::assertStringContainsString("'sale' => (string)__('节令主题陈列')", $storefrontContent);
    }
}
