<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthCoverageLinter;
use PHPUnit\Framework\TestCase;

final class SourceTruthCoverageLinterTest extends TestCase
{
    public function testLintPlanJsonAggregatesFactsAcrossPages(): void
    {
        $sourceTruth = [
            'must_include_facts' => [
                ['id' => 'f01', 'text' => 'Alpha unique phrase xyzzy', 'weight' => 10],
            ],
            'required_home_blocks' => ['hero_download'],
            'must_not_do' => [],
        ];
        $planJson = [
            'pages' => [
                'about_page' => [
                    'page_goal' => '',
                    'blocks' => [
                        [
                            'block_key' => 'story',
                            'content' => 'Alpha unique phrase xyzzy on inner page',
                        ],
                    ],
                ],
                'home_page' => [
                    'page_goal' => 'home',
                    'blocks' => [
                        ['block_key' => 'hero_download_banner', 'content' => 'cta'],
                    ],
                ],
            ],
        ];

        $lint = (new SourceTruthCoverageLinter())->lintPlanJson($sourceTruth, $planJson);
        self::assertSame([], $lint['missing_facts']);
        self::assertEqualsWithDelta(1.0, (float)$lint['coverage'], 0.0001);
    }

    public function testLintPlanJsonRequiredBlocksOnlyFromHomePage(): void
    {
        $sourceTruth = [
            'must_include_facts' => [['id' => 'f01', 'text' => 'fact one', 'weight' => 10]],
            'required_home_blocks' => ['hero_download'],
            'must_not_do' => [],
        ];
        $planJson = [
            'pages' => [
                'contact_page' => [
                    'blocks' => [['block_key' => 'contact_form', 'content' => 'fact one']],
                ],
                'home_page' => [
                    'blocks' => [['block_key' => 'hero_download_main', 'content' => 'fact one']],
                ],
            ],
        ];

        $lint = (new SourceTruthCoverageLinter())->lintPlanJson($sourceTruth, $planJson);
        self::assertSame([], $lint['missing_blocks']);
    }
}
