<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

/**
 * Project-scoped facade for the persistent code, documentation, skill, and edit index.
 *
 * Discovery and parsing live behind this facade. Read tools never recursively scan the
 * repository: they query the persisted project index and only trigger a bounded refresh
 * when the configured freshness interval has elapsed.
 */
final class IntelligenceService
{
    /** @var array<string, ProjectIndex> */
    private array $projectIndexes = [];

    public function __construct(
        private readonly Store $learningStore,
        private readonly Config $config,
    ) {
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
            'learning_projection_closed_loop' => true,
            'learning_projection_verification' => 'revision+file_hash+content_store+skill_metadata',
            'per_file_edit_lock_queue' => true,
            'stale_edit_replan_regions' => true,
            'query_time_repository_scan' => false,
            'auto_refresh' => (bool) $this->config->get('index.auto_refresh', true),
            'post_tool_incremental_refresh' => (bool) $this->config->get('index.auto_refresh', true),
            'post_tool_refresh_strategy' => 'mutation_filtered_targeted_sidecar',
            'index_sidecar' => IndexSidecar::status($this->config),
            'refresh_interval_seconds' => $this->config->duration('index.refresh_interval'),
            'editing_enabled' => (bool) $this->config->get('editing.enabled', true),
            'codex_document_planner' => (bool) $this->config->get('knowledge.codex.enabled', false),
            'module_skill_root' => 'app/code/{Vendor}/{Module}/doc/ai/skills',
        ];
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

            return [
                'request_id' => Ids::make('req'),
                'project_id' => $index->projectId(),
                'repository' => $index->root(),
                'index_db' => $index->path(),
                'index_revision' => $index->revision(),
                'freshness' => $index->status()['freshness'] ?? 'unknown',
                'task' => $task,
                'context' => $context,
                'validated_learning' => $learning,
                'routing_contract' => [
                    'use_returned_paths' => true,
                    'batch_read_returned_paths' => 'Call get_indexed_files once with the exact selected paths instead of reading files one by one.',
                    'use_inspect_symbol_for_graph_followups' => true,
                    'scan_fallback' => false,
                    'note' => 'Initial/incremental discovery is performed by the MCP indexer. AI-side recursive scanning is unnecessary while this index is fresh.',
                ],
            ];
        });
    }

    /** @param array<string, mixed> $input */
    private function getEditBundle(array $input): array
    {
        $task = self::required($input, 'task');
        $paths = self::strings($input['paths'] ?? []);

        return $this->withProject($input, true, function (ProjectIndex $index) use ($input, $task, $paths): array {
            $onDemandIndex = $this->refreshIndexedPaths($index, $paths);

            $bundle = (new ProjectRetriever(
                $index,
                new SparseVectorizer($this->config),
                $this->config,
            ))->getEditBundle($task, [
                'paths' => $paths,
                'symbols' => array_slice(self::strings($input['symbols'] ?? []), 0, 12),
                'module' => trim((string) ($input['module'] ?? '')),
                'kinds' => self::strings($input['kinds'] ?? []),
                'max_regions' => max(1, min(20, (int) ($input['max_regions'] ?? 8))),
                'max_chunks_per_file' => max(1, min(4, (int) ($input['max_chunks_per_file'] ?? 2))),
                'token_budget' => max(256, min(8_000, (int) ($input['token_budget'] ?? 1_800))),
                'include_docs' => (bool) ($input['include_docs'] ?? true),
                'include_skills' => (bool) ($input['include_skills'] ?? true),
            ]);
            if (is_array($onDemandIndex)) {
                $bundle['on_demand_index'] = [
                    'mode' => 'explicit_paths_refresh',
                    'requested_paths' => self::strings($onDemandIndex['scope_paths'] ?? $paths),
                    'changed_paths' => self::strings($onDemandIndex['changed_paths'] ?? []),
                    'duration_ms' => max(0, (int) ($onDemandIndex['duration_ms'] ?? 0)),
                    'project_revision' => $index->revision(),
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
            $bundle['routing'] = [
                'next' => 'Return only replacements for these guarded regions; use apply_compact_edit once to write, validate, and reindex locally.',
                'scan_required' => false,
                'whole_file_read_required' => false,
            ];

            return $bundle;
        });
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
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->resolveSkill($input);
        });
    }

    /** @param array<string, mixed> $input */
    private function getSkill(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->getSkill($input);
        });
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
    private function applyCompactEdit(array $input): array
    {
        $this->requireEditingEnabled();
        if (!is_array($input['plan'] ?? null)) {
            throw new ToolException('VALIDATION_FAILED', 'plan is required');
        }
        $operationCount = count(is_array($input['plan']['operations'] ?? null) ? $input['plan']['operations'] : []);
        if ($operationCount < 1 || $operationCount > 50) {
            throw new ToolException('EDIT_BUDGET_EXCEEDED', 'A compact edit requires between 1 and 50 operations');
        }

        return $this->withProject($input, true, function (ProjectIndex $index, array $resolved) use ($input): array {
            $totalStartedAt = hrtime(true);
            $timingMs = [
                'lock_wait' => 0,
                'preflight_index' => 0,
                'prepare' => 0,
                'apply' => 0,
                'validate' => 0,
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
            $service = $this->editService($index);

            return $service->withPlanFileLocks($draft, function (array $fileLock) use (
                $index,
                $input,
                $service,
                $draft,
                $submittedRevision,
                $totalStartedAt,
                $timingMs,
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
                    $rolledBack = null;
                    $rollbackOnFailure = (bool) ($input['rollback_on_validation_failure'] ?? true);
                    if (($validation['status'] ?? '') !== 'passed' && $rollbackOnFailure) {
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
                        'state' => is_array($rolledBack) ? ($rolledBack['state'] ?? 'rolled_back') : ($validation['status'] === 'passed' ? 'validated' : 'validation_failed'),
                        'paths' => $paths,
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
                        'timing_ms' => $timingMs,
                    ];
                } catch (ToolException $exception) {
                    if (!$this->compactEditNeedsReplan($exception)) {
                        throw $exception;
                    }
                    throw $this->compactEditReplanException($index, $draft, $exception, $fileLock);
                }
            });
        });
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

        $details = [
            'cause' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'details' => $exception->details,
            ],
            'paths' => $paths,
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
                'reuse_previous_operations' => false,
                'preserve_original_requirement' => true,
                'project_revision' => $index->revision(),
                'next_tool' => 'apply_compact_edit',
            ],
            'file_lock' => array_merge($fileLock, ['status' => 'released_before_response']),
            'next' => 'Discard the stale operations. Replan from latest_regions for original_task and submit a new edit-plan.v1; do not retry or patch the unchanged plan.',
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
            $result = $this->editService($index)->apply($token, trim((string) ($input['plan_digest'] ?? '')));
            $result['knowledge_state'] = $this->reconcileKnowledge($index, $this->editResultPaths($result));
            return $result;
        });
    }

    /** @param array<string, mixed> $input */
    private function getEditStatus(array $input): array
    {
        $id = trim((string) ($input['edit_id'] ?? $input['edit_token'] ?? ''));
        if ($id === '') {
            throw new ToolException('VALIDATION_FAILED', 'edit_id or edit_token is required');
        }

        return $this->withProject($input, false, function (ProjectIndex $index) use ($id): array {
            return $this->editService($index)->status($id);
        });
    }

    /** @param array<string, mixed> $input */
    private function validateChange(array $input): array
    {
        return $this->withProject($input, false, function (ProjectIndex $index) use ($input): array {
            if (isset($input['edit_token']) && !isset($input['token'])) {
                $input['token'] = $input['edit_token'];
            }
            return $this->editService($index)->validate($input);
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
            $result = $this->editService($index)->rollback($id);
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
            $knowledge = $this->knowledgeService($index);
            $prepared = $knowledge->prepareSync($input);
            $mode = strtolower(trim((string) ($input['mode'] ?? 'preview')));
            if ($mode === 'preview') {
                return $prepared + ['applied' => false];
            }
            if ($mode !== 'apply') {
                throw new ToolException('VALIDATION_FAILED', 'mode must be preview or apply');
            }
            if (empty($input['confirm'])) {
                throw new ToolException('APPROVAL_REQUIRED', 'confirm=true is required to apply generated module knowledge changes');
            }
            $draft = $prepared['edit_plan'] ?? $prepared['plan'] ?? null;
            if (!is_array($draft)) {
                $operations = is_array($prepared['operations'] ?? null) ? $prepared['operations'] : [];
                $conflicts = is_array($prepared['conflicts'] ?? null) ? $prepared['conflicts'] : [];
                $moduleCode = trim((string) ($prepared['module']['code'] ?? ''));
                if ($operations === [] && $conflicts === [] && $moduleCode !== '') {
                    return $prepared + [
                        'applied' => false,
                        'already_current' => true,
                        'synchronized' => $knowledge->markSynchronized($moduleCode),
                    ];
                }
                throw new ToolException('VALIDATION_FAILED', 'Knowledge sync did not produce an edit plan');
            }
            $edit = $this->editService($index);
            $transaction = $edit->prepare($draft);
            $token = trim((string) ($transaction['edit_token'] ?? $transaction['apply_token'] ?? ''));
            if ($token === '') {
                throw new ToolException('INTERNAL_ERROR', 'Prepared knowledge edit did not return an edit token');
            }
            $applied = $edit->apply($token, (string) ($transaction['plan_digest'] ?? ''));
            $knowledgeState = $this->reconcileKnowledge($index, array_values(array_unique(array_filter(array_map(
                static fn (mixed $operation): string => is_array($operation) ? trim((string) ($operation['path'] ?? '')) : '',
                is_array($draft['operations'] ?? null) ? $draft['operations'] : [],
            )))));
            $moduleCode = trim((string) ($prepared['module']['code'] ?? ''));
            $synchronized = $moduleCode === ''
                ? ['status' => 'unknown', 'reason' => 'Prepared knowledge plan did not expose a module code.']
                : $knowledge->markSynchronized($moduleCode);

            unset($transaction['apply_token'], $transaction['edit_token']);

            return $prepared + [
                'applied' => true,
                'transaction' => $transaction,
                'result' => $applied,
                'knowledge_state' => $knowledgeState,
                'synchronized' => $synchronized,
            ];
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
        if ($repository === '') {
            throw new ToolException('VALIDATION_FAILED', 'repository is required');
        }
        $resolved = ProjectResolver::resolve($repository, false);
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
