<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class PlanJsonContractSchema
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
        ];
    }

    /**
     * @return list<string>
     */
    public function forbiddenTopLevelFields(): array
    {
        return [
            'tasks',
            'build_order',
            'task_plan',
        ];
    }
}
