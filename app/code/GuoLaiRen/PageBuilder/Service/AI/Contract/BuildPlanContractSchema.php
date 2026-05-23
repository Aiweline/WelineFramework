<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class BuildPlanContractSchema
{
    public const VERSION = '2.2';

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @return list<string>
     */
    public function requiredTopLevelFields(): array
    {
        return [
            'contract_meta',
            'source_of_truth',
            'policy_ref',
            'policy_projection',
            'site_brief',
            'design_manifest',
            'i18n',
            'content_manifest',
            'pages',
            'blocks',
            'tasks',
            'build_order',
            'permission_matrix',
            'frozen_fields',
            'mutable_fields',
            'source_contracts',
            'qa_gates',
            'presentation_projection',
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedTaskKinds(): array
    {
        return [
            'asset_generate',
            'block_build',
            'page_assemble',
            'i18n_generate',
            'seo_generate',
            'qa_run',
            'repair_patch',
            'publish_prepare',
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedExecutors(): array
    {
        return [
            'AiSiteAssetQueue',
            'AiSiteBuildQueue',
            'AiSiteQualityGateService',
            'ContractRepairExecutor',
        ];
    }

    /**
     * @return list<string>
     */
    public function forbiddenFieldNames(): array
    {
        return [
            'reason',
            'why',
            'rationale',
            'thinking',
            'analysis',
            'explanation',
            'chain_of_thought',
            'design_reason',
            'reasoning',
            '设计原因',
            '为什么',
            '策略解释',
            '模型思考',
            '审美分析',
            '详细推理',
            'prompt日志',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredDesignTokenGroups(): array
    {
        return [
            'layout',
            'spacing',
            'typography',
            'colors',
            'radius',
            'motion',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredPolicyFields(): array
    {
        return [
            'policy_id',
            'policy_version',
            'policy_hash',
            'source',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredBlockFields(): array
    {
        return [
            'block_id',
            'page_id',
            'block_type',
            'content_keys',
            'task_ids',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredTaskFields(): array
    {
        return [
            'task_id',
            'task_kind',
            'executor',
            'input_scope',
            'runtime_context',
            'output_contract',
            'policy_slices',
            'context_budget',
            'acceptance',
            'acceptance_rule_ids',
            'depends_on',
        ];
    }
}
