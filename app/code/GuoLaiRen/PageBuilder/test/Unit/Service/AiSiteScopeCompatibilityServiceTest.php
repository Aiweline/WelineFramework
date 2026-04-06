<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlocksBuildService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;

class AiSiteScopeCompatibilityServiceTest extends TestCase
{
    public function testNormalizeStageCollapsesLegacyPagebuilderStages(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $this->assertSame('virtual_theme', $service->normalizeStage('page_types'));
        $this->assertSame('virtual_theme', $service->normalizeStage('content'));
        $this->assertSame('visual_edit', $service->normalizeStage('visual_edit'));
        $this->assertSame('publish', $service->normalizeStage('publish'));
    }

    public function testNormalizePageTypeLayoutsConvertsLegacyRegionsIntoLayoutConfig(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $layouts = $service->normalizePageTypeLayouts([
            Page::TYPE_HOME => [
                'regions' => [
                    'header' => [
                        'component' => 'demo_header_nav',
                        'config' => ['variant' => 'primary'],
                    ],
                    'footer' => [
                        'component' => 'demo_footer_default',
                        'config' => ['copyright' => 'Demo'],
                    ],
                ],
                'components' => [
                    [
                        'component' => 'demo_content_hero',
                        'config' => ['title' => 'Hero'],
                        'sort_order' => 10,
                    ],
                ],
            ],
        ], [Page::TYPE_HOME]);

        $layout = $layouts[Page::TYPE_HOME];

        $this->assertSame('header-nav', $layout['header']['component']);
        $this->assertSame(['variant' => 'primary'], $layout['header']['config']);
        $this->assertSame('footer-default', $layout['footer']['component']);
        $this->assertSame('content-hero', $layout['content'][0]['code']);
        $this->assertSame(['title' => 'Hero'], $layout['content'][0]['config']);
        $this->assertSame(10, $layout['content'][0]['sort_order']);
    }

    public function testNormalizeScopeRestoresPreviewSelectionFromLegacyScope(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => \json_encode([Page::TYPE_ABOUT, Page::TYPE_CONTACT], \JSON_THROW_ON_ERROR),
            'website_id' => 77,
            'pagebuilder_pages_by_type' => [
                Page::TYPE_ABOUT => [
                    'page_id' => 22,
                    'website_id' => 77,
                    'type' => Page::TYPE_ABOUT,
                    'title' => 'About',
                    'handle' => 'about',
                ],
                Page::TYPE_HOME => [
                    'page_id' => 11,
                    'website_id' => 77,
                    'type' => Page::TYPE_HOME,
                    'title' => 'Home',
                    'handle' => 'home',
                ],
            ],
        ]);

        $this->assertSame(77, $scope['draft_website_id']);
        $this->assertSame([Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT], $scope['page_types']);
        $this->assertSame(11, $scope['preview_page_id']);
        $this->assertSame(Page::TYPE_HOME, $scope['preview_page_type']);
        $this->assertCount(2, $scope['preview_page_options']);
        $this->assertSame(11, $scope['preview_page_options'][0]['page_id']);
    }

    public function testBuildVirtualPagesByTypeHydratesLegacySingleContentPageIntoBlocks(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlocksBuildService::class);
        $blocksBuilder->expects($this->once())
            ->method('buildPlaceholderBlocksForPageType')
            ->with(Page::TYPE_HOME, $this->isType('array'), $this->isType('array'))
            ->willReturn([
                ['block_id' => 'home-page-hero', 'type' => 'hero', 'html' => '<section>hero</section>'],
                ['block_id' => 'home-page-highlights', 'type' => 'cards', 'html' => '<section>cards</section>'],
            ]);

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $virtualPages = $service->buildVirtualPagesByType([Page::TYPE_HOME], [
            'website_profile' => [
                'site_title' => 'Legacy Demo',
                'default_locale' => 'en_US',
            ],
            'page_type_layouts' => [
                Page::TYPE_HOME => [
                    'header' => ['component' => 'header/ai-site-header', 'config' => []],
                    'content' => [
                        [
                            'code' => 'content/home-page',
                            'enabled' => true,
                            'config' => [],
                            'instance_id' => '',
                            'sort_order' => 10,
                        ],
                    ],
                    'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
                ],
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'title' => '首页',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'ai_description' => 'legacy session',
                    'blocks' => [],
                ],
            ],
        ]);

        self::assertCount(2, $virtualPages[Page::TYPE_HOME]['blocks']);
        self::assertSame('home-page-hero', $virtualPages[Page::TYPE_HOME]['blocks'][0]['block_id']);
    }
}
