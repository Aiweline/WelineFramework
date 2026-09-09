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
        $service = (new \ReflectionClass(\Weline\Blog\Service\BlogSearchCategoryScopeService::class))->newInstanceWithoutConstructor();
        $mapped = (new \ReflectionMethod($service, 'mapTree'))->invoke($service, [
            ['category_id' => 1, 'name' => 'News Center', 'slug' => 'news', 'nodes' => [
                ['category_id' => 2, 'name' => '新闻中心', 'slug' => 'updates', 'nodes' => []],
            ]],
        ]);
        self::assertSame('News Center', $mapped[0]['label']);
        self::assertSame('新闻中心', $mapped[0]['children'][0]['label']);
        self::assertSame(['category_id' => 2], $mapped[0]['children'][0]['params']);
    }

    public function testLocaleSyncMapsKnownChineseLabelsToEnglish(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogCategoryLocaleSyncService.php');
        self::assertStringContainsString('技术分享', $source);
        self::assertStringContainsString('Tech Sharing', $source);
        self::assertStringContainsString('writeName', $source);
    }
}
