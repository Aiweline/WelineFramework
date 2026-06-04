<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlockNodesBuildService;
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

    public function testNormalizeStageCollapsesPlanningStageAliases(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $this->assertSame('plan', $service->normalizeStage('page_types'));
        $this->assertSame('plan', $service->normalizeStage('content'));
        $this->assertSame('visual_edit', $service->normalizeStage('visual_edit'));
        $this->assertSame('publish', $service->normalizeStage('publish'));
    }

    public function testNormalizePageTypeLayoutsRejectsRegionsComponentsShape(): void
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

        $this->assertSame('', $layouts[Page::TYPE_HOME]['header']['component']);
        $this->assertSame([], $layouts[Page::TYPE_HOME]['content']);
        $this->assertSame('', $layouts[Page::TYPE_HOME]['footer']['component']);
    }

    public function testNormalizePageTypeLayoutsAcceptsComponentCodeShorthand(): void
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

    public function testNormalizeScopeRestoresPreviewSelectionFromStoredScope(): void
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

    public function testNormalizeConfirmedPlanFlagUsesConfirmedPlanJson(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = [
            'plan_json' => [
                'confirmed' => 1,
                'confirmed_at' => '2026-04-24 12:00:00',
                'stage1_validation_report' => ['passed' => true],
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0, 'block_key' => 'hero'],
                    ],
                ],
            ],
        ];

        $normalized = $service->normalizeConfirmedPlanFlag($scope);

        self::assertTrue($service->hasConfirmedPlanJsonForBuild($normalized));
        self::assertSame(1, (int)($normalized['plan_json']['confirmed'] ?? 0));
        self::assertSame('2026-04-24 12:00:00', (string)($normalized['plan_json']['confirmed_at'] ?? ''));
    }

    public function testStripDuplicatedStageOneStorageFieldsDropsInternalPlanGenerationRuntimeFields(): void
    {
        $scope = AiSiteScopeCompatibilityService::stripDuplicatedStageOneStorageFields([
            '_internal_plan_generation_state' => ['large' => true],
            'plan_generation_progress' => ['done' => 2],
            'plan_generation_last_error' => ['message' => 'retryable'],
        ]);

        self::assertArrayNotHasKey('_internal_plan_generation_state', $scope);
        self::assertSame(['done' => 2], $scope['plan_generation_progress'] ?? null);
        self::assertSame(['message' => 'retryable'], $scope['plan_generation_last_error'] ?? null);
    }

    public function testNormalizeConfirmedPlanFlagDoesNotRestoreWithoutConfirmedPlanJson(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = [
            'plan_json' => [
                'confirmed' => 0,
                'pages' => [
                    Page::TYPE_HOME => [
                        'hero' => ['status' => 0],
                    ],
                ],
            ],
        ];

        $normalized = $service->normalizeConfirmedPlanFlag($scope);

        self::assertFalse($service->hasConfirmedPlanJsonForBuild($normalized));
        self::assertSame(0, (int)($normalized['plan_json']['confirmed'] ?? 1));
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
            'plan_markdown' => '# Removed seeded test plan',
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
                    'block_nodes' => [],
                ],
                Page::TYPE_ABOUT => [
                    'page_type' => Page::TYPE_ABOUT,
                    'title' => 'About',
                    'block_nodes' => [],
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
                    'block_nodes' => [
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

        $block = $scope['virtual_pages_by_type'][Page::TYPE_HOME]['block_nodes'][0] ?? [];
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

    public function testNormalizeScopeKeepsProvidedDefaultPageTypesWhenSelectionWasNotCustomized(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = $service->normalizeScope([
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT],
        ]);

        $this->assertSame([Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT], $scope['page_types']);
        $this->assertSame(0, $scope[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY]);
    }

    public function testNormalizeScopeKeepsCustomizedSubsetSelection(): void
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

    public function testBuildVirtualPagesByTypeHydratesSingleContentPageIntoBlocks(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlockNodesBuildService::class);
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
                'site_title' => 'Removed Demo',
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
                    'title' => 'Home',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'ai_description' => 'stored session',
                    'block_nodes' => [],
                ],
            ],
        ]);

        self::assertCount(2, $virtualPages[Page::TYPE_HOME]['block_nodes']);
        self::assertSame('home-page-hero', $virtualPages[Page::TYPE_HOME]['block_nodes'][0]['block_id']);
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
            'plan_json' => [
                'stage1_validation_contract' => [
                    'content_locale' => 'zh_Hans_CN',
                    'plan_locale' => 'zh_Hans_CN',
                    'shared_prompt_context' => ['content_locale' => 'zh_Hans_CN'],
                    'shared_components' => [
                        'header' => ['content_locale' => 'zh_Hans_CN'],
                    ],
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
        self::assertSame('pt_BR', (string)($scope['plan_json']['stage1_validation_contract']['content_locale'] ?? ''));
        self::assertSame('pt_BR', (string)($scope['plan_json']['stage1_validation_contract']['shared_prompt_context']['content_locale'] ?? ''));
        self::assertSame('pt_BR', (string)($scope['plan_json']['stage1_validation_contract']['shared_components']['header']['content_locale'] ?? ''));
        self::assertSame('', (string)($scope['website_profile']['site_tagline'] ?? ''));
        self::assertSame('Teenipiya', (string)($scope['website_profile']['seo']['meta_title'] ?? ''));
        self::assertSame('', (string)($scope['website_profile']['seo']['meta_description'] ?? ''));
        self::assertSame('Teenipiya, APK', (string)($scope['website_profile']['seo']['meta_keywords'] ?? ''));
    }

    /**
     * Current fallback contract.
     * Current fallback contract.
     */
    public function testBuildVirtualPagesByTypeRethrowsWhenAiHydrationHitsRecoverable402Failure(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlockNodesBuildService::class);
        $blocksBuilder->method('hydrateGeneratedBlockMetadata')
            ->willReturnCallback(static fn(array $block): array => $block);
        $blocksBuilder->expects($this->once())
            ->method('buildPlaceholderBlocksForPageType')
            ->with(Page::TYPE_HOME, $this->isType('array'), $this->isType('array'))
            ->willThrowException(new \RuntimeException('AI payload validation failed'));

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI payload validation failed');

        $service->buildVirtualPagesByType([Page::TYPE_HOME], [
            'website_profile' => [
                'site_title' => 'Removed Demo',
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
                    'title' => 'Home',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'ai_description' => 'stored session',
                    'block_nodes' => [],
                ],
            ],
        ]);
    }

    /**
     * Current fallback contract.
     */
    public function testBuildVirtualPagesByTypeRethrowsWhenAiComponentPayloadValidationFails(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlockNodesBuildService::class);
        $blocksBuilder->method('hydrateGeneratedBlockMetadata')
            ->willReturnCallback(static fn(array $block): array => $block);
        $blocksBuilder->expects($this->once())
            ->method('buildPlaceholderBlocksForPageType')
            ->with(Page::TYPE_HOME, $this->isType('array'), $this->isType('array'))
            ->willThrowException(new \RuntimeException('AI payload JSON validation failed: missing js_content'));

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI payload JSON validation failed');

        $service->buildVirtualPagesByType([Page::TYPE_HOME], [
            'website_profile' => [
                'site_title' => 'Removed Demo',
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
                    'title' => 'Home',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'ai_description' => 'stored session',
                    'block_nodes' => [],
                ],
            ],
        ]);
    }

    /**
     * Current fallback contract.
     * Current fallback contract.
     * Current fallback contract.
     */
    public function testBuildVirtualPagesByTypeHydratesExistingBlocksWithPlaceholderMetadata(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlockNodesBuildService::class);
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
                    'config' => ['site_title' => 'Removed Demo'],
                    'field_schema' => ['site_title' => ['type' => 'text']],
                ],
            ]);

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $virtualPages = $service->buildVirtualPagesByType([Page::TYPE_HOME], [
            'website_profile' => [
                'site_title' => 'Stored Demo',
                'default_locale' => 'en_US',
            ],
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'page_type' => Page::TYPE_HOME,
                    'title' => 'Home',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'block_nodes' => [
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
        $blocks = $virtualPages[Page::TYPE_HOME]['block_nodes'];
        self::assertIsArray($blocks);
        self::assertNotEmpty($blocks);
        $first = $blocks[0];
        self::assertSame('home-page-hero', $first['block_id']);
    }

    /**
     * Current fallback contract.
     * Current fallback contract.
     */
    public function testBuildVirtualPagesByTypeLeavesBlocksEmptyWhenAiGenerationDisabled(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlockNodesBuildService::class);
        $blocksBuilder->expects($this->never())
            ->method('buildPlaceholderBlocksForPageType');

        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer(), $blocksBuilder);

        $virtualPages = $service->buildVirtualPagesByType([Page::TYPE_HOME], [
            'website_profile' => [
                'site_title' => 'Removed Demo',
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
                    'title' => 'Home',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'block_nodes' => [],
                ],
            ],
        ], false);

        self::assertArrayHasKey(Page::TYPE_HOME, $virtualPages);
        self::assertSame([], $virtualPages[Page::TYPE_HOME]['block_nodes']);
        $homeTitle = (string)$virtualPages[Page::TYPE_HOME]['title'];
        self::assertNotSame('', $homeTitle);
        self::assertSame(0, \preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $homeTitle));
        self::assertContains($homeTitle, ['Stored Demo', 'Home']);
    }

    public function testPortugueseVirtualPagesAndFooterLabelsDoNotFallBackToEnglish(): void
    {
        $blocksBuilder = $this->createMock(AiSiteHtmlBlockNodesBuildService::class);
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

        self::assertSame('Home', $virtualPages[Page::TYPE_HOME]['title']);
        self::assertSame('About', $virtualPages[Page::TYPE_ABOUT]['title']);
        self::assertSame('Contact', $virtualPages[Page::TYPE_CONTACT]['title']);
        self::assertSame('Privacy Policy', $virtualPages[Page::TYPE_PRIVACY_POLICY]['title']);
        self::assertSame('Terms of Service', $virtualPages[Page::TYPE_TERMS_OF_SERVICE]['title']);

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

        self::assertSame('Policy Info', $layout['footer']['config']['links.column2_title']);
        self::assertSame('Privacy Policy', $layout['footer']['config']['links.column2_items'][0]['label']);
        self::assertSame('Terms of Service', $layout['footer']['config']['links.column2_items'][1]['label']);
        self::assertSame('All Pages', $layout['footer']['config']['links.column3_title']);
        self::assertSame('Home', $layout['footer']['config']['links.column3_items'][0]['label']);
        self::assertSame('About', $layout['footer']['config']['links.column3_items'][1]['label']);
        self::assertSame('Contact', $layout['footer']['config']['links.column3_items'][2]['label']);
    }

    public function testSharedLayoutConfigNormalizesGenericHeaderAndFooterLabels(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $layout = $service->localizeSharedLayoutConfigForScope([
            'header' => [
                'component' => 'header/ai-site-header',
                'config' => [
                    'nav_items' => [
                        ['text' => 'Home', 'href' => '/'],
                        ['text' => 'About', 'href' => '/about'],
                    ],
                    'cta.text' => 'Download Now',
                ],
            ],
            'footer' => [
                'component' => 'footer/ai-site-footer',
                'config' => [
                    'links.column2_title' => 'Policy Info',
                    'links.column2_items' => [
                        ['label' => 'Privacy Policy', 'href' => '/privacy'],
                    ],
                ],
            ],
        ], ['content_locale' => 'en_US']);

        self::assertSame('Home', $layout['header']['config']['nav_items'][0]['text']);
        self::assertSame('About', $layout['header']['config']['nav_items'][1]['text']);
        self::assertSame('Download Now', $layout['header']['config']['cta.text']);
        self::assertSame('Policy Info', $layout['footer']['config']['links.column2_title']);
        self::assertSame('Privacy Policy', $layout['footer']['config']['links.column2_items'][0]['label']);
    }
    public function testPreviewContentLocalePrefersGeneratedLocaleOverDefault(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $scope = [
            'content_locale' => 'pt_BR',
            'website_profile' => ['default_locale' => 'en_US'],
            'plan_json' => ['content_locale' => 'pt_BR'],
        ];

        self::assertSame('pt_BR', $service->resolvePreviewContentLocale($scope));
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
        self::assertSame('zh_Hans_CN', $scope['plan_json']['content_locale'] ?? null);
        self::assertSame('zh_Hans_CN', $scope['plan_json']['i18n']['content_locale'] ?? null);
        self::assertSame(['pt_BR'], \array_slice($scope['locales'] ?? [], 0, 1));
    }

    public function testBuildVirtualPagesNormalizesStoredGenericPageTitlesToFallbackLabels(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        $virtualPages = $service->buildVirtualPagesByType(
            [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT],
            [
                'default_locale' => 'pt_BR',
                'website_profile' => ['default_locale' => 'pt_BR'],
                'virtual_pages_by_type' => [
                    Page::TYPE_HOME => ['page_type' => Page::TYPE_HOME, 'title' => 'Home'],
                    Page::TYPE_ABOUT => ['page_type' => Page::TYPE_ABOUT, 'title' => 'About'],
                    Page::TYPE_CONTACT => ['page_type' => Page::TYPE_CONTACT, 'title' => 'Contact'],
                ],
            ],
            false
        );

        self::assertSame('Home', $virtualPages[Page::TYPE_HOME]['title'] ?? null);
        self::assertSame('About', $virtualPages[Page::TYPE_ABOUT]['title'] ?? null);
        self::assertSame('Contact', $virtualPages[Page::TYPE_CONTACT]['title'] ?? null);
    }

    public function testHtmlTrackHasCompleteBlocksRequiresEveryPageTypeToHaveBlocks(): void
    {
        $service = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());

        self::assertTrue($service->htmlTrackHasCompleteBlocks(
            [
                Page::TYPE_HOME => ['block_nodes' => [['block_id' => 'a']]],
                Page::TYPE_ABOUT => ['block_nodes' => [['block_id' => 'b']]],
            ],
            [Page::TYPE_HOME, Page::TYPE_ABOUT]
        ));
        self::assertFalse($service->htmlTrackHasCompleteBlocks(
            [
                Page::TYPE_HOME => ['block_nodes' => [['block_id' => 'a']]],
                Page::TYPE_ABOUT => ['block_nodes' => []],
            ],
            [Page::TYPE_HOME, Page::TYPE_ABOUT]
        ));
        self::assertFalse($service->htmlTrackHasCompleteBlocks(
            [Page::TYPE_HOME => ['block_nodes' => [['block_id' => 'a']]]],
            [Page::TYPE_HOME, Page::TYPE_ABOUT]
        ));
        self::assertFalse($service->htmlTrackHasCompleteBlocks([], [Page::TYPE_HOME]));
        self::assertFalse($service->htmlTrackHasCompleteBlocks([], []));
    }
}
