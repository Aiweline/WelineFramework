<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanProjectionService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPlanProjectionServiceTest extends TestCase
{
    public function testBuildsReadOnlyProjectionFromBuildPlanContract(): void
    {
        $contract = (new AiSiteBuildPlanService())->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'execution_blueprint_draft' => [
                'pages' => [
                    'home_page' => [
                        'title' => 'Home',
                        'page_goal' => 'Explain the service clearly.',
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'title' => 'Explain the service clearly',
                                'goal' => 'Show the service value with a direct CTA.',
                                'field_plan' => [
                                    ['field' => 'description', 'sample' => 'A clear overview helps visitors understand the next step.'],
                                    ['field' => 'cta', 'sample' => 'Contact us'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $projection = (new AiSiteBuildPlanProjectionService())->build($contract);

        self::assertSame((string)$contract['contract_meta']['id'], $projection['source_contract_id']);
        self::assertSame('2.2', $projection['source_contract_version']);
        self::assertSame(true, $projection['never_feed_to_build']);
        self::assertSame('Example Site', $projection['site_name']);
        self::assertSame(1, $projection['page_count']);
        self::assertSame(1, $projection['block_count']);
        self::assertSame(3, $projection['task_count']);
        self::assertSame('premium_web_v1', $projection['design']['policy_id']);
        $titleKey = (string)$contract['pages'][0]['title_key'];
        $expectedTitle = $contract['content_manifest']['items'][$titleKey];
        if (\is_array($expectedTitle)) {
            $expectedTitle = $expectedTitle['text'] ?? $expectedTitle['value'] ?? '';
        }
        self::assertSame((string)$expectedTitle, $projection['pages'][0]['title']);
        self::assertSame('hero', $projection['pages'][0]['blocks'][0]['type']);
    }
}
