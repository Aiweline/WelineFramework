<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AiSiteAgentPlanWorkbenchContractsTest extends TestCase
{
    public function testHydratesStageOnePreviewFromRefactoredWorkbenchContracts(): void
    {
        $scope = [
            'plan_json' => [],
            'plan_structured' => [],
            'plan_workbench' => [
                'confirmed' => [
                    'contracts' => [
                        'block_plan' => [
                            'payload' => [
                                'pages' => [
                                    'home_page' => [
                                        'page_goal' => 'Drive app downloads.',
                                        'blocks' => [
                                            [
                                                'block_key' => 'hero_download',
                                                'title' => 'Hero download',
                                                'goal' => 'Open with conversion CTA.',
                                            ],
                                        ],
                                    ],
                                ],
                                'shared_blocks' => [
                                    'header' => ['title' => 'Navigation'],
                                ],
                                'counts' => ['pages' => 1],
                            ],
                        ],
                        'page_contract' => [
                            'payload' => [
                                'page_types' => ['home_page'],
                                'navigation_plan' => ['items' => ['Home']],
                                'footer_plan' => ['items' => ['Terms']],
                            ],
                        ],
                        'design_manifest' => [
                            'payload' => [
                                'theme_design' => ['style_signature' => 'Curry Gold'],
                                'shared_components' => ['header' => ['component' => 'header/ai-site']],
                            ],
                        ],
                        'site_brief' => [
                            'payload' => [
                                'site_title' => 'Teenipiya',
                                'brief_description' => 'Indian card game APK website.',
                                'request_summary' => ['raw_requirement' => 'Build a card game SEO site.'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'hydrateStageOnePlanPayloadFromWorkbench');
        $method->setAccessible(true);

        $hydrated = $method->invoke($controller, $scope);

        self::assertSame('Drive app downloads.', $hydrated['plan_structured']['pages']['home_page']['page_goal'] ?? null);
        self::assertSame('Hero download', $hydrated['plan_json']['pages']['home_page']['blocks'][0]['title'] ?? null);
        self::assertSame(['Home'], $hydrated['plan_structured']['navigation_plan']['items'] ?? null);
        self::assertSame('Curry Gold', $hydrated['plan_structured']['theme_design']['style_signature'] ?? null);
        self::assertSame('Teenipiya', $hydrated['plan_structured']['site_title'] ?? null);
    }
}
