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
        self::assertStringContainsString('expected_grant_version', $controller);
        self::assertStringContainsString('grant_version_delete', $controller);
        self::assertStringContainsString('Website::ID_DEFAULT', $controller);
        self::assertStringContainsString('postCategoryUpdateOrder', $controller);
        self::assertStringContainsString("fetch('Weline_Catalog::templates/backend/category/index.phtml')", $controller);
        self::assertStringContainsString("'locale' => (string)State::getLangLocal()", $controller);

        foreach ([
            'data-catalog-admin',
            'data-grant-version-create',
            'data-grant-version-update',
            'data-grant-version-delete',
            'data-category-dnd-tree',
            'draggable="true"',
            'data-catalog-form',
            'data-testid="catalog-category-admin"',
            'data-testid="catalog-space-select"',
            'data-testid="catalog-scope-select"',
            'data-testid="catalog-scope-badge"',
            'data-testid="catalog-display-form"',
            'data-testid="catalog-category-name-local"',
            'data-testid="catalog-category-image"',
            'data-testid="catalog-category-banner"',
            'data-testid="catalog-category-summary"',
            'data-testid="catalog-category-description"',
            'name="image"',
            'name="banner"',
            'name="summary"',
            'name="description"',
            'w:websites:website:select',
            'w:websites:store:select',
            'w:websites:channel:select',
            '<local model="Weline\\Product\\Model\\Category\\LocalDescription"',
            '保存后可翻译多语言',
            'name="space"',
            'name="scope_level"',
            'weline_catalog/backend/category/index',
            'weline_catalog/backend/category/category-post',
            'weline_catalog/backend/category/display-save',
            'Weline_Catalog::js/backend/category-admin.js',
            'Weline_Catalog::css/backend/category-admin.css',
            '?v=20260912-tree1',
            'data-catalog-tree-toggle',
            'aria-expanded="true"',
            'data-text-delete-products-intro',
            'Ops tag only',
        ] as $contract) {
            self::assertStringContainsString($contract, $template);
        }
        self::assertStringNotContainsString("__('来源')", $template);
        self::assertStringNotContainsString('leafCode', $template);
        self::assertStringNotContainsString('data-w-ui="tree"', $template);

        self::assertStringContainsString('postDisplaySave', $controller);
        self::assertStringContainsString('getCategoryProductsForDelete', $controller);
        self::assertStringContainsString('product_ids', $controller);
        self::assertStringContainsString("api.resource('catalog_category_admin')", $script);
        self::assertStringContainsString('expected_grant_version', $script);
        self::assertStringContainsString('grantVersionCreate', $script);
        self::assertStringContainsString('scope_level: scopeLevel', $script);
        self::assertStringContainsString('categoryAdminReorder', $script);
        self::assertStringContainsString('categoryAdminSaveDisplay', $script);
        self::assertStringContainsString('categoryAdminListProductsForDelete', $script);
        self::assertStringContainsString('requiresGrantVersion', $script);
        self::assertStringContainsString('initTreeCollapse', $script);
        self::assertStringContainsString('weline.catalog.tree.collapsed', $script);
        self::assertStringContainsString("size: 'lg'", $script);
        self::assertStringContainsString('w-catalog-delete-products', $script);
        self::assertStringNotContainsString('#64748b', $script);
        self::assertStringNotContainsString('#b91c1c', $script);
        self::assertStringNotContainsString('alert(', $template . $script);
        self::assertStringNotContainsString('confirm(', $template . $script);

        self::assertStringContainsString("return 'catalog_category_admin';", $query);
        self::assertStringContainsString('categoryAdminReorder', $query);
        self::assertStringContainsString('categoryAdminSaveDisplay', $query);
        self::assertStringContainsString('categoryAdminListProductsForDelete', $query);
        self::assertStringContainsString("'name' => 'product_ids'", $query);
        self::assertStringContainsString("'name' => 'expected_grant_version'", $query);
        self::assertStringContainsString("'expected_grant_version' =>", $query);
        self::assertStringContainsString("'name' => 'store_id'", $query);
        self::assertStringContainsString("'name' => 'channel_id'", $query);
        self::assertStringContainsString("'name' => 'google_taxonomy_id'", $query);
        self::assertStringContainsString('data-testid="catalog-google-mapping"', $template);
        self::assertStringContainsString('data-label-layout="stacked"', $template);
        self::assertStringContainsString('w-field__label-row', $template);
        self::assertStringContainsString('id="catalog-google-taxonomy-id"', $template);
        self::assertStringContainsString('for="catalog-google-taxonomy-id"', $template);
        self::assertStringContainsString("'name' => 'image'", $query);
        self::assertStringContainsString("'name' => 'banner'", $query);
        self::assertStringContainsString("'name' => 'summary'", $query);
        self::assertStringContainsString("'name' => 'description'", $query);
        self::assertStringContainsString('google_taxonomy_id:', $script);
        self::assertStringContainsString('image:', $script);
        self::assertStringContainsString('banner:', $script);
        self::assertStringContainsString('catalog-category-image', $script);
        self::assertStringContainsString('catalog-category-banner', $script);
        self::assertStringContainsString(
            "public const ACL_SOURCE = 'Weline_Catalog::commerce:universal-catalog:categories';",
            $query,
        );

        self::assertStringContainsString('weline_catalog/backend/category/index', $menu);
        self::assertStringContainsString('website_id=0', $menu);
        self::assertStringContainsString('Weline_Catalog::commerce:universal-catalog:categories', $menu);
        self::assertStringContainsString('weline_catalog/backend/google-taxonomy/index', $menu);
        self::assertStringContainsString('Weline_Catalog::commerce:universal-catalog:google-taxonomy', $menu);
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
