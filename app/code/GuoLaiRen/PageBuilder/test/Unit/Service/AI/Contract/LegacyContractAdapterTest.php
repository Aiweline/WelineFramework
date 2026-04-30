<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\LegacyContractAdapter;
use PHPUnit\Framework\TestCase;

final class LegacyContractAdapterTest extends TestCase
{
    public function testStageOneLegacyArtifactsProduceCompatibilityContracts(): void
    {
        $contracts = (new LegacyContractAdapter())->adaptStageOne([
            'site_title' => 'Legacy Site',
            'brief_description' => 'Legacy brief',
            'plan_json' => [
                'theme_design' => ['style_signature' => 'editorial'],
                'palette' => ['name' => 'ink'],
                'page_types' => ['home_page'],
                'pages' => [
                    'home_page' => [
                        'blocks' => [
                            ['block_key' => 'hero', 'content' => 'Hero copy'],
                        ],
                    ],
                ],
                'page_plans' => [
                    'home_page' => ['page_goal' => 'Explain value'],
                ],
            ],
            'execution_blueprint' => [
                'block_index' => ['page:home_page:hero' => ['block_key' => 'hero']],
            ],
        ]);

        self::assertArrayHasKey(ContractType::TYPE_SITE_BRIEF, $contracts);
        self::assertArrayHasKey(ContractType::TYPE_PAGE_CONTRACT, $contracts);
        self::assertSame(ContractType::STATUS_COMPATIBILITY, $contracts[ContractType::TYPE_SITE_BRIEF]['contract_meta']['status']);
        self::assertSame('Legacy Site', $contracts[ContractType::TYPE_SITE_BRIEF]['payload']['site_title']);
        self::assertSame(['home_page'], $contracts[ContractType::TYPE_PAGE_CONTRACT]['payload']['page_types']);
        self::assertSame('hero', $contracts[ContractType::TYPE_PAGE_CONTRACT]['payload']['pages']['home_page']['blocks'][0]['block_key']);
        self::assertArrayHasKey('compatibility_shape', $contracts[ContractType::TYPE_BLOCK_PLAN]['qa_gates']);
    }

    public function testStageTwoLegacyArtifactsPreserveSourceContracts(): void
    {
        $contracts = (new LegacyContractAdapter())->adaptStageTwo([
            'task_plan_structured' => [
                'style_tokens' => ['surface' => 'dark'],
                'block_task_schema' => ['version' => 'stage2-block-task-v1'],
            ],
            'virtual_theme_plan' => [
                'confirmed' => [
                    'shared_tasks' => [
                        ['task_key' => 'shared:header'],
                    ],
                    'page_tasks' => [
                        'home_page' => [
                            ['task_key' => 'page:home_page:hero'],
                        ],
                    ],
                ],
            ],
        ], [
            ['id' => 'contract_page', 'type' => ContractType::TYPE_PAGE_CONTRACT, 'status' => ContractType::STATUS_CONFIRMED],
        ]);

        self::assertArrayHasKey(ContractType::TYPE_BLOCK_VISUAL_CONTRACT, $contracts);
        self::assertArrayHasKey(ContractType::TYPE_BLOCK_TASK_CONTRACT, $contracts);
        self::assertSame(ContractType::STATUS_COMPATIBILITY, $contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT]['contract_meta']['status']);
        self::assertSame('contract_page', $contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT]['source_contracts'][0]['id']);
        self::assertSame('shared:header', $contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT]['payload']['shared_tasks'][0]['task_key']);
        self::assertSame('dark', $contracts[ContractType::TYPE_BLOCK_VISUAL_CONTRACT]['payload']['style_tokens']['surface']);
    }
}
