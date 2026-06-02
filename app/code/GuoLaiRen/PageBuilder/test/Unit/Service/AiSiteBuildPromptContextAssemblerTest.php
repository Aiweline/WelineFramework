<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPromptContextAssembler;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPromptContextAssemblerTest extends TestCase
{
    public function testAssemblesOnlyCurrentTaskContextSlice(): void
    {
        $contract = (new AiSiteBuildPlanService())->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
            'default_locale' => 'en_US',
            'plan_json' => [
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
        $tasks = (new AiSiteBuildTaskService(new AiSitePageBlueprintService()))->listTaskDefinitions([
            'page_types' => ['home_page'],
            'build_plan_v2' => $contract,
        ]);
        $pageTasks = \array_values(\array_filter(
            $tasks,
            static fn(array $task): bool => (string)($task['task_type'] ?? '') === 'page_section'
        ));
        $task = $pageTasks[0];

        $context = (new AiSiteBuildPromptContextAssembler())->assemble($contract, $task);

        self::assertSame((string)$contract['contract_meta']['id'], $context['contract_id']);
        self::assertSame($task['task_key'], $context['task']['task_key']);
        self::assertSame('home_page.hero', $context['block']['block_id']);
        self::assertArrayHasKey('block.home_page.hero.title', $context['content_items']);
        self::assertIsArray($context['policy_slices']);
        self::assertArrayHasKey('runtime_context', $context);
        self::assertArrayHasKey('output_contract', $context);
        self::assertArrayHasKey('acceptance', $context);
        self::assertSame('en_US', $context['content_locale']);
        self::assertSame('en_US', $context['language_contract']['source_of_truth_locale'] ?? null);
        self::assertArrayNotHasKey('policy_projection', $context);
        self::assertArrayNotHasKey('design_manifest', $context);
    }
}
