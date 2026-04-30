<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class LegacyContractAdapter
{
    public function __construct(
        private readonly ?ContractMetaBuilder $metaBuilder = null,
        private readonly ?PermissionMatrix $permissionMatrix = null,
        private readonly ?QaGateHelper $qaGateHelper = null
    ) {
    }

    /**
     * @param array<string, mixed> $legacy
     * @return array<string, array<string, mixed>>
     */
    public function adaptStageOne(array $legacy): array
    {
        $plan = \is_array($legacy['plan_json'] ?? null) ? $legacy['plan_json'] : [];
        $structured = \is_array($legacy['structured'] ?? null) ? $legacy['structured'] : [];
        $blueprint = \is_array($legacy['execution_blueprint'] ?? null) ? $legacy['execution_blueprint'] : [];
        $source = $plan !== [] ? $plan : ($structured !== [] ? $structured : $blueprint);

        return [
            ContractType::TYPE_SITE_BRIEF => $this->contract(
                ContractType::TYPE_SITE_BRIEF,
                ContractType::STAGE_STAGE1,
                [
                    'site_title' => (string)($legacy['site_title'] ?? $source['site_title'] ?? ''),
                    'brief_description' => (string)($legacy['brief_description'] ?? $source['brief_description'] ?? ''),
                    'site_strategy' => \is_array($source['site_strategy'] ?? null) ? $source['site_strategy'] : [],
                ]
            ),
            ContractType::TYPE_DESIGN_MANIFEST => $this->contract(
                ContractType::TYPE_DESIGN_MANIFEST,
                ContractType::STAGE_STAGE1,
                [
                    'theme_design' => \is_array($source['theme_design'] ?? null) ? $source['theme_design'] : (\is_array($blueprint['theme_context_snapshot'] ?? null) ? $blueprint['theme_context_snapshot'] : []),
                    'palette' => \is_array($source['palette'] ?? null) ? $source['palette'] : [],
                    'shared_components' => \is_array($source['shared_components'] ?? null) ? $source['shared_components'] : (\is_array($blueprint['shared_components'] ?? null) ? $blueprint['shared_components'] : []),
                ]
            ),
            ContractType::TYPE_PAGE_CONTRACT => $this->contract(
                ContractType::TYPE_PAGE_CONTRACT,
                ContractType::STAGE_STAGE1,
                [
                    'pages' => \is_array($source['pages'] ?? null) ? $source['pages'] : (\is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : []),
                    'page_types' => \array_values(\array_filter(\array_map('strval', \is_array($source['page_types'] ?? null) ? $source['page_types'] : []))),
                ]
            ),
            ContractType::TYPE_BLOCK_PLAN => $this->contract(
                ContractType::TYPE_BLOCK_PLAN,
                ContractType::STAGE_STAGE1,
                [
                    'page_plans' => \is_array($source['page_plans'] ?? null) ? $source['page_plans'] : (\is_array($blueprint['page_plans'] ?? null) ? $blueprint['page_plans'] : []),
                    'block_index' => \is_array($source['block_index'] ?? null) ? $source['block_index'] : (\is_array($blueprint['block_index'] ?? null) ? $blueprint['block_index'] : []),
                    'queue_jobs' => \is_array($source['queue_jobs'] ?? null) ? $source['queue_jobs'] : (\is_array($blueprint['queue_jobs'] ?? null) ? $blueprint['queue_jobs'] : []),
                ]
            ),
        ];
    }

    /**
     * @param array<string, mixed> $legacy
     * @param array<int|string, mixed> $sourceContracts
     * @return array<string, array<string, mixed>>
     */
    public function adaptStageTwo(array $legacy, array $sourceContracts = []): array
    {
        $plan = \is_array($legacy['virtual_theme_plan']['confirmed'] ?? null)
            ? $legacy['virtual_theme_plan']['confirmed']
            : (\is_array($legacy['virtual_theme_plan'] ?? null) ? $legacy['virtual_theme_plan'] : $legacy);
        $structured = \is_array($legacy['task_plan_structured'] ?? null) ? $legacy['task_plan_structured'] : $plan;

        $sources = (new SourceContractHelper())->normalize($sourceContracts);

        return [
            ContractType::TYPE_BLOCK_VISUAL_CONTRACT => $this->contract(
                ContractType::TYPE_BLOCK_VISUAL_CONTRACT,
                ContractType::STAGE_STAGE2,
                [
                    'style_tokens' => \is_array($structured['style_tokens'] ?? null) ? $structured['style_tokens'] : [],
                    'block_task_schema' => \is_array($structured['block_task_schema'] ?? null) ? $structured['block_task_schema'] : [],
                    'shared_block_tasks' => \is_array($structured['shared_block_tasks'] ?? null) ? $structured['shared_block_tasks'] : [],
                    'page_block_tasks' => \is_array($structured['page_block_tasks'] ?? null) ? $structured['page_block_tasks'] : [],
                ],
                $sources
            ),
            ContractType::TYPE_BLOCK_TASK_CONTRACT => $this->contract(
                ContractType::TYPE_BLOCK_TASK_CONTRACT,
                ContractType::STAGE_STAGE2,
                [
                    'shared_tasks' => \is_array($plan['shared_tasks'] ?? null) ? $plan['shared_tasks'] : [],
                    'page_tasks' => \is_array($plan['page_tasks'] ?? null) ? $plan['page_tasks'] : [],
                    'task_tree' => \is_array($plan['task_tree'] ?? null) ? $plan['task_tree'] : [],
                    'task_directory_tree' => \is_array($plan['task_directory_tree'] ?? null) ? $plan['task_directory_tree'] : [],
                ],
                $sources
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array{id:string,type:string,version:string,status:string}> $sourceContracts
     * @return array<string, mixed>
     */
    private function contract(string $type, string $stage, array $payload, array $sourceContracts = []): array
    {
        $matrix = ($this->permissionMatrix ?? new PermissionMatrix())->forStage($stage);
        $qa = $this->qaGateHelper ?? new QaGateHelper();

        return [
            'contract_meta' => ($this->metaBuilder ?? new ContractMetaBuilder())->build(
                $type,
                $stage,
                ContractType::STATUS_COMPATIBILITY,
                'legacy_adapter',
                'legacy_contract_adapter',
                $payload
            ),
            'permission_matrix' => $matrix,
            'frozen_fields' => ($this->permissionMatrix ?? new PermissionMatrix())->defaultFrozenFields($stage),
            'mutable_fields' => [],
            'source_contracts' => $sourceContracts,
            'qa_gates' => $qa->pendingSet(['compatibility_shape']),
            'payload' => $payload,
        ];
    }
}
