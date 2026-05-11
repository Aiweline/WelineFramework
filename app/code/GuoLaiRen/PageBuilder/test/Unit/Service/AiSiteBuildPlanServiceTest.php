<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPlanServiceTest extends TestCase
{
    public function testBuildsValidBuildPlanV2FromStageOneExecutionBlueprint(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Convert qualified buyers with clear trust proof.',
            'execution_blueprint_draft' => [
                'signature' => 'stage1-signature',
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Show the product value and lead visitors to contact sales.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'title' => 'Launch reliable AI workflows',
                                'goal' => 'Show the core value with a direct CTA.',
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'Operational tooling for teams that need dependable automation.'],
                                    ['field' => 'cta', 'sample' => 'Book a workflow audit'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
        self::assertSame('2.2', $contract['contract_meta']['version']);
        self::assertSame('draft', $contract['contract_meta']['status']);
        self::assertCount(3, $contract['tasks']);
        self::assertSame(['shared:header', 'shared:footer', 'page:home_page:hero'], $contract['build_order']);
    }

    public function testConfirmMarksContractConfirmedAndProjectionIsReadOnly(): void
    {
        $service = new AiSiteBuildPlanService();

        $contract = $service->confirm($service->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
        ]));
        $projection = $service->projection($contract);

        self::assertTrue($service->validate($contract)['valid']);
        self::assertSame('confirmed', $contract['contract_meta']['status']);
        self::assertSame(true, $projection['never_feed_to_build']);
        self::assertSame((string)$contract['contract_meta']['id'], $projection['source_contract_id']);
    }
}
