<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class BlogCategoryLocalizedNameContractTest extends TestCase
{
    public function testContentResolverUsesCategoryAttributeService(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogContentResolver.php');
        self::assertStringContainsString('BlogCategoryAttributeService', $source);
        self::assertStringContainsString('resolveDisplayName', $source);
        self::assertStringContainsString('listCategories(int $websiteId = 0, string $locale = \'\')', $source);
    }

    public function testSearchScopeServiceUsesLocalizedLabels(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogSearchCategoryScopeService.php');
        self::assertStringContainsString('BlogCategoryAttributeService', $source);
        self::assertStringContainsString('resolveDisplayName', $source);
    }

    public function testLocaleSyncMapsKnownChineseLabelsToEnglish(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogCategoryLocaleSyncService.php');
        self::assertStringContainsString('技术分享', $source);
        self::assertStringContainsString('Tech Sharing', $source);
        self::assertStringContainsString('writeName', $source);
    }
}
