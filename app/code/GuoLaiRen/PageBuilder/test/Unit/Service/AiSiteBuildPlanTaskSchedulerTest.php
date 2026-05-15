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
}
