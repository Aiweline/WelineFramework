<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Storefront search type scopes must not leak website_id=0 (default-site) blog
 * taxonomies onto other websites (e.g. Hanfu Guide on DaoCharms).
 */
final class BlogSearchCategoryScopeNoCrossBrandLeakContractTest extends TestCase
{
    public function testSearchScopesPreferOwnedOnlyTreeForNonDefaultWebsites(): void
    {
        $scopeSource = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogSearchCategoryScopeService.php'
        );
        self::assertStringContainsString('treeOwnedOnly', $scopeSource);
        self::assertStringContainsString('if ($websiteId > 0)', $scopeSource);
        self::assertStringContainsString('return [];', $scopeSource);

        $adminSource = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogCategoryAdminService.php'
        );
        self::assertStringContainsString('function treeOwnedOnly(', $adminSource);
        self::assertStringContainsString('category_tree_owned', $adminSource);
        self::assertStringContainsString('$includeGlobal', $adminSource);
    }
}
