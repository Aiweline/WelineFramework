<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class CategoryAdminSurfaceContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            require_once dirname(__DIR__, 2) . '/bootstrap.php';
        }
    }

    public function testCategoryPageUsesCatalogHubSurfaceWithSpaceAndScope(): void
    {
        $controller = $this->read('app/code/Weline/Catalog/Controller/Backend/Category.php');
        $template = $this->read('app/code/Weline/Catalog/view/templates/backend/category/index.phtml');
        $script = $this->read('app/code/Weline/Catalog/view/statics/js/backend/category-admin.js');
        $query = $this->read('app/code/Weline/Catalog/extends/module/Weline_Framework/Query/CatalogCategoryAdminQueryProvider.php');
        $menu = $this->read('app/code/Weline/Catalog/etc/backend/menu.xml');

        self::assertStringContainsString('CatalogHubService', $controller);
        self::assertStringContainsString('BackendObjectAuthorizationGuardInterface', $controller);
        self::assertStringContainsString('postCategoryUpdateOrder', $controller);
        self::assertStringContainsString("fetch('Weline_Catalog::templates/backend/category/index.phtml')", $controller);

        foreach ([
            'data-catalog-admin',
            'data-category-dnd-tree',
            'draggable="true"',
            'data-catalog-form',
            'data-testid="catalog-category-admin"',
            'data-testid="catalog-space-select"',
            'data-testid="catalog-scope-badge"',
            'w:websites:website:select',
            'name="space"',
            'name="scope_level"',
            'weline_catalog/backend/category/index',
            'weline_catalog/backend/category/category-post',
            'Weline_Catalog::js/backend/category-admin.js',
            'Weline_Catalog::css/backend/category-admin.css',
        ] as $contract) {
            self::assertStringContainsString($contract, $template);
        }

        self::assertStringContainsString("api.resource('catalog_category_admin')", $script);
        self::assertStringContainsString('scope_level: scopeLevel', $script);
        self::assertStringContainsString('categoryAdminReorder', $script);
        self::assertStringNotContainsString('alert(', $template . $script);
        self::assertStringNotContainsString('confirm(', $template . $script);

        self::assertStringContainsString("return 'catalog_category_admin';", $query);
        self::assertStringContainsString('categoryAdminReorder', $query);
        self::assertStringContainsString(
            "public const ACL_SOURCE = 'Weline_Catalog::commerce:universal-catalog:categories';",
            $query,
        );

        self::assertStringContainsString('weline_catalog/backend/category/index', $menu);
        self::assertStringContainsString('Weline_Catalog::commerce:universal-catalog:categories', $menu);
    }

    public function testLegacyProductCategoriesRouteRedirectsToCatalog(): void
    {
        $controller = $this->read('app/code/Weline/Product/Controller/Backend/Catalog.php');
        self::assertStringContainsString("redirect('weline_catalog/backend/category/index'", $controller);
        self::assertStringNotContainsString('renderCategoriesSection', $controller);
        self::assertStringNotContainsString('postCategoryPost', $controller);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . $path);
        self::assertIsString($content, 'Unable to read ' . $path);

        return $content;
    }
}
