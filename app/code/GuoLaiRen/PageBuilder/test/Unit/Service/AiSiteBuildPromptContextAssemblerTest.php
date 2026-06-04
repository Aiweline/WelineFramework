<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPromptContextAssembler;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPromptContextAssemblerTest extends TestCase
{
    public function testAssemblesOnlyCurrentTaskContextSlice(): void
    {
        $contract = [
            'contract_meta' => ['id' => 'plan-json-contract'],
            'site_brief' => ['site_name' => 'Example Site'],
            'content_manifest' => [
                'items' => [
                    'block.home_page.hero.title' => 'Explain the service clearly',
                    'block.home_page.hero.description' => 'A clear overview helps visitors understand the next step.',
                    'block.home_page.hero.cta' => 'Contact us',
                ],
            ],
            'pages' => [
                ['page_id' => 'home_page', 'page_type' => 'home_page', 'page_goal' => 'Explain the service clearly.'],
            ],
            'block_nodes' => [
                [
                    'block_id' => 'home_page.hero',
                    'page_id' => 'home_page',
                    'content_keys' => [
                        'block.home_page.hero.title',
                        'block.home_page.hero.description',
                        'block.home_page.hero.cta',
                    ],
                ],
            ],
        ];
        $task = [
            'task_key' => 'page:home_page:hero',
            'task_type' => 'page_section',
            'page_id' => 'home_page',
            'block_id' => 'home_page.hero',
            'runtime_context' => [
                'content_locale' => 'en_US',
            ],
        ];

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

    public function testContentLocaleComesFromTaskRuntimeContextOnly(): void
    {
        $contract = [
            'contract_meta' => ['id' => 'locale-contract'],
            'i18n' => ['primary_locale' => 'de_DE'],
            'content_manifest' => [
                'primary_locale' => 'de_DE',
                'items' => [],
            ],
            'pages' => [
                ['page_id' => 'home_page', 'page_type' => 'home_page'],
            ],
            'block_nodes' => [
                ['block_id' => 'home_page.hero', 'page_id' => 'home_page'],
            ],
        ];
        $task = [
            'task_key' => 'page:home_page:content/home-page-hero',
            'page_id' => 'home_page',
            'block_id' => 'home_page.hero',
            'runtime_context' => [
                'content_locale' => 'pt_BR',
            ],
        ];

        $context = (new AiSiteBuildPromptContextAssembler())->assemble($contract, $task);

        self::assertSame('pt_BR', $context['content_locale']);
        self::assertSame('pt_BR', $context['language_contract']['source_of_truth_locale'] ?? null);
    }
}
