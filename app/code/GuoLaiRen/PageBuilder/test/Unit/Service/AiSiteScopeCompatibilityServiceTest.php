<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlocksBuildService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;

class AiSiteScopeCompatibilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::remove('pagebuilder.workspace.placeholder.force_static');
    }

    protected function tearDown(): void
    {
        RequestContext::remove('pagebuilder.workspace.placeholder.force_static');
    }

    public function testNormalizeStageCollapsesLegacyPagebuilderStages(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $this->assertSame('plan', $service->normalizeStage('page_types'));
        $this->assertSame('plan', $service->normalizeStage('content'));
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

    public function testNormalizeScopeResolvesPreviewTypeFromVirtualPagesWhenMaterializedPageIsMissing(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'preview_page_type' => Page::TYPE_ABOUT,
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'title' => 'Home',
                    'blocks' => [],
                ],
                Page::TYPE_ABOUT => [
                    'page_type' => Page::TYPE_ABOUT,
                    'title' => 'About',
                    'blocks' => [],
                ],
            ],
        ]);

        $this->assertSame(0, $scope['preview_page_id']);
        $this->assertSame(Page::TYPE_ABOUT, $scope['preview_page_type']);
    }

    public function testNormalizePageTypesDefaultsToAllSupportedPageTypes(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $this->assertSame(\array_keys(Page::getPageTypes()), $service->normalizePageTypes([]));
        $this->assertSame(\array_keys(Page::getPageTypes()), $service->normalizePageTypes(''));
    }

    public function testNormalizeScopeExpandsLegacyDefaultPageTypesWhenSelectionWasNotCustomized(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT],
        ]);

        $this->assertSame(\array_keys(Page::getPageTypes()), $scope['page_types']);
        $this->assertSame(0, $scope[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY]);
    }

    public function testNormalizeScopeKeepsLegacySubsetWhenUserCustomizedSelection(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT],
            AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
        ]);

        $this->assertSame([Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT], $scope['page_types']);
        $this->assertSame(1, $scope[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY]);
    }

    public function testBuildVirtualPagesByTypeHydratesLegacySingleContentPageIntoBlocks(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlocksBuildService::class);
        $blocksBuilder->method('hydrateGeneratedBlockMetadata')
            ->willReturnCallback(static fn(array $block): array => $block);
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

    /**
     * 锁定当前真相：AI 生成抛出异常时生产代码不再自动降级到 static placeholder，异常会原样冒泡到调用方。
     * 历史上该路径带 fallback，现已被移除；若未来再度引入降级能力，应新增专门测试而非修改这条。
     */
    public function testBuildVirtualPagesByTypeRethrowsWhenAiHydrationHitsRecoverable402Failure(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlocksBuildService::class);
        $blocksBuilder->method('hydrateGeneratedBlockMetadata')
            ->willReturnCallback(static fn(array $block): array => $block);
        $blocksBuilder->expects($this->once())
            ->method('buildPlaceholderBlocksForPageType')
            ->with(Page::TYPE_HOME, $this->isType('array'), $this->isType('array'))
            ->willThrowException(new \RuntimeException('AI流式生成失败: AI API 错误 (HTTP 402, unknown_error): Insufficient Balance'));

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI API 错误 (HTTP 402');

        $service->buildVirtualPagesByType([Page::TYPE_HOME], [
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
    }

    /**
     * 锁定当前真相：AI 组件 JSON 校验失败时同样直接冒泡 RuntimeException，不自动走 static placeholder 路径。
     */
    public function testBuildVirtualPagesByTypeRethrowsWhenAiComponentPayloadValidationFails(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlocksBuildService::class);
        $blocksBuilder->method('hydrateGeneratedBlockMetadata')
            ->willReturnCallback(static fn(array $block): array => $block);
        $blocksBuilder->expects($this->once())
            ->method('buildPlaceholderBlocksForPageType')
            ->with(Page::TYPE_HOME, $this->isType('array'), $this->isType('array'))
            ->willThrowException(new \RuntimeException('AI 组件 JSON 校验失败：[js_content] 反引号 ` 会导致模板语法错误'));

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI 组件 JSON 校验失败');

        $service->buildVirtualPagesByType([Page::TYPE_HOME], [
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
    }

    /**
     * 锁定当前真相：当 scope 没有 page_type_layouts（shouldHydrateLegacyBlocks=true 的历史路径已改）时，
     * 生产代码只会通过 buildPlaceholderBlocksForPageType 补齐 blocks（不存在 static 专用 API），
     * 已保存的 blocks 记录在 hydrateEditableBlockMetadata 路径下会与 placeholder 元数据合并。
     */
    public function testBuildVirtualPagesByTypeHydratesExistingBlocksWithPlaceholderMetadata(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlocksBuildService::class);
        $blocksBuilder->method('hydrateGeneratedBlockMetadata')
            ->willReturnCallback(static fn(array $block): array => $block);
        $blocksBuilder->method('buildPlaceholderBlocksForPageType')
            ->with(Page::TYPE_HOME, $this->isType('array'), $this->isType('array'))
            ->willReturn([
                [
                    'block_id' => 'home-page-hero',
                    'type' => 'hero',
                    'html' => '<section>default hero</section>',
                    'config' => ['headline' => 'Default hero'],
                    'field_schema' => ['headline' => ['type' => 'text']],
                ],
                [
                    'block_id' => 'home-page-site-footer',
                    'type' => 'site_footer',
                    'html' => '<footer>footer</footer>',
                    'config' => ['site_title' => 'Legacy Demo'],
                    'field_schema' => ['site_title' => ['type' => 'text']],
                ],
            ]);

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $virtualPages = $service->buildVirtualPagesByType([Page::TYPE_HOME], [
            'website_profile' => [
                'site_title' => 'Legacy Demo',
                'default_locale' => 'en_US',
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'title' => '首页',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'blocks' => [
                        [
                            'block_id' => 'home-page-hero',
                            'type' => 'hero',
                            'html' => '<section>saved hero</section>',
                            'config' => [],
                            'field_schema' => [],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertArrayHasKey(Page::TYPE_HOME, $virtualPages);
        $blocks = $virtualPages[Page::TYPE_HOME]['blocks'];
        self::assertIsArray($blocks);
        self::assertNotEmpty($blocks);
        $first = $blocks[0];
        self::assertSame('home-page-hero', $first['block_id']);
    }

    /**
     * 锁定当前真相：$allowAiPlaceholderGeneration=false 时生产代码不会调用 AI builder，
     * 也不存在 static placeholder 旁路，因此 blocks 最终为空数组（由后续阶段负责显式填充）。
     */
    public function testBuildVirtualPagesByTypeLeavesBlocksEmptyWhenAiGenerationDisabled(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlocksBuildService::class);
        $blocksBuilder->expects($this->never())
            ->method('buildPlaceholderBlocksForPageType');

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
                    'blocks' => [],
                ],
            ],
        ], false);

        self::assertArrayHasKey(Page::TYPE_HOME, $virtualPages);
        self::assertSame([], $virtualPages[Page::TYPE_HOME]['blocks']);
        self::assertSame('首页', $virtualPages[Page::TYPE_HOME]['title']);
    }

    /**
     * 补充 htmlTrackHasCompleteBlocks 的正向/负向用例：所有 page type 必须同时具备非空 blocks 才算完整。
     */
    public function testHtmlTrackHasCompleteBlocksRequiresEveryPageTypeToHaveBlocks(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        self::assertTrue($service->htmlTrackHasCompleteBlocks(
            [
                Page::TYPE_HOME => ['blocks' => [['block_id' => 'a']]],
                Page::TYPE_ABOUT => ['blocks' => [['block_id' => 'b']]],
            ],
            [Page::TYPE_HOME, Page::TYPE_ABOUT]
        ));
        self::assertFalse($service->htmlTrackHasCompleteBlocks(
            [
                Page::TYPE_HOME => ['blocks' => [['block_id' => 'a']]],
                Page::TYPE_ABOUT => ['blocks' => []],
            ],
            [Page::TYPE_HOME, Page::TYPE_ABOUT]
        ));
        self::assertFalse($service->htmlTrackHasCompleteBlocks(
            [Page::TYPE_HOME => ['blocks' => [['block_id' => 'a']]]],
            [Page::TYPE_HOME, Page::TYPE_ABOUT]
        ));
        self::assertFalse($service->htmlTrackHasCompleteBlocks([], [Page::TYPE_HOME]));
        self::assertFalse($service->htmlTrackHasCompleteBlocks([], []));
    }
}
