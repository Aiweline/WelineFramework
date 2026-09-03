<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class BlogCategoryTreeContractTest extends TestCase
{
    public function testAdminServiceEnforcesTwoLevelTree(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogCategoryAdminService.php',
        );

        self::assertStringContainsString('public const MAX_DEPTH = 2', $source);
        self::assertStringContainsString('schema_fields_PARENT_ID', $source);
        self::assertStringContainsString('selfAndDescendantIds', $source);
        self::assertStringContainsString('博客分类最多支持两级', $source);
        self::assertStringContainsString("\$node['nodes'] = \$categoryId > 0 ? \$walk(\$categoryId) : []", $source);
    }

    public function testCategoryModelDeclaresParentId(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/Category.php',
        );

        self::assertStringContainsString("schema_fields_PARENT_ID = 'parent_id'", $source);
    }

    public function testContentResolverAggregatesDescendantPosts(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogContentResolver.php',
        );

        self::assertStringContainsString('selfAndDescendantIds', $source);
        self::assertStringContainsString("Post::schema_fields_CATEGORY_ID, \$categoryIds, 'IN'", $source);
        self::assertStringContainsString("'children' => \$children", $source);
    }

    public function testFrontendFilterSupportsExpandableChildren(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/partials/category-filter.phtml',
        );

        self::assertStringContainsString('data-blog-filter-toggle', $source);
        self::assertStringContainsString('amazon-blog-filter__children', $source);
        self::assertStringContainsString("\$category['children']", $source);
    }

    public function testEthnicSeedRemountsUnderChinaEthnicDress(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/seed-china-ethnic-categories.php',
        );

        self::assertStringContainsString('remountEthnicChildren', $source);
        self::assertStringContainsString("str_starts_with(\$code, 'ethnic-cn-')", $source);
        self::assertStringContainsString("\$admin->reorder(WEBSITE_ID, \$categoryId, \$hubId, 2, \$position)", $source);
    }

    public function testCatalogProviderPassesParentIdOnSave(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Catalog/Space/BlogCatalogSpaceProvider.php',
        );

        self::assertStringContainsString("\$payload['parent_id'] ?? \$payload['pid'] ?? 0", $source);
        self::assertStringContainsString('two-level tree', $source);
    }
}
