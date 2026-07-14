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
            'resolve_task_context' => $this->resolveTaskContext($input),
            'search_project_knowledge' => $this->searchProjectKnowledge($input),
            'get_indexed_document' => $this->getIndexedDocument($input),
            'get_indexed_files' => $this->getIndexedFiles($input),
            'inspect_symbol' => $this->inspectSymbol($input),
            'resolve_skill' => $this->resolveSkill($input),
            'get_skill' => $this->getSkill($input),
            'record_index_feedback' => $this->recordIndexFeedback($input),
            'prepare_edit' => $this->prepareEdit($input),
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
            'query_time_repository_scan' => false,
            'auto_refresh' => (bool) $this->config->get('index.auto_refresh', true),
            'post_tool_incremental_refresh' => (bool) $this->config->get('index.auto_refresh', true),
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
