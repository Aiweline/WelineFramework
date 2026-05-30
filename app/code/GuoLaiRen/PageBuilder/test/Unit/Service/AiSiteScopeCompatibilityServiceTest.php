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

    public function testNormalizePageTypeLayoutsAcceptsLegacyComponentCodeShorthand(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $layouts = $service->normalizePageTypeLayouts([
            Page::TYPE_HOME => [
                'header' => ['component_code' => 'header_default'],
                'content' => ['component_code' => 'content_default'],
                'footer' => ['component_code' => 'footer_default'],
            ],
        ], [Page::TYPE_HOME]);

        $layout = $layouts[Page::TYPE_HOME];

        $this->assertSame('header-default', $layout['header']['component']);
        $this->assertSame('content-default', $layout['content'][0]['code'] ?? null);
        $this->assertSame('footer-default', $layout['footer']['component']);
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

    public function testNormalizeScopeUnwrapsNestedMaterializedPageResult(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [Page::TYPE_HOME],
            'pagebuilder_pages_by_type' => [
                'home_page_id' => 44,
                'preview_page_id' => 44,
                'preview_page_type' => Page::TYPE_HOME,
                'pagebuilder_pages_by_type' => [
                    Page::TYPE_HOME => [
                        'page_id' => 44,
                        'website_id' => 7,
                        'type' => Page::TYPE_HOME,
                        'title' => 'AI Home',
                        'handle' => 'ai-home',
                    ],
                ],
            ],
        ]);

        $this->assertSame(44, $scope['preview_page_id']);
        $this->assertSame(Page::TYPE_HOME, $scope['preview_page_type']);
        $this->assertSame(44, $scope['pagebuilder_pages_by_type'][Page::TYPE_HOME]['page_id'] ?? 0);
    }

    public function testNormalizeConfirmedPlanFlagRestoresStaleFlagFromConfirmedBuildPlan(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = [
            'plan_confirmed' => 0,
            'build_plan_confirmed' => 1,
            'build_plan_confirmed_at' => '2026-04-24 12:00:00',
            'build_plan_v2' => [
                'contract_meta' => ['status' => 'confirmed', 'signature' => 'build-plan-sig'],
                'blocks' => [['block_id' => 'hero']],
            ],
        ];

        $normalized = $service->normalizeConfirmedPlanFlag($scope);

        self::assertTrue($service->hasConfirmedStageOnePlanForBuildPlan($normalized));
        self::assertSame(1, (int)($normalized['plan_confirmed'] ?? 0));
        self::assertSame('2026-04-24 12:00:00', (string)($normalized['plan_confirmed_at'] ?? ''));
    }

    public function testStripDeprecatedScopeArtifactKeysRemovesLegacyTopLevelFields(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->stripDeprecatedScopeArtifactKeys([
            'execution_blueprint' => ['tasks' => []],
            'execution_blueprint_draft' => ['tasks' => []],
            'build_blueprint' => ['tasks' => []],
            'build_tasks' => ['page:home_page:hero' => ['status' => 'done']],
            'build_plan_v2' => ['blocks' => []],
            '_artifact_refs' => [
                'visual_edit' => [
                    'build_blueprint' => ['storage' => 'session_artifact_v1'],
                    'build_workbench' => ['storage' => 'session_artifact_v1'],
                ],
            ],
            'plan_workbench' => [
                'confirmed' => [
                    'execution_blueprint' => ['signature' => 'legacy'],
                    'signature' => 'confirmed',
                ],
            ],
        ]);

        self::assertArrayNotHasKey('execution_blueprint', $scope);
        self::assertArrayNotHasKey('execution_blueprint_draft', $scope);
        self::assertArrayNotHasKey('build_blueprint', $scope);
        self::assertArrayNotHasKey('build_tasks', $scope);
        self::assertArrayHasKey('build_plan_v2', $scope);
        self::assertArrayNotHasKey('build_blueprint', $scope['_artifact_refs']['visual_edit'] ?? []);
        self::assertArrayNotHasKey('execution_blueprint', $scope['plan_workbench']['confirmed'] ?? []);
    }

    public function testNormalizeConfirmedPlanFlagDoesNotRestoreWithoutConfirmedBuildPlan(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = [
            'plan_confirmed' => 0,
            'build_plan_confirmed' => 0,
            'build_plan_v2' => [
                'contract_meta' => ['status' => 'draft', 'signature' => 'draft-sig'],
                'blocks' => [['block_id' => 'hero']],
            ],
        ];

        $normalized = $service->normalizeConfirmedPlanFlag($scope);

        self::assertFalse($service->hasConfirmedStageOnePlanForBuildPlan($normalized));
        self::assertSame(0, (int)($normalized['plan_confirmed'] ?? 0));
    }

    public function testPersistedStageOnePlanRequiresUsableStrongContractPlanJson(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        self::assertFalse($service->hasPersistedStageOnePlan([
            'plan_json' => [
                'pages' => [
                    Page::TYPE_HOME => ['title' => 'Home'],
                ],
                'theme_design' => [
                    'tone' => 'Practical',
                    'typography' => 'Readable sans',
                ],
            ],
            'plan_structured' => [
                'pages' => [
                    Page::TYPE_HOME => ['title' => 'Home'],
                ],
            ],
            'plan_markdown' => '# Legacy seeded test plan',
            'plan_generated_at' => '2026-05-04 18:00:00',
        ]));

        self::assertTrue($service->hasPersistedStageOnePlan([
            'plan_json' => [
                'requirement_expansion' => [
                    'original_brief' => 'Build a SaaS website',
                    'expanded_brief' => 'Build a SaaS website for B2B buyers.',
                    'planning_summary' => 'Create a clear conversion path.',
                    'site_goal' => 'Book demos.',
                    'page_strategy' => [
                        ['page_type' => Page::TYPE_HOME, 'intent' => 'Explain value'],
                    ],
                ],
                'theme_design' => [
                    'theme_purpose' => 'Build trust and guide demos.',
                    'selection_reason' => 'The SaaS website brief needs a trust-first system.',
                    'color_scheme' => [
                        'primary' => '#123456',
                        'accent' => '#abcdef',
                    ],
                    'typography_spacing_radius' => [
                        'font_family' => 'Aptos',
                        'spacing_scale' => '8px grid',
                    ],
                    'visual_keywords' => ['trust', 'clarity'],
                ],
                'shared_components' => [
                    'header' => [
                        'goal' => 'Guide visitors.',
                        'implementation_detail' => 'Logo, nav, CTA.',
                    ],
                    'footer' => [
                        'goal' => 'Close with trust.',
                        'implementation_detail' => 'Links and contact.',
                    ],
                ],
            ],
        ]));
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

    public function testNormalizePageTypesDefaultsToHomeAndAbout(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $this->assertSame([Page::TYPE_HOME, Page::TYPE_ABOUT], $service->normalizePageTypes([]));
        $this->assertSame([Page::TYPE_HOME, Page::TYPE_ABOUT], $service->normalizePageTypes(''));
    }

    public function testNormalizeScopeKeepsSelectedSkillCodes(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'selected_skill_codes' => [' claude-design ', '', 'claude-design', 'custom-skill'],
        ]);

        $this->assertSame(['claude-design', 'custom-skill'], $scope['selected_skill_codes']);
    }

    public function testNormalizeScopeKeepsAiBlockLookupIdentifiers(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [Page::TYPE_HOME],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'blocks' => [
                        [
                            'block_id' => 'home-page-hero',
                            'type' => 'ai_generated_section',
                            'component_code' => 'content/home-page-hero',
                            'section_code' => 'home:content/home-page-hero',
                            'component' => 'content/home-page-hero',
                            'code' => 'content/home-page-hero',
                            'block_key' => 'home-page-hero',
                            'task_key' => 'home:content/home-page-hero',
                            'html' => '<section>Hero</section>',
                        ],
                    ],
                ],
            ],
        ]);

        $block = $scope['virtual_pages_by_type'][Page::TYPE_HOME]['blocks'][0] ?? [];
        $this->assertSame('content/home-page-hero', $block['component_code'] ?? null);
        $this->assertSame('home:content/home-page-hero', $block['section_code'] ?? null);
        $this->assertSame('content/home-page-hero', $block['component'] ?? null);
        $this->assertSame('content/home-page-hero', $block['code'] ?? null);
        $this->assertSame('home-page-hero', $block['block_key'] ?? null);
        $this->assertSame('home:content/home-page-hero', $block['task_key'] ?? null);
    }

    public function testSessionScopeWhitelistKeepsSelectedSkillCodes(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'selected_skill_codes'", $source);
    }

    public function testNormalizeScopeMapsLegacyDefaultPageTypesToCurrentDefaultSelectionWhenSelectionWasNotCustomized(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT],
        ]);

        $this->assertSame([Page::TYPE_HOME, Page::TYPE_ABOUT], $scope['page_types']);
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

    public function testNormalizeScopeKeepsRequiredHomeOnlyWhenCustomizedSelectionIsEmpty(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [],
            AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
        ]);

        $this->assertSame([Page::TYPE_HOME], $scope['page_types']);
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

    public function testBuildVirtualPagesByTypeLocalizesFallbackTitlesForEnglishLocale(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $virtualPages = $service->buildVirtualPagesByType(
            [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_TERMS_OF_SERVICE],
            [
                'website_profile' => [
                    'site_title' => 'Teenipiya',
                    'default_locale' => 'en_US',
                ],
                'default_locale' => 'en_US',
            ],
            false
        );

        self::assertSame('Teenipiya', (string)($virtualPages[Page::TYPE_HOME]['title'] ?? ''));
        self::assertSame('About', (string)($virtualPages[Page::TYPE_ABOUT]['title'] ?? ''));
        self::assertSame('Terms of Service', (string)($virtualPages[Page::TYPE_TERMS_OF_SERVICE]['title'] ?? ''));
    }

    public function testNormalizeScopeRemovesPlanningLanguageFromVisitorPageMetadataForSelectedLocale(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());
        $planningCopy = "\u{5E2E}\u{52A9}\u{5DF4}\u{897F}\u{8BBF}\u{5BA2}\u{7406}\u{89E3}\u{5E76}\u{4E0B}\u{8F7D} APK";

        $scope = $service->normalizeScope([
            'default_locale' => 'pt_BR',
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'site_tagline' => "\u{8461}\u{8404}\u{7259}\u{8BED}\u{7F51}\u{7AD9}",
                'default_locale' => 'pt_BR',
                'seo' => [
                    'meta_title' => 'Teenipiya | ' . "\u{8461}\u{8404}\u{7259}\u{8BED}\u{7F51}\u{7AD9}",
                    'meta_description' => $planningCopy,
                    'meta_keywords' => 'Teenipiya, ' . $planningCopy . ', APK',
                ],
            ],
            'stage1_contract' => [
                'content_locale' => 'zh_Hans_CN',
                'plan_locale' => 'zh_Hans_CN',
                'shared_prompt_context' => ['content_locale' => 'zh_Hans_CN'],
                'shared_components' => [
                    'header' => ['content_locale' => 'zh_Hans_CN'],
                ],
            ],
            'page_types' => [Page::TYPE_HOME, Page::TYPE_BLOG_CATEGORY, Page::TYPE_CUSTOM],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'title' => "\u{9996}\u{9875}",
                    'meta_title' => "\u{9996}\u{9875} | Teenipiya",
                    'meta_description' => $planningCopy,
                    'meta_keywords' => 'Teenipiya, ' . $planningCopy . ', APK',
                    'ai_description' => $planningCopy,
                ],
                Page::TYPE_BLOG_CATEGORY => [
                    'page_type' => Page::TYPE_BLOG_CATEGORY,
                    'title' => "\u{535A}\u{5BA2}\u{5206}\u{7C7B}",
                    'meta_title' => "\u{535A}\u{5BA2}\u{5206}\u{7C7B} | Teenipiya",
                    'meta_description' => $planningCopy,
                    'ai_description' => $planningCopy,
                ],
                Page::TYPE_CUSTOM => [
                    'page_type' => Page::TYPE_CUSTOM,
                    'title' => "\u{81EA}\u{5B9A}\u{4E49}\u{9875}\u{9762}",
                    'meta_title' => "\u{81EA}\u{5B9A}\u{4E49}\u{9875}\u{9762} | Teenipiya",
                    'meta_description' => $planningCopy,
                    'ai_description' => $planningCopy,
                ],
            ],
        ]);

        foreach ([Page::TYPE_HOME, Page::TYPE_BLOG_CATEGORY, Page::TYPE_CUSTOM] as $pageType) {
            $page = $scope['virtual_pages_by_type'][$pageType] ?? [];
            self::assertDoesNotMatchRegularExpression('/[\x{4E00}-\x{9FFF}]/u', (string)($page['title'] ?? ''));
            self::assertDoesNotMatchRegularExpression('/[\x{4E00}-\x{9FFF}]/u', (string)($page['meta_title'] ?? ''));
            self::assertSame('', (string)($page['meta_description'] ?? ''));
            self::assertSame('', (string)($page['ai_description'] ?? ''));
        }
        self::assertSame('Teenipiya, APK', (string)($scope['virtual_pages_by_type'][Page::TYPE_HOME]['meta_keywords'] ?? ''));
        self::assertSame('pt_BR', (string)($scope['stage1_contract']['content_locale'] ?? ''));
        self::assertSame('pt_BR', (string)($scope['stage1_contract']['shared_prompt_context']['content_locale'] ?? ''));
        self::assertSame('pt_BR', (string)($scope['stage1_contract']['shared_components']['header']['content_locale'] ?? ''));
        self::assertSame('', (string)($scope['website_profile']['site_tagline'] ?? ''));
        self::assertSame('Teenipiya', (string)($scope['website_profile']['seo']['meta_title'] ?? ''));
        self::assertSame('', (string)($scope['website_profile']['seo']['meta_description'] ?? ''));
        self::assertSame('Teenipiya, APK', (string)($scope['website_profile']['seo']['meta_keywords'] ?? ''));
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
        // 非 CJK locale（en_US）下，home 标题不能保留 CJK 残留；当 site_title 在 scope 中存在时，
        // 走"首页用站点名"路径（'Legacy Demo'），否则回退到本地化 page type 默认值（'Home'）。
        $homeTitle = (string)$virtualPages[Page::TYPE_HOME]['title'];
        self::assertNotSame('', $homeTitle);
        self::assertSame(0, \preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $homeTitle), 'home title 不应残留 CJK 文字');
        self::assertContains($homeTitle, ['Legacy Demo', 'Home'], 'home title 必须是 site_title 或本地化 page label 之一');
    }

    public function testPortugueseVirtualPagesAndFooterLabelsDoNotFallBackToEnglish(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlocksBuildService::class);
        $blocksBuilder->expects($this->never())
            ->method('buildPlaceholderBlocksForPageType');

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);
        $scope = [
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'default_locale' => 'pt_BR',
                'content_locale' => 'pt_BR',
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => ['page_type' => Page::TYPE_HOME, 'title' => 'Home', 'locale' => 'pt_BR'],
                Page::TYPE_ABOUT => ['page_type' => Page::TYPE_ABOUT, 'title' => 'About', 'locale' => 'pt_BR'],
                Page::TYPE_CONTACT => ['page_type' => Page::TYPE_CONTACT, 'title' => 'Contact', 'locale' => 'pt_BR'],
                Page::TYPE_PRIVACY_POLICY => ['page_type' => Page::TYPE_PRIVACY_POLICY, 'title' => 'Privacy Policy', 'locale' => 'pt_BR'],
                Page::TYPE_TERMS_OF_SERVICE => ['page_type' => Page::TYPE_TERMS_OF_SERVICE, 'title' => 'Terms of Service', 'locale' => 'pt_BR'],
            ],
            'page_type_layouts' => [
                Page::TYPE_HOME => [
                    'header' => ['component' => 'header/ai-site-header', 'config' => []],
                    'content' => [],
                    'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
                ],
            ],
        ];

        $virtualPages = $service->buildVirtualPagesByType([
            Page::TYPE_HOME,
            Page::TYPE_ABOUT,
            Page::TYPE_CONTACT,
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
        ], $scope, false);

        self::assertSame('Início', $virtualPages[Page::TYPE_HOME]['title']);
        self::assertSame('Sobre', $virtualPages[Page::TYPE_ABOUT]['title']);
        self::assertSame('Contato', $virtualPages[Page::TYPE_CONTACT]['title']);
        self::assertSame('Política de Privacidade', $virtualPages[Page::TYPE_PRIVACY_POLICY]['title']);
        self::assertSame('Termos de Serviço', $virtualPages[Page::TYPE_TERMS_OF_SERVICE]['title']);

        $layout = $service->localizeSharedLayoutConfigForScope([
            'footer' => [
                'component' => 'footer/ai-site-footer',
                'config' => [
                    'links.column2_title' => 'Policy Info',
                    'links.column2_items' => [
                        ['label' => 'Privacy Policy', 'href' => '/privacy'],
                        ['label' => 'Terms of Service', 'href' => '/terms'],
                    ],
                    'links.column3_title' => 'All Pages',
                    'links.column3_items' => [
                        ['label' => 'Home', 'href' => '/'],
                        ['label' => 'About', 'href' => '/about'],
                        ['label' => 'Contact', 'href' => '/contact'],
                    ],
                ],
            ],
        ], $scope);

        self::assertSame('Informações legais', $layout['footer']['config']['links.column2_title']);
        self::assertSame('Política de Privacidade', $layout['footer']['config']['links.column2_items'][0]['label']);
        self::assertSame('Termos de Serviço', $layout['footer']['config']['links.column2_items'][1]['label']);
        self::assertSame('Todas as páginas', $layout['footer']['config']['links.column3_title']);
        self::assertSame('Início', $layout['footer']['config']['links.column3_items'][0]['label']);
        self::assertSame('Sobre', $layout['footer']['config']['links.column3_items'][1]['label']);
        self::assertSame('Contato', $layout['footer']['config']['links.column3_items'][2]['label']);
    }

    public function testEnglishSharedLayoutConfigLocalizesChineseHeaderAndFooterLabels(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $layout = $service->localizeSharedLayoutConfigForScope([
            'header' => [
                'component' => 'header/ai-site-header',
                'config' => [
                    'navigation.items' => "首页=>/\n关于我们=>/about",
                    'nav_items' => [
                        ['text' => '首页', 'href' => '/'],
                        ['text' => '关于我们', 'href' => '/about'],
                    ],
                    'cta.text' => '下载APK',
                ],
            ],
            'footer' => [
                'component' => 'footer/ai-site-footer',
                'config' => [
                    'brand.description' => '驱动印度玩家下载BharatPlay，建立品牌信任。',
                    'links.column1_title' => '重点页面',
                    'links.column1_items' => "首页=>/\n关于我们=>/about",
                    'links.column2_title' => '政策信息',
                    'links.column3_title' => '全部页面',
                    'copyright.text' => '保留所有权利。',
                ],
            ],
        ], [
            'content_locale' => 'en_US',
            'default_locale' => 'en_US',
            'website_profile' => ['content_locale' => 'en_US'],
        ]);

        self::assertSame("Home=>/\nAbout=>/about", $layout['header']['config']['navigation.items']);
        self::assertSame('Home', $layout['header']['config']['nav_items'][0]['text']);
        self::assertSame('About', $layout['header']['config']['nav_items'][1]['text']);
        self::assertSame('Download Now', $layout['header']['config']['cta.text']);
        self::assertSame('Featured Pages', $layout['footer']['config']['links.column1_title']);
        self::assertSame("Home=>/\nAbout=>/about", $layout['footer']['config']['links.column1_items']);
        self::assertSame('Policy Info', $layout['footer']['config']['links.column2_title']);
        self::assertSame('All Pages', $layout['footer']['config']['links.column3_title']);
        self::assertSame('All rights reserved.', $layout['footer']['config']['copyright.text']);
        self::assertSame(
            'A curated destination with clear information, trusted support, and simple next steps.',
            $layout['footer']['config']['brand.description']
        );
    }

    public function testPreviewContentLocalePrefersGeneratedLocaleOverStaleEnglishDefault(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizePreviewContentLocale([
            'content_locale' => 'en_US',
            'default_locale' => 'en_US',
            'website_profile' => [
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
            ],
            'plan_generated_locale' => 'zh_Hans_CN',
        ]);

        self::assertSame('zh_Hans_CN', $scope['content_locale'] ?? null);
        self::assertSame('zh_Hans_CN', $scope['ai_content_locale'] ?? null);
        self::assertSame('zh_Hans_CN', $scope['website_profile']['content_locale'] ?? null);
        self::assertSame('zh_Hans_CN', $service->resolvePreviewContentLocale($scope, 'en_US'));
        self::assertSame('pt_BR', $service->resolvePreviewContentLocale($scope, 'pt_BR'));

        $layout = $service->localizeSharedLayoutConfigForScope([
            'header' => [
                'component' => 'header/ai-site-header',
                'config' => [
                    'navigation.items' => '首页=>/',
                    'cta.text' => '立即加入牌桌',
                ],
            ],
            'footer' => [
                'component' => 'footer/ai-site-footer',
                'config' => [
                    'brand.description' => '让访问者在10秒内理解霓虹棋牌馆的游戏房间价值。',
                    'links.column1_title' => '重点页面',
                    'copyright.text' => '保留所有权利。',
                ],
            ],
        ], $scope);

        self::assertSame('首页=>/', $layout['header']['config']['navigation.items']);
        self::assertSame('立即加入牌桌', $layout['header']['config']['cta.text']);
        self::assertSame('重点页面', $layout['footer']['config']['links.column1_title']);
        self::assertSame('保留所有权利。', $layout['footer']['config']['copyright.text']);

        $normalized = $service->normalizeScope($service->normalizePreviewContentLocale([
            'content_locale' => 'en_US',
            'default_locale' => 'en_US',
            'website_profile' => ['content_locale' => 'en_US', 'default_locale' => 'en_US'],
            'plan_generated_locale' => 'zh_Hans_CN',
            'page_types' => [Page::TYPE_HOME],
            'page_type_layouts' => [
                Page::TYPE_HOME => [
                    'header' => [
                        'component' => 'header/ai-site-header',
                        'config' => [
                            'navigation.items' => '首页=>/',
                            'cta.text' => '立即加入牌桌',
                        ],
                    ],
                    'content' => [],
                    'footer' => [
                        'component' => 'footer/ai-site-footer',
                        'config' => [
                            'brand.description' => '让访问者在10秒内理解霓虹棋牌馆的游戏房间价值。',
                            'links.column1_title' => '重点页面',
                            'copyright.text' => '保留所有权利。',
                        ],
                    ],
                ],
            ],
        ]));

        self::assertSame('zh_Hans_CN', $normalized['content_locale'] ?? null);
        self::assertSame('立即加入牌桌', $normalized['page_type_layouts'][Page::TYPE_HOME]['header']['config']['cta.text'] ?? null);
        self::assertSame('重点页面', $normalized['page_type_layouts'][Page::TYPE_HOME]['footer']['config']['links.column1_title'] ?? null);
    }

    public function testNormalizeScopePrefersSelectedLocaleOverStalePlanLocale(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'content_locale' => 'zh_Hans_CN',
            'plan_generated_locale' => 'zh_Hans_CN',
            'default_locale' => 'pt_BR',
            'website_profile' => [
                'content_locale' => 'zh_Hans_CN',
                'default_locale' => 'pt_BR',
            ],
            'plan_json' => [
                'content_locale' => 'zh_Hans_CN',
                'i18n' => ['content_locale' => 'zh_Hans_CN'],
            ],
        ]);

        self::assertSame('pt_BR', $scope['content_locale'] ?? null);
        self::assertSame('pt_BR', $scope['website_profile']['content_locale'] ?? null);
        self::assertArrayNotHasKey('execution_blueprint', $scope);
        self::assertSame('pt_BR', $scope['plan_json']['content_locale'] ?? null);
        self::assertSame('pt_BR', $scope['plan_json']['i18n']['content_locale'] ?? null);
        self::assertSame(['pt_BR'], \array_slice($scope['locales'] ?? [], 0, 1));
    }

    public function testBuildVirtualPagesLocalizesStaleChinesePageTitlesToSelectedLocale(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $virtualPages = $service->buildVirtualPagesByType(
            [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT],
            [
                'default_locale' => 'pt_BR',
                'website_profile' => ['default_locale' => 'pt_BR'],
                'virtual_pages_by_type' => [
                    Page::TYPE_HOME => ['page_type' => Page::TYPE_HOME, 'title' => '首页'],
                    Page::TYPE_ABOUT => ['page_type' => Page::TYPE_ABOUT, 'title' => '关于我们'],
                    Page::TYPE_CONTACT => ['page_type' => Page::TYPE_CONTACT, 'title' => '联系我们'],
                ],
            ],
            false
        );

        self::assertSame('Início', $virtualPages[Page::TYPE_HOME]['title'] ?? null);
        self::assertSame('Sobre', $virtualPages[Page::TYPE_ABOUT]['title'] ?? null);
        self::assertSame('Contato', $virtualPages[Page::TYPE_CONTACT]['title'] ?? null);
    }

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
