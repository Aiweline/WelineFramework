<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

/**
 * Project-scoped facade for the persistent code, documentation, skill, and edit index.
 *
 * Discovery and parsing live behind this facade. Read tools query the persisted project
 * index and trigger either the configured freshness refresh, an exact-path refresh, or
 * one forced incremental refresh before proving a symbol-only target absent.
 */
final class IntelligenceService
{
    /** @var array<string, ProjectIndex> */
    private array $projectIndexes = [];
    private readonly ProjectReadinessService $readiness;

    public function __construct(
        private readonly Store $learningStore,
        private readonly Config $config,
    ) {
        $this->readiness = new ProjectReadinessService($config, new ProcessRunner());
    }

    public function __destruct()
    {
        foreach ($this->projectIndexes as $index) {
            $index->close();
        }
        $this->projectIndexes = [];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function call(string $name, array $input): array
    {
        return match ($name) {
            'prepare_project' => $this->prepareProject($input),
            'repair_project_docs' => $this->repairProjectDocs($input),
            'set_session_directives' => $this->setSessionDirectives($input),
            'resolve_deploy_plan' => $this->resolveDeployPlan($input),
            'project_index_status' => $this->projectIndexStatus($input),
            'index_project' => $this->indexProject($input),
            'verify_learning_projection' => $this->verifyLearningProjection($input),
            'resolve_task_context' => $this->resolveTaskContext($input),
            'get_edit_bundle' => $this->getEditBundle($input),
            'search_project_knowledge' => $this->searchProjectKnowledge($input),
            'get_indexed_document' => $this->getIndexedDocument($input),
            'get_indexed_files' => $this->getIndexedFiles($input),
            'inspect_symbol' => $this->inspectSymbol($input),
            'resolve_skill' => $this->resolveSkill($input),
            'get_skill' => $this->getSkill($input),
            'record_index_feedback' => $this->recordIndexFeedback($input),
            'prepare_edit' => $this->prepareEdit($input),
            'apply_compact_edit' => $this->applyCompactEdit($input),
            'apply_edit' => $this->applyEdit($input),
            'get_edit_status' => $this->getEditStatus($input),
            'get_run_status' => $this->getRunStatus($input),
            'get_run_trace' => $this->getRunTrace($input),
            'validate_change' => $this->validateChange($input),
            'rollback_edit' => $this->rollbackEdit($input),
            'check_document_drift' => $this->checkDocumentDrift($input),
            'sync_module_knowledge' => $this->syncModuleKnowledge($input),
            default => throw new ToolException('NOT_FOUND', 'Unknown project intelligence tool: ' . $name),
        };
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'enabled' => (bool) $this->config->get('index.enabled', true),
            'engine' => 'sqlite_fts5_sparse_vector_content_store',
            'neural_embeddings' => false,
            'indexed_file_content_store' => true,
            'batch_indexed_file_read' => true,
            'compact_edit_bundle' => true,
            'compact_edit_lifecycle' => true,
            'durable_execution_runs' => true,
            'execution_run_schema' => 'execution-run.v1',
            'mcp_app_live_trace' => true,
            'learning_projection_closed_loop' => false,
            'learning_projection_verification' => 'retired_in_0.13.0',
            'per_file_edit_lock_queue' => true,
            'edit_lock_ownership' => 'kernel_flock_not_lockfile_existence',
            'edit_lock_timeout_ms' => (int) $this->config->get('editing.lock_timeout_ms', 30_000),
            'edit_lock_poll_interval_ms' => (int) $this->config->get('editing.lock_poll_interval_ms', 50),
            'interrupted_edit_recovery' => 'next_edit_or_status_hash_reconciliation',
            'stale_edit_replan_regions' => true,
            'query_time_repository_scan' => false,
            'auto_refresh' => (bool) $this->config->get('index.auto_refresh', true),
            'post_tool_incremental_refresh' => (bool) $this->config->get('index.auto_refresh', true),
            'post_tool_refresh_strategy' => 'mutation_filtered_targeted_sidecar',
            'index_sidecar' => IndexSidecar::status($this->config),
            'refresh_interval_seconds' => $this->config->duration('index.refresh_interval'),
            'editing_enabled' => (bool) $this->config->get('editing.enabled', true),
            'config_runtime_migrations' => $this->config->runtimeMigrations(),
            'codex_document_planner' => (bool) $this->config->get('knowledge.codex.enabled', false),
            'readiness_protocol' => 'project-readiness.v1',
            'guidance_protocol' => 'guidance-bundle.v1',
            'module_knowledge_root' => 'app/code/{Vendor}/{Module}/doc',
            'static_development_skills' => false,
        ];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function assertProjectReadiness(array $input): array
    {
        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            return $this->readiness->assertReady($index, $input);
        });
    }

    /** @param array<string,mixed> $input */
    private function prepareProject(array $input): array
    {
        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            return $this->readiness->prepare($index, $input);
        });
    }

    /** @param array<string,mixed> $input */
    private function repairProjectDocs(array $input): array
    {
        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            return $this->readiness->repair($index, $input);
        });
    }

    /** @param array<string,mixed> $input */
    private function setSessionDirectives(array $input): array
    {
        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            return $this->readiness->setDirectives($index, $input);
        });
    }

    /** @param array<string,mixed> $input */
    private function resolveDeployPlan(array $input): array
    {
        return $this->withProject($input, false, static function (ProjectIndex $index) use ($input): array {
            return (new DeployBridgeService(new ProcessRunner()))->resolve($index->root(), $input);
        });
    }

    /** @param array<string, mixed> $input */
    private function projectIndexStatus(array $input): array
    {
        return $this->withProject($input, false, static function (ProjectIndex $index): array {
            return [
                'request_id' => Ids::make('req'),
                'project_id' => $index->projectId(),
                'repository' => $index->root(),
                'index' => $index->status(),
            ];
        });
    }

    /** @param array<string, mixed> $input */
    private function indexProject(array $input): array
    {
        $this->requireIndexEnabled();

        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            $mode = strtolower(trim((string) ($input['mode'] ?? 'incremental')));
            if (!in_array($mode, ['full', 'incremental'], true)) {
                throw new ToolException('VALIDATION_FAILED', 'mode must be full or incremental');
            }
            $paths = self::strings($input['paths'] ?? []);
            $options = ['mode' => $mode];
            if ($paths !== []) {
                $options['paths'] = $paths;
            }
            $result = (new ProjectIndexer($index, $this->config, new ProcessRunner()))->index($options);
            $knowledgeState = $this->reconcileKnowledge(
                $index,
                self::strings($result['changed_paths'] ?? []),
            );
            $result = $this->compactIndexResult($result);

            return [
                'request_id' => Ids::make('req'),
                'project_id' => $index->projectId(),
                'repository' => $index->root(),
                'result' => $result,
                'knowledge_state' => $knowledgeState,
                'index' => $index->status(),
            ];
        });
    }

    /**
     * Internal completion gate for marker-owned learning projections.
     * This is intentionally not exposed on the compact MCP tool surface.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function verifyLearningProjection(array $input): array
    {
        $rawExpected = $input['expected_hashes'] ?? [];
        if (!is_array($rawExpected) || ($rawExpected !== [] && array_is_list($rawExpected))) {
            throw new ToolException('VALIDATION_FAILED', 'expected_hashes must be an object keyed by path');
        }
        $expectedRevision = (int) ($input['expected_revision'] ?? 0);

        return $this->withProject($input, false, static function (ProjectIndex $index) use (
            $rawExpected,
            $expectedRevision,
            $input,
        ): array {
            $actualRevision = $index->revision();
            if ($expectedRevision <= 0 || $actualRevision !== $expectedRevision) {
                throw new ToolException(
                    'LEARNING_INDEX_CLOSURE_FAILED',
                    'Project index revision changed before learning projection verification',
                    true,
                    ['expected_revision' => $expectedRevision, 'actual_revision' => $actualRevision],
                );
            }

            $expectedHashes = [];
            foreach ($rawExpected as $path => $hash) {
                if (!is_string($path) || !is_string($hash)) {
                    throw new ToolException('VALIDATION_FAILED', 'Learning projection path/hash guards must be strings');
                }
                $relativePath = $index->normalizeRelativePath($path);
                $normalizedHash = strtolower(trim($hash));
                $normalizedHash = str_starts_with($normalizedHash, 'sha256:')
                    ? $normalizedHash
                    : 'sha256:' . $normalizedHash;
                if ($relativePath === '' || preg_match('/^sha256:[a-f0-9]{64}$/D', $normalizedHash) !== 1) {
                    throw new ToolException('VALIDATION_FAILED', 'Learning projection contains an invalid path or SHA-256');
                }
                $expectedHashes[$relativePath] = $normalizedHash;
            }
            ksort($expectedHashes, SORT_STRING);

            $skillPaths = [];
            foreach (self::strings($input['skill_paths'] ?? []) as $path) {
                $relativePath = $index->normalizeRelativePath($path);
                if ($relativePath !== '') {
                    $skillPaths[] = $relativePath;
                }
            }
            $skillPaths = Text::uniqueStrings($skillPaths);

            $missingPaths = [];
            foreach (self::strings($input['missing_paths'] ?? []) as $path) {
                $relativePath = $index->normalizeRelativePath($path);
                if ($relativePath !== '') {
                    $missingPaths[] = $relativePath;
                }
            }
            $missingPaths = Text::uniqueStrings($missingPaths);
            if (array_intersect(array_keys($expectedHashes), $missingPaths) !== []) {
                throw new ToolException('VALIDATION_FAILED', 'A learning projection path cannot be both present and missing');
            }

            $allPaths = Text::uniqueStrings(array_merge(array_keys($expectedHashes), $missingPaths));
            if (count($allPaths) > 2_048) {
                throw new ToolException('VALIDATION_FAILED', 'Learning projection verification exceeds 2048 paths');
            }

            $rows = [];
            $queryBatches = 0;
            foreach (array_chunk($allPaths, 200) as $batch) {
                if ($batch === []) {
                    continue;
                }
                $statement = $index->pdo()->prepare(
                    'SELECT f.path, f.content_hash, f.revision AS file_revision,
                            c.content_hash AS stored_hash,
                            s.path AS skill_path, s.status AS skill_status, s.source_hash AS skill_source_hash
                       FROM indexed_files AS f
                  LEFT JOIN indexed_file_contents AS c ON c.file_id = f.id
                  LEFT JOIN skills AS s ON s.file_id = f.id
                      WHERE f.path IN (' . implode(',', array_fill(0, count($batch), '?')) . ')'
                );
                $statement->execute($batch);
                foreach ($statement->fetchAll() as $row) {
                    $rows[(string) $row['path']] = $row;
                }
                ++$queryBatches;
            }

            $failures = [];
            foreach ($expectedHashes as $path => $expectedHash) {
                $row = $rows[$path] ?? null;
                if (!is_array($row)) {
                    $failures[] = ['path' => $path, 'reason' => 'indexed_file_missing'];
                    continue;
                }
                $actualHash = strtolower((string) ($row['content_hash'] ?? ''));
                $storedHash = strtolower((string) ($row['stored_hash'] ?? ''));
                if (!hash_equals($expectedHash, $actualHash)) {
                    $failures[] = ['path' => $path, 'reason' => 'file_hash_mismatch'];
                }
                if ($storedHash === '' || !hash_equals($expectedHash, $storedHash)) {
                    $failures[] = ['path' => $path, 'reason' => 'content_store_hash_mismatch'];
                }
                $fileRevision = (int) ($row['file_revision'] ?? 0);
                if ($fileRevision <= 0 || $fileRevision > $actualRevision) {
                    $failures[] = ['path' => $path, 'reason' => 'file_revision_invalid'];
                }
            }
            foreach ($skillPaths as $path) {
                $row = $rows[$path] ?? null;
                $expectedHash = $expectedHashes[$path] ?? '';
                if (!is_array($row) || $expectedHash === '') {
                    $failures[] = ['path' => $path, 'reason' => 'skill_file_guard_missing'];
                    continue;
                }
                if ((string) ($row['skill_path'] ?? '') !== $path) {
                    $failures[] = ['path' => $path, 'reason' => 'skill_metadata_missing'];
                }
                if (!in_array((string) ($row['skill_status'] ?? ''), ['canonical', 'validated'], true)) {
                    $failures[] = ['path' => $path, 'reason' => 'skill_not_actionable'];
                }
                if (!hash_equals($expectedHash, strtolower((string) ($row['skill_source_hash'] ?? '')))) {
                    $failures[] = ['path' => $path, 'reason' => 'skill_source_hash_mismatch'];
                }
            }
            foreach ($missingPaths as $path) {
                if (isset($rows[$path])) {
                    $failures[] = ['path' => $path, 'reason' => 'removed_projection_still_indexed'];
                }
            }

            $status = $index->status();
            if (!in_array((string) ($status['freshness'] ?? 'unknown'), ['current', 'fresh'], true)) {
                $failures[] = ['path' => '', 'reason' => 'project_index_not_fresh'];
            }
            if ($failures !== []) {
                throw new ToolException(
                    'LEARNING_INDEX_CLOSURE_FAILED',
                    'Learning projection is not fully queryable from project SQLite',
                    true,
                    [
                        'revision' => $actualRevision,
                        'failure_count' => count($failures),
                        'failures' => array_slice($failures, 0, 20),
                    ],
                );
            }

            return [
                'status' => 'verified',
                'mode' => 'project_index_projection',
                'index_db' => $index->path(),
                'revision' => $actualRevision,
                'freshness' => (string) ($status['freshness'] ?? 'unknown'),
                'verified_file_count' => count($expectedHashes),
                'verified_skill_count' => count($skillPaths),
                'verified_removed_path_count' => count($missingPaths),
                'query_batch_count' => $queryBatches,
                'verification_source' => 'project.sqlite:indexed_files+indexed_file_contents+skills',
                'verified_at' => Clock::now(),
            ];
        });
    }

    /** @param array<string, mixed> $input */
    private function resolveTaskContext(array $input): array
    {
        $task = self::required($input, 'task');
        $tokenBudget = max(256, min(32_000, (int) ($input['token_budget'] ?? $this->config->get('index.context_token_budget', 6_000))));

        return $this->withProject($input, true, function (ProjectIndex $index) use ($input, $task, $tokenBudget): array {
            $retriever = new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config);
            $requestedSymbols = array_slice(self::strings($input['symbols'] ?? []), 0, 20);
            $context = $retriever->resolveContext($task, [
                'paths' => self::strings($input['paths'] ?? []),
                'symbols' => self::strings($input['symbols'] ?? []),
                'module' => trim((string) ($input['module'] ?? '')),
                'kinds' => self::strings($input['kinds'] ?? []),
                'limit' => max(1, min(50, (int) ($input['limit'] ?? 20))),
                'token_budget' => $tokenBudget,
                'include_skill_content' => (bool) ($input['include_skill_content'] ?? true),
            ]);
            $context['requested_symbols'] = [];
            foreach ($requestedSymbols as $symbol) {
                try {
                    $context['requested_symbols'][] = $retriever->inspectSymbol($symbol, 'context');
                } catch (Throwable $exception) {
                    [$message] = Redactor::string($exception->getMessage());
                    $context['requested_symbols'][] = [
                        'symbol' => $symbol,
                        'symbols' => [],
                        'warning' => Text::truncate($message, 500),
                    ];
                }
            }
            $learning = $this->learningContext(
                $index->projectId(),
                $task,
                self::strings($input['paths'] ?? []),
                max(1, min(10, (int) ($input['learning_limit'] ?? 5))),
            );

            $groups = is_array($context['context'] ?? null) ? $context['context'] : [];
            $fragments = [];
            $sources = [];
            foreach (['documents', 'rules', 'configuration', 'code'] as $group) {
                foreach (is_array($groups[$group] ?? null) ? $groups[$group] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $path = (string) ($item['relative_path'] ?? '');
                    $fragment = [
                        'kind' => $group,
                        'path' => $path,
                        'module' => (string) ($item['module'] ?? ''),
                        'title' => (string) ($item['title'] ?? ''),
                        'start_line' => (int) ($item['start_line'] ?? 1),
                        'end_line' => (int) ($item['end_line'] ?? $item['start_line'] ?? 1),
                        'content' => (string) ($item['snippet'] ?? ''),
                        'file_hash' => (string) ($item['file_hash'] ?? ''),
                        'content_hash' => (string) ($item['content_hash'] ?? ''),
                        'token_estimate' => (int) ($item['token_estimate'] ?? 0),
                    ];
                    $fragments[] = $fragment;
                    if ($path !== '') {
                        $sources[$path] = [
                            'path' => $path,
                            'file_hash' => $fragment['file_hash'],
                            'kind' => $group,
                        ];
                    }
                }
            }
            $ruleSummaries = [];
            foreach ($fragments as $fragment) {
                if ($fragment['kind'] === 'rules') {
                    $ruleSummaries[] = [
                        'summary' => Text::truncate((string) $fragment['content'], 500),
                        'source' => $fragment['path'],
                        'file_hash' => $fragment['file_hash'],
                    ];
                }
            }
            foreach ($learning as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rule = trim((string) ($item['rule'] ?? ''));
                if ($rule !== '') {
                    $ruleSummaries[] = [
                        'summary' => Text::truncate($rule, 500),
                        'source' => 'validated_session_learning',
                        'experience_id' => (string) ($item['experience_id'] ?? ''),
                    ];
                }
            }
            $workflowCatalog = new GuidanceWorkflowCatalog();
            $pinnedBudget = max(
                384,
                min(1_800, (int) ($this->config->get('guidance.pinned_token_budget', 1_200))),
            );
            $pinnedFragments = $workflowCatalog->pinnedFragments($retriever, $pinnedBudget);
            $fragments = GuidanceWorkflowCatalog::mergeFragments($fragments, $pinnedFragments);
            $sources = [];
            foreach ($fragments as $fragment) {
                $path = (string) ($fragment['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $sources[$path] = [
                    'path' => $path,
                    'file_hash' => (string) ($fragment['file_hash'] ?? ''),
                    'kind' => (string) ($fragment['kind'] ?? 'documents'),
                    'pinned' => (bool) ($fragment['pinned'] ?? false),
                ];
            }
            foreach ($pinnedFragments as $pinned) {
                if (!is_array($pinned)) {
                    continue;
                }
                $content = trim((string) ($pinned['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $ruleSummaries[] = [
                    'summary' => Text::truncate($content, 500),
                    'source' => (string) ($pinned['path'] ?? 'workflow_pin'),
                    'file_hash' => (string) ($pinned['file_hash'] ?? ''),
                    'pinned' => true,
                ];
            }
            $sessionId = trim((string) ($input['client_session_id'] ?? ''));

            return [
                'schema_version' => 'guidance-bundle.v1',
                'request_id' => Ids::make('req'),
                'guidance_id' => Ids::deterministic(
                    'guidance',
                    $index->projectId() . "\0" . $index->revision() . "\0" . $task . "\0" . $sessionId,
                ),
                'project_id' => $index->projectId(),
                'repository' => $index->root(),
                'readiness_id' => (string) ($input['readiness_id'] ?? ''),
                'client_session_id' => $sessionId,
                'project_revision' => $index->revision(),
                'freshness' => $index->status()['freshness'] ?? 'unknown',
                'task' => $task,
                'session_directives' => $this->readiness->directives($index, $sessionId),
                'rules' => $ruleSummaries,
                'fragments' => $fragments,
                'pinned_fragments' => $pinnedFragments,
                'sources' => array_values($sources),
                'token_usage' => [
                    'budget' => $tokenBudget,
                    'pinned_budget' => $pinnedBudget,
                    'estimated' => array_sum(array_map(
                        static fn (array $fragment): int => (int) $fragment['token_estimate'],
                        $fragments,
                    )),
                ],
                'query' => [
                    'query_id' => (string) ($context['query_id'] ?? ''),
                    'result_count' => count($fragments),
                    'warnings' => is_array($context['warnings'] ?? null) ? $context['warnings'] : [],
                ],
                'workflow_contract' => GuidanceWorkflowCatalog::contract(),
                'routing_contract' => [
                    'authoritative_sources' => 'Framework/doc and app/code/*/*/doc',
                    'use_get_edit_bundle_for_code' => true,
                    'static_skill_files' => false,
                    'scan_fallback' => false,
                    'extension_point_selection_required' => true,
                    'workflow_doc' => GuidanceWorkflowCatalog::contract()['authoritative_workflow_doc'],
                    'note' => 'Complete extension-point selection and plan before code changes. Use task-matched fragments and hashes; request another bounded query when evidence is insufficient.',
                ],
            ];
        });
    }

    /** @param array<string, mixed> $input */
    private function getEditBundle(array $input): array
    {
        $task = self::required($input, 'task');
        $paths = self::strings($input['paths'] ?? []);
        $symbols = self::strings($input['symbols'] ?? []);
        $taskContract = $this->normalizeTaskContract($input, $task, $paths, $symbols);
        $paths = Text::uniqueStrings(array_merge(
            $paths,
            self::strings($taskContract['known_paths'] ?? []),
        ));
        $symbols = Text::uniqueStrings(array_merge(
            $symbols,
            self::strings($taskContract['known_symbols'] ?? []),
        ));
        $taskContract['known_paths'] = $paths;
        $taskContract['known_symbols'] = $symbols;
        $exactSymbols = $this->requiredExactSymbolTargets($symbols);
        $defaultMaxRegions = $paths === [] ? 24 : min(48, max(24, count($paths) * 4));
        $defaultTokenBudget = $paths === [] ? 8_000 : min(24_000, max(8_000, count($paths) * 1_200));

        return $this->withProject(
            $input,
            true,
            function (ProjectIndex $index, array $resolved) use (
                $input,
                $task,
                $paths,
                $symbols,
                $exactSymbols,
                $taskContract,
                $defaultMaxRegions,
                $defaultTokenBudget,
            ): array {
                $sessionId = trim((string) ($input['client_session_id'] ?? ''));
                $sessionDirectives = $this->readiness->directives($index, $sessionId);
                if ($sessionDirectives !== []) {
                    $taskContract['session_directives'] = $sessionDirectives;
                }
                $runs = new ExecutionRunService($this->learningStore, $this->config);
                $run = $runs->begin(
                    $index->projectId(),
                    $task,
                    $taskContract,
                    $index->revision(),
                    trim((string) ($input['supersedes_run_id'] ?? '')),
                );
                $runId = (string) $run['run_id'];
                try {
                $retriever = new ProjectRetriever(
                    $index,
                    new SparseVectorizer($this->config),
                    $this->config,
                );
                $module = trim((string) ($input['module'] ?? ''));
                $scope = $this->discoveryScope($index, $module, $taskContract, $paths);
                $scopeRoots = $scope['roots'];
                $forbiddenPatterns = $scope['forbidden_patterns'];
                $moduleScopedDiscovery = $paths === [] && $module !== '' && $scopeRoots !== [];
                $moduleTargetsMcp = preg_match(
                    '~(?:^|[_/.-])mcp(?:$|[_/.-])|project[ _./-]*intelligence~i',
                    $module,
                ) === 1;
                $inferredExpectedRoles = $retriever->expectedContextRolesForTask($task, [
                    'include_mcp_infrastructure' => !$moduleScopedDiscovery || $moduleTargetsMcp,
                    'include_validation_role' => !$moduleScopedDiscovery,
                ]);
                $requestedKinds = self::strings($input['kinds'] ?? []);
                $documentationRequested = (bool) ($input['include_docs'] ?? true)
                    && ($requestedKinds === [] || in_array('doc', $requestedKinds, true));
                $expectedRoles = $documentationRequested
                    ? $inferredExpectedRoles
                    : array_values(array_diff($inferredExpectedRoles, ['documentation']));
                $discoveryRoles = $moduleScopedDiscovery
                    ? Text::uniqueStrings(array_merge($expectedRoles, ['implementation']))
                    : $expectedRoles;
                $roleDiscoveryLimit = min(24, max(2, count($discoveryRoles) * 2));
                $indexer = new ProjectIndexer(
                    $index,
                    $this->config,
                    new ProcessRunner(),
                );
                $symbolIndexRefresh = null;
                if ($paths === [] && $exactSymbols !== []) {
                    try {
                        $symbolIndexRefresh = $indexer->index(['mode' => 'incremental']);
                    } catch (Throwable $exception) {
                        [$message] = Redactor::string($exception->getMessage());
                        throw new ToolException(
                            'INDEX_SYMBOL_REFRESH_FAILED',
                            'Unable to refresh a symbol-only discovery scope',
                            true,
                            [
                                'requested_symbols' => $exactSymbols,
                                'evidence' => Text::truncate($message, 400),
                                'index_revision' => $index->revision(),
                            ],
                        );
                    }
                }
                $roleCandidates = $indexer->discoverContextPaths(
                    $discoveryRoles,
                    $paths,
                    $roleDiscoveryLimit,
                    $scopeRoots,
                    $forbiddenPatterns,
                );
                $rolePaths = Text::uniqueStrings(array_map(
                    static fn (array $candidate): string => (string) ($candidate['path'] ?? ''),
                    $roleCandidates,
                ));
                $availableRoles = Text::uniqueStrings(array_merge(...array_map(
                    static fn (array $candidate): array => is_array($candidate['roles'] ?? null)
                        ? $candidate['roles']
                        : [],
                    $roleCandidates,
                )));
                // Explicit paths are a bounded scope too; do not require roles that
                // cannot exist inside the caller's materialization boundary.
                if ($moduleScopedDiscovery || $paths !== []) {
                    $scopedExpectedRoles = array_values(array_intersect(
                        $expectedRoles,
                        $availableRoles,
                    ));
                    if ($scopedExpectedRoles !== []) {
                        $expectedRoles = $scopedExpectedRoles;
                    } elseif (in_array('implementation', $availableRoles, true)) {
                        $expectedRoles = ['implementation'];
                    }
                }
                $materializationPaths = $paths === [] && $scopeRoots !== []
                    ? $scopeRoots
                    : Text::uniqueStrings(array_merge($paths, $rolePaths));
                $refreshPaths = Text::uniqueStrings(array_merge($paths, $rolePaths));
                $onDemandIndex = $this->refreshIndexedPaths($index, $refreshPaths);
                $exactSymbolScopeProven = $exactSymbols === []
                    || ($paths === [] ? is_array($symbolIndexRefresh) : is_array($onDemandIndex));
                $effectiveMaxRegions = array_key_exists('max_regions', $input)
                    ? max(1, min(48, (int) $input['max_regions']))
                    : min(48, max($defaultMaxRegions, count($materializationPaths) * 2));
                $effectiveTokenBudget = array_key_exists('token_budget', $input)
                    ? max(256, min(24_000, (int) $input['token_budget']))
                    : min(24_000, max($defaultTokenBudget, count($materializationPaths) * 900));

                $bundle = $retriever->getEditBundle($task, [
                    'paths' => $materializationPaths,
                    'requested_paths' => $paths,
                    'symbols' => $symbols,
                    'module' => $module,
                    'expected_roles' => $expectedRoles,
                    'role_paths' => $rolePaths,
                    'scope_roots' => $scopeRoots,
                    'exact_symbol_paths' => $paths,
                    'exact_symbol_scope_proven' => $exactSymbolScopeProven,
                    'forbidden_patterns' => $forbiddenPatterns,
                    'kinds' => self::strings($input['kinds'] ?? []),
                    'max_regions' => $effectiveMaxRegions,
                    'max_chunks_per_file' => max(1, min(8, (int) ($input['max_chunks_per_file'] ?? 4))),
                    'token_budget' => $effectiveTokenBudget,
                    'include_docs' => (bool) ($input['include_docs'] ?? true),
                    'include_skills' => (bool) ($input['include_skills'] ?? true),
                ]);
                $serverAggregation = is_array($bundle['server_aggregation'] ?? null)
                    ? $bundle['server_aggregation']
                    : [];
                $serverAggregation['role_discovery'] = [
                    'inferred_expected_roles' => $inferredExpectedRoles,
                    'expected_roles' => $expectedRoles,
                    'available_roles' => $availableRoles,
                    'discovered_path_count' => count($rolePaths),
                    'materialization_path_count' => count($materializationPaths),
                    'paths' => $roleCandidates,
                    'scope_roots' => $scopeRoots,
                    'forbidden_patterns' => $forbiddenPatterns,
                    'scope_constrained' => $scopeRoots !== [],
                    'single_batch_index_refresh' => true,
                    'external_round_trip_required' => false,
                ];
                $bundle['server_aggregation'] = $serverAggregation;
                if (is_array($onDemandIndex)) {
                    $bundle['on_demand_index'] = [
                        'mode' => 'server_aggregated_paths_refresh',
                        'user_requested_paths' => $paths,
                        'role_discovered_paths' => $rolePaths,
                        'requested_paths' => self::strings(
                            $onDemandIndex['scope_paths'] ?? $refreshPaths,
                        ),
                        'changed_paths' => self::strings($onDemandIndex['changed_paths'] ?? []),
                        'duration_ms' => max(0, (int) ($onDemandIndex['duration_ms'] ?? 0)),
                        'project_revision' => $index->revision(),
                    ];
                }
                if (is_array($symbolIndexRefresh)) {
                    $bundle['symbol_scope_refresh'] = [
                        'mode' => 'forced_incremental_before_exact_absence_proof',
                        'requested_symbols' => $exactSymbols,
                        'changed_paths' => self::strings($symbolIndexRefresh['changed_paths'] ?? []),
                        'project_revision' => $index->revision(),
                        'scope_proven' => $exactSymbolScopeProven,
                    ];
                }
                $learning = $this->learningContext(
                    $index->projectId(),
                    $task,
                    $paths,
                    3,
                );
                $bundle['validated_learning'] = array_map(static fn (array $item): array => [
                    'title' => $item['title'] ?? '',
                    'rule' => $item['rule'] ?? '',
                    'trigger' => $item['trigger'] ?? '',
                    'confidence' => $item['confidence'] ?? 0,
                ], $learning);
                $continuation = is_array($bundle['continuation'] ?? null)
                    ? $bundle['continuation']
                    : [];
                $needsFollowup = (bool) ($continuation['needed'] ?? false);
                $contextBatchPlan = is_array($continuation['batch_plan'] ?? null)
                    ? $continuation['batch_plan']
                    : [];
                if ($needsFollowup) {
                    $contextBatchPlan = $this->executableContextBatchPlan(
                        $contextBatchPlan,
                        $task,
                        $taskContract,
                        $input,
                        $index->root(),
                        $runId,
                        (string) ($bundle['bundle_id'] ?? ''),
                    );
                    $continuation['batch_plan'] = $contextBatchPlan;
                    $continuation['external_followup_allowed'] = true;
                    $bundle['continuation'] = $continuation;
                }
                $childRequests = is_array($contextBatchPlan['child_requests'] ?? null)
                    ? $contextBatchPlan['child_requests']
                    : [];
                $bundle['routing'] = [
                    'phase' => $paths === [] ? 'discovery' : 'materialization',
                    'batch_request' => [
                        'phase' => $paths === [] ? 'discovery' : 'materialization',
                        'requested_path_count' => count($paths),
                        'requested_symbol_count' => count($symbols),
                        'requested_target_count' => count($paths) + count($symbols),
                        'multi_path_batch' => count($paths) > 1,
                        'single_file_round_trip' => count($paths) === 1 && count($symbols) <= 1,
                    ],
                    'architecture_first' => true,
                    'candidate_manifest_required' => true,
                    'context_policy' => 'Use the stable ready_for_edit decision. Candidate path batches and semantic goals are aggregated by this MCP call; never compensate with native per-file reads.',
                    'next' => (bool) ($bundle['ready_for_edit'] ?? false)
                        ? 'Emit one complete edit-plan.v1 for all required files and call apply_compact_edit exactly once.'
                        : 'Treat CONTEXT_BATCH_PLANNED as a successful terminal parent plan. Execute only batch_plan.child_requests in order; each ready child is independently planned and applied with its own run_id and bundle_id.',
                    'batch_followup_allowed' => $needsFollowup,
                    'followup_request' => [
                        'child_requests' => $needsFollowup ? $childRequests : [],
                        'path_batches' => $needsFollowup
                            ? (array) ($continuation['next_path_batches'] ?? [])
                            : [],
                        'symbol_batches' => $needsFollowup
                            ? (array) ($continuation['next_symbol_batches'] ?? [])
                            : [],
                        'combined_search_goals' => $needsFollowup
                            ? (array) ($continuation['next_search_goals'] ?? [])
                            : [],
                        'collect_symbols' => (array) (
                            $continuation['next_symbol_batches'] ?? []
                        ) !== [],
                        'single_file_round_trips' => false,
                    ],
                    'context_batch_plan' => $contextBatchPlan,
                    'child_execution_required' => $needsFollowup,
                    'scan_required' => false,
                    'whole_file_read_required' => false,
                    'native_single_file_read_allowed' => false,
                    'intermediate_user_confirmation_required' => false,
                    'write_contract' => 'When ready_for_edit=true, exactly one successful apply_compact_edit call is the normal path.',
                ];
                $bundle['task_contract'] = $taskContract;
                $bundle['execution_contract'] = [
                    'get_edit_bundle_call_target' => 1 + count($childRequests),
                    'apply_compact_edit_success_call_target' => $needsFollowup
                        ? count($childRequests)
                        : 1,
                    'native_single_file_read_target' => 0,
                    'intermediate_user_confirmation_target' => 0,
                    'automatic_retry_errors' => [
                        'INDEX_NOT_READY',
                        'EDIT_REPLAN_REQUIRED',
                        'VALIDATION_FAILED',
                    ],
                    'non_terminal_states' => [
                        'CONFLICT_REPLAN',
                        'EDIT_BATCH_PLANNED',
                        'EDIT_BATCHING',
                        'VALIDATION_REPAIR',
                        'IMPACT_EXPANSION',
                    ],
                    'terminal_plan_states' => [
                        'CONTEXT_BATCH_PLANNED',
                    ],
                    'context_batch_plan' => $contextBatchPlan,
                    'user_confirmation_exceptions' => [
                        'conflicting_requirements',
                        'missing_business_decision',
                        'new_authority_required',
                        'irreversible_external_operation',
                        'host_policy_requires_approval',
                    ],
                ];

                $bundle['repository'] = $index->root();
                $repositorySource = (string) ($resolved['repository_source'] ?? 'argument');
                $bundle['repository_resolution'] = [
                    'repository' => $index->root(),
                    'source' => $repositorySource,
                    'inferred' => $repositorySource !== 'argument',
                    'validated_by_known_paths' => $repositorySource === 'process_cwd_validated_by_paths',
                ];
                $bundle['task_id'] = (string) $run['task_id'];
                $bundle['run_id'] = $runId;
                $bundle['trace_id'] = (string) $run['trace_id'];
                $bundle['readiness_id'] = (string) ($input['readiness_id'] ?? '');
                $bundle['client_session_id'] = $sessionId;
                $bundle['session_directives'] = $sessionDirectives;
                $bundle['write_contract'] = [
                    'tool' => $needsFollowup ? null : 'apply_compact_edit',
                    'run_id' => $runId,
                    'bundle_id' => (string) ($bundle['bundle_id'] ?? ''),
                    'plan_schema' => 'edit-plan.v1',
                    'parent_apply_allowed' => !$needsFollowup,
                    'successful_calls_target' => $needsFollowup ? 0 : 1,
                    'child_successful_calls_target' => $needsFollowup
                        ? count($childRequests)
                        : 0,
                ];
                $bundle['execution_run'] = $runs->completeBundle($runId, $index->projectId(), $bundle);
                $bundle['workflow_contract'] = GuidanceWorkflowCatalog::contract();
                $bundle['routing'] = array_replace(
                    is_array($bundle['routing'] ?? null) ? $bundle['routing'] : [],
                    [
                        'extension_point_selection_required' => true,
                        'workflow_doc' => GuidanceWorkflowCatalog::contract()['authoritative_workflow_doc'],
                        'mandatory_before_apply' => GuidanceWorkflowCatalog::contract()['mandatory_before_code'],
                    ],
                );

                return $bundle;
                } catch (Throwable $exception) {
                    $runs->fail($runId, $index->projectId(), $exception, 'get_edit_bundle');
                    throw $exception;
                }
            },
        );
    }

    /**
     * Convert an internal capacity plan into deterministic child get_edit_bundle
     * requests. Each child narrows known paths/symbols so it cannot recreate the
     * oversized parent request.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $taskContract
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function executableContextBatchPlan(
        array $plan,
        string $task,
        array $taskContract,
        array $input,
        string $repository,
        string $parentRunId,
        string $parentBundleId,
    ): array {
        $requests = [];
        $append = function (
            string $kind,
            array $paths,
            array $symbols,
            string $searchGoal = '',
        ) use (
            &$requests,
            $task,
            $taskContract,
            $input,
            $repository,
            $parentRunId,
            $parentBundleId,
        ): void {
            $batchIndex = count($requests);
            $childContract = $taskContract;
            $childContract['known_paths'] = Text::uniqueStrings($paths);
            $childContract['known_symbols'] = Text::uniqueStrings($symbols);
            $background = trim((string) ($childContract['background'] ?? ''));
            $continuationNote = 'Bounded context child '
                . ($batchIndex + 1)
                . ' of parent run '
                . $parentRunId
                . '; retain the parent bundle while integrating this child.';
            $childContract['background'] = $background === ''
                ? $continuationNote
                : $background . ' ' . $continuationNote;
            $childTask = $searchGoal === ''
                ? $task
                : $task . "\nContext continuation goal: " . $searchGoal;
            $childInput = [
                'repository' => $repository,
                'client_session_id' => (string) ($input['client_session_id'] ?? ''),
                'readiness_id' => (string) ($input['readiness_id'] ?? ''),
                'task' => $childTask,
                'task_contract' => $childContract,
                'paths' => $childContract['known_paths'],
                'symbols' => $childContract['known_symbols'],
                'max_regions' => 48,
                'max_chunks_per_file' => max(
                    1,
                    min(8, (int) ($input['max_chunks_per_file'] ?? 4)),
                ),
                'token_budget' => 24_000,
                'include_docs' => (bool) ($input['include_docs'] ?? true),
                'include_skills' => (bool) ($input['include_skills'] ?? true),
            ];
            $module = trim((string) ($input['module'] ?? ''));
            if ($module !== '') {
                $childInput['module'] = $module;
            }
            $kinds = self::strings($input['kinds'] ?? []);
            if ($kinds !== []) {
                $childInput['kinds'] = $kinds;
            }
            $batchId = 'context-batch-' . substr(hash(
                'sha256',
                $parentRunId
                    . "\0"
                    . $parentBundleId
                    . "\0"
                    . $kind
                    . "\0"
                    . $batchIndex
                    . "\0"
                    . Json::canonical([$paths, $symbols, $searchGoal]),
            ), 0, 24);
            $requests[] = [
                'schema_version' => 'context-batch-request.v1',
                'batch_index' => $batchIndex,
                'batch_id' => $batchId,
                'kind' => $kind,
                'parent_run_id' => $parentRunId,
                'parent_bundle_id' => $parentBundleId,
                'next_tool' => 'get_edit_bundle',
                'input' => $childInput,
                'aggregation' => [
                    'retain_parent_plan' => true,
                    'preserve_order' => true,
                    'apply_child_independently' => true,
                ],
            ];
        };

        foreach ((array) ($plan['path_batches'] ?? []) as $paths) {
            if (is_array($paths) && $paths !== []) {
                $append('paths', $paths, []);
            }
        }
        foreach ((array) ($plan['symbol_batches'] ?? []) as $symbols) {
            if (is_array($symbols) && $symbols !== []) {
                $append('symbols', [], $symbols);
            }
        }
        foreach ((array) ($plan['search_goals'] ?? []) as $searchGoal) {
            $searchGoal = trim((string) $searchGoal);
            if ($searchGoal !== '') {
                $append('search_goal', [], [], $searchGoal);
            }
        }

        $plan['child_requests'] = $requests;
        $plan['batch_count'] = count($requests);
        $plan['parent_terminal'] = true;
        $plan['task_terminal'] = false;
        $plan['terminal'] = true;
        $plan['aggregation_contract'] = [
            'mode' => 'ordered_independent_child_runs',
            'all_child_requests_required' => true,
            'single_file_round_trips' => false,
            'parent_apply_allowed' => false,
            'each_ready_child_uses_its_own_run_and_bundle' => true,
            'task_complete_when_all_children_complete' => true,
        ];

        return $plan;
    }

    /** @param list<string> $paths
     *  @return array<string, mixed>|null
     */
    private function refreshIndexedPaths(ProjectIndex $index, array $paths): ?array
    {
        $paths = Text::uniqueStrings($paths);
        if ($paths === []) {
            return null;
        }

        $refresh = (new ProjectIndexer($index, $this->config, new ProcessRunner()))->indexPaths($paths);
        $changedPaths = self::strings($refresh['changed_paths'] ?? []);
        if ($changedPaths !== []) {
            $this->reconcileKnowledge($index, $changedPaths);
        }

        return $refresh;
    }

    /**
     * Convert a module and TaskContract path policy into bounded discovery roots.
     * Wildcards remain policy patterns, while only their safe static prefixes
     * become filesystem traversal roots.
     *
     * @param array<string, mixed> $taskContract
     * @param list<string> $explicitPaths
     * @return array{roots:list<string>,forbidden_patterns:list<string>}
     */
    private function discoveryScope(
        ProjectIndex $index,
        string $module,
        array $taskContract,
        array $explicitPaths,
    ): array {
        // forbidden_scope is an apply-time write policy. Read-only dependency context
        // must remain visible so planning and impact analysis can prove a safe edit.
        $forbiddenPatterns = [];
        if ($explicitPaths !== []) {
            return ['roots' => [], 'forbidden_patterns' => $forbiddenPatterns];
        }

        $allowedValues = is_array($taskContract['allowed_scope'] ?? null)
            ? $taskContract['allowed_scope']
            : [];
        $repositoryWide = false;
        $allowedRoots = [];
        $unsupportedAllowedPatterns = [];
        foreach ($allowedValues as $value) {
            $value = trim((string) $value);
            if (in_array($value, ['current_project', '.'], true)) {
                $repositoryWide = true;
                continue;
            }
            $pattern = $this->taskPathPattern($value);
            if ($pattern === null) {
                continue;
            }
            $root = $this->staticScopeRoot($pattern);
            if ($root !== null) {
                $allowedRoots[] = $root;
            } else {
                $unsupportedAllowedPatterns[] = $pattern;
            }
        }
        $allowedRoots = Text::uniqueStrings($allowedRoots);
        if (!$repositoryWide && $unsupportedAllowedPatterns !== []) {
            throw new ToolException(
                'TASK_SCOPE_UNSUPPORTED',
                'Discovery allowed_scope supports literal paths or a static directory prefix ending in /**',
                false,
                ['unsupported_patterns' => Text::uniqueStrings($unsupportedAllowedPatterns)],
            );
        }

        $moduleRoot = $this->moduleRoot($module);
        if ($moduleRoot !== '') {
            $roots = [$moduleRoot];
            if (!$repositoryWide && $allowedRoots !== []) {
                $roots = [];
                foreach ($allowedRoots as $allowedRoot) {
                    if ($allowedRoot === $moduleRoot
                        || str_starts_with($allowedRoot, $moduleRoot . '/')) {
                        $roots[] = $allowedRoot;
                    } elseif (str_starts_with($moduleRoot, $allowedRoot . '/')) {
                        $roots[] = $moduleRoot;
                    }
                }
                $roots = Text::uniqueStrings($roots);
                if ($roots === []) {
                    throw new ToolException(
                        'TASK_SCOPE_EMPTY',
                        'Module and TaskContract allowed_scope do not overlap',
                        false,
                        ['module' => $module, 'allowed_scope' => $allowedValues],
                    );
                }
            }

            return [
                'roots' => array_values(array_filter($roots, static function (string $root) use ($index): bool {
                    try {
                        $index->normalizeRelativePath($root);
                        return true;
                    } catch (Throwable) {
                        return false;
                    }
                })),
                'forbidden_patterns' => $forbiddenPatterns,
            ];
        }

        return [
            'roots' => $repositoryWide ? [] : $allowedRoots,
            'forbidden_patterns' => $forbiddenPatterns,
        ];
    }

    /** @return list<string> */
    private function taskPathPatterns(mixed $values): array
    {
        $patterns = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $pattern = $this->taskPathPattern((string) $value);
            if ($pattern !== null) {
                $patterns[] = $pattern;
            }
        }

        return Text::uniqueStrings($patterns);
    }

    private function taskPathPattern(string $value): ?string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === ''
            || in_array($value, ['current_project', '.', 'external systems', 'irreversible operations', 'unrelated projects'], true)
            || str_contains($value, "\0")
            || str_starts_with($value, '/')
            || preg_match('~^[A-Za-z]:/~D', $value) === 1
            || preg_match('~(?:^|/)\.\.(?:/|$)~D', $value) === 1) {
            return null;
        }
        $value = preg_replace('~^\./+~', '', $value) ?? $value;
        $value = trim($value, '/');

        return $value === '' ? null : $value;
    }

    private function staticScopeRoot(string $pattern): ?string
    {
        if (strpbrk($pattern, '*?[') === false) {
            return $pattern;
        }
        if (preg_match('~^([^*?\[]+)/\*\*$~D', $pattern, $matches) !== 1) {
            return null;
        }
        $root = rtrim($matches[1], '/');

        return $root === '' ? null : $root;
    }

    private function moduleRoot(string $module): string
    {
        $parts = str_contains($module, '/')
            ? explode('/', $module, 2)
            : explode('_', $module, 2);
        if (count($parts) !== 2
            || preg_match('~^[A-Za-z][A-Za-z0-9_.-]*$~D', $parts[0]) !== 1
            || preg_match('~^[A-Za-z][A-Za-z0-9_.-]*$~D', $parts[1]) !== 1) {
            return '';
        }

        return 'app/code/' . $parts[0] . '/' . $parts[1];
    }

    /** @param array<string, mixed> $input */
    private function searchProjectKnowledge(array $input): array
    {
        $query = self::required($input, 'query');

        return $this->withProject($input, true, function (ProjectIndex $index) use ($input, $query): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->search($query, [
                'paths' => self::strings($input['paths'] ?? []),
                'kinds' => self::strings($input['kinds'] ?? []),
                'module' => trim((string) ($input['module'] ?? '')),
                'limit' => max(1, min(100, (int) ($input['limit'] ?? 20))),
                'token_budget' => max(128, min(32_000, (int) ($input['token_budget'] ?? 4_000))),
            ]);
        });
    }

    /** @param array<string, mixed> $input */
    private function getIndexedDocument(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->getDocument($input);
        });
    }

    /** @param array<string, mixed> $input */
    private function getIndexedFiles(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->getFiles($input);
        });
    }

    /** @param array<string, mixed> $input */
    private function inspectSymbol(array $input): array
    {
        $symbol = self::required($input, 'symbol');
        $mode = strtolower(trim((string) ($input['mode'] ?? 'context')));
        if (!in_array($mode, ['context', 'references', 'callers', 'callees', 'impact', 'upstream', 'downstream'], true)) {
            throw new ToolException('VALIDATION_FAILED', 'Unsupported symbol inspection mode');
        }

        return $this->withProject($input, true, function (ProjectIndex $index) use ($symbol, $mode): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->inspectSymbol($symbol, $mode);
        });
    }

    /** @param array<string, mixed> $input */
    private function resolveSkill(array $input): array
    {
        $result = $this->resolveTaskContext($input);
        $result['compatibility_alias'] = 'resolve_skill';
        $result['static_skill_files'] = false;

        return $result;
    }

    /** @param array<string, mixed> $input */
    private function getSkill(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        if ($task === '') {
            $hint = trim((string) ($input['path'] ?? $input['skill_id'] ?? ''));
            $task = $hint === '' ? '查询当前任务适用的框架开发规范' : '查询与 ' . $hint . ' 相关的框架开发规范';
        }
        $input['task'] = $task;
        $result = $this->resolveTaskContext($input);
        $result['compatibility_alias'] = 'get_skill';
        $result['static_skill_files'] = false;

        return $result;
    }

    /** @param array<string, mixed> $input */
    private function recordIndexFeedback(array $input): array
    {
        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->recordFeedback($input);
        });
    }

    /** @param array<string, mixed> $input */
    private function prepareEdit(array $input): array
    {
        $this->requireEditingEnabled();

        return $this->withProject($input, true, function (ProjectIndex $index, array $resolved) use ($input): array {
            $draft = is_array($input['plan'] ?? null) ? $input['plan'] : $input;
            unset($draft['repository'], $draft['project_id'], $draft['plan']);
            $draft['schema_version'] = (string) ($draft['schema_version'] ?? 'edit-plan.v1');
            $draft['project_id'] = $index->projectId();
            $draft['base_commit'] = (string) ($draft['base_commit'] ?? $resolved['head_commit']);
            $draft['project_revision'] = (int) ($draft['project_revision'] ?? $draft['index_revision'] ?? $index->revision());

            $prepared = $this->editService($index)->prepare($draft);
            if (isset($prepared['apply_token']) && !isset($prepared['edit_token'])) {
                $prepared['edit_token'] = $prepared['apply_token'];
                unset($prepared['apply_token']);
            }

            return $prepared;
        });
    }

    /** @param array<string, mixed> $input */

    /**
     * Normalize the complete user intent into a stable contract that survives every typed retry.
     *
     * @param array<string, mixed> $input
     * @param list<string> $paths
     * @param list<string> $symbols
     * @return array<string, mixed>
     */
    private function normalizeTaskContract(array $input, string $task, array $paths, array $symbols): array
    {
        $provided = is_array($input['task_contract'] ?? null) ? $input['task_contract'] : [];
        $activeSkills = $provided['active_skills'] ?? $input['active_skills'] ?? null;
        $activeSkillsDeclared = is_array($activeSkills);

        return [
            'goal' => trim((string) ($provided['goal'] ?? $input['goal'] ?? $task)),
            'requirements' => $this->contractStringList(
                $provided['requirements'] ?? $input['requirements'] ?? [],
                [$task],
            ),
            'known_paths' => $this->contractStringList(
                $provided['known_paths'] ?? $input['known_paths'] ?? [],
                $paths,
            ),
            'known_symbols' => $this->contractStringList(
                $provided['known_symbols'] ?? $input['known_symbols'] ?? [],
                $symbols,
            ),
            'acceptance_criteria' => $this->contractStringList(
                $provided['acceptance_criteria'] ?? $input['acceptance_criteria'] ?? [],
            ),
            'allowed_scope' => $this->contractStringList(
                $provided['allowed_scope'] ?? $input['allowed_scope'] ?? [],
                $paths !== [] ? $paths : ['current_project'],
            ),
            'forbidden_scope' => $this->contractStringList(
                $provided['forbidden_scope'] ?? $input['forbidden_scope'] ?? [],
                ['external systems', 'irreversible operations', 'unrelated projects'],
            ),
            'authorized_actions' => $this->contractStringList(
                $provided['authorized_actions'] ?? $input['authorized_actions'] ?? [],
                [
                    'read indexed project context',
                    'edit allowed project files',
                    'run fixed validation',
                    'targeted reindex',
                    'automatic rollback on validation failure',
                ],
            ),
            'assumptions' => $this->contractStringList(
                $provided['assumptions'] ?? $input['assumptions'] ?? [],
            ),
            'background' => trim((string) ($provided['background'] ?? $input['background'] ?? '')),
            'active_skills' => $activeSkillsDeclared
                ? $this->contractStringList($activeSkills)
                : null,
            'active_skills_display' => $activeSkillsDeclared
                ? 'host_supplied'
                : '宿主未提供',
            'instruction_sources' => $this->contractStringList(
                $provided['instruction_sources'] ?? $input['instruction_sources'] ?? [],
            ),
            'validation_expectations' => $this->contractStringList(
                $provided['validation_expectations'] ?? $input['validation_expectations'] ?? [],
            ),
        ];
    }

    /**
     * @param mixed $value
     * @param list<string> $fallback
     * @return list<string>
     */
    private function contractStringList(mixed $value, array $fallback = []): array
    {
        $items = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $normalized = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $normalized[$text] = true;
            }
        }

        return $normalized === [] ? array_values($fallback) : array_keys($normalized);
    }

    /** @param list<string> $symbols
     *  @return list<string>
     */
    private function requiredExactSymbolTargets(array $symbols): array
    {
        return array_values(array_filter(
            $symbols,
            static fn (string $symbol): bool =>
                str_contains($symbol, '::')
                || str_contains($symbol, '\\')
                || preg_match('~^[A-Z][A-Za-z0-9_]*$~D', $symbol) === 1,
        ));
    }

    /**
     * Attach the single-writer state machine and reject unclassified or exhausted recursion.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function normalizeClosedLoopPlan(array $draft, ProjectIndex $index): array
    {
        $metadata = is_array($draft['metadata'] ?? null) ? $draft['metadata'] : [];
        $task = trim((string) ($metadata['task'] ?? 'authorized local project change'));
        $revision = (int) ($draft['project_revision'] ?? $index->revision());
        $reason = strtoupper(trim((string) ($metadata['recursion_reason'] ?? 'NORMAL')));
        $allowed = ['NORMAL', 'CONFLICT_REPLAN', 'IMPACT_EXPANSION', 'VALIDATION_REPAIR', 'USER_SCOPE_CHANGE'];
        if (!in_array($reason, $allowed, true)) {
            throw new ToolException(
                'WORKFLOW_RECURSION_UNCLASSIFIED',
                'A repeated edit must use a typed closed-loop recursion reason',
                false,
                ['allowed_reasons' => $allowed, 'received' => $reason],
            );
        }

        $counts = is_array($metadata['recursion_counts'] ?? null) ? $metadata['recursion_counts'] : [];
        $limits = [
            'CONFLICT_REPLAN' => 2,
            'IMPACT_EXPANSION' => 2,
            'VALIDATION_REPAIR' => 2,
            'USER_SCOPE_CHANGE' => 1,
        ];
        $count = max(0, (int) ($counts[$reason] ?? 0));
        if ($reason !== 'NORMAL' && $count > ($limits[$reason] ?? 0)) {
            throw new ToolException(
                'WORKFLOW_RETRY_BUDGET_EXCEEDED',
                'The typed recursion budget is exhausted',
                false,
                ['reason' => $reason, 'count' => $count, 'limit' => $limits[$reason] ?? 0],
            );
        }
        $sameErrorCount = max(0, (int) ($metadata['same_error_count'] ?? 0));
        if ($sameErrorCount >= 3) {
            throw new ToolException(
                'WORKFLOW_REPEATED_FAILURE',
                'The same workflow error occurred three times; automatic recursion stopped',
                false,
                ['same_error_count' => $sameErrorCount, 'reason' => $reason],
            );
        }

        $metadata['task'] = $task;
        $metadata['task_id'] = trim((string) ($metadata['task_id'] ?? ''))
            ?: 'task-' . substr(hash('sha256', $index->projectId() . "\0" . $task), 0, 24);
        $metadata['bundle_id'] = trim((string) ($metadata['bundle_id'] ?? ''))
            ?: 'bundle-' . substr(hash('sha256', $index->projectId() . "\0" . $revision . "\0" . $task), 0, 24);
        $metadata['recursion_reason'] = $reason;
        $metadata['recursion_counts'] = $counts;
        $metadata['same_error_count'] = $sameErrorCount;
        $metadata['writer_mode'] = 'single_writer';
        $metadata['subagents_allowed'] = false;
        $metadata['workflow_limits'] = [
            'conflict_replans' => 2,
            'impact_expansion_depth' => 2,
            'validation_repairs' => 2,
            'same_error_stop_count' => 3,
        ];
        $metadata['validation_plan'] = is_array($metadata['validation_plan'] ?? null)
            ? $metadata['validation_plan']
            : [
                'fixed_checks_in_apply' => ['syntax', 'diff_check', 'targeted_reindex'],
                'regression_entry' => 'validate_change',
                'regression_runs_max' => 1,
                'runtime_entry_runs_max' => 1,
            ];
        $draft['metadata'] = $metadata;

        return $draft;
    }

    private function applyCompactEdit(array $input): array
    {
        $this->requireEditingEnabled();
        if (!is_array($input['plan'] ?? null)) {
            throw new ToolException('VALIDATION_FAILED', 'plan is required');
        }
        $runId = self::required($input, 'run_id');
        $bundleId = self::required($input, 'bundle_id');
        $operationCount = count(is_array($input['plan']['operations'] ?? null) ? $input['plan']['operations'] : []);
        if ($operationCount < 1) {
            throw new ToolException('VALIDATION_FAILED', 'A compact edit requires at least one operation');
        }

        return $this->withProject($input, true, function (ProjectIndex $index, array $resolved) use (
            $input,
            $runId,
            $bundleId,
            $operationCount,
        ): array {
            $runs = new ExecutionRunService($this->learningStore, $this->config);
            try {
            if ($operationCount > 50) {
                return $runs->planApplyBatches(
                    $runId,
                    $index->projectId(),
                    $bundleId,
                    $input['plan'],
                );
            }
            $totalStartedAt = hrtime(true);
            $timingMs = [
                'lock_wait' => 0,
                'preflight_index' => 0,
                'prepare' => 0,
                'apply' => 0,
                'validate' => 0,
                'regression' => 0,
                'index' => 0,
                'knowledge' => 0,
                'total' => 0,
            ];
            $draft = $input['plan'];
            $draft['schema_version'] = (string) ($draft['schema_version'] ?? 'edit-plan.v1');
            $draft['project_id'] = $index->projectId();
            $draft['base_commit'] = (string) ($draft['base_commit'] ?? $resolved['head_commit']);
            $submittedRevision = (int) ($draft['project_revision'] ?? $draft['index_revision'] ?? $index->revision());
            $draft['project_revision'] = $submittedRevision;
            $metadata = is_array($draft['metadata'] ?? null) ? $draft['metadata'] : [];
            foreach (['run_id' => $runId, 'bundle_id' => $bundleId] as $key => $expected) {
                $provided = trim((string) ($metadata[$key] ?? ''));
                if ($provided !== '' && !hash_equals($expected, $provided)) {
                    throw new ToolException('RUN_BUNDLE_MISMATCH', 'Plan metadata does not match the apply request', false, [
                        'field' => $key,
                        'expected' => $expected,
                        'received' => $provided,
                    ]);
                }
                $metadata[$key] = $expected;
            }
            $draft['metadata'] = $metadata;
            $draft = $this->normalizeClosedLoopPlan($draft, $index);
            $runs->beginApply($runId, $index->projectId(), $draft);
            $service = $this->editService($index);
            $recovery = $service->recoverInterruptedTransactions();
            if ((bool) ($recovery['requires_attention'] ?? false)
                || (bool) ($recovery['has_more'] ?? false)) {
                $message = (bool) ($recovery['requires_attention'] ?? false)
                    ? 'An interrupted edit has unknown file hashes and must be inspected before new writes'
                    : 'Interrupted edits remain after the bounded recovery batch; retry before new writes';
                throw new ToolException(
                    'EDIT_RECOVERY_REQUIRED',
                    $message,
                    false,
                    ['recovery' => $recovery],
                );
            }

            $result = $service->withPlanFileLocks($draft, function (array $fileLock) use (
                $index,
                $input,
                $service,
                $draft,
                $submittedRevision,
                $totalStartedAt,
                $timingMs,
                $recovery,
            ): array {
                $timingMs['lock_wait'] = max(0, (int) ($fileLock['wait_ms'] ?? 0));
                $lockedRevision = $index->revision();

                try {
                    $preflightStartedAt = hrtime(true);
                    $targetRefresh = $this->refreshIndexedPaths(
                        $index,
                        self::strings($fileLock['paths'] ?? []),
                    );
                    $timingMs['preflight_index'] = self::elapsedMilliseconds($preflightStartedAt);
                    $refreshedRevision = $index->revision();
                    $draft['project_revision'] = $refreshedRevision;
                    $metadata = is_array($draft['metadata'] ?? null) ? $draft['metadata'] : [];
                    $metadata['_mcp_concurrency'] = [
                        'submitted_project_revision' => $submittedRevision,
                        'locked_project_revision' => $lockedRevision,
                        'refreshed_project_revision' => $refreshedRevision,
                        'contended_paths' => $fileLock['contended_paths'] ?? [],
                    ];
                    $draft['metadata'] = $metadata;

                    $prepareStartedAt = hrtime(true);
                    $prepared = $service->prepare($draft, true);
                    $timingMs['prepare'] = self::elapsedMilliseconds($prepareStartedAt);
                    $token = trim((string) ($prepared['apply_token'] ?? $prepared['edit_token'] ?? ''));
                    if ($token === '') {
                        throw new ToolException('INTERNAL_ERROR', 'Prepared compact edit did not return a local apply token');
                    }
                    $editId = trim((string) ($prepared['edit_id'] ?? ''));
                    if ($editId === '') {
                        throw new ToolException('INTERNAL_ERROR', 'Prepared compact edit did not return an edit id');
                    }
                    $applyStartedAt = hrtime(true);
                    $applied = $service->apply(
                        $token,
                        trim((string) ($prepared['plan_digest'] ?? '')),
                        true,
                        true,
                    );
                    $timingMs['apply'] = self::elapsedMilliseconds($applyStartedAt);
                    $paths = $this->editResultPaths($applied);
                    $validateStartedAt = hrtime(true);
                    $validation = $service->validate([
                        'edit_id' => $editId,
                        'profile' => (string) ($draft['validation_profile'] ?? 'default'),
                    ]);
                    $timingMs['validate'] = self::elapsedMilliseconds($validateStartedAt);
                    $regressionStartedAt = hrtime(true);
                    $regressionValidation = $this->runRegressionValidation($index, $paths);
                    $timingMs['regression'] = self::elapsedMilliseconds($regressionStartedAt);
                    $rolledBack = null;
                    $validationPassed = ($validation['status'] ?? '') === 'passed'
                        && ($regressionValidation['status'] ?? '') !== 'failed';
                    if (!$validationPassed) {
                        $rolledBack = $service->rollback($editId);
                    }
                    $indexRefresh = is_array($rolledBack)
                        ? (is_array($rolledBack['index_refresh'] ?? null) ? $rolledBack['index_refresh'] : [])
                        : $service->refreshIndex($editId);
                    $timingMs['index'] = max(0, (int) ($indexRefresh['duration_ms'] ?? 0));
                    $indexRefreshed = (($indexRefresh['status'] ?? '') === 'completed');

                    $knowledgeStartedAt = hrtime(true);
                    $knowledge = $indexRefreshed
                        ? $this->reconcileKnowledge($index, $paths)
                        : [
                            'status' => 'pending',
                            'project_revision' => $index->revision(),
                            'reason' => 'Knowledge reconciliation waits for the recoverable deferred index refresh.',
                            'recovery' => 'Retry index_project in incremental mode; its durable index path also reconciles module knowledge.',
                        ];
                    $timingMs['knowledge'] = self::elapsedMilliseconds($knowledgeStartedAt);
                    $finalStatus = $service->status($editId);

                    $checks = [];
                    foreach (is_array($validation['results'] ?? null) ? $validation['results'] : [] as $result) {
                        if (!is_array($result)) {
                            continue;
                        }
                        $checks[] = [
                            'check' => $result['check'] ?? '',
                            'path' => $result['path'] ?? null,
                            'status' => $result['status'] ?? '',
                            'exit_code' => $result['exit_code'] ?? null,
                            'output' => Text::truncate(trim((string) ($result['output'] ?? '')), 400),
                        ];
                    }
                    $timingMs['total'] = self::elapsedMilliseconds($totalStartedAt);

                    return [
                        'request_id' => Ids::make('req'),
                        'edit_id' => $editId,
                        'state' => $finalStatus['state'] ?? (is_array($rolledBack) ? ($rolledBack['state'] ?? 'rolled_back') : ($validation['status'] === 'passed' ? 'validated' : 'validation_failed')),
                        'paths' => $paths,
                        'base_commit' => (string) ($finalStatus['base_commit'] ?? $prepared['base_commit'] ?? ''),
                        'files' => is_array($finalStatus['files'] ?? null) ? $finalStatus['files'] : [],
                        'change_report' => is_array($finalStatus['change_report'] ?? null) ? $finalStatus['change_report'] : [],
                        'review_contract' => is_array($finalStatus['change_report']['review_contract'] ?? null)
                            ? $finalStatus['change_report']['review_contract']
                            : [],
                        'apply_pipeline' => is_array($finalStatus['apply_pipeline'] ?? null) ? $finalStatus['apply_pipeline'] : [],
                        'impact_delta' => $this->compactEditImpactDelta($index, $draft, $paths),
                        'rebased_files' => is_array($prepared['rebased_files'] ?? null) ? $prepared['rebased_files'] : [],
                        'target_refresh' => [
                            'mode' => 'locked_preflight',
                            'requested_paths' => self::strings($targetRefresh['scope_paths'] ?? ($fileLock['paths'] ?? [])),
                            'changed_paths' => self::strings($targetRefresh['changed_paths'] ?? []),
                            'duration_ms' => max(0, (int) ($targetRefresh['duration_ms'] ?? 0)),
                            'project_revision' => (int) ($draft['project_revision'] ?? $index->revision()),
                        ],
                        'validation' => [
                            'id' => $validation['validation_id'] ?? null,
                            'profile' => $validation['profile'] ?? '',
                            'status' => $validation['status'] ?? 'unknown',
                            'checks' => $checks,
                        ],
                        'regression_validation' => $regressionValidation,
                        'index_revision' => $index->revision(),
                        'index_refreshed' => $indexRefreshed,
                        'knowledge_state' => [
                            'status' => $knowledge['status'] ?? 'unknown',
                            'module_count' => $knowledge['module_count'] ?? count(is_array($knowledge['modules'] ?? null) ? $knowledge['modules'] : []),
                            'reason' => $knowledge['reason'] ?? null,
                            'recovery' => $knowledge['recovery'] ?? null,
                        ],
                        'rolled_back' => is_array($rolledBack),
                        'rollback_available' => !is_array($rolledBack),
                        'interrupted_edit_recovery' => $recovery,
                        'timing_ms' => $timingMs,
                    ];
                } catch (ToolException $exception) {
                    if (!$this->compactEditNeedsReplan($exception)) {
                        throw $exception;
                    }
                    throw $this->compactEditReplanException($index, $draft, $exception, $fileLock);
                }
            });
            $result['run_id'] = $runId;
            $result['bundle_id'] = $bundleId;
            $result['execution_run'] = $runs->completeApply($runId, $index->projectId(), $result);

            return $result;
            } catch (Throwable $exception) {
                $runs->fail($runId, $index->projectId(), $exception);
                throw $exception;
            }
        });
    }

    /**
     * Run a fixed, argv-only regression adapter selected exclusively by changed paths.
     *
     * @param list<string> $paths
     * @return array<string,mixed>
     */
    private function runRegressionValidation(ProjectIndex $index, array $paths): array
    {
        $matchesMcp = false;
        foreach ($paths as $path) {
            if (str_starts_with($path, 'app/code/Weline/Ai/Mcp/')) {
                $matchesMcp = true;
                break;
            }
        }
        $runner = $index->root() . DIRECTORY_SEPARATOR . 'app/code/Weline/Ai/Mcp/tests/run.php';
        if (!$matchesMcp || !is_file($runner)) {
            return [
                'status' => 'skipped',
                'profile' => 'none',
                'reason' => $matchesMcp
                    ? 'The Weline MCP fixed regression runner is not present.'
                    : 'No server-approved regression adapter matched the changed paths.',
            ];
        }

        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $runner, '--quick'],
            $index->root(),
            '',
            120,
            ['WELINE_MCP_TEST_MODE' => 'validation'],
        );
        [$stdout] = Redactor::string((string) ($result['stdout'] ?? ''));
        [$stderr] = Redactor::string((string) ($result['stderr'] ?? ''));

        return [
            'status' => (int) ($result['exit_code'] ?? 1) === 0 && !(bool) ($result['timed_out'] ?? false)
                ? 'passed'
                : 'failed',
            'profile' => 'weline_mcp_quick',
            'exit_code' => (int) ($result['exit_code'] ?? 1),
            'timed_out' => (bool) ($result['timed_out'] ?? false),
            'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
            'output' => Text::truncate(trim($stdout . "\n" . $stderr), 4_000),
        ];
    }

    private function compactEditNeedsReplan(ToolException $exception): bool
    {
        return in_array($exception->errorCode, [
            'EDIT_REBASE_TARGET_CHANGED',
            'EDIT_FILE_STALE',
            'EDIT_INDEX_STALE',
            'EDIT_REVISION_STALE',
            'EDIT_COMMIT_STALE',
            'EDIT_TEXT_NOT_FOUND',
            'EDIT_TEXT_AMBIGUOUS',
            'EDIT_RANGE_INVALID',
            'EDIT_DIGEST_STALE',
            'EDIT_SYMBOL_NOT_FOUND',
            'EDIT_SYMBOL_AMBIGUOUS',
            'EDIT_SYMBOL_RANGE_INVALID',
            'EDIT_SECTION_NOT_FOUND',
            'EDIT_SECTION_AMBIGUOUS',
            'EDIT_TARGET_EXISTS',
            'EDIT_TARGET_INVALID',
        ], true);
    }

    /** @param array<string, mixed> $draft
     *  @param array<string, mixed> $fileLock
     */
    private function compactEditReplanException(
        ProjectIndex $index,
        array $draft,
        ToolException $exception,
        array $fileLock,
    ): ToolException {
        $paths = self::strings($fileLock['paths'] ?? []);
        $warning = null;
        $targetRefresh = null;
        try {
            $targetRefresh = $this->refreshIndexedPaths($index, $paths);
        } catch (Throwable $refreshException) {
            [$message] = Redactor::string($refreshException->getMessage());
            $warning = 'Targeted index refresh failed: ' . Text::truncate($message, 500);
        }

        $latestBundle = [];
        try {
            $latestBundle = (new ProjectRetriever(
                $index,
                new SparseVectorizer($this->config),
                $this->config,
            ))->getEditBundle($this->compactEditReplanTask($draft, $exception), [
                'paths' => $paths,
                'symbols' => $this->compactEditReplanSymbols($draft, $exception),
                'max_regions' => max(1, min(20, count($paths) * 4)),
                'max_chunks_per_file' => 4,
                'token_budget' => 8_000,
                'include_docs' => true,
                'include_skills' => false,
            ]);
        } catch (Throwable $bundleException) {
            [$message] = Redactor::string($bundleException->getMessage());
            $warning = trim(($warning === null ? '' : $warning . ' ') . 'Latest-region retrieval failed: ' . Text::truncate($message, 500));
        }

        $operationPartition = $this->compactEditOperationPartition($draft, $exception);
        $details = [
            'cause' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'details' => $exception->details,
            ],
            'paths' => $paths,
            'project_id' => $index->projectId(),
            'task_id' => (string) ($draft['metadata']['task_id'] ?? ''),
            'bundle_id' => (string) ($draft['metadata']['bundle_id'] ?? ''),
            'failed_operation' => is_array($exception->details['operation'] ?? null)
                ? $exception->details['operation']
                : null,
            'failed_operations' => $operationPartition['failed_operations'],
            'unchanged_operations' => $operationPartition['unchanged_operations'],
            'semantic_diff_from_bundle' => [
                'cause_code' => $exception->errorCode,
                'changed_paths' => is_array($targetRefresh)
                    ? self::strings($targetRefresh['changed_paths'] ?? [])
                    : [],
                'latest_region_count' => is_array($latestBundle['regions'] ?? null)
                    ? count($latestBundle['regions'])
                    : 0,
                'latest_query_id' => $latestBundle['query_id'] ?? null,
            ],
            'requested_symbols' => $this->compactEditReplanSymbols($draft, $exception),
            'project_revision' => $index->revision(),
            'original_task' => $this->compactEditOriginalTask($draft),
            'latest_query_id' => $latestBundle['query_id'] ?? null,
            'latest_regions' => is_array($latestBundle['regions'] ?? null) ? $latestBundle['regions'] : [],
            'target_refresh' => [
                'mode' => 'mismatch_recovery',
                'requested_paths' => is_array($targetRefresh)
                    ? self::strings($targetRefresh['scope_paths'] ?? $paths)
                    : $paths,
                'changed_paths' => is_array($targetRefresh)
                    ? self::strings($targetRefresh['changed_paths'] ?? [])
                    : [],
                'duration_ms' => is_array($targetRefresh)
                    ? max(0, (int) ($targetRefresh['duration_ms'] ?? 0))
                    : 0,
            ],
            'retry_contract' => [
                'plan_schema' => 'edit-plan.v1',
                'new_plan_required' => true,
                'reuse_previous_operations' => true,
                'preserve_unchanged_operations' => true,
                'replace_only_failed_operations' => true,
                'recursion_reason' => 'CONFLICT_REPLAN',
                'max_replans' => 2,
                'same_conflict_stop_count' => 3,
                'preserve_original_requirement' => true,
                'project_revision' => $index->revision(),
                'guard_source' => 'Copy guards only from the latest_region matching each operation symbol_uid/target_ref/path; never use content_sha256 or an adjacent symbol digest.',
                'next_tool' => 'apply_compact_edit',
            ],
            'file_lock' => array_merge($fileLock, ['status' => 'released_before_response']),
            'next' => 'Keep every unchanged operation. Replace only failed operations by matching path plus symbol_uid/target_ref against latest_regions, classify the retry as CONFLICT_REPLAN, and preserve original_task; never guess a digest.',
        ];
        if ($warning !== null && $warning !== '') {
            $details['warning'] = $warning;
        }

        return new ToolException(
            'EDIT_REPLAN_REQUIRED',
            'The locked file changed since the submitted plan and its previous target is no longer safe to apply',
            true,
            $details,
        );
    }

    /**
     * Split the submitted plan at the exact failed operation so callers can retain all safe work.
     *
     * @param array<string, mixed> $draft
     * @return array{failed_operations:list<array<string,mixed>>,unchanged_operations:list<array<string,mixed>>}
     */
    private function compactEditOperationPartition(array $draft, ToolException $exception): array
    {
        $failedDescriptor = is_array($exception->details['operation'] ?? null)
            ? $exception->details['operation']
            : [];
        $failedIndex = isset($failedDescriptor['operation_index'])
            ? (int) $failedDescriptor['operation_index']
            : -1;
        $failedId = trim((string) ($failedDescriptor['op_id'] ?? ''));
        $failed = [];
        $unchanged = [];

        foreach (is_array($draft['operations'] ?? null) ? $draft['operations'] : [] as $index => $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $operation['operation_index'] = (int) $index;
            $operationId = trim((string) ($operation['op_id'] ?? ''));
            $matches = $failedIndex === (int) $index
                || ($failedId !== '' && $operationId !== '' && hash_equals($failedId, $operationId));
            if ($matches) {
                $failed[] = $operation;
                continue;
            }
            $unchanged[] = $operation;
        }

        return [
            'failed_operations' => $failed,
            'unchanged_operations' => $unchanged,
        ];
    }

    /**
     * Calculate post-apply consumers from the refreshed index without another model/tool round trip.
     *
     * @param array<string, mixed> $draft
     * @param list<string> $paths
     * @return array<string, mixed>
     */
    private function compactEditImpactDelta(ProjectIndex $index, array $draft, array $paths): array
    {
        $metadata = is_array($draft['metadata'] ?? null) ? $draft['metadata'] : [];
        $counts = is_array($metadata['recursion_counts'] ?? null) ? $metadata['recursion_counts'] : [];
        $depth = max(0, (int) ($counts['IMPACT_EXPANSION'] ?? 0));
        $symbols = [];
        foreach (is_array($draft['operations'] ?? null) ? $draft['operations'] : [] as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            foreach (['symbol_uid', 'target_ref'] as $key) {
                $symbol = trim((string) ($operation[$key] ?? ''));
                if ($symbol !== '') {
                    $symbols[$symbol] = true;
                    break;
                }
            }
        }

        $knownPaths = array_fill_keys($paths, true);
        $taskContract = is_array($metadata['task_contract'] ?? null) ? $metadata['task_contract'] : [];
        foreach (self::strings($taskContract['known_paths'] ?? []) as $path) {
            $knownPaths[$path] = true;
        }

        try {
            $bundle = (new ProjectRetriever(
                $index,
                new SparseVectorizer($this->config),
                $this->config,
            ))->getEditBundle(
                'Post-apply indexed impact closure for ' . $this->compactEditOriginalTask($draft),
                [
                    'paths' => $paths,
                    'symbols' => array_keys($symbols),
                    'max_regions' => max(1, min(48, count($paths) * 3)),
                    'max_chunks_per_file' => 3,
                    'token_budget' => 12_000,
                    'include_docs' => true,
                    'include_skills' => false,
                ],
            );
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());

            return [
                'requires_followup' => false,
                'new_affected_paths' => [],
                'new_affected_symbols' => [],
                'reason' => 'Post-apply impact inspection was unavailable: ' . Text::truncate($message, 300),
                'related_regions' => [],
                'depth' => $depth,
                'max_depth' => 2,
                'budget_exhausted' => $depth >= 2,
                'status' => 'unavailable',
                'next_state_when_required' => 'IMPACT_EXPANSION',
            ];
        }

        $newPaths = [];
        $newSymbols = [];
        foreach (is_array($bundle['impacts'] ?? null) ? $bundle['impacts'] : [] as $impact) {
            if (!is_array($impact)) {
                continue;
            }
            $symbolHasNewConsumer = false;
            foreach (self::strings($impact['upstream_files'] ?? []) as $path) {
                if (isset($knownPaths[$path])) {
                    continue;
                }
                $newPaths[$path] = true;
                $symbolHasNewConsumer = true;
            }
            $symbol = trim((string) ($impact['symbol'] ?? ''));
            if ($symbolHasNewConsumer && $symbol !== '') {
                $newSymbols[$symbol] = true;
            }
        }
        $relatedRegions = [];
        foreach (is_array($bundle['regions'] ?? null) ? $bundle['regions'] : [] as $region) {
            if (!is_array($region) || !isset($newPaths[(string) ($region['path'] ?? '')])) {
                continue;
            }
            $relatedRegions[] = [
                'path' => (string) ($region['path'] ?? ''),
                'start_line' => (int) ($region['start_line'] ?? 0),
                'end_line' => (int) ($region['end_line'] ?? 0),
                'symbol_uid' => (string) ($region['symbol_uid'] ?? ''),
                'target_ref' => (string) ($region['target_ref'] ?? ''),
                'expected_file_sha256' => (string) ($region['expected_file_sha256'] ?? ''),
                'expected_digest' => (string) ($region['expected_digest'] ?? ''),
            ];
            if (count($relatedRegions) >= 20) {
                break;
            }
        }

        $hasExpansion = $newPaths !== [];
        $budgetExhausted = $depth >= 2;

        return [
            'requires_followup' => $hasExpansion && !$budgetExhausted,
            'new_affected_paths' => array_keys($newPaths),
            'new_affected_symbols' => array_keys($newSymbols),
            'reason' => $hasExpansion
                ? ($budgetExhausted
                    ? 'New indexed consumers were found, but the impact expansion depth is exhausted.'
                    : 'The refreshed index found consumers outside the submitted TaskContract paths.')
                : 'The refreshed index found no consumers outside the submitted TaskContract paths.',
            'related_regions' => $relatedRegions,
            'depth' => $depth,
            'max_depth' => 2,
            'budget_exhausted' => $budgetExhausted,
            'status' => 'complete',
            'next_state_when_required' => 'IMPACT_EXPANSION',
        ];
    }

    /** @param array<string, mixed> $draft */
    private function compactEditOriginalTask(array $draft): string
    {
        $metadata = is_array($draft['metadata'] ?? null) ? $draft['metadata'] : [];
        foreach (['task', 'requirement', 'objective'] as $key) {
            if (!is_scalar($metadata[$key] ?? null)) {
                continue;
            }
            $task = trim((string) $metadata[$key]);
            if ($task === '') {
                continue;
            }

            return Text::truncate(preg_replace('/\s+/', ' ', $task) ?? $task, 1_200);
        }

        return '';
    }

    /** @param array<string, mixed> $draft */
    /** @return list<string> */
    private function compactEditReplanSymbols(array $draft, ToolException $exception): array
    {
        $symbols = [];
        $failedOperation = is_array($exception->details['operation'] ?? null)
            ? $exception->details['operation']
            : [];
        foreach (['symbol_uid', 'target_ref'] as $key) {
            $value = trim((string) ($failedOperation[$key] ?? ''));
            if ($value !== '') {
                $symbols[] = $value;
                break;
            }
        }

        foreach (is_array($draft['operations'] ?? null) ? $draft['operations'] : [] as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            foreach (['symbol_uid', 'target_ref'] as $key) {
                $value = trim((string) ($operation[$key] ?? ''));
                if ($value !== '') {
                    $symbols[] = $value;
                    break;
                }
            }
        }

        return array_slice(array_values(array_unique($symbols)), 0, 12);
    }

    private function compactEditReplanTask(array $draft, ToolException $exception): string
    {
        $anchors = [];
        foreach (is_array($draft['operations'] ?? null) ? $draft['operations'] : [] as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $parts = [trim((string) ($operation['kind'] ?? $operation['operation'] ?? 'edit'))];
            foreach (['target_ref', 'symbol_uid', 'heading', 'search'] as $key) {
                $value = trim((string) ($operation[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                $value = preg_replace('/\s+/', ' ', $value) ?? $value;
                $parts[] = Text::truncate($value, 120);
            }
            $anchors[] = implode(':', $parts);
        }

        $replan = 'Replan a stale compact edit after ' . $exception->errorCode
            . '. Locate the latest exact target regions for '
            . implode('; ', array_slice($anchors, 0, 20));
        $originalTask = $this->compactEditOriginalTask($draft);

        return $originalTask === '' ? $replan : 'Original requirement: ' . $originalTask . '. ' . $replan;
    }

    /** @param array<string, mixed> $input */
    private function applyEdit(array $input): array
    {
        $this->requireEditingEnabled();
        $token = self::required($input, 'edit_token');

        return $this->withProject($input, false, function (ProjectIndex $index) use ($input, $token): array {
            $service = $this->editService($index);
            $recovery = $service->recoverInterruptedTransactions($token);
            if ((bool) ($recovery['requires_attention'] ?? false)) {
                throw new ToolException('EDIT_RECOVERY_REQUIRED', 'Interrupted edit recovery requires inspection', false, ['recovery' => $recovery]);
            }
            $result = ((int) ($recovery['recovered'] ?? 0)) > 0
                ? $service->status($token)
                : $service->apply($token, trim((string) ($input['plan_digest'] ?? '')));
            $result['interrupted_edit_recovery'] = $recovery;
            $result['knowledge_state'] = $this->reconcileKnowledge($index, $this->editResultPaths($result));
            return $result;
        });
    }

    /** @param array<string, mixed> $input */
    private function getRunStatus(array $input): array
    {
        $runId = self::required($input, 'run_id');

        return $this->withProject($input, false, function (ProjectIndex $index) use ($runId): array {
            return [
                'request_id' => Ids::make('req'),
                'project_id' => $index->projectId(),
                'repository' => $index->root(),
                'execution_run' => (new ExecutionRunService($this->learningStore, $this->config))
                    ->status($index->projectId(), $runId),
            ];
        });
    }

    /** @param array<string, mixed> $input */
    private function getRunTrace(array $input): array
    {
        $runId = self::required($input, 'run_id');

        return $this->withProject($input, false, function (ProjectIndex $index) use ($input, $runId): array {
            $trace = (new ExecutionRunService($this->learningStore, $this->config))->trace(
                $index->projectId(),
                $runId,
                [
                    'after_sequence' => max(0, (int) ($input['after_sequence'] ?? 0)),
                    'limit' => max(1, min(200, (int) ($input['limit'] ?? 100))),
                    'include_files' => (bool) ($input['include_files'] ?? true),
                    'include_diffs' => (bool) ($input['include_diffs'] ?? false),
                    'path' => trim((string) ($input['path'] ?? '')),
                ],
            );
            $trace['request_id'] = Ids::make('req');
            $trace['project_id'] = $index->projectId();
            $trace['repository'] = $index->root();

            return $trace;
        });
    }

    /** @param array<string, mixed> $input */
    private function getEditStatus(array $input): array
    {
        $id = trim((string) ($input['edit_id'] ?? $input['edit_token'] ?? ''));
        $reviewCursor = trim((string) ($input['review_cursor'] ?? ''));
        if ($id === '') {
            throw new ToolException('VALIDATION_FAILED', 'edit_id or edit_token is required');
        }

        return $this->withProject($input, false, function (ProjectIndex $index) use ($id, $reviewCursor): array {
            $service = $this->editService($index);
            $recovery = $service->recoverInterruptedTransactions($id);
            $status = $service->status($id, $reviewCursor);
            $status['interrupted_edit_recovery'] = $recovery;
            $status['review_contract'] = is_array($status['change_report']['review_contract'] ?? null)
                ? $status['change_report']['review_contract']
                : [];
            return $status;
        });
    }

    /** @param array<string, mixed> $input */
    private function validateChange(array $input): array
    {
        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            if (isset($input['edit_token']) && !isset($input['token'])) {
                $input['token'] = $input['edit_token'];
            }
            $id = trim((string) ($input['edit_id'] ?? $input['token'] ?? ''));
            $service = $this->editService($index);
            if ($id !== '') {
                $recovery = $service->recoverInterruptedTransactions($id);
                if ((bool) ($recovery['requires_attention'] ?? false)) {
                    throw new ToolException('EDIT_RECOVERY_REQUIRED', 'Interrupted edit recovery requires inspection', false, ['recovery' => $recovery]);
                }
            }
            return $service->validate($input);
        });
    }

    /** @param array<string, mixed> $input */
    private function rollbackEdit(array $input): array
    {
        $this->requireEditingEnabled();
        $id = trim((string) ($input['edit_id'] ?? $input['edit_token'] ?? ''));
        if ($id === '') {
            throw new ToolException('VALIDATION_FAILED', 'edit_id or edit_token is required');
        }

        return $this->withProject($input, false, function (ProjectIndex $index) use ($id): array {
            $service = $this->editService($index);
            $recovery = $service->recoverInterruptedTransactions($id);
            $result = $service->rollback($id);
            $result['interrupted_edit_recovery'] = $recovery;
            $result['knowledge_state'] = $this->reconcileKnowledge($index, $this->editResultPaths($result));
            return $result;
        });
    }

    /** @param array<string, mixed> $input */
    private function checkDocumentDrift(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            return $this->knowledgeService($index)->checkDrift($input);
        });
    }

    /** @param array<string, mixed> $input */
    private function syncModuleKnowledge(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            $prepared = $this->knowledgeService($index)->prepareSync($input);
            $mode = strtolower(trim((string) ($input['mode'] ?? 'preview')));
            if ($mode === 'preview') {
                return $prepared + ['applied' => false];
            }
            if ($mode !== 'apply') {
                throw new ToolException('VALIDATION_FAILED', 'mode must be preview or apply');
            }
            throw new ToolException(
                'STATIC_KNOWLEDGE_PROJECTION_RETIRED',
                'sync_module_knowledge apply mode was retired. Run prepare_project; missing module documents are auto-repaired during preparation.',
                false,
                ['replacement' => 'prepare_project', 'static_skill_files' => false],
            );
        });
    }

    private function editService(ProjectIndex $index): EditService
    {
        return new EditService(
            $index,
            new ProjectIndexer($index, $this->config, new ProcessRunner()),
            $this->config,
        );
    }

    private function knowledgeService(ProjectIndex $index): KnowledgeService
    {
        $runner = new ProcessRunner();
        $codex = new CodexInvoker($this->config, $runner);

        return new KnowledgeService(
            $index,
            new ProjectIndexer($index, $this->config, $runner),
            $this->config,
            $codex,
        );
    }

    /** @param array<string, mixed> $input
     *  @param callable(ProjectIndex,array<string,mixed>):array<string,mixed> $callback
     *  @return array<string, mixed>
     */
    private function withProject(array $input, bool $refresh, callable $callback): array
    {
        $repository = trim((string) ($input['repository'] ?? ''));
        $repositorySource = 'argument';
        if ($repository === '') {
            $repository = $this->inferRepositoryFromKnownPaths($input) ?? '';
            $repositorySource = 'process_cwd_validated_by_paths';
        }
        if ($repository === '') {
            throw new ToolException(
                'REPOSITORY_REQUIRED',
                'repository is required unless every known path exists safely under the current project directory',
                false,
                [
                    'repository_inference_attempted' => true,
                    'known_path_count' => count(self::strings($input['paths'] ?? [])),
                ],
            );
        }
        $resolved = ProjectResolver::resolve($repository, false);
        $resolved['repository_source'] = $repositorySource;
        $requestedProject = trim((string) ($input['project_id'] ?? ''));
        $actualProject = (string) $resolved['project']['id'];
        if ($requestedProject !== '' && $requestedProject !== $actualProject) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'project_id does not match repository');
        }
        $this->learningStore->upsertProject($resolved['project']);
        $cacheKey = hash('sha256', $actualProject . "\0" . (string) $resolved['repository']);
        if (!isset($this->projectIndexes[$cacheKey])) {
            if (count($this->projectIndexes) >= 8) {
                $oldestKey = array_key_first($this->projectIndexes);
                if (is_string($oldestKey)) {
                    $this->projectIndexes[$oldestKey]->close();
                    unset($this->projectIndexes[$oldestKey]);
                }
            }
            $this->projectIndexes[$cacheKey] = new ProjectIndex($this->config, $resolved);
        }
        $index = $this->projectIndexes[$cacheKey];
        if ($refresh) {
            $this->refreshIfNeeded($index);
        }

        return $callback($index, $resolved);
    }

    /** @param array<string,mixed> $input */
    private function inferRepositoryFromKnownPaths(array $input): ?string
    {
        $paths = self::strings($input['paths'] ?? []);
        $cwd = getcwd();
        $root = is_string($cwd) ? realpath($cwd) : false;
        if ($paths === [] || $root === false || !is_dir($root)) {
            return null;
        }
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($paths as $path) {
            $path = str_replace('\\', '/', trim($path));
            if ($path === ''
                || str_starts_with($path, '/')
                || preg_match('~^[A-Za-z]:/~', $path) === 1
                || in_array('..', explode('/', $path), true)) {
                return null;
            }
            $absolute = realpath($rootPrefix . str_replace('/', DIRECTORY_SEPARATOR, $path));
            if ($absolute === false || !is_file($absolute)) {
                return null;
            }
            $normalizedRoot = strtolower($rootPrefix);
            $normalizedAbsolute = strtolower($absolute);
            if (!str_starts_with($normalizedAbsolute, $normalizedRoot)) {
                return null;
            }
        }

        return $root;
    }

    private function refreshIfNeeded(ProjectIndex $index): void
    {
        $this->requireIndexEnabled();
        if (!(bool) $this->config->get('index.auto_refresh', true) && $index->revision() > 0) {
            return;
        }
        $state = $index->state();
        $last = strtotime((string) ($state['last_indexed_at'] ?? $state['last_completed_at'] ?? '')) ?: 0;
        $stale = $last === 0 || (time() - $last) >= $this->config->duration('index.refresh_interval');
        $contentStoreIncomplete = $index->revision() > 0 && $index->missingFileContentCount() > 0;
        if ($index->revision() === 0 || $stale || $contentStoreIncomplete) {
            $result = (new ProjectIndexer($index, $this->config, new ProcessRunner()))->index([
                'mode' => $index->revision() === 0 ? 'full' : 'incremental',
            ]);
            $this->reconcileKnowledge($index, self::strings($result['changed_paths'] ?? []));
        }
    }

    /** @param array<string, mixed> $result
     *  @return list<string>
     */
    private function editResultPaths(array $result): array
    {
        $paths = [];
        foreach (is_array($result['files'] ?? null) ? $result['files'] : [] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $paths[] = $file['path'];
            }
        }
        return Text::uniqueStrings($paths);
    }

    /** @param list<string> $paths
     *  @return array<string, mixed>
     */
    private function reconcileKnowledge(ProjectIndex $index, array $paths): array
    {
        if ($paths === []) {
            return [
                'status' => 'unchanged',
                'project_revision' => $index->revision(),
                'modules' => [],
            ];
        }
        try {
            $result = ['status' => 'completed'] + $this->knowledgeService($index)->afterIndexed($paths);
            $modules = is_array($result['modules'] ?? null) ? $result['modules'] : [];
            $statusCounts = [];
            foreach ($modules as $module) {
                $status = is_array($module) ? (string) ($module['status'] ?? 'unknown') : 'unknown';
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }
            ksort($statusCounts);
            $result['module_count'] = count($modules);
            $result['module_status_counts'] = $statusCounts;
            if (count($modules) > 20) {
                $result['modules'] = array_slice(array_values(array_filter(
                    $modules,
                    static fn (mixed $module): bool => is_array($module)
                        && (($module['status'] ?? 'unknown') !== 'unknown'
                            || ($module['stale_skill_ids'] ?? []) !== []
                            || ($module['hydrated_skill_id'] ?? null) !== null),
                )), 0, 20);
                $result['modules_truncated'] = true;
            } else {
                $result['modules_truncated'] = false;
            }
            return $result;
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            return [
                'status' => 'pending',
                'project_revision' => $index->revision(),
                'error' => Text::truncate($message, 2_000),
            ];
        }
    }

    /** @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    private function compactIndexResult(array $result): array
    {
        $paths = self::strings($result['changed_paths'] ?? []);
        $result['changed_path_count'] = count($paths);
        $result['changed_paths_digest'] = 'sha256:' . hash('sha256', Json::canonical($paths));
        if (count($paths) > 20) {
            $result['changed_paths'] = array_slice($paths, 0, 20);
            $result['changed_paths_truncated'] = true;
        } else {
            $result['changed_paths'] = $paths;
            $result['changed_paths_truncated'] = false;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function learningContext(string $projectId, string $task, array $paths, int $limit): array
    {
        try {
            $result = $this->learningStore->searchExperiences(
                $projectId,
                $task,
                [],
                ['validated', 'promotion_eligible', 'promoted'],
                $paths,
                $limit,
            );
            $context = [];
            foreach ($result['experiences'] ?? [] as $experience) {
                $context[] = [
                    'experience_id' => $experience['experience_id'] ?? '',
                    'title' => $experience['title'] ?? '',
                    'rule' => $experience['reusable_rule'] ?? '',
                    'trigger' => $experience['trigger'] ?? '',
                    'status' => $experience['status'] ?? '',
                    'confidence' => $experience['confidence'] ?? 0,
                    'scope' => $experience['scope'] ?? [],
                ];
            }

            return $context;
        } catch (Throwable) {
            return [];
        }
    }

    private function requireIndexEnabled(): void
    {
        if (!(bool) $this->config->get('index.enabled', true)) {
            throw new ToolException('DISABLED', 'Project intelligence indexing is disabled');
        }
    }

    private function requireEditingEnabled(): void
    {
        if (!(bool) $this->config->get('editing.enabled', true)) {
            throw new ToolException('DISABLED', 'Project editing is disabled');
        }
    }

    private static function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /** @param array<string, mixed> $input */
    private static function required(array $input, string $key): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            throw new ToolException('VALIDATION_FAILED', $key . ' is required');
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? Text::uniqueStrings($value) : [];
    }
}
