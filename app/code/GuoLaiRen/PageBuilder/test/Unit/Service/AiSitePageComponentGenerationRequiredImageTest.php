<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationRequiredImageTest extends TestCase
{
    public function testRequiredImageWithoutGeneratorFailsInsteadOfDowngrading(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflection = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'prepareInlineImageAssetForComponentSpec');
        $reflection->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REQUIRED_IMAGE_ASSET_UNRESOLVED');

        $reflection->invoke($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => 'asset:home:proof:image',
            ],
            'renderContext' => [
                '_visual_contract' => ['required' => 1, 'slot_id' => 'asset:home:proof:image'],
            ],
        ]);
    }

    public function testRequiredImageGeneratorWritesVerifiedAssetFields(): void
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

        /** @var array<string,mixed> $context */
        $context = $reflection->invoke(
            $service,
            'home_page',
            ['content_locale' => 'fr_FR'],
            ['website_profile' => ['content_locale' => 'fr_FR']],
            ['runtime.content_locale' => 'fr_FR']
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
