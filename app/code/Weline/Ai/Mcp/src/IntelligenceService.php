<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

/**
 * Project-scoped facade for the persistent code, documentation, and skill index.
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
            'project_index_status' => $this->projectIndexStatus($input),
            'index_project' => $this->indexProject($input),
            'resolve_task_context' => $this->resolveTaskContext($input),
            'search_project_knowledge' => $this->searchProjectKnowledge($input),
            'get_indexed_document' => $this->getIndexedDocument($input),
            'get_indexed_files' => $this->getIndexedFiles($input),
            'inspect_symbol' => $this->inspectSymbol($input),
            'resolve_skill' => $this->resolveSkill($input),
            'get_skill' => $this->getSkill($input),
            'check_document_drift' => $this->checkDocumentDrift($input),
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
            'learning_projection_closed_loop' => false,
            'learning_projection_verification' => 'retired_in_0.13.0',
            'query_time_repository_scan' => false,
            'auto_refresh' => (bool) $this->config->get('index.auto_refresh', true),
            'post_tool_incremental_refresh' => (bool) $this->config->get('index.auto_refresh', true),
            'post_tool_refresh_strategy' => 'mutation_filtered_targeted_sidecar',
            'index_sidecar' => IndexSidecar::status($this->config),
            'bound_repository' => RepositoryScope::boundRepository($this->config),
            'bound_generation' => RepositoryScope::boundGeneration($this->config),
            'data_dir' => $this->config->dataDir(),
            'refresh_interval_seconds' => $this->config->duration('index.refresh_interval'),
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

            $groups = is_array($context['context'] ?? null) ? $context['context'] : [];
            $fragments = [];
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
                }
            }
            $ruleSummaries = [];
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
            $sessionId = trim((string) ($input['client_session_id'] ?? ''));
            $workflowContract = GuidanceWorkflowCatalog::forTask($task);

            return ContextResponseBudget::fit([
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
                'rules' => $ruleSummaries,
                'fragments' => GuidanceWorkflowCatalog::mergeFragments($fragments, []),
                'query' => [
                    'query_id' => (string) ($context['query_id'] ?? ''),
                    'result_count' => count($fragments),
                    'warnings' => is_array($context['warnings'] ?? null) ? $context['warnings'] : [],
                ],
                'workflow_contract' => $workflowContract,
                'framework_candidates' => FrameworkPlanCandidates::build($task, $fragments),
                'routing_contract' => [
                    'authoritative_sources' => 'Framework/doc and app/code/*/*/doc',
                    'host_native_coding' => true,
                    'mcp_index_read' => true,
                    'static_skill_files' => false,
                    'scan_fallback' => false,
                    'extension_point_selection_required' => true,
                    'workflow_doc' => HardConstraintsCatalog::AUTHORITATIVE_WORKFLOW_DOC,
                    'note' => 'Use indexed fragments, hashes, search_project_knowledge, and get_indexed_document/get_indexed_files for code-map reads. Prefer resolve_skill/get_skill for engineering skills. Implement with host-native editors.',
                ],
            ], $tokenBudget);
        });
    }

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

    private function getIndexedDocument(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->getDocument($input);
        });
    }

    private function getIndexedFiles(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index) use ($input): array {
            return (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->getFiles($input);
        });
    }

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

    private function resolveSkill(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index, array $resolved) use ($input): array {
            $repository = (string) ($resolved['repository'] ?? $index->root());
            $task = trim((string) ($input['task'] ?? $input['query'] ?? ''));
            $listAll = !empty($input['list_all'])
                || in_array(mb_strtolower($task, 'UTF-8'), ['提取技能', 'list', 'list_all', 'list skills', 'mcp skills', 'all'], true);
            $limit = (int) ($input['limit'] ?? ($listAll ? 500 : 5));
            $includeContent = !empty($input['include_content']);
            $skills = McpSkillCatalog::resolve($task, $limit, $includeContent, $repository, $listAll);
            $policy = McpSkillCatalog::policy($repository);

            return [
                'schema_version' => McpSkillCatalog::SCHEMA,
                'provider' => McpSkillCatalog::PROVIDER,
                'static_skill_files' => false,
                'task' => $task,
                'list_all' => $listAll,
                'skills' => $skills,
                'catalog_count' => count(McpSkillCatalog::summary($repository)),
                'catalog_counts' => $policy['catalog_counts'] ?? [],
                'commands' => $listAll ? ($policy['commands'] ?? []) : [],
                'greeting' => $listAll ? ($policy['greeting'] ?? null) : null,
                'fetch' => [
                    'tool' => 'get_skill',
                    'required' => ['skill_id'],
                    'note' => 'Call get_skill(skill_id) for full skill body. Host SKILL.md is not authoritative.',
                ],
                'related_tools' => ['get_skill', 'resolve_task_context', 'search_project_knowledge'],
                'policy' => [
                    'authority' => 'mcp',
                    'host_shell_role' => 'optional_thin_mirror',
                    'extract_skills_command' => $policy['extract_skills_command'] ?? null,
                ],
            ];
        });
    }

    private function getSkill(array $input): array
    {
        return $this->withProject($input, true, function (ProjectIndex $index, array $resolved) use ($input): array {
            $repository = (string) ($resolved['repository'] ?? $index->root());
            $selector = trim((string) ($input['skill_id'] ?? $input['name'] ?? ''));
            if ($selector === '') {
                $selector = trim((string) ($input['path'] ?? ''));
            }
            $skill = $selector !== '' ? McpSkillCatalog::get($selector, true, $repository) : null;
            if ($skill === null) {
                $task = trim((string) ($input['task'] ?? ''));
                if ($task === '' && $selector !== '') {
                    $task = $selector;
                }
                if ($task !== '') {
                    $matches = McpSkillCatalog::resolve($task, 1, true, $repository, false);
                    $skill = $matches[0] ?? null;
                }
            }

            return [
                'schema_version' => McpSkillCatalog::SCHEMA,
                'provider' => McpSkillCatalog::PROVIDER,
                'static_skill_files' => false,
                'skill' => $skill,
                'warnings' => $skill === null
                    ? ['Skill was not found in the MCP skill catalog. Call resolve_skill(list_all=true) or resolve_task_context.']
                    : [],
                'related_tools' => ['resolve_skill', 'resolve_task_context'],
                'policy' => [
                    'authority' => 'mcp',
                    'host_shell_role' => 'optional_thin_mirror',
                ],
            ];
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
        try {
            RepositoryScope::assertAllowed($this->config, (string) $resolved['repository']);
        } catch (\RuntimeException $exception) {
            throw new ToolException(
                'PROJECT_SCOPE_VIOLATION',
                $exception->getMessage(),
                false,
                [
                    'bound_repository' => RepositoryScope::boundRepository($this->config),
                    'requested_repository' => (string) $resolved['repository'],
                ],
            );
        }
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
