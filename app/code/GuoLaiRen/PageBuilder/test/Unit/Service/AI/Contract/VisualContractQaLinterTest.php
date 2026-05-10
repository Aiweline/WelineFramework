<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\VisualContractQaLinter;
use PHPUnit\Framework\TestCase;

final class VisualContractQaLinterTest extends TestCase
{
    public function testForbiddenVisualInRenderedPayloadIsHit(): void
    {
        $scope = [
            'reference_image_insights' => [
                'visual_contract' => [
                    'hero_composition' => [],
                    'forbidden_visuals' => ['purple gradient chrome'],
                ],
            ],
            'plan_json' => [],
        ];
        $payload = [
            'page_type_layouts' => [
                'home_page' => [
                    'content' => [
                        ['html' => '<div>Avoid purple gradient chrome on hero</div>'],
                    ],
                ],
            ],
        ];

        $result = (new VisualContractQaLinter())->analyze($scope, $payload);
        self::assertContains('purple gradient chrome', $result['forbidden_visuals_hit']);
    }

    public function testImplementableCueMissingFromHaystackIsUnused(): void
    {
        $scope = [
            'reference_image_insights' => [
                'visual_contract' => [
                    'hero_composition' => [
                        'nav' => 'unique_nav_cue_xyz',
                        'headline' => '',
                    ],
                    'forbidden_visuals' => [],
                ],
            ],
            'plan_json' => ['pages' => []],
        ];
        $payload = ['page_type_layouts' => []];

        $result = (new VisualContractQaLinter())->analyze($scope, $payload);
        self::assertContains('unique_nav_cue_xyz', $result['visual_contract_unused']);
    }
}
