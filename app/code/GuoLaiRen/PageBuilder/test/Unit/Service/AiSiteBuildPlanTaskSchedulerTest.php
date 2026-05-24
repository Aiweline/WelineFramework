<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractBuilder;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanTaskScheduler;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPlanTaskSchedulerTest extends TestCase
{
    public function testBuildConfirmationScopePatchConfirmsBuildPlanAndProjection(): void
    {
        $patch = (new AiSiteBuildPlanTaskScheduler())->buildConfirmationScopePatch([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain the service clearly.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'content' => 'Explain the service clearly.',
                                'goal' => 'Show the service value with a direct CTA.',
                                'page_flow_role' => 'opening_conversion',
                                'visual_signature' => $this->visualSignature('hero'),
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Explain the service clearly'],
                                    ['field' => 'description', 'sample' => 'A clear overview helps visitors understand the next step.'],
                                    ['field' => 'button_text', 'sample' => 'Schedule the service walkthrough'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        self::assertSame(1, $patch['build_plan_confirmed']);
        self::assertSame(1, $patch['has_build_plan_v2']);
        self::assertSame('virtual_theme', $patch['workspace_track']);
        self::assertSame('confirmed', $patch['build_plan_v2']['contract_meta']['status']);
        self::assertSame((string)$patch['build_plan_v2']['contract_meta']['id'], $patch['plan_projection']['source_contract_id']);
        self::assertSame(true, $patch['build_plan_v2_validation']['valid']);
        self::assertArrayHasKey('items', $patch['content_manifest']);
    }

    public function testBuildConfirmationScopePatchFailsWhenSourceTruthCoverageHasErrors(): void
    {
        $sourceTruth = (new SourceTruthContractBuilder())->build(
            ['site_title' => 'Example Site', 'brief_description' => 'Visitors must see the exact phrase diamond vip lounge.'],
            ['site_title' => 'Example Site'],
            [],
            '',
            ['home_page'],
            'en_US'
        );

        $patch = (new AiSiteBuildPlanTaskScheduler())->buildConfirmationScopePatch([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'source_truth_contract' => $sourceTruth,
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain the service clearly.',
                        'theme_alignment_summary' => 'Use the shared product theme with a clear CTA.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'content' => 'Launch reliable AI workflows faster.',
                                'goal' => 'Explain the offer.',
                                'page_flow_role' => 'opening_conversion',
                                'visual_signature' => $this->visualSignature('hero'),
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Launch reliable AI workflows faster'],
                                    ['field' => 'description', 'sample' => 'Focused automation for teams'],
                                    ['field' => 'button_text', 'sample' => 'Start the workflow assessment'],
                                ],
                                'execution_script' => ['core_copy' => 'Launch reliable AI workflows faster.'],
                            ],
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        self::assertSame(0, $patch['build_plan_confirmed']);
        self::assertFalse((bool)($patch['build_plan_v2_validation']['valid'] ?? true));
        self::assertStringContainsString('Missing must-include fact', \implode("\n", $patch['build_plan_v2_validation']['errors'] ?? []));
    }

    public function testBuildConfirmationScopePatchUsesPlanJsonForSourceTruthCoverage(): void
    {
        $sourceTruth = [
            'must_include_facts' => [
                ['id' => 'f48', 'text' => 'Casual competitive gamers', 'weight' => 10],
                ['id' => 'f51', 'text' => 'Wants quick entertainment', 'weight' => 10],
                ['id' => 'f61', 'text' => 'Gold accents', 'weight' => 10],
                ['id' => 'f62', 'text' => 'Dark luxury background', 'weight' => 10],
                ['id' => 'f64', 'text' => 'Slight Bollywood energy', 'weight' => 10],
            ],
            'required_home_blocks' => [],
            'must_not_do' => [],
        ];

        $patch = (new AiSiteBuildPlanTaskScheduler())->buildConfirmationScopePatch([
            'page_types' => ['home_page', 'blog_category'],
            'site_title' => 'Example Site',
            'brief_description' => 'Target casual competitive gamers who want quick entertainment with gold accents on a dark luxury background and slight Bollywood energy.',
            'default_locale' => 'en_US',
            'source_truth_contract' => $sourceTruth,
            'plan_json' => [
                'theme_design' => [
                    'color_scheme' => [
                        'primary' => '#d4af37',
                        'accent' => '#d4af37',
                        'background' => '#111111',
                    ],
                    'style_keywords' => ['gold accents', 'dark luxury background', 'slight Bollywood energy'],
                    'audience_profile' => ['casual competitive gamers', 'wants quick entertainment'],
                ],
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Welcome casual competitive gamers to quick entertainment.',
                        'theme_alignment_summary' => 'Gold accents on a dark luxury background with slight Bollywood energy.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'content' => 'Play fast. Win more.',
                                'goal' => 'Introduce the offer.',
                                'page_flow_role' => 'opening_conversion',
                                'visual_signature' => $this->visualSignature('hero'),
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Play fast. Win more.'],
                                    ['field' => 'description', 'sample' => 'Built for casual competitive gamers.'],
                                    ['field' => 'button_text', 'sample' => 'Start a quick match'],
                                ],
                                'execution_script' => ['core_copy' => 'Play fast. Win more.'],
                            ],
                            [
                                'block_key' => 'game_showcase_or_features',
                                'content' => 'Gold accents on a dark luxury background with slight Bollywood energy.',
                                'page_flow_role' => 'feature_showcase',
                                'visual_signature' => $this->visualSignature('game_showcase_or_features'),
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Quick entertainment highlights'],
                                    ['field' => 'description', 'sample' => 'Gold accents and slight Bollywood energy frame quick entertainment for casual competitive gamers.'],
                                    ['field' => 'button_text', 'sample' => 'Explore games'],
                                ],
                                'execution_script' => ['core_copy' => 'Built for casual competitive gamers.'],
                            ],
                        ],
                    ],
                    'blog_category' => [
                        'page_goal' => 'Browse categories quickly.',
                        'theme_alignment_summary' => 'Gold accents on dark luxury background.',
                        'blocks' => [
                            [
                                'block_key' => 'article_collection',
                                'content' => 'Browse featured articles and keep exploring.',
                                'goal' => 'Browse categories quickly.',
                                'page_flow_role' => 'content_discovery',
                                'visual_signature' => $this->visualSignature('article_collection'),
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Featured Articles'],
                                    ['field' => 'description', 'sample' => 'Quick reads for competitive gamers.'],
                                    ['field' => 'button_text', 'sample' => 'Browse competitive reads'],
                                ],
                                'execution_script' => ['core_copy' => 'Browse featured articles and keep exploring.'],
                            ],
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $errors = \implode("\n", $patch['build_plan_v2_validation']['errors'] ?? []);
        self::assertSame(1, $patch['build_plan_confirmed'], $errors);
        self::assertTrue((bool)($patch['build_plan_v2_validation']['valid'] ?? false), $errors);
        self::assertStringNotContainsString('Missing must-include fact', $errors);
        self::assertStringNotContainsString('planning or implementation copy: block.blog_category.article_collection.title', $errors);
    }

    public function testBuildConfirmationScopePatchFailsSourceTruthWhenPlanJsonMissing(): void
    {
        $patch = (new AiSiteBuildPlanTaskScheduler())->buildConfirmationScopePatch([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'source_truth_contract' => [
                'must_include_facts' => [
                    ['id' => 'f01', 'text' => 'Diamond VIP lounge', 'weight' => 10],
                ],
                'required_home_blocks' => [],
                'must_not_do' => [],
            ],
            'execution_blueprint_draft' => [
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain the service clearly.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'title' => 'Explain the service clearly',
                                'goal' => 'Show the service value with a direct CTA.',
                                'page_flow_role' => 'opening_conversion',
                                'visual_signature' => $this->visualSignature('hero'),
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'A clear overview helps visitors understand the next step.'],
                                    ['field' => 'button_text', 'sample' => 'Schedule the service walkthrough'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        self::assertSame(0, $patch['build_plan_confirmed']);
        self::assertStringContainsString(
            'plan_json',
            \implode("\n", $patch['build_plan_v2_validation']['errors'] ?? [])
        );
    }

    public function testBuildConfirmationUsesGeneratedPlanLocaleBeforeDefaultLocale(): void
    {
        $patch = (new AiSiteBuildPlanTaskScheduler())->buildConfirmationScopePatch([
            'page_types' => ['home_page'],
            'site_title' => 'Teenipiya',
            'brief_description' => '引导玩家安全下载APK并快速上手游戏。',
            'default_locale' => 'en_US',
            'plan_locale' => 'zh_Hans_CN',
            'plan_generated_locale' => 'zh_Hans_CN',
            'plan_json' => [
                'content_locale' => 'en_US',
                'i18n' => ['locale' => 'zh_Hans_CN'],
                'pages' => [
                    'home_page' => [
                        'page_goal' => '引导玩家安全下载APK并快速上手游戏。',
                        'blocks' => [
                            [
                                'block_key' => 'hero_download',
                                'content' => '三步完成APK下载，快速进入游戏大厅。',
                                'goal' => '让玩家明确下载路径和安全信任点。',
                                'page_flow_role' => 'opening_conversion',
                                'visual_signature' => $this->visualSignature('hero_download'),
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => '安全下载APK，快速进入游戏'],
                                    ['field' => 'description', 'sample' => '三步完成APK下载，查看玩法规则和安全说明。'],
                                    ['field' => 'button_text', 'sample' => '立即下载APK'],
                                ],
                                'execution_script' => ['core_copy' => '三步完成APK下载，快速进入游戏大厅。'],
                            ],
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $errors = \implode("\n", $patch['build_plan_v2_validation']['errors'] ?? []);
        self::assertSame(1, $patch['build_plan_confirmed'], $errors);
        self::assertSame('zh_Hans_CN', $patch['build_plan_v2']['i18n']['primary_locale'] ?? null);
        self::assertSame('zh_Hans_CN', $patch['content_manifest']['primary_locale'] ?? null);
        self::assertStringNotContainsString('locale leakage', $errors);
    }

    public function testBuildConfirmationScopePatchCarriesBlockContractIntoBuildPlanTasks(): void
    {
        $slotId = 'asset:home_page:proof:proof:image';
        $patch = (new AiSiteBuildPlanTaskScheduler())->buildConfirmationScopePatch([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly with proof and a direct CTA.',
            'default_locale' => 'en_US',
            'plan_json' => [
                'site_design_system' => [
                    'version' => '2.0',
                    'morphology_pool' => ['metric_proof_strip', 'editorial_split_media', 'conversion_cta_panel'],
                ],
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain the service clearly with proof and a direct CTA.',
                        'blocks' => [
                            [
                                'block_key' => 'proof',
                                'content' => 'Proof points explain the service clearly.',
                                'goal' => 'Show credible proof for the service.',
                                'page_flow_role' => 'proof',
                                'visual_signature' => $this->visualSignature('proof'),
                                'image_intent' => [
                                    'needs_image' => true,
                                    'image_role' => 'section_image',
                                    'image_subject' => 'proof visual for the service',
                                    'placement' => 'supporting_media',
                                    'visual_atmosphere' => 'trustworthy editorial proof',
                                    'image_treatment' => 'responsive crop with readable caption',
                                    'reuse_policy' => 'reuse_when_intent_matches',
                                    'css_motif' => '',
                                ],
                                'asset_requirements' => [
                                    ['slot_id' => $slotId, 'required' => true],
                                ],
                                'block_contract' => [
                                    'version' => '2.2',
                                    'page_flow_role' => 'proof',
                                    'block_goal' => 'Show credible proof for the service.',
                                    'morphology_id' => 'metric_proof_strip',
                                    'composition_pattern' => ['layout_keywords' => ['metrics', 'proof']],
                                    'media_strategy' => [
                                        'needs_real_image' => true,
                                        'asset_slot_id' => $slotId,
                                        'placement' => 'supporting_media',
                                        'image_subject' => 'proof visual for the service',
                                        'image_treatment' => 'responsive crop with readable caption',
                                    ],
                                    'responsive_contract' => ['desktop' => 'metric strip', 'mobile' => 'stack'],
                                    'acceptance_checks' => ['required_real_image_slot_present'],
                                ],
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Proof that the service works'],
                                    ['field' => 'description', 'sample' => 'Clear evidence helps visitors choose the next step.'],
                                    ['field' => 'button_text', 'sample' => 'Schedule the service walkthrough'],
                                ],
                                'execution_script' => ['core_copy' => 'Proof points explain the service clearly.'],
                            ],
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $errors = \implode("\n", $patch['build_plan_v2_validation']['errors'] ?? []);
        self::assertSame(1, $patch['build_plan_confirmed'], $errors);
        $blocks = $patch['build_plan_v2']['blocks'] ?? [];
        self::assertSame('metric_proof_strip', (string)($blocks[0]['block_contract']['morphology_id'] ?? ''));
        self::assertSame($slotId, (string)($blocks[0]['block_contract']['media_strategy']['asset_slot_id'] ?? ''));
        self::assertSame($slotId, (string)($blocks[0]['asset_requirements'][0]['slot_id'] ?? ''));
        $tasks = $patch['build_plan_v2']['tasks'] ?? [];
        self::assertSame('metric_proof_strip', (string)($tasks[2]['runtime_context']['block_contract']['morphology_id'] ?? ''));
        self::assertSame($slotId, (string)($tasks[2]['output_contract']['block_contract']['media_strategy']['asset_slot_id'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    private function visualSignature(string $blockKey): array
    {
        return [
            'composition_pattern' => $blockKey . ' section with a clear hierarchy and purposeful supporting detail',
            'spatial_rhythm' => 'balanced vertical rhythm across heading, copy, action, and evidence zones',
            'media_strategy' => 'integrated media or CSS motif that supports the message without decorative filler',
            'surface_treatment' => 'clean contrast and readable content surfaces',
            'interaction_pattern' => 'subtle focus and hover feedback on actionable elements',
        ];
    }
}
