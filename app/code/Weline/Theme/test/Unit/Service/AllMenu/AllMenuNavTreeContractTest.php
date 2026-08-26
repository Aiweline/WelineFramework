<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\AllMenu;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;
use Weline\Theme\Service\AllMenu\PageCandidateService;

final class AllMenuNavTreeContractTest extends TestCase
{
    public function testNormalizeKeepsCrossTagsAndCustomNodes(): void
    {
        $normalizer = new MenuTreeNormalizer();
        $tree = $normalizer->normalize([
            [
                'tag' => 'page',
                'name' => 'About',
                'url' => '/about',
                'description' => 'hidden-on-storefront',
                'image' => '/x.png',
                'children' => [
                    [
                        'tag' => 'category',
                        'name' => 'Electronics',
                        'url' => '/category/electronics',
                        'children' => [
                            [
                                'tag' => 'custom',
                                'name' => 'Promo',
                                'url' => 'https://example.com/promo',
                                'children' => [
                                    [
                                        'tag' => 'custom',
                                        'name' => 'TooDeep',
                                        'url' => '/too-deep',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $tree);
        self::assertSame('page', $tree[0]['tag']);
        self::assertSame('hidden-on-storefront', $tree[0]['description'] ?? null);
        self::assertSame('category', $tree[0]['children'][0]['tag']);
        self::assertSame('custom', $tree[0]['children'][0]['children'][0]['tag']);
        // depth 4 child must be trimmed (max depth 3)
        self::assertSame([], $tree[0]['children'][0]['children'][0]['children']);
    }

    public function testToNavItemsKeepsSourceNameForI18n(): void
    {
        $normalizer = new MenuTreeNormalizer();
        $tree = $normalizer->normalize([
            [
                'tag' => 'custom',
                'name' => '关于我们',
                'url' => '/c',
                'description' => 'secret',
                'image' => '/img.png',
                'ref' => 'x:1',
                'name_i18n' => [
                    'en_US' => 'About Us',
                    'zh_Hans_CN' => '关于我们',
                ],
                'children' => [
                    ['tag' => 'page', 'name' => '帮助中心', 'url' => '/child', 'description' => 'nope'],
                ],
            ],
        ]);
        // Legacy per-locale maps must not be persisted on nodes.
        self::assertArrayNotHasKey('name_i18n', $tree[0]);
        $nav = $normalizer->toNavItems($tree);
        // text stays Chinese source key; Header headerEsc / WidgetI18n translates for current locale.
        self::assertSame('关于我们', $nav[0]['text']);
        self::assertSame('/c', $nav[0]['url']);
        self::assertArrayNotHasKey('tag', $nav[0]);
        self::assertSame('帮助中心', $nav[0]['children'][0]['text']);
        // Optional visual fields are preserved when present on source nodes.
        self::assertSame('secret', $nav[0]['description']);
        self::assertSame('nope', $nav[0]['children'][0]['description']);
    }

    public function testPageSeedStoresChineseSourceKeysWithoutLocaleMaps(): void
    {
        $pages = (new PageCandidateService())->defaultSeedTree();
        self::assertNotEmpty($pages);
        foreach ($pages as $node) {
            self::assertSame(MenuTreeNormalizer::TAG_PAGE, $node['tag']);
            self::assertNotSame('', (string)$node['name']);
            self::assertNotSame('', (string)$node['url']);
            self::assertArrayNotHasKey('name_i18n', $node);
        }
        $about = null;
        foreach ($pages as $node) {
            if (($node['url'] ?? '') === '/about') {
                $about = $node;
                break;
            }
        }
        self::assertNotNull($about);
        self::assertSame('关于我们', $about['name']);
    }

    public function testNormalizeKeepsNodeI18nAndFileImage(): void
    {
        $normalizer = new MenuTreeNormalizer();
        $tree = $normalizer->normalize([
            [
                'tag' => 'custom',
                'name' => '关于我们',
                'url' => '/about',
                'description' => '后台描述',
                'image' => ['type' => 'file-image', 'usage' => ['version' => 1, 'asset_id' => 'a1', 'locale_code' => 'zh_Hans_CN']],
                'i18n' => [
                    'name' => ['en_US' => 'About Us'],
                    'description' => ['en_US' => 'About page'],
                ],
            ],
        ]);

        self::assertSame('About Us', $tree[0]['i18n']['name']['en_US'] ?? null);
        self::assertSame('About page', $tree[0]['i18n']['description']['en_US'] ?? null);
        self::assertSame('file-image', $tree[0]['image']['type'] ?? null);
    }

    public function testToNavItemsResolvesNameAndDescriptionI18n(): void
    {
        $normalizer = new MenuTreeNormalizer();
        $tree = $normalizer->normalize([
            [
                'tag' => 'custom',
                'name' => '男装',
                'url' => '/category/clothing/men',
                'description' => '男装分类说明',
                'i18n' => [
                    'name' => [
                        'zh_Hans_CN' => '男装',
                        'en_US' => "Men's clothing",
                    ],
                    'description' => [
                        'zh_Hans_CN' => '男装分类说明',
                        'en_US' => 'Men apparel overview',
                    ],
                ],
                'children' => [
                    [
                        'tag' => 'custom',
                        'name' => '衬衫',
                        'url' => '/category/clothing/men/shirts',
                        'description' => '衬衫说明',
                        'i18n' => [
                            'name' => ['en_US' => 'Shirt'],
                            'description' => ['en_US' => 'Shirts and tops'],
                        ],
                    ],
                ],
            ],
        ]);

        $_SERVER['REQUEST_URI'] = '/en_US/';
        try {
            $nav = $normalizer->toNavItems($tree);
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }

        self::assertSame("Men's clothing", $nav[0]['text']);
        self::assertSame('Men apparel overview', $nav[0]['description']);
        self::assertSame('Shirt', $nav[0]['children'][0]['text']);
        self::assertSame('Shirts and tops', $nav[0]['children'][0]['description']);
    }

    public function testAllMenuWidgetAndParamWiringExist(): void
    {
        $widget = dirname(__DIR__, 4) . '/view/theme/frontend/widgets/navigation/all-menu/default.phtml';
        $schema = dirname(__DIR__, 4) . '/Ui/ParamSchema/all_menu_tree.php';
        $header = dirname(__DIR__, 4) . '/view/theme/frontend/partials/header/default.phtml';
        $navTreeType = dirname(__DIR__, 5) . '/Widget/Ui/ParamType/NavTreeType.php';
        $js = dirname(__DIR__, 5) . '/Widget/view/statics/js/widget-param-types.js';

        self::assertFileExists($widget);
        self::assertFileExists($schema);
        self::assertFileExists($navTreeType);
        $widgetSrc = (string)file_get_contents($widget);
        self::assertStringContainsString('@widget.code {all-menu}', $widgetSrc);
        self::assertStringContainsString('type="all_menu_tree"', $widgetSrc);
        self::assertStringContainsString('AllMenuTreeRegistry::publish', $widgetSrc);
        self::assertStringContainsString('js-header-drawer-trigger', $widgetSrc);

        $schemaDef = include $schema;
        self::assertSame('nav_tree', $schemaDef['base_type'] ?? null);
        self::assertArrayHasKey('item_schema', $schemaDef);

        $headerSrc = (string)file_get_contents($header);
        self::assertStringContainsString('<w:widget type="navigation" name="all-menu"', $headerSrc);
        self::assertStringContainsString('AllMenuTreeRegistry::hasPublished()', $headerSrc);

        $jsSrc = (string)file_get_contents($js);
        self::assertStringContainsString('initNavTreeEditors', $jsSrc);
        self::assertStringContainsString('w-nav-tree-add-custom', $jsSrc);
        self::assertStringContainsString('w-nav-tree-detail', $jsSrc);
        self::assertStringContainsString('w-nav-tree-badge', $jsSrc);
        self::assertStringContainsString('bindDropSurface', $jsSrc);
        self::assertStringContainsString('resolveDropMode', $jsSrc);
        self::assertStringContainsString('canNestUnder', $jsSrc);
        self::assertStringContainsString('w-nav-tree-drop-end', $jsSrc);
        self::assertStringContainsString('w-nav-tree-children', $jsSrc);
        self::assertStringContainsString('bootEl.value || bootEl.textContent', $jsSrc);

        $typeSrc = (string)file_get_contents($navTreeType);
        self::assertStringContainsString("return 'nav_tree'", $typeSrc);
        self::assertStringContainsString('data-w-component="nav-tree"', $typeSrc);
        self::assertStringContainsString('w-nav-tree-boot-data', $typeSrc);
        self::assertStringContainsString('_nav_tree_boot', $typeSrc);
        self::assertStringNotContainsString('application/json" id=', $typeSrc);

        self::assertStringContainsString('bootEl.value || bootEl.textContent', $jsSrc);
    }

    public function testWidgetConfigServiceExpandsAllMenuTreeBeforeRender(): void
    {
        $serviceSrc = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Widget/Service/WidgetConfigService.php'
        );

        self::assertStringContainsString(
            '$this->paramSchemaRegistry->expandParams($params)',
            $serviceSrc
        );
        self::assertStringContainsString(
            'expandParams([$key => $param])',
            $serviceSrc
        );
    }

    public function testCandidateEventsAreRegistered(): void
    {
        $themeEvents = dirname(__DIR__, 4) . '/etc/event.xml';
        $productEvents = dirname(__DIR__, 5) . '/Product/etc/event.xml';
        $themeSrc = (string)file_get_contents($themeEvents);
        $productSrc = (string)file_get_contents($productEvents);
        self::assertStringContainsString('Weline_Theme::all_menu_page_candidates', $themeSrc);
        self::assertStringContainsString('AllMenuPageCandidates', $themeSrc);
        self::assertStringContainsString('Weline_Theme::all_menu_category_candidates', $productSrc);
        self::assertStringContainsString('AllMenuCategoryCandidates', $productSrc);
    }
}
