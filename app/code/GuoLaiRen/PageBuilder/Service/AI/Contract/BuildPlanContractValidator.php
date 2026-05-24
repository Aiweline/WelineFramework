<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AiSiteDesignPolicyRegistry;

final class BuildPlanContractValidator
{
    public function __construct(
        private readonly ?BuildPlanContractSchema $schema = null,
        private readonly ?BuildPlanNoReasonLinter $noReasonLinter = null,
        private readonly ?BuildPlanTaskGraphValidator $taskGraphValidator = null,
        private readonly ?BuildPlanDesignPolicyLinter $designPolicyLinter = null,
        private readonly ?BuildPlanContentManifestLinter $contentManifestLinter = null
    ) {
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];
        foreach ($this->schema()->requiredTopLevelFields() as $field) {
            if (!\array_key_exists($field, $contract)) {
                $errors[] = 'Missing top-level field: ' . $field;
            }
        }

        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        if ($meta === []) {
            $errors[] = 'contract_meta must be an object';
        } elseif ((string)($meta['version'] ?? '') !== $this->schema()->version()) {
            $errors[] = 'contract_meta.version must be ' . $this->schema()->version();
        }

        foreach ([
            'source_of_truth',
            'policy_ref',
            'policy_projection',
            'site_brief',
            'design_manifest',
            'i18n',
            'content_manifest',
            'permission_matrix',
            'presentation_projection',
        ] as $field) {
            if (\array_key_exists($field, $contract) && !\is_array($contract[$field])) {
                $errors[] = $field . ' must be an object';
            }
        }
        foreach (['pages', 'blocks', 'tasks', 'build_order', 'frozen_fields', 'mutable_fields', 'source_contracts', 'qa_gates'] as $field) {
            if (\array_key_exists($field, $contract) && !\is_array($contract[$field])) {
                $errors[] = $field . ' must be an array';
            }
        }

        $errors = \array_merge(
            $errors,
            $this->validateContractMeta($contract),
            $this->validateSourceContracts($contract),
            $this->validatePolicyRef($contract),
            $this->validateSiteBrief($contract),
            $this->validateBlocks($contract),
            $this->validateTasks($contract),
            $this->validatePermissions($contract),
            $this->validatePresentationProjection($contract)
        );

        foreach ([
            $this->noReasonLinter()->validate($contract),
            $this->designPolicyLinter()->validate($contract),
            $this->contentManifestLinter()->validate($contract),
            $this->taskGraphValidator()->validate($contract),
        ] as $result) {
            $errors = \array_merge($errors, \is_array($result['errors'] ?? null) ? $result['errors'] : []);
        }

        return [
            'valid' => $errors === [],
            'errors' => \array_values(\array_unique($errors)),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validateContractMeta(array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        if ($meta === []) {
            return [];
        }

        $errors = [];
        foreach (['id', 'version', 'type', 'stage', 'status', 'created_at', 'creator', 'adapter_type'] as $field) {
            if (\trim((string)($meta[$field] ?? '')) === '') {
                $errors[] = 'contract_meta.' . $field . ' is required';
            }
        }
        if ((string)($meta['type'] ?? '') !== 'build_plan_v2') {
            $errors[] = 'contract_meta.type must be build_plan_v2';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validateSourceContracts(array $contract): array
    {
        $sourceContracts = \is_array($contract['source_contracts'] ?? null) ? $contract['source_contracts'] : null;
        if ($sourceContracts === null) {
            return [];
        }

        if ($sourceContracts === []) {
            return ['source_contracts must not be empty'];
        }

        $result = (new SourceContractHelper())->validateRequired($contract, [
            ContractType::TYPE_SOURCE_TRUTH,
            ContractType::TYPE_SITE_BRIEF,
            ContractType::TYPE_DESIGN_MANIFEST,
            ContractType::TYPE_PAGE_CONTRACT,
            ContractType::TYPE_BLOCK_PLAN,
        ]);

        return \is_array($result['errors'] ?? null) ? \array_values(\array_map('strval', $result['errors'])) : [];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validatePolicyRef(array $contract): array
    {
        $errors = [];
        $policyRef = \is_array($contract['policy_ref'] ?? null) ? $contract['policy_ref'] : [];
        foreach ($this->schema()->requiredPolicyFields() as $field) {
            if (\trim((string)($policyRef[$field] ?? '')) === '') {
                $errors[] = 'policy_ref.' . $field . ' is required';
            }
        }
        $policyId = \trim((string)($policyRef['policy_id'] ?? ''));
        if ($policyId !== '' && !(new AiSiteDesignPolicyRegistry())->hasPolicy($policyId)) {
            $errors[] = 'policy_ref.policy_id is not registered: ' . $policyId;
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validateSiteBrief(array $contract): array
    {
        $brief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $errors = [];
        if (\trim((string)($brief['site_name'] ?? $brief['site_title'] ?? '')) === '') {
            $errors[] = 'site_brief.site_name is required';
        }
        if (\trim((string)($brief['primary_goal'] ?? '')) === '') {
            $errors[] = 'site_brief.primary_goal is required';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validateBlocks(array $contract): array
    {
        $errors = [];
        foreach ($this->normalizeRecordSet($contract['blocks'] ?? [], ['block_id', 'id']) as $blockId => $block) {
            foreach ($this->schema()->requiredBlockFields() as $field) {
                if ($field === 'block_id') {
                    continue;
                }
                if (!\array_key_exists($field, $block) || (\is_array($block[$field]) && $block[$field] === []) || \trim((string)(\is_array($block[$field]) ? 'array' : $block[$field])) === '') {
                    $errors[] = 'Block ' . $blockId . ' is missing required field: ' . $field;
                }
            }
            if (\trim((string)($block['page_id'] ?? '')) !== '') {
                if (\trim((string)($block['page_flow_role'] ?? '')) === '') {
                    $errors[] = 'Block ' . $blockId . ' is missing stage-one page_flow_role';
                }
                $signature = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
                foreach (['composition_pattern', 'spatial_rhythm', 'media_strategy', 'surface_treatment'] as $field) {
                    if (\trim((string)($signature[$field] ?? '')) === '') {
                        $errors[] = 'Block ' . $blockId . ' is missing visual_signature.' . $field;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validateTasks(array $contract): array
    {
        $errors = [];
        $allowedKinds = $this->schema()->allowedTaskKinds();
        $allowedExecutors = $this->schema()->allowedExecutors();
        foreach ($this->normalizeRecordSet($contract['tasks'] ?? [], ['task_id', 'id']) as $taskId => $task) {
            foreach ($this->schema()->requiredTaskFields() as $field) {
                if ($field === 'task_id') {
                    continue;
                }
                if ($field === 'depends_on') {
                    if (!\array_key_exists($field, $task) || !\is_array($task[$field])) {
                        $errors[] = 'Task ' . $taskId . ' is missing required field: ' . $field;
                    }
                    continue;
                }
                if (!\array_key_exists($field, $task) || (\is_array($task[$field]) && $task[$field] === []) || (!\is_array($task[$field]) && \trim((string)$task[$field]) === '')) {
                    $errors[] = 'Task ' . $taskId . ' is missing required field: ' . $field;
                }
            }
            $kind = \trim((string)($task['task_kind'] ?? ''));
            if ($kind !== '' && !\in_array($kind, $allowedKinds, true)) {
                $errors[] = 'Task ' . $taskId . ' has unsupported task_kind: ' . $kind;
            }
            $executor = \trim((string)($task['executor'] ?? ''));
            if ($executor !== '' && !\in_array($executor, $allowedExecutors, true)) {
                $errors[] = 'Task ' . $taskId . ' has unsupported executor: ' . $executor;
            }
            $inputScope = \is_array($task['input_scope'] ?? null) ? $task['input_scope'] : [];
            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            $outputContract = \is_array($task['output_contract'] ?? null) ? $task['output_contract'] : [];
            $acceptance = \is_array($task['acceptance'] ?? null) ? $task['acceptance'] : [];
            $contextBudget = \is_array($task['context_budget'] ?? null) ? $task['context_budget'] : [];
            if ($runtimeContext === []) {
                $errors[] = 'Task ' . $taskId . ' runtime_context must not be empty';
            }
            if (\trim((string)($runtimeContext['content_locale'] ?? '')) === '') {
                $errors[] = 'Task ' . $taskId . ' runtime_context.content_locale is required';
            }
            $languageContract = \is_array($runtimeContext['language_contract'] ?? null) ? $runtimeContext['language_contract'] : [];
            if ($languageContract === []) {
                $errors[] = 'Task ' . $taskId . ' runtime_context.language_contract is required';
            } elseif (\trim((string)($languageContract['source_of_truth_locale'] ?? '')) === '') {
                $errors[] = 'Task ' . $taskId . ' runtime_context.language_contract.source_of_truth_locale is required';
            }
            foreach ($this->findForbiddenRuntimeContextKeys($runtimeContext) as $path) {
                $errors[] = 'Task ' . $taskId . ' runtime_context contains forbidden broad context: ' . $path;
            }
            if ($outputContract === []) {
                $errors[] = 'Task ' . $taskId . ' output_contract must not be empty';
            } else {
                if (\trim((string)($outputContract['format'] ?? '')) === '') {
                    $errors[] = 'Task ' . $taskId . ' output_contract.format is required';
                }
                if (!\is_array($outputContract['required_outputs'] ?? null) || $outputContract['required_outputs'] === []) {
                    $errors[] = 'Task ' . $taskId . ' output_contract.required_outputs must not be empty';
                }
            }
            if ($acceptance === []) {
                $errors[] = 'Task ' . $taskId . ' acceptance must not be empty';
            } elseif (!\is_array($acceptance['checks'] ?? null) || $acceptance['checks'] === []) {
                $errors[] = 'Task ' . $taskId . ' acceptance.checks must not be empty';
            }
            $maxTokens = (int)($contextBudget['max_tokens'] ?? 0);
            if ($maxTokens <= 0) {
                $errors[] = 'Task ' . $taskId . ' context_budget.max_tokens must be greater than zero';
            }
            if ($this->stringList($task['policy_slices'] ?? []) === []) {
                $errors[] = 'Task ' . $taskId . ' policy_slices must not be empty';
            }
            if ($this->stringList($task['acceptance_rule_ids'] ?? []) === []) {
                $errors[] = 'Task ' . $taskId . ' acceptance_rule_ids must not be empty';
            }
            if (\trim((string)($inputScope['page_type'] ?? '')) !== '') {
                if (\trim((string)($task['page_flow_role'] ?? $inputScope['page_flow_role'] ?? '')) === '') {
                    $errors[] = 'Task ' . $taskId . ' is missing stage-one page_flow_role';
                }
                $signature = \is_array($task['visual_signature'] ?? null) ? $task['visual_signature'] : [];
                foreach (['composition_pattern', 'spatial_rhythm', 'media_strategy', 'surface_treatment'] as $field) {
                    if (\trim((string)($signature[$field] ?? '')) === '') {
                        $errors[] = 'Task ' . $taskId . ' is missing visual_signature.' . $field;
                    }
                }
            }
            $semanticError = $this->validateTaskExecutorPair($taskId, $kind, $executor);
            if ($semanticError !== '') {
                $errors[] = $semanticError;
            }
        }

        return $errors;
    }

    private function validateTaskExecutorPair(string $taskId, string $kind, string $executor): string
    {
        if ($kind === '' || $executor === '') {
            return '';
        }

        $expected = [
            'asset_generate' => 'AiSiteAssetQueue',
            'block_build' => 'AiSiteBuildQueue',
            'page_assemble' => 'AiSiteBuildQueue',
            'i18n_generate' => 'AiSiteBuildQueue',
            'seo_generate' => 'AiSiteBuildQueue',
            'qa_run' => 'AiSiteQualityGateService',
            'repair_patch' => 'ContractRepairExecutor',
            'publish_prepare' => 'AiSiteBuildQueue',
        ];
        $requiredExecutor = $expected[$kind] ?? '';
        if ($requiredExecutor !== '' && $executor !== $requiredExecutor) {
            return 'Task ' . $taskId . ' executor must be ' . $requiredExecutor . ' for task_kind ' . $kind;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function findForbiddenRuntimeContextKeys(mixed $value, string $path = 'runtime_context'): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $forbidden = [
            'scope' => true,
            'plan_json' => true,
            'plan_structured' => true,
            'plan_workbench' => true,
            'execution_blueprint' => true,
            'execution_blueprint_draft' => true,
            'presentation_projection' => true,
            'ui_projection' => true,
        ];
        $errors = [];
        foreach ($value as $key => $item) {
            $keyText = \trim((string)$key);
            $nextPath = $path . '.' . $keyText;
            if (isset($forbidden[$keyText])) {
                $errors[] = $nextPath;
            }
            foreach ($this->findForbiddenRuntimeContextKeys($item, $nextPath) as $nestedPath) {
                $errors[] = $nestedPath;
            }
        }

        return \array_values(\array_unique($errors));
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validatePermissions(array $contract): array
    {
        $errors = [];
        $frozen = $this->stringList($contract['frozen_fields'] ?? []);
        $mutable = $this->stringList($contract['mutable_fields'] ?? []);
        foreach (\array_intersect($frozen, $mutable) as $path) {
            $errors[] = 'Field cannot be both frozen and mutable: ' . $path;
        }

        $matrix = \is_array($contract['permission_matrix'] ?? null) ? $contract['permission_matrix'] : [];
        foreach (['read', 'create', 'patch', 'forbidden'] as $field) {
            if (\array_key_exists($field, $matrix) && !\is_array($matrix[$field])) {
                $errors[] = 'permission_matrix.' . $field . ' must be an array';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function validatePresentationProjection(array $contract): array
    {
        $projection = \is_array($contract['presentation_projection'] ?? null) ? $contract['presentation_projection'] : [];
        if ($projection !== [] && !((bool)($projection['never_feed_to_build'] ?? false))) {
            return ['presentation_projection.never_feed_to_build must be true'];
        }

        return [];
    }

    /**
     * @param list<string> $idFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRecordSet(mixed $items, array $idFields): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $id = '';
            foreach ($idFields as $field) {
                $id = \trim((string)($item[$field] ?? ''));
                if ($id !== '') {
                    break;
                }
            }
            if ($id === '' && \is_string($key)) {
                $id = $key;
            }
            if ($id !== '') {
                $normalized[$id] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    private function schema(): BuildPlanContractSchema
    {
        return $this->schema ?? new BuildPlanContractSchema();
    }

    private function noReasonLinter(): BuildPlanNoReasonLinter
    {
        return $this->noReasonLinter ?? new BuildPlanNoReasonLinter($this->schema());
    }

    private function taskGraphValidator(): BuildPlanTaskGraphValidator
    {
        return $this->taskGraphValidator ?? new BuildPlanTaskGraphValidator();
    }

    private function designPolicyLinter(): BuildPlanDesignPolicyLinter
    {
        return $this->designPolicyLinter ?? new BuildPlanDesignPolicyLinter(null, $this->schema());
    }

    private function contentManifestLinter(): BuildPlanContentManifestLinter
    {
        return $this->contentManifestLinter ?? new BuildPlanContentManifestLinter();
    }
}
