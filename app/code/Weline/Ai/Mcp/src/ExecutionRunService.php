<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use Throwable;

/**
 * Durable and redacted observability for one closed-loop coding task.
 *
 * Only deterministic summaries are stored. Model reasoning, hidden prompts,
 * credentials and arbitrary commands are deliberately excluded.
 */
final class ExecutionRunService
{
    private const PHASES = [
        'REQUIREMENT_PARSE',
        'CONTEXT_COLLECT',
        'CONTEXT_CHECK',
        'EDIT_PLAN_WAIT',
        'ATOMIC_APPLY',
        'SYNTAX_VALIDATE',
        'REGRESSION_VALIDATE',
        'IMPACT_REVIEW',
        'COMPLETED',
        'FAILED',
        'ROLLED_BACK',
        'SUPERSEDED',
    ];

    private const TERMINAL = ['completed', 'planned', 'failed', 'rolled_back', 'superseded'];

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
    ) {
    }

    /** @param array<string,mixed> $contract
     *  @return array<string,mixed>
     */
    public function begin(
        string $projectId,
        string $task,
        array $contract,
        int $revision,
        string $supersedesRunId = '',
    ): array {
        $this->pruneExpired();
        $task = Text::truncate($this->sanitizeText($task), 20_000);
        $contract = $this->sanitize($contract);
        $taskDigest = Ids::hash(Json::canonical($contract + ['original_requirement' => $task]));
        $taskId = Ids::deterministic('task', $projectId . "\0" . $taskDigest, 24);
        if ($supersedesRunId !== '') {
            $taskId = (string) $this->row($supersedesRunId, $projectId)['task_id'];
        }

        $runId = Ids::make('run');
        $traceId = Ids::make('trace');
        $now = Clock::now();
        $budgets = [
            'get_edit_bundle' => 1,
            'successful_apply_compact_edit' => 1,
            'conflict_replans_max' => 2,
            'context_batches_max' => 8,
            'edit_batches_max' => 4,
            'impact_expansion_depth_max' => 2,
            'validation_repairs_max' => 2,
            'same_error_stop_count' => 3,
            'native_source_reads' => 0,
            'direct_writes' => 0,
            'intermediate_user_inquiries' => 0,
        ];
        $counters = [
            'get_edit_bundle_calls' => 1,
            'apply_compact_edit_calls' => 0,
            'successful_apply_compact_edit_calls' => 0,
            'native_file_read_fallback_count' => 0,
            'intermediate_user_inquiry_count' => 0,
            'automatic_rollback_count' => 0,
            'candidate_file_count' => 0,
            'materialized_file_count' => 0,
            'modified_file_count' => 0,
            'event_count' => 0,
        ];
        $recursion = [
            'counts' => [
                'CONFLICT_REPLAN' => 0,
                'CONTEXT_BATCHING' => 0,
                'EDIT_BATCHING' => 0,
                'IMPACT_EXPANSION' => 0,
                'VALIDATION_REPAIR' => 0,
                'USER_SCOPE_CHANGE' => $supersedesRunId === '' ? 0 : 1,
            ],
            'same_error_count' => 0,
            'last_error_fingerprint' => '',
        ];

        $statement = $this->db()->prepare(
            'INSERT INTO execution_runs(
                run_id, task_id, trace_id, project_id, schema_version, supersedes_run_id,
                task_digest, task_original_redacted, task_contract_json, current_phase,
                status, workflow_state, index_revision, budgets_json, counters_json,
                recursion_json, result_summary_json, started_at, updated_at
             ) VALUES(
                :run_id, :task_id, :trace_id, :project_id, :schema_version, :supersedes_run_id,
                :task_digest, :task, :contract, :phase, :status, :workflow_state,
                :revision, :budgets, :counters, :recursion, :result, :started_at, :updated_at
             )'
        );
        $statement->execute([
            'run_id' => $runId,
            'task_id' => $taskId,
            'trace_id' => $traceId,
            'project_id' => $projectId,
            'schema_version' => 'execution-run.v1',
            'supersedes_run_id' => $this->nullable($supersedesRunId),
            'task_digest' => $taskDigest,
            'task' => $task,
            'contract' => Json::encode($contract),
            'phase' => 'REQUIREMENT_PARSE',
            'status' => 'running',
            'workflow_state' => $supersedesRunId === '' ? 'NORMAL' : 'USER_SCOPE_CHANGE',
            'revision' => max(0, $revision),
            'budgets' => Json::encode($budgets),
            'counters' => Json::encode($counters),
            'recursion' => Json::encode($recursion),
            'result' => '{}',
            'started_at' => $now,
            'updated_at' => $now,
        ]);

        if ($supersedesRunId !== '') {
            $this->db()->prepare(
                "UPDATE execution_runs
                    SET status = 'superseded', current_phase = 'SUPERSEDED',
                        workflow_state = 'USER_SCOPE_CHANGE', superseded_by_run_id = :next_run,
                        completed_at = :now, updated_at = :now
                  WHERE run_id = :run_id AND project_id = :project_id"
            )->execute([
                'next_run' => $runId,
                'now' => $now,
                'run_id' => $supersedesRunId,
                'project_id' => $projectId,
            ]);
        }

        $this->event($runId, [
            'event_type' => 'PHASE_COMPLETED',
            'phase' => 'REQUIREMENT_PARSE',
            'status' => 'completed',
            'tool_name' => 'get_edit_bundle',
            'operation_name' => 'parse_task_contract',
            'reason_code' => 'OWNER_DISCOVERY',
            'reason_text' => 'Normalized the complete task contract and established one project-scoped execution run.',
            'input_summary' => [
                'known_path_count' => count((array) ($contract['known_paths'] ?? [])),
                'known_symbol_count' => count((array) ($contract['known_symbols'] ?? [])),
                'active_skills_declared' => is_array($contract['active_skills'] ?? null),
            ],
        ]);
        $this->phase($runId, 'CONTEXT_COLLECT', 'running');

        return $this->status($projectId, $runId);
    }

    /** @param array<string,mixed> $bundle */
    public function completeBundle(string $runId, string $projectId, array $bundle): array
    {
        $run = $this->row($runId, $projectId);
        $candidates = is_array($bundle['candidate_files'] ?? null)
            ? $bundle['candidate_files']
            : (is_array($bundle['candidate_paths'] ?? null) ? $bundle['candidate_paths'] : []);
        $regions = is_array($bundle['exact_regions'] ?? null)
            ? $bundle['exact_regions']
            : (is_array($bundle['regions'] ?? null) ? $bundle['regions'] : []);
        $byPath = [];
        foreach ($regions as $region) {
            if (!is_array($region)) {
                continue;
            }
            $path = trim((string) ($region['path'] ?? ''));
            if ($path !== '') {
                $byPath[$path][] = $region;
            }
        }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $path = trim((string) ($candidate['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $materialized = (bool) ($candidate['materialized'] ?? isset($byPath[$path]));
            $this->file($runId, $path, [
                'candidate' => true,
                'selected' => $materialized,
                'materialized' => $materialized,
                'excluded' => !$materialized,
                'candidate_reason' => implode('; ', Text::uniqueStrings(array_merge(
                    (array) ($candidate['reasons'] ?? []),
                    [(string) ($candidate['source'] ?? '')],
                ), false)),
                'excluded_reason' => $materialized
                    ? ''
                    : (string) ($candidate['excluded_reason'] ?? 'not selected within the bounded context budget'),
                'regions' => $byPath[$path] ?? [],
            ]);
        }
        foreach ($byPath as $path => $pathRegions) {
            $this->file($runId, $path, [
                'candidate' => true,
                'selected' => true,
                'materialized' => true,
                'regions' => $pathRegions,
            ]);
        }

        $this->event($runId, [
            'event_type' => 'CALL_COMPLETED',
            'phase' => 'CONTEXT_COLLECT',
            'status' => 'completed',
            'tool_name' => 'get_edit_bundle',
            'operation_name' => 'server_context_aggregation',
            'reason_code' => 'CALLER_DISCOVERY',
            'reason_text' => 'Aggregated architecture, definitions, callers, contracts, configuration, tests, docs and consumers.',
            'output_summary' => [
                'candidate_file_count' => count($candidates),
                'region_count' => count($regions),
                'impact_count' => count((array) ($bundle['impacts'] ?? [])),
            ],
            'metrics' => $bundle['performance'] ?? [],
        ]);
        $this->phase($runId, 'CONTEXT_CHECK', 'running');

        $ready = (bool) ($bundle['ready_for_edit'] ?? false);
        $continuation = is_array($bundle['continuation'] ?? null)
            ? $bundle['continuation']
            : [];
        $contextBatchPlan = is_array($continuation['batch_plan'] ?? null)
            ? $continuation['batch_plan']
            : [];
        $contextPlanned = !$ready
            && (bool) ($bundle['continuation_needed'] ?? $continuation['needed'] ?? false)
            && (int) ($contextBatchPlan['batch_count'] ?? 0) > 0;
        $budgets = Json::decode((string) $run['budgets_json'], []);
        if ($contextPlanned) {
            $budgets['context_batches_max'] = max(
                (int) ($budgets['context_batches_max'] ?? 8),
                (int) ($contextBatchPlan['batch_count'] ?? 0),
            );
        }
        $contextFailed = !$ready && !$contextPlanned;
        $bundleStatus = trim((string) ($bundle['status'] ?? ''));
        $targetNotFound = $contextFailed && $bundleStatus === 'CONTEXT_TARGET_NOT_FOUND';
        $targetAmbiguous = $contextFailed && $bundleStatus === 'CONTEXT_TARGET_AMBIGUOUS';
        $failedWorkflowState = $targetAmbiguous
            ? 'CONTEXT_TARGET_AMBIGUOUS'
            : ($targetNotFound ? 'CONTEXT_TARGET_NOT_FOUND' : 'CONTEXT_INCOMPLETE');
        $failedReasonCode = $targetAmbiguous
            ? 'EXACT_TARGET_AMBIGUOUS'
            : ($targetNotFound ? 'EXACT_TARGET_NOT_FOUND' : 'CONTRACT_DISCOVERY');
        $completeness = is_array($bundle['context_completeness'] ?? null)
            ? $bundle['context_completeness']
            : ['score' => $ready ? 100 : 0, 'covered' => [], 'missing' => []];
        $contextStatus = $ready ? 'completed' : ($contextPlanned ? 'planned' : 'failed');
        $this->event($runId, [
            'event_type' => 'CONTEXT_COMPLETENESS',
            'phase' => 'CONTEXT_CHECK',
            'status' => $contextStatus,
            'operation_name' => 'evaluate_context_completeness',
            'reason_code' => $contextPlanned ? 'CONTEXT_BATCH_LIMIT' : $failedReasonCode,
            'reason_text' => $ready
                ? 'All required edit-context dimensions are materialized.'
                : ($contextPlanned
                    ? 'The bounded parent finished with ordered executable child context requests.'
                    : ($targetAmbiguous
                        ? 'One or more short requested symbols match multiple in-scope definitions.'
                        : ($targetNotFound
                            ? 'One or more exact requested symbols do not exist in the refreshed in-scope index.'
                            : 'One or more required context dimensions are missing without a recoverable batch plan.'))),
            'output_summary' => $completeness + [
                'context_batch_plan' => $contextBatchPlan,
            ],
        ]);
        $recursion = Json::decode((string) $run['recursion_json'], []);
        $this->update($runId, [
            'bundle_id' => (string) ($bundle['bundle_id'] ?? ''),
            'index_revision' => max(0, (int) ($bundle['index_revision'] ?? $bundle['project_revision'] ?? 0)),
            'current_phase' => $ready
                ? 'EDIT_PLAN_WAIT'
                : ($contextPlanned ? 'CONTEXT_CHECK' : 'FAILED'),
            'status' => $ready
                ? 'waiting_for_plan'
                : ($contextPlanned ? 'planned' : 'failed'),
            'workflow_state' => $ready
                ? 'NORMAL'
                : ($contextPlanned ? 'CONTEXT_BATCH_PLANNED' : $failedWorkflowState),
            'budgets_json' => Json::encode($budgets),
            'recursion_json' => Json::encode($recursion),
            'result_summary_json' => Json::encode($this->sanitize([
                'ready_for_edit' => $ready,
                'context_completeness' => $completeness,
                'context_batch_plan' => $contextBatchPlan,
                'architecture_summary' => $bundle['architecture_summary'] ?? $bundle['architecture'] ?? [],
                'impact_summary' => $bundle['impact_summary'] ?? ['risk' => $bundle['impact_risk'] ?? 'unknown'],
                'validation_plan' => $bundle['validation_plan'] ?? [],
            ])),
            'completed_at' => !$ready ? Clock::now() : null,
        ]);
        $this->refreshCounters($runId);

        return $this->status($projectId, $runId);
    }

    /** @param array<string,mixed> $plan
     *  @return array<string,mixed>
     */
    public function planApplyBatches(
        string $runId,
        string $projectId,
        string $bundleId,
        array $plan,
    ): array {
        $run = $this->row($runId, $projectId);
        if ((string) $run['status'] !== 'waiting_for_plan') {
            throw new ToolException('RUN_NOT_APPLICABLE', 'Execution run is not accepting an edit batch plan', false, [
                'run_id' => $runId,
                'status' => $run['status'],
                'workflow_state' => $run['workflow_state'],
            ]);
        }
        $metadata = is_array($plan['metadata'] ?? null) ? $plan['metadata'] : [];
        $planRunId = trim((string) ($metadata['run_id'] ?? ''));
        $planBundleId = trim((string) ($metadata['bundle_id'] ?? ''));
        $expectedBundleId = trim((string) ($run['bundle_id'] ?? ''));
        if ($bundleId === ''
            || $expectedBundleId === ''
            || !hash_equals($expectedBundleId, $bundleId)
            || ($planRunId !== '' && !hash_equals($runId, $planRunId))
            || $planBundleId === ''
            || !hash_equals($bundleId, $planBundleId)) {
            throw new ToolException('RUN_BUNDLE_MISMATCH', 'The edit batch plan is not bound to this execution run bundle', false, [
                'run_id' => $runId,
                'expected_bundle_id' => $expectedBundleId,
                'received_bundle_id' => $bundleId,
            ]);
        }

        $operationDescriptors = [];
        $operationPaths = [];
        foreach ((array) ($plan['operations'] ?? []) as $operationIndex => $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $path = trim((string) ($operation['path'] ?? ''));
            if ($path !== '') {
                $operationPaths[] = $path;
            }
            $operationDescriptors[] = [
                'operation_index' => (int) $operationIndex,
                'path' => $path,
            ];
        }
        if (count($operationDescriptors) <= 50) {
            throw new ToolException(
                'VALIDATION_FAILED',
                'An apply batch plan requires more than 50 operations',
            );
        }
        $this->assertOperationPathsInTaskScope($run, Text::uniqueStrings($operationPaths));

        $planDigest = Ids::hash(Json::encode($plan));
        $batches = [];
        foreach (array_chunk($operationDescriptors, 50) as $batchIndex => $batchOperations) {
            $batchPaths = Text::uniqueStrings(array_map(
                static fn (array $operation): string => (string) ($operation['path'] ?? ''),
                $batchOperations,
            ));
            $operationOffset = (int) ($batchOperations[0]['operation_index'] ?? 0);
            $batches[] = [
                'batch_index' => $batchIndex,
                'batch_id' => 'apply-batch-' . substr(hash(
                    'sha256',
                    $runId . "\0" . $planDigest . "\0" . $batchIndex,
                ), 0, 24),
                'operation_offset' => $operationOffset,
                'operation_count' => count($batchOperations),
                'operation_end_exclusive' => $operationOffset + count($batchOperations),
                'paths' => $batchPaths,
            ];
        }
        $batchPlan = [
            'schema_version' => 'apply-batch-plan.v1',
            'status' => 'planned',
            'strategy' => 'ordered_child_execution_runs',
            'parent_run_id' => $runId,
            'parent_bundle_id' => $bundleId,
            'project_id' => $projectId,
            'plan_digest' => $planDigest,
            'batch_count' => count($batches),
            'max_operations_per_batch' => 50,
            'operation_count' => count($operationDescriptors),
            'batches' => $batches,
            'preserve_operation_order' => true,
            'requires_user_confirmation' => false,
            'terminal' => false,
            'next' => 'Create one child execution run per ordered batch with the original TaskContract; each child is independently guarded, validated, reindexed, and rolled back on failure.',
        ];

        $counters = Json::decode((string) $run['counters_json'], []);
        $counters['apply_compact_edit_calls'] = (int) ($counters['apply_compact_edit_calls'] ?? 0) + 1;
        $recursion = Json::decode((string) $run['recursion_json'], []);
        $recursion = $this->incrementRecursion($recursion, 'EDIT_BATCHING', [
            'plan_digest' => $planDigest,
            'batch_count' => count($batches),
        ]);
        $this->update($runId, [
            'current_phase' => 'EDIT_PLAN_WAIT',
            'status' => 'waiting_for_edit_batch',
            'workflow_state' => 'EDIT_BATCHING',
            'counters_json' => Json::encode($counters),
            'recursion_json' => Json::encode($recursion),
            'result_summary_json' => Json::encode($this->sanitize([
                'state' => 'EDIT_BATCH_PLANNED',
                'batch_plan' => $batchPlan,
            ])),
            'error_json' => null,
            'completed_at' => null,
        ]);
        $this->event($runId, [
            'event_type' => 'BATCH_PLAN_REQUIRED',
            'phase' => 'EDIT_PLAN_WAIT',
            'status' => 'waiting_for_edit_batch',
            'tool_name' => 'apply_compact_edit',
            'operation_name' => 'plan_bounded_apply_batches',
            'reason_code' => 'EDIT_BATCH_LIMIT',
            'reason_text' => 'The operation limit was reached; the parent plan was split into ordered child execution batches without writing files.',
            'output_summary' => [
                'batch_count' => count($batches),
                'operation_count' => count($operationDescriptors),
                'plan_digest' => $planDigest,
            ],
            'retry' => $recursion,
        ]);
        $this->refreshCounters($runId);

        return [
            'status' => 'EDIT_BATCH_PLANNED',
            'ready_for_apply' => false,
            'run_id' => $runId,
            'bundle_id' => $bundleId,
            'batch_plan' => $batchPlan,
            'execution_run' => $this->status($projectId, $runId),
        ];
    }

    /** @param array<string,mixed> $plan */
    public function beginApply(string $runId, string $projectId, array $plan): void
    {
        $run = $this->row($runId, $projectId);
        if ((string) $run['status'] !== 'waiting_for_plan') {
            throw new ToolException('RUN_NOT_APPLICABLE', 'Execution run is not accepting an edit plan', false, [
                'run_id' => $runId,
                'status' => $run['status'],
                'workflow_state' => $run['workflow_state'],
            ]);
        }
        $metadata = is_array($plan['metadata'] ?? null) ? $plan['metadata'] : [];
        $planRunId = trim((string) ($metadata['run_id'] ?? ''));
        $planBundleId = trim((string) ($metadata['bundle_id'] ?? ''));
        $expectedBundleId = trim((string) ($run['bundle_id'] ?? ''));
        if (($planRunId !== '' && !hash_equals($runId, $planRunId))
            || $planBundleId === ''
            || $expectedBundleId === ''
            || !hash_equals($expectedBundleId, $planBundleId)) {
            throw new ToolException('RUN_BUNDLE_MISMATCH', 'The edit plan is not bound to this execution run bundle', false, [
                'run_id' => $runId,
                'expected_bundle_id' => $expectedBundleId,
                'received_bundle_id' => $planBundleId,
            ]);
        }

        $operations = [];
        foreach ((array) ($plan['operations'] ?? []) as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $path = trim((string) ($operation['path'] ?? ''));
            if ($path !== '') {
                $operations[$path] = ($operations[$path] ?? 0) + 1;
            }
        }
        $this->assertOperationPathsInTaskScope($run, array_keys($operations));
        foreach ($operations as $path => $count) {
            $this->file($runId, $path, ['selected' => true, 'operation_count' => $count]);
        }

        $counters = Json::decode((string) $run['counters_json'], []);
        $counters['apply_compact_edit_calls'] = (int) ($counters['apply_compact_edit_calls'] ?? 0) + 1;
        $this->update($runId, [
            'current_phase' => 'ATOMIC_APPLY',
            'status' => 'running',
            'counters_json' => Json::encode($counters),
            'error_json' => null,
            'completed_at' => null,
        ]);
        $this->event($runId, [
            'event_type' => 'CALL_STARTED',
            'phase' => 'ATOMIC_APPLY',
            'status' => 'running',
            'tool_name' => 'apply_compact_edit',
            'operation_name' => 'seal_lock_apply_validate_reindex',
            'reason_code' => 'IMPACT_VALIDATION',
            'reason_text' => 'Started one guarded multi-file edit transaction.',
            'input_summary' => [
                'schema_version' => $plan['schema_version'] ?? 'edit-plan.v1',
                'operation_count' => array_sum($operations),
                'file_count' => count($operations),
                'recursion_reason' => $plan['metadata']['recursion_reason'] ?? 'NORMAL',
            ],
            'targets' => array_keys($operations),
        ]);
    }

    /** @param array<string,mixed> $run
     *  @param list<string> $operationPaths
     */
    private function assertOperationPathsInTaskScope(array $run, array $operationPaths): void
    {
        $statement = $this->db()->prepare(
            'SELECT path FROM execution_run_files WHERE run_id = :run_id AND selected = 1'
        );
        $statement->execute(['run_id' => (string) $run['run_id']]);
        $selected = [];
        while (($path = $statement->fetchColumn()) !== false) {
            $normalized = $this->taskScopePath((string) $path);
            if ($normalized !== null) {
                $selected[$normalized] = true;
            }
        }

        $contract = Json::decode((string) ($run['task_contract_json'] ?? ''), []);
        $contractScopes = is_array($contract['allowed_scope'] ?? null)
            ? $contract['allowed_scope']
            : [];
        $repositoryWide = false;
        $scopes = [];
        foreach ($contractScopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if (in_array($scope, ['current_project', '.'], true)) {
                $repositoryWide = true;
                continue;
            }
            $normalized = $this->taskScopePath($scope);
            if ($normalized !== null) {
                $scopes[] = $normalized;
            }
        }
        $forbiddenScopes = [];
        foreach (is_array($contract['forbidden_scope'] ?? null)
            ? $contract['forbidden_scope']
            : [] as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            if (in_array(trim(strtolower($scope)), [
                'external systems',
                'irreversible operations',
                'unrelated projects',
                'writes',
            ], true)) {
                continue;
            }
            $normalized = $this->taskScopePath($scope);
            if ($normalized !== null) {
                $forbiddenScopes[] = $normalized;
            }
        }

        foreach ($operationPaths as $path) {
            $normalized = $this->taskScopePath($path);
            if ($normalized !== null) {
                foreach ($forbiddenScopes as $scope) {
                    if ($normalized === $scope
                        || str_starts_with($normalized, $scope . '/')
                        || Text::globMatches($scope, $normalized)) {
                        throw new ToolException(
                            'EDIT_TASK_SCOPE_DENIED',
                            'Edit path matches TaskContract forbidden_scope; filesystem permissions were not evaluated',
                            false,
                            [
                                'path' => $normalized,
                                'policy_reason' => 'forbidden_task_contract',
                                'matched_rule' => $scope,
                                'filesystem_permissions_evaluated' => false,
                            ],
                        );
                    }
                }
            }
            $allowed = $repositoryWide || ($normalized !== null && isset($selected[$normalized]));
            if (!$allowed && $normalized !== null) {
                foreach ($scopes as $scope) {
                    if ($normalized === $scope
                        || str_starts_with($normalized, $scope . '/')
                        || Text::globMatches($scope, $normalized)) {
                        $allowed = true;
                        break;
                    }
                }
            }
            if ($allowed) {
                continue;
            }
            throw new ToolException(
                'EDIT_TASK_SCOPE_DENIED',
                'Edit path is outside the selected files and TaskContract scope; filesystem permissions were not evaluated',
                false,
                [
                    'path' => $normalized ?? $path,
                    'policy_reason' => 'outside_task_contract',
                    'filesystem_permissions_evaluated' => false,
                ],
            );
        }
    }

    private function taskScopePath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === ''
            || str_starts_with($path, '/')
            || preg_match('~^[A-Za-z]:/~D', $path) === 1
            || preg_match('~(?:^|/)\.\.(?:/|$)~D', $path) === 1) {
            return null;
        }
        $path = preg_replace('~^\./+~', '', $path) ?? $path;
        $path = trim($path, '/');
        return $path === '' ? null : $path;
    }

    /** @param array<string,mixed> $result */
    public function completeApply(string $runId, string $projectId, array $result): array
    {
        $run = $this->row($runId, $projectId);
        $validation = is_array($result['validation'] ?? null) ? $result['validation'] : [];
        $validationStatus = strtolower((string) ($validation['status'] ?? 'unknown'));
        $checks = is_array($validation['checks'] ?? null) ? $validation['checks'] : [];

        $this->phase($runId, 'SYNTAX_VALIDATE', 'running');
        $this->event($runId, [
            'event_type' => 'VALIDATION_RESULT',
            'phase' => 'SYNTAX_VALIDATE',
            'status' => $validationStatus === 'passed' ? 'completed' : 'failed',
            'operation_name' => 'fixed_syntax_and_diff_checks',
            'reason_code' => 'IMPACT_VALIDATION',
            'reason_text' => 'Executed server-approved syntax, JSON and transaction diff checks.',
            'output_summary' => ['status' => $validationStatus, 'checks' => $checks],
        ]);

        $this->phase($runId, 'REGRESSION_VALIDATE', 'running');
        $regression = is_array($result['regression_validation'] ?? null)
            ? $result['regression_validation']
            : ['status' => 'skipped', 'reason' => 'No server-approved regression adapter matched the changed paths.'];
        $this->event($runId, [
            'event_type' => 'VALIDATION_RESULT',
            'phase' => 'REGRESSION_VALIDATE',
            'status' => ($regression['status'] ?? '') === 'failed' ? 'failed' : 'completed',
            'operation_name' => 'server_approved_regression_batch',
            'reason_code' => 'TEST_DISCOVERY',
            'reason_text' => 'Executed or explicitly skipped the fixed regression batch selected by the server.',
            'output_summary' => $regression,
        ]);

        $reportFiles = is_array($result['change_report']['files'] ?? null)
            ? $result['change_report']['files']
            : (is_array($result['files'] ?? null) ? $result['files'] : []);
        foreach ($reportFiles as $report) {
            if (!is_array($report)) {
                continue;
            }
            $path = trim((string) ($report['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $this->file($runId, $path, [
                'selected' => true,
                'modified' => true,
                'pre_sha256' => (string) ($report['before_sha256'] ?? $report['pre_sha256'] ?? ''),
                'post_sha256' => (string) ($report['after_sha256'] ?? $report['post_sha256'] ?? ''),
                'validation' => $validation,
                'diff' => (string) ($report['diff'] ?? ''),
                'diff_truncated' => (bool) ($report['diff_truncated'] ?? false),
            ]);
        }

        $this->phase($runId, 'IMPACT_REVIEW', 'running');
        $impact = is_array($result['impact_delta'] ?? null) ? $result['impact_delta'] : [];
        $this->event($runId, [
            'event_type' => 'IMPACT_REVIEWED',
            'phase' => 'IMPACT_REVIEW',
            'status' => 'completed',
            'operation_name' => 'post_index_impact_delta',
            'reason_code' => 'IMPACT_VALIDATION',
            'reason_text' => 'Compared the post-edit symbol graph with the sealed bundle scope.',
            'output_summary' => $impact,
        ]);

        $rolledBack = (bool) ($result['rolled_back'] ?? false);
        $validationFailed = $validationStatus === 'failed' || (($regression['status'] ?? '') === 'failed');
        $impactExpansion = (bool) ($impact['requires_followup'] ?? false);
        $workflowState = $validationFailed
            ? 'VALIDATION_REPAIR'
            : ($impactExpansion ? 'IMPACT_EXPANSION' : 'COMPLETED');
        $recursion = Json::decode((string) $run['recursion_json'], []);
        if ($validationFailed) {
            $recursion = $this->incrementRecursion($recursion, 'VALIDATION_REPAIR', [
                'validation' => $validation,
                'regression' => $regression,
            ]);
        } elseif ($impactExpansion) {
            $recursion = $this->incrementRecursion($recursion, 'IMPACT_EXPANSION', $impact);
        }

        $terminal = !$validationFailed && !$impactExpansion;
        $counters = Json::decode((string) $run['counters_json'], []);
        if ($terminal) {
            $counters['successful_apply_compact_edit_calls'] =
                (int) ($counters['successful_apply_compact_edit_calls'] ?? 0) + 1;
        }
        if ($rolledBack) {
            $counters['automatic_rollback_count'] = (int) ($counters['automatic_rollback_count'] ?? 0) + 1;
        }
        $this->update($runId, [
            'edit_id' => (string) ($result['edit_id'] ?? ''),
            'index_revision' => max(0, (int) ($result['index_revision'] ?? $run['index_revision'])),
            'current_phase' => $terminal ? 'COMPLETED' : 'EDIT_PLAN_WAIT',
            'status' => $terminal ? 'completed' : 'waiting_for_plan',
            'workflow_state' => $workflowState,
            'counters_json' => Json::encode($counters),
            'recursion_json' => Json::encode($recursion),
            'result_summary_json' => Json::encode($this->sanitize([
                'state' => $result['state'] ?? '',
                'changed_files' => count($reportFiles),
                'validation' => $validation,
                'regression_validation' => $regression,
                'rolled_back' => $rolledBack,
                'impact_delta' => $impact,
                'timing_ms' => $result['timing_ms'] ?? [],
            ])),
            'completed_at' => $terminal ? Clock::now() : null,
        ]);
        $this->event($runId, [
            'event_type' => $terminal ? 'RUN_COMPLETED' : 'REPLAN_REQUIRED',
            'phase' => $terminal ? 'COMPLETED' : 'EDIT_PLAN_WAIT',
            'status' => $terminal ? 'completed' : 'waiting_for_plan',
            'tool_name' => 'apply_compact_edit',
            'operation_name' => 'closed_loop_result',
            'reason_code' => $validationFailed ? 'VALIDATION_REPAIR' : 'IMPACT_VALIDATION',
            'reason_text' => $terminal
                ? 'The guarded edit, validation, reindex and impact review completed.'
                : 'A typed automatic recursion is required; no user confirmation is needed.',
            'output_summary' => [
                'workflow_state' => $workflowState,
                'rolled_back' => $rolledBack,
                'edit_id' => $result['edit_id'] ?? '',
            ],
            'retry' => $recursion,
        ]);
        $this->refreshCounters($runId);

        return $this->status($projectId, $runId);
    }

    public function fail(
        string $runId,
        string $projectId,
        Throwable $error,
        string $toolName = 'apply_compact_edit',
    ): void
    {
        $run = $this->row($runId, $projectId);
        $code = $error instanceof ToolException ? $error->errorCode : 'INTERNAL_ERROR';
        $details = $error instanceof ToolException ? $error->details : [];
        $typed = $code === 'EDIT_REPLAN_REQUIRED'
            ? 'CONFLICT_REPLAN'
            : (in_array($code, ['INDEX_SYMBOL_QUERY_FAILED', 'INDEX_SYMBOL_REFRESH_FAILED'], true)
                ? 'CONTEXT_INDEX_RETRY'
            : (in_array($code, ['INDEX_NOT_READY', 'CONTEXT_BATCH_REQUIRED'], true)
                ? 'CONTEXT_BATCHING'
                : (str_contains($code, 'VALIDATION') ? 'VALIDATION_REPAIR' : 'FAILED')));
        $recursion = Json::decode((string) $run['recursion_json'], []);
        $recoverableStates = ['CONFLICT_REPLAN', 'CONTEXT_BATCHING', 'VALIDATION_REPAIR'];
        if (in_array($typed, $recoverableStates, true)) {
            $recursion = $this->incrementRecursion($recursion, $typed, [
                'code' => $code,
                'details' => $details,
            ]);
        }
        $budgets = Json::decode((string) $run['budgets_json'], []);
        $retryLimit = $typed === 'CONTEXT_BATCHING'
            ? max(1, (int) ($budgets['context_batches_max'] ?? 8))
            : 2;
        $automaticRetry = in_array($typed, $recoverableStates, true)
            && (int) ($recursion['same_error_count'] ?? 0) < 3
            && (int) ($recursion['counts'][$typed] ?? 0) <= $retryLimit;
        $terminalRetryable = $typed === 'CONTEXT_INDEX_RETRY'
            && $error instanceof ToolException
            && $error->retryable;
        $retryable = $automaticRetry || $terminalRetryable;
        $waitingPhase = $typed === 'CONTEXT_BATCHING' ? 'CONTEXT_COLLECT' : 'EDIT_PLAN_WAIT';
        $waitingStatus = $typed === 'CONTEXT_BATCHING'
            ? 'waiting_for_context_batch'
            : 'waiting_for_plan';
        $this->update($runId, [
            'current_phase' => $automaticRetry ? $waitingPhase : 'FAILED',
            'status' => $automaticRetry ? $waitingStatus : 'failed',
            'workflow_state' => $retryable ? $typed : 'FAILED',
            'recursion_json' => Json::encode($recursion),
            'error_json' => Json::encode($this->sanitize([
                'code' => $code,
                'message' => $error->getMessage(),
                'details' => $details,
                'retryable' => $retryable,
            ])),
            'completed_at' => $automaticRetry ? null : Clock::now(),
        ]);
        $this->event($runId, [
            'event_type' => $automaticRetry ? 'REPLAN_REQUIRED' : 'RUN_FAILED',
            'phase' => $automaticRetry ? $waitingPhase : 'FAILED',
            'status' => $automaticRetry ? $waitingStatus : 'failed',
            'tool_name' => $toolName,
            'operation_name' => $toolName === 'get_edit_bundle' ? 'context_failure' : 'typed_failure',
            'reason_code' => $typed === 'CONFLICT_REPLAN'
                ? 'CONFLICT_REFRESH'
                : ($typed === 'CONTEXT_BATCHING' ? 'CONTEXT_BATCH_LIMIT' : $typed),
            'reason_text' => $automaticRetry
                ? 'The server returned a typed bounded recursion with refreshed evidence.'
                : ($terminalRetryable
                    ? 'The indexed query failed with preserved evidence; start a new bounded retry instead of treating the target as absent.'
                    : 'The run stopped because the failure is terminal or its retry budget is exhausted.'),
            'error' => ['code' => $code, 'message' => $error->getMessage(), 'details' => $details],
            'retry' => $recursion,
        ]);
    }

    /** @return array<string,mixed> */
    public function status(string $projectId, string $runId): array
    {
        $row = $this->row($runId, $projectId);
        $latest = (int) $this->scalar(
            'SELECT COALESCE(MAX(sequence), 0) FROM execution_run_events WHERE run_id = :run_id',
            ['run_id' => $runId],
        );

        return $this->sanitize([
            'schema_version' => (string) $row['schema_version'],
            'task_id' => (string) $row['task_id'],
            'run_id' => (string) $row['run_id'],
            'trace_id' => (string) $row['trace_id'],
            'project_id' => (string) $row['project_id'],
            'supersedes_run_id' => (string) ($row['supersedes_run_id'] ?? ''),
            'superseded_by_run_id' => (string) ($row['superseded_by_run_id'] ?? ''),
            'task_digest' => (string) $row['task_digest'],
            'original_requirement' => (string) $row['task_original_redacted'],
            'task_contract' => Json::decode((string) $row['task_contract_json'], []),
            'current_phase' => (string) $row['current_phase'],
            'status' => (string) $row['status'],
            'terminal' => in_array((string) $row['status'], self::TERMINAL, true),
            'workflow_state' => (string) $row['workflow_state'],
            'bundle_id' => (string) ($row['bundle_id'] ?? ''),
            'edit_id' => (string) ($row['edit_id'] ?? ''),
            'index_revision' => (int) $row['index_revision'],
            'budgets' => Json::decode((string) $row['budgets_json'], []),
            'counters' => Json::decode((string) $row['counters_json'], []),
            'recursion' => Json::decode((string) $row['recursion_json'], []),
            'result_summary' => Json::decode((string) $row['result_summary_json'], []),
            'error' => Json::decode((string) ($row['error_json'] ?? ''), null),
            'latest_sequence' => $latest,
            'started_at' => (string) $row['started_at'],
            'updated_at' => (string) $row['updated_at'],
            'completed_at' => (string) ($row['completed_at'] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $options
     *  @return array<string,mixed>
     */
    public function trace(string $projectId, string $runId, array $options = []): array
    {
        $status = $this->status($projectId, $runId);
        $after = max(0, (int) ($options['after_sequence'] ?? 0));
        $limit = max(1, min(200, (int) ($options['limit'] ?? 100)));
        $statement = $this->db()->prepare(
            'SELECT * FROM execution_run_events
              WHERE run_id = :run_id AND sequence > :after
              ORDER BY sequence ASC LIMIT ' . ($limit + 1)
        );
        $statement->bindValue(':run_id', $runId, PDO::PARAM_STR);
        $statement->bindValue(':after', $after, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $events = array_map(fn (array $row): array => $this->eventRow($row), $rows);

        $files = [];
        if ((bool) ($options['include_files'] ?? true)) {
            $path = trim((string) ($options['path'] ?? ''));
            $sql = 'SELECT * FROM execution_run_files WHERE run_id = :run_id';
            $parameters = ['run_id' => $runId];
            if ($path !== '') {
                $sql .= ' AND path = :path';
                $parameters['path'] = $path;
            }
            $sql .= ' ORDER BY modified DESC, selected DESC, path ASC';
            $fileStatement = $this->db()->prepare($sql);
            $fileStatement->execute($parameters);
            foreach ($fileStatement->fetchAll(PDO::FETCH_ASSOC) as $fileRow) {
                $files[] = $this->fileRow($fileRow, (bool) ($options['include_diffs'] ?? false));
            }
        }

        return $this->sanitize([
            'schema_version' => 'execution-trace.v1',
            'status' => $status,
            'events' => $events,
            'files' => $files,
            'page' => [
                'after_sequence' => $after,
                'next_sequence' => $events === [] ? $after : (int) end($events)['sequence'],
                'limit' => $limit,
                'has_more' => $hasMore,
            ],
            'privacy' => [
                'redacted' => true,
                'hidden_reasoning_included' => false,
                'export_safe' => true,
            ],
        ]);
    }

    /** @param array<string,mixed> $data */
    public function event(string $runId, array $data): string
    {
        $phase = strtoupper(trim((string) ($data['phase'] ?? '')));
        if (!in_array($phase, self::PHASES, true)) {
            throw new ToolException('RUN_PHASE_INVALID', 'Unknown execution run phase', false, ['phase' => $phase]);
        }
        $eventId = Ids::make('event');
        $statement = $this->db()->prepare(
            'INSERT INTO execution_run_events(
                event_id, run_id, parent_event_id, event_type, phase, status,
                reason_code, reason_text, tool_name, operation_name,
                input_summary_json, output_summary_json, targets_json, metrics_json,
                retry_json, error_json, started_at, completed_at, duration_ms
             ) VALUES(
                :event_id, :run_id, :parent_event_id, :event_type, :phase, :status,
                :reason_code, :reason_text, :tool_name, :operation_name,
                :input_summary, :output_summary, :targets, :metrics,
                :retry, :error, :started_at, :completed_at, :duration_ms
             )'
        );
        $statement->execute([
            'event_id' => $eventId,
            'run_id' => $runId,
            'parent_event_id' => $this->nullable((string) ($data['parent_event_id'] ?? '')),
            'event_type' => Text::truncate(strtoupper((string) ($data['event_type'] ?? 'EVENT')), 80),
            'phase' => $phase,
            'status' => Text::truncate(strtolower((string) ($data['status'] ?? 'completed')), 40),
            'reason_code' => $this->nullable(Text::truncate(strtoupper((string) ($data['reason_code'] ?? '')), 80)),
            'reason_text' => $this->nullable(Text::truncate($this->sanitizeText((string) ($data['reason_text'] ?? '')), 1_000)),
            'tool_name' => $this->nullable(Text::truncate((string) ($data['tool_name'] ?? ''), 120)),
            'operation_name' => $this->nullable(Text::truncate((string) ($data['operation_name'] ?? ''), 160)),
            'input_summary' => Json::encode($this->sanitize($data['input_summary'] ?? [])),
            'output_summary' => Json::encode($this->sanitize($data['output_summary'] ?? [])),
            'targets' => Json::encode($this->sanitize($data['targets'] ?? [])),
            'metrics' => Json::encode($this->sanitize($data['metrics'] ?? [])),
            'retry' => Json::encode($this->sanitize($data['retry'] ?? [])),
            'error' => isset($data['error']) ? Json::encode($this->sanitize($data['error'])) : null,
            'started_at' => (string) ($data['started_at'] ?? Clock::now()),
            'completed_at' => (string) ($data['completed_at'] ?? Clock::now()),
            'duration_ms' => max(0, (int) ($data['duration_ms'] ?? 0)),
        ]);
        $this->db()->prepare('UPDATE execution_runs SET updated_at = :now WHERE run_id = :run_id')
            ->execute(['now' => Clock::now(), 'run_id' => $runId]);

        return $eventId;
    }

    private function phase(string $runId, string $phase, string $status): void
    {
        $this->update($runId, ['current_phase' => $phase, 'status' => $status]);
        $this->event($runId, [
            'event_type' => 'PHASE_STARTED',
            'phase' => $phase,
            'status' => $status,
            'operation_name' => strtolower($phase),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function file(string $runId, string $path, array $data): void
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '' || str_contains($path, '../')) {
            return;
        }
        $existingStatement = $this->db()->prepare(
            'SELECT * FROM execution_run_files WHERE run_id = :run_id AND path = :path'
        );
        $existingStatement->execute(['run_id' => $runId, 'path' => $path]);
        $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);
        $existing = is_array($existing) ? $existing : [];
        $value = static fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data)
            ? $data[$key]
            : ($existing[$key] ?? $fallback);
        $regions = array_key_exists('regions', $data)
            ? $this->sanitize($data['regions'])
            : Json::decode((string) ($existing['regions_json'] ?? '[]'), []);
        $impact = array_key_exists('impact', $data)
            ? $this->sanitize($data['impact'])
            : Json::decode((string) ($existing['impact_json'] ?? '{}'), []);
        $validation = array_key_exists('validation', $data)
            ? $this->sanitize($data['validation'])
            : Json::decode((string) ($existing['validation_json'] ?? '{}'), []);
        $diff = array_key_exists('diff', $data)
            ? Text::truncate($this->sanitizeText((string) $data['diff']), 80_000)
            : (string) ($existing['diff_redacted'] ?? '');

        $statement = $this->db()->prepare(
            'INSERT INTO execution_run_files(
                run_id, path, candidate, selected, materialized, excluded, modified,
                candidate_reason, excluded_reason, regions_json, operation_count,
                pre_sha256, post_sha256, impact_json, validation_json, diff_redacted,
                diff_truncated, updated_at
             ) VALUES(
                :run_id, :path, :candidate, :selected, :materialized, :excluded, :modified,
                :candidate_reason, :excluded_reason, :regions, :operation_count,
                :pre_sha256, :post_sha256, :impact, :validation, :diff,
                :diff_truncated, :updated_at
             )
             ON CONFLICT(run_id, path) DO UPDATE SET
                candidate = excluded.candidate, selected = excluded.selected,
                materialized = excluded.materialized, excluded = excluded.excluded,
                modified = excluded.modified, candidate_reason = excluded.candidate_reason,
                excluded_reason = excluded.excluded_reason, regions_json = excluded.regions_json,
                operation_count = excluded.operation_count, pre_sha256 = excluded.pre_sha256,
                post_sha256 = excluded.post_sha256, impact_json = excluded.impact_json,
                validation_json = excluded.validation_json, diff_redacted = excluded.diff_redacted,
                diff_truncated = excluded.diff_truncated, updated_at = excluded.updated_at'
        );
        $statement->execute([
            'run_id' => $runId,
            'path' => $path,
            'candidate' => (int) (bool) $value('candidate', false),
            'selected' => (int) (bool) $value('selected', false),
            'materialized' => (int) (bool) $value('materialized', false),
            'excluded' => (int) (bool) $value('excluded', false),
            'modified' => (int) (bool) $value('modified', false),
            'candidate_reason' => $this->nullable(Text::truncate($this->sanitizeText((string) $value('candidate_reason', '')), 1_000)),
            'excluded_reason' => $this->nullable(Text::truncate($this->sanitizeText((string) $value('excluded_reason', '')), 1_000)),
            'regions' => Json::encode($regions),
            'operation_count' => max(0, (int) $value('operation_count', 0)),
            'pre_sha256' => $this->nullable((string) $value('pre_sha256', '')),
            'post_sha256' => $this->nullable((string) $value('post_sha256', '')),
            'impact' => Json::encode($impact),
            'validation' => Json::encode($validation),
            'diff' => $this->nullable($diff),
            'diff_truncated' => (int) (bool) $value('diff_truncated', false),
            'updated_at' => Clock::now(),
        ]);
    }

    /** @param array<string,mixed> $fields */
    private function update(string $runId, array $fields): void
    {
        $allowed = [
            'bundle_id', 'edit_id', 'index_revision', 'current_phase', 'status',
            'workflow_state', 'budgets_json', 'counters_json', 'recursion_json',
            'result_summary_json', 'error_json', 'completed_at', 'superseded_by_run_id',
        ];
        $sets = [];
        $parameters = ['run_id' => $runId, 'updated_at' => Clock::now()];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = $key . ' = :' . $key;
            $parameters[$key] = $value === '' && in_array(
                $key,
                ['bundle_id', 'edit_id', 'error_json', 'completed_at'],
                true,
            ) ? null : $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = :updated_at';
        $statement = $this->db()->prepare(
            'UPDATE execution_runs SET ' . implode(', ', $sets) . ' WHERE run_id = :run_id'
        );
        $statement->execute($parameters);
    }

    private function refreshCounters(string $runId): void
    {
        $statement = $this->db()->prepare('SELECT counters_json FROM execution_runs WHERE run_id = :run_id');
        $statement->execute(['run_id' => $runId]);
        $counters = Json::decode((string) $statement->fetchColumn(), []);
        foreach ([
            'candidate_file_count' => 'candidate = 1',
            'materialized_file_count' => 'materialized = 1',
            'modified_file_count' => 'modified = 1',
        ] as $key => $where) {
            $counters[$key] = (int) $this->scalar(
                'SELECT COUNT(*) FROM execution_run_files WHERE run_id = :run_id AND ' . $where,
                ['run_id' => $runId],
            );
        }
        $counters['event_count'] = (int) $this->scalar(
            'SELECT COUNT(*) FROM execution_run_events WHERE run_id = :run_id',
            ['run_id' => $runId],
        );
        $this->update($runId, ['counters_json' => Json::encode($counters)]);
    }

    /** @param array<string,mixed> $recursion
     *  @return array<string,mixed>
     */
    private function incrementRecursion(array $recursion, string $reason, mixed $error): array
    {
        $counts = is_array($recursion['counts'] ?? null) ? $recursion['counts'] : [];
        $counts[$reason] = (int) ($counts[$reason] ?? 0) + 1;
        $fingerprint = Ids::hash(Json::canonical($this->sanitize($error)));
        $same = hash_equals((string) ($recursion['last_error_fingerprint'] ?? ''), $fingerprint)
            ? (int) ($recursion['same_error_count'] ?? 0) + 1
            : 1;

        return [
            'counts' => $counts,
            'same_error_count' => $same,
            'last_error_fingerprint' => $fingerprint,
        ];
    }

    /** @return array<string,mixed> */
    private function row(string $runId, string $projectId): array
    {
        $statement = $this->db()->prepare(
            'SELECT * FROM execution_runs WHERE run_id = :run_id AND project_id = :project_id LIMIT 1'
        );
        $statement->execute(['run_id' => $runId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ToolException('RUN_NOT_FOUND', 'Execution run was not found in this project', false, [
                'run_id' => $runId,
                'project_id' => $projectId,
            ]);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function eventRow(array $row): array
    {
        return $this->sanitize([
            'sequence' => (int) $row['sequence'],
            'event_id' => (string) $row['event_id'],
            'parent_event_id' => (string) ($row['parent_event_id'] ?? ''),
            'event_type' => (string) $row['event_type'],
            'phase' => (string) $row['phase'],
            'status' => (string) $row['status'],
            'reason_code' => (string) ($row['reason_code'] ?? ''),
            'reason_text' => (string) ($row['reason_text'] ?? ''),
            'tool_name' => (string) ($row['tool_name'] ?? ''),
            'operation_name' => (string) ($row['operation_name'] ?? ''),
            'input_summary' => Json::decode((string) $row['input_summary_json'], []),
            'output_summary' => Json::decode((string) $row['output_summary_json'], []),
            'targets' => Json::decode((string) $row['targets_json'], []),
            'metrics' => Json::decode((string) $row['metrics_json'], []),
            'retry' => Json::decode((string) $row['retry_json'], []),
            'error' => Json::decode((string) ($row['error_json'] ?? ''), null),
            'started_at' => (string) $row['started_at'],
            'completed_at' => (string) ($row['completed_at'] ?? ''),
            'duration_ms' => (int) $row['duration_ms'],
        ]);
    }

    /** @return array<string,mixed> */
    private function fileRow(array $row, bool $includeDiff): array
    {
        $result = [
            'path' => (string) $row['path'],
            'candidate' => (bool) $row['candidate'],
            'selected' => (bool) $row['selected'],
            'materialized' => (bool) $row['materialized'],
            'excluded' => (bool) $row['excluded'],
            'modified' => (bool) $row['modified'],
            'candidate_reason' => (string) ($row['candidate_reason'] ?? ''),
            'excluded_reason' => (string) ($row['excluded_reason'] ?? ''),
            'regions' => Json::decode((string) $row['regions_json'], []),
            'operation_count' => (int) $row['operation_count'],
            'pre_sha256' => (string) ($row['pre_sha256'] ?? ''),
            'post_sha256' => (string) ($row['post_sha256'] ?? ''),
            'impact' => Json::decode((string) $row['impact_json'], []),
            'validation' => Json::decode((string) $row['validation_json'], []),
            'diff_available' => trim((string) ($row['diff_redacted'] ?? '')) !== '',
            'diff_truncated' => (bool) $row['diff_truncated'],
        ];
        if ($includeDiff) {
            $result['diff'] = (string) ($row['diff_redacted'] ?? '');
        }
        return $this->sanitize($result);
    }

    private function pruneExpired(): void
    {
        $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - $this->config->duration('privacy.execution_run_ttl'));
        $statement = $this->db()->prepare(
            "DELETE FROM execution_runs
              WHERE status IN ('completed', 'planned', 'failed', 'rolled_back', 'superseded')
                AND updated_at < :cutoff"
        );
        $statement->execute(['cutoff' => $cutoff]);
    }

    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    private function db(): PDO
    {
        return $this->store->database();
    }

    private function nullable(string $value): ?string
    {
        return trim($value) === '' ? null : $value;
    }

    private function sanitizeText(string $value): string
    {
        [$value] = Redactor::string($value);
        $value = preg_replace('~(?<![A-Za-z0-9_])/(?:Users|home)/[^/\s]+~', '~', $value) ?? $value;
        $value = preg_replace('~(?i)(?<![A-Za-z0-9_])[A-Z]:\\\\Users\\\\[^\\\\\s]+~', '~', $value) ?? $value;
        return $value;
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(
            '/(?:chain[_-]?of[_-]?thought|hidden[_-]?reasoning|reasoning[_-]?tokens|internal[_-]?thought)/i',
            $key,
        ) === 1) {
            return '[OMITTED]';
        }
        if (is_string($value)) {
            return $this->sanitizeText($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $current => $item) {
            $result[$current] = $this->sanitize($item, is_string($current) ? $current : null);
        }
        [$result] = Redactor::value($result);
        return $result;
    }
}
