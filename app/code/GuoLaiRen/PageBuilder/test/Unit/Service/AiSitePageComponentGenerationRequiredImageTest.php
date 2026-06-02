<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;

final class AiSitePageComponentGenerationRequiredImageTest extends TestCase
{
    public function testRequiredImageWithoutGeneratorDowngradesToCssMediaSurface(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'prepareInlineImageAssetForComponentSpec');
        $reflection->setAccessible(true);

        /** @var array<string,mixed> $spec */
        $spec = $reflection->invoke($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => 'asset:home:proof:image',
            ],
            'renderContext' => [
                '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:home:proof:image'],
            ],
        ]);

        self::assertSame('0', $spec['defaultConfig']['runtime.section_image_required'] ?? null);
        self::assertSame('1', $spec['defaultConfig']['runtime.section_image_unavailable'] ?? null);
        self::assertSame('generator_missing', $spec['defaultConfig']['runtime.section_image_unavailable_reason'] ?? null);
        self::assertSame(0, $spec['renderContext']['_visual_contract']['required'] ?? null);
        self::assertSame(1, $spec['renderContext']['_visual_contract']['image_unavailable'] ?? null);
        self::assertArrayNotHasKey('_required_image_assets', $spec['renderContext']);
        self::assertArrayNotHasKey('visual.image_url', $spec['defaultConfig']);
    }

    public function testRequiredImageProviderFailureDowngradesInsteadOfFailingSection(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'prepareInlineImageAssetForComponentSpec');
        $reflection->setAccessible(true);

        /** @var array<string,mixed> $spec */
        $spec = $reflection->invoke($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => 'asset:home:hero:image',
                'visual.image_url' => '/stale.jpg',
            ],
            'renderContext' => [
                '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:home:hero:image', 'final_url' => '/stale.jpg'],
                '_required_image_assets' => ['asset:home:hero:image' => '/stale.jpg'],
                '_inline_image_asset_generator' => static function (): void {
                    throw new \RuntimeException('VectorEngine API returned error (HTTP: 403): request id abc');
                },
            ],
        ]);

        self::assertSame('0', $spec['defaultConfig']['runtime.section_image_required'] ?? null);
        self::assertSame('1', $spec['defaultConfig']['runtime.section_image_unavailable'] ?? null);
        self::assertSame('generation_failed', $spec['defaultConfig']['runtime.section_image_unavailable_reason'] ?? null);
        self::assertSame('asset:home:hero:image', $spec['renderContext']['_inline_image_unavailable']['slot_id'] ?? null);
        self::assertArrayNotHasKey('visual.image_url', $spec['defaultConfig']);
        self::assertArrayNotHasKey('final_url', $spec['renderContext']['_visual_contract']);
        self::assertArrayNotHasKey('_required_image_assets', $spec['renderContext']);
    }

    public function testBuildQueueImageFailureSuspendsLaterInlineImageAttemptsForCurrentBuild(): void
    {
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspended');
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspend_reason');
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'prepareInlineImageAssetForComponentSpec');
        $reflection->setAccessible(true);

        $buildTask = [
            'task_key' => 'page:about_page:content/about-page-origin-story',
            'page_type' => 'about_page',
            'section_code' => 'content/about-page-origin-story',
        ];

        $first = $reflection->invoke($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => 'asset:first:image',
            ],
            'renderContext' => [
                '_build_plan_task' => $buildTask,
                '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:first:image'],
                '_inline_image_asset_generator' => static function (): void {
                    throw new \RuntimeException('VectorEngine timed out');
                },
            ],
        ]);

        $called = false;
        $second = $reflection->invoke($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => 'asset:second:image',
            ],
            'renderContext' => [
                '_build_plan_task' => \array_replace($buildTask, ['task_key' => 'page:blog_list:content/blog-list-resource-hero']),
                '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:second:image'],
                '_inline_image_asset_generator' => static function () use (&$called): array {
                    $called = true;
                    return ['final_url' => '/pub/media/should-not-run.jpg'];
                },
            ],
        ]);

        self::assertSame('generation_failed', $first['defaultConfig']['runtime.section_image_unavailable_reason'] ?? null);
        self::assertFalse($called);
        self::assertSame('deferred_after_failure', $second['defaultConfig']['runtime.section_image_unavailable_reason'] ?? null);
        self::assertSame('1', $second['defaultConfig']['runtime.section_image_deferred_retry'] ?? null);
        self::assertSame(1, $second['renderContext']['_visual_contract']['image_deferred'] ?? null);

        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspended');
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspend_reason');
    }

    public function testExplicitTestSwitchSkipsInlineImageProviderAndDefersRetry(): void
    {
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspended');
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspend_reason');
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'prepareInlineImageAssetForComponentSpec');
        $reflection->setAccessible(true);
        $called = false;

        /** @var array<string,mixed> $spec */
        $spec = $reflection->invoke($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => 'asset:test:image',
            ],
            'renderContext' => [
                '_scope' => [
                    'ai_site_build_options' => [
                        'skip_inline_image_generation' => 1,
                    ],
                ],
                '_build_plan_task' => [
                    'task_key' => 'page:home_page:content/home-page-hero',
                ],
                '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:test:image'],
                '_inline_image_asset_generator' => static function () use (&$called): array {
                    $called = true;
                    return ['final_url' => '/pub/media/should-not-run.jpg'];
                },
            ],
        ]);

        self::assertFalse($called);
        self::assertSame('0', $spec['defaultConfig']['runtime.section_image_required'] ?? null);
        self::assertSame('disabled_by_test_switch', $spec['defaultConfig']['runtime.section_image_unavailable_reason'] ?? null);
        self::assertSame('1', $spec['defaultConfig']['runtime.section_image_deferred_retry'] ?? null);
        self::assertSame(1, $spec['renderContext']['_visual_contract']['image_deferred'] ?? null);
        self::assertSame('disabled_by_test_switch', $spec['renderContext']['_visual_contract']['deferred_reason'] ?? null);
    }

    public function testQueueRequestContextSwitchSkipsInlineImageProviderAndDefersRetry(): void
    {
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspended');
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspend_reason');
        RequestContext::set('pagebuilder.ai.inline_image_generation.disabled', true);

        try {
            $service = new AiSitePageComponentGenerationService();
            $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'prepareInlineImageAssetForComponentSpec');
            $reflection->setAccessible(true);
            $called = false;

            /** @var array<string,mixed> $spec */
            $spec = $reflection->invoke($service, [
                'region' => 'content',
                'defaultConfig' => [
                    'runtime.section_image_required' => '1',
                    'runtime.section_image_slot_id' => 'asset:test:queue-context-image',
                ],
                'renderContext' => [
                    '_build_plan_task' => [
                        'task_key' => 'page:blog_list:content/blog-list-resource-hero',
                    ],
                    '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:test:queue-context-image'],
                    '_inline_image_asset_generator' => static function () use (&$called): array {
                        $called = true;
                        return ['final_url' => '/pub/media/should-not-run.jpg'];
                    },
                ],
            ]);

            self::assertFalse($called);
            self::assertSame('0', $spec['defaultConfig']['runtime.section_image_required'] ?? null);
            self::assertSame('disabled_by_test_switch', $spec['defaultConfig']['runtime.section_image_unavailable_reason'] ?? null);
            self::assertSame('1', $spec['defaultConfig']['runtime.section_image_deferred_retry'] ?? null);
            self::assertSame(1, $spec['renderContext']['_visual_contract']['image_deferred'] ?? null);
        } finally {
            RequestContext::remove('pagebuilder.ai.inline_image_generation.disabled');
            RequestContext::remove('pagebuilder.ai.inline_image_generation.suspended');
            RequestContext::remove('pagebuilder.ai.inline_image_generation.suspend_reason');
        }
    }

    public function testRequiredImageGeneratorWritesVerifiedAssetFields(): void
    {
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspended');
        RequestContext::remove('pagebuilder.ai.inline_image_generation.suspend_reason');
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'prepareInlineImageAssetForComponentSpec');
        $reflection->setAccessible(true);

        /** @var array<string,mixed> $spec */
        $spec = $reflection->invoke($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => 'asset:home:proof:image',
                'content.heading' => 'Proof section',
            ],
            'renderContext' => [
                '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:home:proof:image'],
                '_inline_image_asset_generator' => static function (): array {
                    return ['final_url' => '/pub/media/page-build/site/ai-generated/proof.jpg'];
                },
            ],
        ]);

        self::assertSame('/pub/media/page-build/site/ai-generated/proof.jpg', $spec['defaultConfig']['visual.image_url'] ?? null);
        self::assertSame('/pub/media/page-build/site/ai-generated/proof.jpg', $spec['renderContext']['verified_assets']['asset:home:proof:image'] ?? null);
        self::assertSame('/pub/media/page-build/site/ai-generated/proof.jpg', $spec['renderContext']['_visual_contract']['final_url'] ?? null);
    }

    public function testHttpColon429ImageProviderSaturationIsRetryable(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'shouldRetryInlineImageGeneration');
        $reflection->setAccessible(true);

        $retry = $reflection->invoke(
            $service,
            new \RuntimeException('VectorEngine API returned error (HTTP: 429): 当前分组上游负载已饱和，请稍后再试')
        );

        self::assertTrue((bool)$retry);
    }

    public function testUnavailableImagePromptAllowsCssMediaWithoutFakeUrls(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'buildSectionVisualContractPromptAddon');
        $reflection->setAccessible(true);

        $prompt = (string)$reflection->invoke($service, [
            'required' => 0,
            'slot_id' => 'asset:home:hero:image',
            'image_unavailable' => 1,
            'unavailable_reason' => 'generation_failed',
            'fallback_strategy' => 'css_product_ui_media',
        ]);

        self::assertStringContainsString('image_required: no', $prompt);
        self::assertStringContainsString('image_unavailable: yes', $prompt);
        self::assertStringContainsString('CSS-only or product-UI media surface', $prompt);
        self::assertStringContainsString('Do not invent image URLs', $prompt);
    }

    public function testBlockContractPromptContainsMorphologySlotAcceptanceAndForbiddenRepetition(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'buildBlockContractPrompt');
        $reflection->setAccessible(true);

        $prompt = (string)$reflection->invoke(
            $service,
            ['runtime.section_image_slot_id' => 'page:home_page:proof:proof:image'],
            [],
            [
                'runtime_context' => [
                    'content_locale' => 'ar_SA',
                    'language_contract' => [
                        'source_of_truth_locale' => 'ar_SA',
                        'visible_copy_rule' => 'All visitor-facing copy uses ar_SA.',
                    ],
                    'block_contract' => [
                        'contract_v2' => [
                            'version' => '2.2',
                            'page_flow_role' => 'proof',
                            'block_goal' => 'Show proof.',
                            'morphology_id' => 'metric_proof_strip',
                            'composition_pattern' => ['layout_keywords' => ['metric strip']],
                            'content_hierarchy' => ['primary' => 'Proof'],
                            'media_strategy' => [
                                'needs_real_image' => true,
                                'asset_slot_id' => 'page:home_page:proof:proof:image',
                            ],
                            'responsive_contract' => ['desktop' => 'grid', 'mobile' => 'stack'],
                            'diversity_constraints' => [
                                'previous_morphology_id' => 'editorial_split_media',
                                'must_differ_from_previous_block' => ['morphology_id', 'media_placement'],
                                'forbidden_repetition' => ['same title+paragraph+button skeleton'],
                            ],
                            'acceptance_checks' => ['must_use_required_asset_slot'],
                        ],
                    ],
                ],
            ]
        );

        self::assertStringContainsString('CTX_CURRENT_BLOCK_CONTRACT', $prompt);
        self::assertStringContainsString('morphology_id', $prompt);
        self::assertStringContainsString('metric_proof_strip', $prompt);
        self::assertStringContainsString('page:home_page:proof:proof:image', $prompt);
        self::assertStringContainsString('acceptance_checks', $prompt);
        self::assertStringContainsString('forbidden repetition', $prompt);
        self::assertStringContainsString('forbidden_repetition', $prompt);
        self::assertStringContainsString('source_of_truth_locale', $prompt);
        self::assertStringContainsString('ar_SA', $prompt);
        self::assertStringContainsString('previous_morphology_id', $prompt);
        self::assertStringContainsString('must_differ_from_previous_block', $prompt);
        self::assertStringContainsString('morphology_id is binding', $prompt);
        self::assertStringContainsString('adjacent block guard', $prompt);
    }

    public function testRenderContextExposesPlainLocaleKeysForBlockGeneration(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'buildRenderContext');
        $reflection->setAccessible(true);
        $buildPlanTask = [
            'task_key' => 'page:home_page:content/home-page-hero',
            'task_type' => 'page_section',
            'page_type' => 'home_page',
            'region' => 'content',
            'section_code' => 'content/home-page-hero',
            'section_key' => 'hero',
            'block_key' => 'hero',
            'block_id' => 'home_page.hero',
            'block_type' => 'hero',
        ];
        $scope = [
            'website_profile' => ['content_locale' => 'fr_FR'],
            'build_plan_v2' => [
                'contract_meta' => [
                    'status' => 'confirmed',
                    'signature' => 'unit-render-context-locale',
                ],
                'i18n' => ['primary_locale' => 'fr_FR'],
                'pages' => [
                    [
                        'page_id' => 'home_page',
                        'page_type' => 'home_page',
                    ],
                ],
                'blocks' => [
                    [
                        'block_id' => 'home_page.hero',
                        'page_id' => 'home_page',
                        'page_type' => 'home_page',
                        'section_key' => 'hero',
                        'block_type' => 'hero',
                        'page_flow_role' => 'hero',
                        'content_keys' => ['hero.headline'],
                    ],
                ],
                'content_manifest' => [
                    'primary_locale' => 'fr_FR',
                    'items' => [
                        'hero.headline' => 'Bienvenue',
                    ],
                ],
            ],
        ];

        /** @var array<string,mixed> $context */
        $context = $reflection->invoke(
            $service,
            'home_page',
            ['content_locale' => 'fr_FR'],
            $scope,
            [
                'runtime.content_locale' => 'fr_FR',
                'runtime.build_plan_task_json' => (string)\json_encode(
                    $buildPlanTask,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
                ),
            ]
        );

        self::assertSame('fr_FR', $context['content_locale'] ?? null);
        self::assertSame('fr_FR', $context['locale'] ?? null);
        self::assertSame('fr_FR', $context['website_locale'] ?? null);
        self::assertSame('fr_FR', $context['_content_locale'] ?? null);
    }

    public function testFrozenTaskPromptPassesLanguageAndCurrentBlockContext(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'buildBuildPlanTaskPromptAddon');
        $reflection->setAccessible(true);

        $task = [
            'task_key' => 'page:home_page:content/home-page-proof',
            'task_type' => 'page_section',
            'page_type' => 'home_page',
            'block_key' => 'proof',
            'section_code' => 'content/home-page-proof',
            'page_flow_role' => 'proof',
            'runtime_context' => [
                'content_locale' => 'de_DE',
                'language_contract' => [
                    'source_of_truth_locale' => 'de_DE',
                    'visible_copy_rule' => 'All copy uses German.',
                ],
                'theme_context_snapshot' => ['palette' => ['accent' => '#2563eb']],
                'shared_prompt_context' => ['site_display_name' => 'Beispiel'],
                'site_context' => ['site_name' => 'Beispiel'],
            ],
            'plan_context' => [
                'page_goal' => 'Convert qualified visitors.',
                'block_goal' => 'Show proof before conversion.',
                'page_flow_role' => 'proof',
            ],
            'task_script' => [
                'story_goal' => 'Show proof before conversion.',
                'stage3_directive' => 'Build a proof section.',
                'field_content_requirements' => [
                    ['field' => 'headline', 'sample' => 'Reliable proof'],
                ],
            ],
            'block_task' => [
                'content_plan' => [
                    'content_copy' => [
                        ['field' => 'headline', 'copy' => 'Reliable proof'],
                    ],
                ],
                'style_plan' => ['visual_signature' => ['composition_pattern' => 'metric proof strip']],
            ],
            'visual_signature' => [
                'composition_pattern' => 'metric proof strip',
                'spatial_rhythm' => 'compact metric row',
                'media_strategy' => 'CSS proof device',
                'surface_treatment' => 'elevated strip',
            ],
            'image_intent' => ['needs_image' => false, 'css_motif' => 'metric rail'],
            'runtime' => [],
            'implementation_contract' => [
                'acceptance' => ['must render proof device'],
                'data_contract' => ['headline' => 'string'],
            ],
        ];

        $prompt = (string)$reflection->invoke($service, $task, 'section', []);

        self::assertStringContainsString('CTX_WEBSITE_LANGUAGE', $prompt);
        self::assertStringContainsString('source_of_truth_locale=de_DE', $prompt);
        self::assertStringContainsString('current_block_context', $prompt);
        self::assertStringContainsString('block_context_source', $prompt);
        self::assertStringContainsString('block-context execution rule', $prompt);
        self::assertStringContainsString('content execution rule', $prompt);
        self::assertStringContainsString('Reliable proof', $prompt);
    }
}
