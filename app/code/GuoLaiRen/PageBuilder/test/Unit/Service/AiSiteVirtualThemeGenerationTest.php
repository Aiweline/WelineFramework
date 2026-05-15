<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

/**
 * PHPUnit 下 AiSitePageComponentGenerationService 走桩数据（不调用真实 AI），
 * 验证 ensureAiGeneratedVirtualTheme 能落库并返回完整 page_type_layouts。
 */
final class AiSiteVirtualThemeGenerationTest extends TestCase
{
    public function testBuildNativeBlogPageLayoutUsesPageBuilderBlogComponents(): void
    {
        $service = new AiSiteVirtualThemeService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'buildNativeBlogPageLayout');
        $method->setAccessible(true);

        $layout = $method->invokeArgs($service, [
            Page::TYPE_BLOG_LIST,
            ['style_code' => 'default'],
            [],
            'header/ai-site-header',
            ['site_title' => 'Blog Site'],
            'footer/ai-site-footer',
            ['site_title' => 'Blog Site'],
            true,
        ]);

        self::assertIsArray($layout);
        self::assertTrue((bool)($layout['native_blog_template'] ?? false));
        self::assertSame('blog_list', $layout['blog_page_type'] ?? '');
        self::assertSame('blog-list', $layout['blog_component_code'] ?? '');
        self::assertSame('blog-list', $layout['content'][0]['code'] ?? '');
        self::assertSame('header/ai-site-header', $layout['header']['component'] ?? '');
        self::assertContains('blog_posts', $layout['blog_runtime_data_keys'] ?? []);
        self::assertSame(true, $layout['content'][0]['config']['show_pagination'] ?? null);
    }

    public function testVirtualThemeConfigAssetUrlsRejectBrokenLogoValues(): void
    {
        $service = new AiSiteVirtualThemeService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'sanitizeConfigAssetUrls');
        $method->setAccessible(true);

        $config = $method->invokeArgs($service, [[
            'brand.logo' => '/pub/media/page-build/site/ai-generated/',
            'logo.image' => 'data:image/svg+xml;base64,PHN2Zw==',
            'logo.url' => 'https://images.unsplash.com/photo-1.jpg',
            'brand.name' => 'Royal Games',
            'logo' => '/pub/media/page-build/site/ai-generated/logo.webp',
        ]]);

        self::assertSame('', $config['brand.logo'] ?? null);
        self::assertSame('', $config['logo.image'] ?? null);
        self::assertSame('', $config['logo.url'] ?? null);
        self::assertSame('Royal Games', $config['brand.name'] ?? null);
        self::assertSame('/pub/media/page-build/site/ai-generated/logo.webp', $config['logo'] ?? null);
    }

    public function testEnsureAiGeneratedVirtualThemeCreatesThemeAndLayoutsUnderPhpUnit(): void
    {
        $suffix = \bin2hex(\random_bytes(4));
        $siteTitle = 'PHPUnit VT ' . $suffix;

        $scope = [
            'site_title' => $siteTitle,
            'brief_description' => 'Unit test virtual theme generation scope.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_BLOG_LIST],
            'virtual_theme_id' => 0,
        ];
        $websiteProfile = [
            'site_title' => $siteTitle,
            'brief_description' => $scope['brief_description'],
            'site_tagline' => 'Tag',
        ];
        $pageTypes = [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_BLOG_LIST];

        $generation = new class extends AiSitePageComponentGenerationService {
            public function generateSharedComponents(array $websiteProfile, array $scope): array
            {
                return [
                    'header' => [
                        'code' => 'header/ai-site-header',
                        'name' => 'AI Site Header',
                        'phtml' => '<header><nav>Home</nav></header>',
                        'default_config' => ['site_title' => (string)($websiteProfile['site_title'] ?? 'Site')],
                    ],
                    'footer' => [
                        'code' => 'footer/ai-site-footer',
                        'name' => 'AI Site Footer',
                        'phtml' => '<footer><p>Footer</p></footer>',
                        'default_config' => ['site_title' => (string)($websiteProfile['site_title'] ?? 'Site')],
                    ],
                ];
            }

            public function generatePageSections(string $pageType, array $websiteProfile, array $scope): array
            {
                return [
                    'blueprint' => [
                        'page_label' => $pageType,
                    ],
                    'sections' => [
                        [
                            'code' => 'content/' . $pageType . '-unit-section',
                            'name' => $pageType . ' Unit Section',
                            'phtml' => '<section><h2>' . \htmlspecialchars($pageType, \ENT_QUOTES, 'UTF-8') . '</h2></section>',
                            'default_config' => ['headline' => $pageType . ' headline'],
                            'sort_order' => 100,
                            'key' => $pageType . '_unit_section',
                        ],
                    ],
                ];
            }
        };
        $service = new AiSiteVirtualThemeService(new AiSitePageBlueprintService(), $generation);

        $result = $service->ensureAiGeneratedVirtualTheme($scope, $websiteProfile, $pageTypes, [], 0);

        $virtualThemeId = (int)($result['virtual_theme_id'] ?? 0);
        self::assertGreaterThan(0, $virtualThemeId);
        self::assertArrayHasKey('page_type_layouts', $result);
        self::assertArrayHasKey(Page::TYPE_HOME, $result['page_type_layouts']);
        self::assertArrayHasKey(Page::TYPE_ABOUT, $result['page_type_layouts']);
        self::assertArrayHasKey(Page::TYPE_BLOG_LIST, $result['page_type_layouts']);

        $homeLayout = $result['page_type_layouts'][Page::TYPE_HOME];
        self::assertSame('header/ai-site-header', \trim((string)($homeLayout['header']['component'] ?? '')));
        self::assertSame('footer/ai-site-footer', \trim((string)($homeLayout['footer']['component'] ?? '')));
        self::assertNotEmpty($homeLayout['content'] ?? []);
        self::assertSame('1.0', (string)($homeLayout['version'] ?? ''));

        $blogLayout = $result['page_type_layouts'][Page::TYPE_BLOG_LIST];
        self::assertSame('blog-list', $blogLayout['content'][0]['code'] ?? '');
        self::assertTrue((bool)($blogLayout['native_blog_template'] ?? false));
        self::assertContains('blog_posts', $blogLayout['blog_runtime_data_keys'] ?? []);

        /** @var VirtualTheme $themeModel */
        $themeModel = ObjectManager::getInstance(VirtualTheme::class);
        $loaded = clone $themeModel;
        $loaded->clearData()->clearQuery()->load($virtualThemeId);
        self::assertSame($virtualThemeId, (int)$loaded->getId());

        $config = $loaded->getConfig();
        self::assertIsArray($config);
        self::assertArrayHasKey('virtual_page_layouts', $config);
        self::assertIsArray($config['virtual_page_layouts']);
    }
}
