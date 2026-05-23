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
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain the service clearly.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'content' => 'Explain the service clearly.',
                                'goal' => 'Show the service value with a direct CTA.',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Explain the service clearly'],
                                    ['field' => 'description', 'sample' => 'A clear overview helps visitors understand the next step.'],
                                    ['field' => 'button_text', 'sample' => 'Contact us'],
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
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Launch reliable AI workflows faster'],
                                    ['field' => 'description', 'sample' => 'Focused automation for teams'],
                                    ['field' => 'button_text', 'sample' => 'Start now'],
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
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Play fast. Win more.'],
                                    ['field' => 'description', 'sample' => 'Built for casual competitive gamers.'],
                                    ['field' => 'button_text', 'sample' => 'Play now'],
                                ],
                                'execution_script' => ['core_copy' => 'Play fast. Win more.'],
                            ],
                            [
                                'block_key' => 'game_showcase_or_features',
                                'content' => 'Gold accents on a dark luxury background with slight Bollywood energy.',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Quick entertainment highlights'],
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
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Featured Articles'],
                                    ['field' => 'description', 'sample' => 'Quick reads for competitive gamers.'],
                                    ['field' => 'button_text', 'sample' => 'Browse articles'],
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
}
