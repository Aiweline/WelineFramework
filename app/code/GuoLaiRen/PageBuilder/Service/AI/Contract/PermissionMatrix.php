<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class PermissionMatrix
{
    /**
     * @return array<string, mixed>
     */
    public function forStage(string $stage): array
    {
        return match ($stage) {
            ContractType::STAGE_STAGE1 => [
                'stage' => ContractType::STAGE_STAGE1,
                'can_create' => ['site_brief', 'design_manifest', 'page_contract', 'block_plan'],
                'can_patch' => ['site_brief.*', 'design_manifest.*', 'page_contract.*', 'block_plan.*'],
                'read_only' => [],
            ],
            ContractType::STAGE_BUILD_PLAN => [
                'stage' => ContractType::STAGE_BUILD_PLAN,
                'can_create' => ['block_visual_contract', 'block_task_contract'],
                'can_patch' => ['block_visual_contract.*', 'block_task_contract.*'],
                'read_only' => ['site_brief.*', 'design_manifest.*', 'page_contract.*', 'block_plan.*'],
            ],
            ContractType::STAGE_BUILD => [
                'stage' => ContractType::STAGE_BUILD,
                'can_create' => ['render_data', 'theme_manifest'],
                'can_patch' => ['render_data.*', 'theme_manifest.*'],
                'read_only' => ['site_brief.*', 'design_manifest.*', 'page_contract.*', 'block_plan.*', 'block_visual_contract.*', 'block_task_contract.*'],
            ],
            ContractType::STAGE_QA => [
                'stage' => ContractType::STAGE_QA,
                'can_create' => ['qa_report'],
                'can_patch' => ['qa_gates.*'],
                'read_only' => ['site_brief.*', 'design_manifest.*', 'page_contract.*', 'block_plan.*', 'block_visual_contract.*', 'block_task_contract.*', 'render_data.*', 'theme_manifest.*'],
            ],
            ContractType::STAGE_REPAIR => [
                'stage' => ContractType::STAGE_REPAIR,
                'can_create' => ['repair_patch'],
                'can_patch' => ['mutable_fields.*', 'qa_gates.*'],
                'read_only' => ['frozen_fields.*'],
            ],
            default => [
                'stage' => $stage,
                'can_create' => [],
                'can_patch' => [],
                'read_only' => [],
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function defaultFrozenFields(string $stage): array
    {
        return match ($stage) {
            ContractType::STAGE_BUILD_PLAN => [
                'site_brief.site_title',
                'design_manifest.theme_design',
                'design_manifest.palette',
                'page_contract.pages',
                'block_plan.pages.*.blocks',
            ],
            ContractType::STAGE_BUILD,
            ContractType::STAGE_QA,
            ContractType::STAGE_REPAIR => [
                'site_brief.*',
                'design_manifest.*',
                'page_contract.*',
                'block_plan.*',
                'block_visual_contract.*',
                'block_task_contract.*',
            ],
            default => [],
        };
    }
}
