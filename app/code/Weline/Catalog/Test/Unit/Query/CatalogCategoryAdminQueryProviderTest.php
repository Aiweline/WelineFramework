<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Extends\Module\Weline_Framework\Query\CatalogCategoryAdminQueryProvider;

final class CatalogCategoryAdminQueryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testDescriptorUsesCatalogCategoryAdminAcl(): void
    {
        $provider = new CatalogCategoryAdminQueryProvider();
        $descriptor = $provider->getDescriptor();

        self::assertSame('catalog_category_admin', $descriptor['provider']);
        self::assertSame('Weline_Catalog', $descriptor['module']);
        self::assertSame(
            CatalogCategoryAdminQueryProvider::ACL_SOURCE,
            $descriptor['operations'][0]['backend_acl']['source_id'] ?? '',
        );
        $names = array_column($descriptor['operations'], 'name');
        self::assertContains('categoryAdminReorder', $names);
    }
}
