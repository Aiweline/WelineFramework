<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPromptContextAssembler;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPromptContextAssemblerTest extends TestCase
{
    public function testAssemblesOnlyCurrentTaskContextSlice(): void
    {
        $contract = (new AiSiteBuildPlanService())->buildFromScope([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
        ]);
        $task = $contract['tasks'][2];

        $context = (new AiSiteBuildPromptContextAssembler())->assemble($contract, $task);

        self::assertSame((string)$contract['contract_meta']['id'], $context['contract_id']);
        self::assertSame($task['task_id'], $context['task']['task_id']);
        self::assertSame('home_page.hero', $context['block']['block_id']);
        self::assertArrayHasKey('block.home_page.hero.title', $context['content_items']);
        self::assertNotEmpty($context['policy_slices']);
    }
}
