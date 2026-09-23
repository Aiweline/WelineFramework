<?php

declare(strict_types=1);

namespace LearningMcp;

final class ToolService
{
    public const VERSION = '0.13.29';

    /** MCP server instructions: bootstrap + hard-constraints preamble (bodies in HardConstraintsCatalog). */
    public static function instructions(): string
    {
        return HardConstraintsCatalog::mcpInstructions();
    }

    private readonly IntelligenceService $intelligence;
    private readonly string $runtimeGeneration;
    private readonly string $runtimeStartedAt;
    private readonly int $runtimePid;

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly Analyzer $analyzer,
        ?IntelligenceService $intelligence = null,
    ) {
        $this->intelligence = $intelligence ?? new IntelligenceService($store, $config);
        $this->runtimeGeneration = self::sourceGeneration();
        $this->runtimeStartedAt = Clock::now();
        $this->runtimePid = (int) getmypid();
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $readOnly = self::annotations(true, false, true);
        $additive = self::annotations(false, false, true);

        $scope = [
            'project_id' => self::stringSchema('Stable project ID; when present it must match repository.'),
            'repository' => self::stringSchema('Absolute project directory. Its canonical directory path is the project boundary and identity; never replace it with an enclosing Git root.'),
        ];
        $project = $scope + [
            'client_session_id' => self::stringSchema('Stable identifier for the current AI client session.'),
            'readiness_id' => self::stringSchema('Current readiness_id returned by prepare_project for this client session.'),
        ];

        $definitions = [
            self::tool(
                'prepare_project',
                'Prepare Weline project knowledge',
                'Mandatory session entry. Scan app/code/*/*, verify the three-document contract and dev-branch policy, incrementally refresh the isolated SQLite index, and return project-readiness.v1 with hard_constraints, mcp_skills, and index freshness for knowledge/code-map tools.',
                self::objectSchema($scope + [
                    'client_session_id' => self::stringSchema('Stable identifier for the current AI client session.'),
                ], ['repository', 'client_session_id']),
                $additive,
            ),
            self::tool(
                'repair_project_docs',
                'Apply a deterministic documentation repair',
                'Create only the missing module documents from the deterministic repair bundle, reindex transactionally, and roll back created files if reindexing fails. Existing documents are never overwritten. prepare_project already auto-repairs missing documents; this tool remains for manual replay of the same bundle.',
                self::objectSchema($scope + [
                    'client_session_id' => self::stringSchema('Session that received the repair bundle.'),
                    'repair_bundle_id' => self::stringSchema('Exact deterministic bundle returned by prepare_project.'),
                    'authorized' => ['type' => 'boolean', 'description' => 'Deprecated compatibility flag; ignored because repairs are automatic.'],
                ], ['repository', 'client_session_id', 'repair_bundle_id']),
                $additive,
            ),
            self::tool(
                'project_index_status',
                'Project index status',
                'Return the isolated index database path, revision, freshness, counts, parser/vector modes, and skipped-path statistics without scanning repository content.',
                self::objectSchema($scope, ['repository']),
                $readOnly,
            ),
            self::tool(
                'index_project',
                'Refresh project index',
                'Build or incrementally refresh the local code, documentation, symbol, FTS, and sparse-vector index. Discovery uses a bounded filesystem catalogue rooted at the exact project directory with strict exclusions, including for non-Git directories.',
                self::objectSchema($project + [
                    'mode' => ['type' => 'string', 'enum' => ['full', 'incremental']],
                    'paths' => self::stringsSchema('Optional exact repository-relative paths for targeted refresh.'),
                ], ['repository']),
                $additive,
            ),
            self::tool(
                'resolve_task_context',
                'Resolve indexed task context',
                'Return a token-bounded guidance-bundle.v1 with exact code/document locations, hashes, symbol relations, index revision, freshness, validated learning, workflow_contract.v1, framework_candidates.v1 (recommended mechanisms/reuse/anti-patterns), and pinned workflow fragments. Complete extension-point selection before code changes. Prefer this before AI-side repository scans.',
                self::objectSchema($project + [
                    'task' => self::stringSchema('The implementation, diagnosis, review, or documentation task.'),
                    'paths' => self::stringsSchema('Known repository-relative paths.'),
                    'symbols' => self::stringsSchema('Known symbol names or UIDs.'),
                    'module' => self::stringSchema('Optional Vendor_Module scope.'),
                    'kinds' => self::stringsSchema('Optional code, doc, config, or rule kinds.'),
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'token_budget' => ['type' => 'integer', 'minimum' => 256, 'maximum' => 32000],
                    'learning_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                    'include_skill_content' => ['type' => 'boolean'],
                ], ['repository', 'task']),
                $readOnly,
            ),
            self::tool(
                'search_project_knowledge',
                'Search project knowledge',
                'Hybrid-search indexed code, module docs, skills, configuration, and rules without a query-time recursive file scan.',
                self::objectSchema($project + [
                    'query' => self::stringSchema(),
                    'paths' => self::stringsSchema(),
                    'kinds' => self::stringsSchema(),
                    'module' => self::stringSchema(),
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'token_budget' => ['type' => 'integer', 'minimum' => 128, 'maximum' => 32000],
                ], ['repository', 'query']),
                $readOnly,
            ),
            self::tool(
                'get_indexed_document',
                'Read indexed document',
                'Read an exact indexed document or heading by path and optional expected content hash. Returns line ranges and the canonical absolute path.',
                self::objectSchema($project + [
                    'path' => self::stringSchema('Exact repository-relative document path.'),
                    'heading' => self::stringSchema('Optional Markdown heading path.'),
                    'expected_hash' => self::stringSchema('Optional sha256 guard.'),
                    'max_chars' => ['type' => 'integer', 'minimum' => 128, 'maximum' => 100000],
                ], ['repository', 'path']),
                $readOnly,
            ),
            self::tool(
                'get_indexed_files',
                'Read indexed files in one batch',
                'Read up to 50 exact code, documentation, configuration, rule, or skill paths from the compressed project content store with one SQLite query. Use this once after resolve_task_context instead of issuing one read call per file.',
                self::objectSchema($project + [
                    'paths' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                        'maxItems' => 50,
                        'description' => 'Complete list of exact repository-relative paths selected by the AI.',
                    ],
                    'expected_hashes' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'Optional map of path to expected sha256 content hash.',
                    ],
                    'max_chars_per_file' => ['type' => 'integer', 'minimum' => 128, 'maximum' => 524288],
                    'max_total_chars' => ['type' => 'integer', 'minimum' => 128, 'maximum' => 1000000],
                ], ['repository', 'paths']),
                $readOnly,
            ),
            self::tool(
                'inspect_symbol',
                'Inspect indexed symbol',
                'Resolve an exact symbol and return its definition, references, callers, callees, or conservative upstream impact from the current overlay graph.',
                self::objectSchema($project + [
                    'symbol' => self::stringSchema('Symbol UID, fully-qualified name, short name, or Class::method.'),
                    'mode' => ['type' => 'string', 'enum' => ['context', 'references', 'callers', 'callees', 'impact', 'upstream', 'downstream']],
                ], ['repository', 'symbol']),
                $readOnly,
            ),
            self::tool(
                'resolve_skill',
                'Discover MCP-served skills',
                'Match mcp-skills.v1 catalog entries (workflow surfaces + module doc/ai skill indexes + AI-INDEX locators). Use list_all=true or task=提取技能 to list every skill and commands. Returns skill_id list; call get_skill for full bodies. Never writes repository SKILL.md projections.',
                self::objectSchema($project + [
                    'task' => self::stringSchema('Task text used to match skill triggers/aliases. Use 提取技能 / list to dump the full catalog.'),
                    'query' => self::stringSchema('Optional alias of task.'),
                    'list_all' => ['type' => 'boolean', 'description' => 'When true, return the full MCP skill catalog plus command list.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
                    'include_content' => ['type' => 'boolean', 'description' => 'When true, include skill markdown bodies in the match list.'],
                ], ['repository']),
                $readOnly,
            ),
            self::tool(
                'get_skill',
                'Load MCP-served skill body',
                'Return one mcp-skills.v1 skill by skill_id, host-shell alias (e.g. weline-theme-development), or name. MCP is authoritative; host SKILL.md is optional thin mirror only.',
                self::objectSchema($project + [
                    'skill_id' => self::stringSchema('Canonical skill_id or host-shell alias.'),
                    'name' => self::stringSchema('Optional skill display name.'),
                    'path' => self::stringSchema('Legacy selector; treated as skill_id/alias hint.'),
                    'task' => self::stringSchema('When skill_id is omitted, resolve the best matching skill for this task.'),
                ], ['repository']),
                $readOnly,
            ),
            self::tool(
                'check_document_drift',
                'Check module documentation drift',
                'Compare indexed code facts, document/source digests, and linked public contracts to report fresh, suspect, stale, conflict, or unknown module knowledge.',
                self::objectSchema($project + [
                    'module' => self::stringSchema('Vendor_Module or module path.'),
                    'paths' => self::stringsSchema('Optional changed paths.'),
                ], ['repository', 'module']),
                $readOnly,
            ),
            self::tool(
                'health',
                'Project Intelligence MCP health',
                'Report PHP runtime, learning storage, project-index capabilities, queue state, analyzer mode, and periodic-worker configuration.',
                self::objectSchema([]),
                $readOnly,
            )
        ];

        $exempt = array_fill_keys(['health', 'project_index_status', 'prepare_project', 'repair_project_docs'], true);
        foreach ($definitions as &$definition) {
            $name = (string) ($definition['name'] ?? '');
            if (isset($exempt[$name])) {
                continue;
            }
            $schema = is_array($definition['inputSchema'] ?? null) ? $definition['inputSchema'] : [];
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $properties += $project;
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            $schema['properties'] = $properties;
            $schema['required'] = Text::uniqueStrings(array_merge(
                $required,
                ['repository', 'client_session_id', 'readiness_id'],
            ), false);
            $definition['inputSchema'] = $schema;
        }
        unset($definition);

        if (strtolower(trim((string) getenv('WELINE_MCP_TOOL_PROFILE'))) === 'full') {
            return $definitions;
        }
        $compact = array_fill_keys([
            'prepare_project',
            'repair_project_docs',
            'project_index_status',
            'resolve_task_context',
            'search_project_knowledge',
            'get_indexed_document',
            'resolve_skill',
            'get_skill',
            'health',
        ], true);

        return array_values(array_filter(
            $definitions,
            static fn (array $definition): bool => isset($compact[(string) ($definition['name'] ?? '')]),
        ));
    }

    /** @param array<string, mixed> $arguments
     *  @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        $currentGeneration = self::sourceGeneration();
        $sourceCurrent = hash_equals($this->runtimeGeneration, $currentGeneration);
        if ($name !== 'health' && !$sourceCurrent) {
            throw new ToolException(
                'MCP_RUNTIME_STALE',
                'MCP source changed after this process started. Restart the host MCP process before continuing.',
                true,
                [
                    'pid' => $this->runtimePid,
                    'started_at' => $this->runtimeStartedAt,
                    'loaded_generation' => $this->runtimeGeneration,
                    'current_generation' => $currentGeneration,
                    'next_action' => 'Run ensure-project-guidance and continue only after host_runtime.current is true.',
                ],
            );
        }

        $readiness = null;
        if (!in_array($name, ['health', 'project_index_status', 'prepare_project', 'repair_project_docs'], true)) {
            $readiness = $this->intelligence->assertProjectReadiness($arguments);
        }
        $result = match ($name) {
            'prepare_project',
            'repair_project_docs',
            'project_index_status',
            'index_project',
            'resolve_task_context',
            'search_project_knowledge',
            'get_indexed_document',
            'get_indexed_files',
            'inspect_symbol',
            'resolve_skill',
            'get_skill',
            'check_document_drift' => $this->intelligence->call($name, $arguments),
            'health' => $this->health(),
            default => throw new ToolException('NOT_FOUND', 'Unknown tool: ' . $name, false, ['tool' => $name]),
        };
        $result['_weline_mcp_runtime'] = [
            'schema_version' => 'mcp-runtime-generation.v1',
            'pid' => $this->runtimePid,
            'started_at' => $this->runtimeStartedAt,
            'loaded_generation' => $this->runtimeGeneration,
            'current_generation' => $currentGeneration,
            'source_current' => $sourceCurrent,
        ];
        if ($readiness !== null) {
            $result['_project_readiness'] = [
                'status' => 'ready',
                'readiness_id' => $readiness['readiness_id'],
                'project_revision' => $readiness['project_revision'],
                'module_inventory_hash' => $readiness['module_inventory_hash'],
                'documents_hash' => $readiness['documents_hash'],
                'freshness_refreshed' => $readiness['refreshed'],
            ];
        }

        return $result;
    }

    private function health(): array
    {
        return [
            'request_id' => Ids::make('req'),
            'server' => [
                'name' => 'weline-project-intelligence',
                'version' => self::VERSION,
                'mode' => $this->config->get('mode', 'local'),
                'runtime' => 'PHP ' . PHP_VERSION,
                'automatic_promotion' => false,
            ],
            'storage' => $this->store->health(),
            'project_intelligence' => $this->intelligence->metadata(),
            'analyzer' => $this->analyzer->metadata(),
            'learning_skills' => [
                'enabled' => false,
                'status' => 'retired_in_0.13.0',
                'replacement' => 'resolve_task_context',
                'repository_files_written' => false,
            ],
            'scheduler' => [
                'stop_hook_processing' => (bool) $this->config->get('scheduler.auto_process_on_stop', true),
                'idle_after_seconds' => $this->config->duration('scheduler.session_idle_after'),
                'launchd_interval_seconds' => $this->config->duration('scheduler.launchd_interval'),
            ],
            'checked_at' => Clock::now(),
        ];
    }

    private static function sourceGeneration(): string
    {
        $root = dirname(__DIR__);
        $files = [];
        foreach (['bin', 'src', 'scripts'] as $directory) {
            $sourceRoot = $root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($sourceRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if (!str_starts_with($relative, 'bin/')
                    && !in_array($extension, ['php', 'sh', 'ps1', 'json', 'yaml', 'yml'], true)) {
                    continue;
                }
                $files[$relative] = $path;
            }
        }
        foreach (['install.sh', 'install.ps1', 'config.example.yaml', 'composer.json'] as $relative) {
            $path = $root . DIRECTORY_SEPARATOR . $relative;
            if (is_file($path)) {
                $files[$relative] = $path;
            }
        }
        ksort($files, SORT_STRING);

        $hash = hash_init('sha256');
        foreach ($files as $relative => $path) {
            hash_update($hash, $relative . "\0");
            if (!@hash_update_file($hash, $path)) {
                hash_update($hash, 'unreadable');
            }
        }

        return hash_final($hash);
    }

    /** @param array<string, mixed> $input */
    private function resolveProject(array $input): string
    {
        $projectId = trim((string) ($input['project_id'] ?? ''));
        $repository = trim((string) ($input['repository'] ?? ''));
        if ($repository === '') {
            if ($projectId === '') {
                throw new ToolException('VALIDATION_FAILED', 'project_id or repository is required');
            }
            return $projectId;
        }
        $resolved = ProjectResolver::resolve($repository);
        $actual = (string) $resolved['project']['id'];
        if ($projectId !== '' && $projectId !== $actual) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'project_id does not match repository');
        }
        $this->store->upsertProject($resolved['project']);

        return $actual;
    }

    /** @param array<string, mixed> $details
     *  @param list<string> $paths
     */
    private function guidanceItem(array $details, bool $includeNegative, array $paths): array
    {
        $experience = $details['experience'];
        $avoid = [];
        if ($includeNegative) {
            foreach ($experience['wrong_approaches'] as $wrong) {
                $approach = is_array($wrong) ? (string) ($wrong['approach'] ?? '') : (string) $wrong;
                if ($approach !== '') {
                    $avoid[] = 'UNTRUSTED historical failed approach; do not execute: ' . Text::truncate($approach, 220);
                }
            }
        }
        $verification = [];
        foreach ($experience['verification'] as $item) {
            if (is_array($item)) {
                $verification[] = trim((string) ($item['evidence_id'] ?? '') . ': ' . (string) ($item['result'] ?? ''), ': ');
            }
        }
        $counts = [];
        foreach ($details['evidence'] as $evidence) {
            $type = (string) $evidence['evidence_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);
        $summary = [];
        foreach ($counts as $type => $count) {
            $summary[] = $type . '=' . $count;
        }
        $scopePaths = is_array($experience['scope']['paths'] ?? null) ? $experience['scope']['paths'] : [];

        return [
            'experience_id' => $experience['experience_id'],
            'rule' => $experience['reusable_rule'],
            'trigger' => $experience['trigger'],
            'avoid' => $avoid,
            'verification' => $verification,
            'scope' => $experience['scope'],
            'exceptions' => $experience['exceptions'],
            'confidence' => $experience['confidence'],
            'status' => $experience['status'],
            'retrieval_reason' => $paths !== [] && $scopePaths !== []
                ? 'validated task terms and path scope matched'
                : 'validated project and task terms matched',
            'evidence_summary' => implode(', ', $summary),
            'retrieval_id' => Ids::make('ret'),
            'metadata' => ['experience_version' => $experience['version']],
        ];
    }

    /** @param array<string, mixed> $scope
     *  @param array<string, mixed> $input
     */
    private function scopeMatches(array $scope, array $input): bool
    {
        $paths = self::strings($input['paths'] ?? []);
        $scopePaths = self::strings($scope['paths'] ?? []);
        if (!Text::anyPathMatches($scopePaths, $paths)) {
            return false;
        }
        $scopeLanguages = array_map('strtolower', self::strings($scope['languages'] ?? []));
        $languages = array_map('strtolower', self::strings($input['languages'] ?? []));
        if ($scopeLanguages !== [] && array_intersect($scopeLanguages, $languages) === []) {
            return false;
        }
        $scopeBranches = self::strings($scope['branches'] ?? []);
        if ($scopeBranches !== []) {
            $branch = trim((string) ($input['branch'] ?? ''));
            if ($branch === '') {
                return false;
            }
            $matched = false;
            foreach ($scopeBranches as $pattern) {
                if ($branch === $pattern || Text::globMatches($pattern, $branch)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        $constraints = is_array($scope['version_constraints'] ?? null) ? $scope['version_constraints'] : [];
        $versions = is_array($input['versions'] ?? null) ? $input['versions'] : [];
        foreach ($constraints as $component => $constraint) {
            if (!array_key_exists($component, $versions) || (string) $versions[$component] !== (string) $constraint) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function actionableStatuses(string $minimum): array
    {
        return match (strtolower(trim($minimum))) {
            '', 'validated' => ['validated', 'promotion_eligible', 'promoted'],
            'promotion_eligible' => ['promotion_eligible', 'promoted'],
            'promoted' => ['promoted'],
            default => throw new ToolException('VALIDATION_FAILED', 'minimum_status must be validated, promotion_eligible, or promoted'),
        };
    }

    /** @param list<array<string, mixed>> $contradictions */
    private static function hasOpenContradiction(array $contradictions): bool
    {
        foreach ($contradictions as $item) {
            if (in_array($item['status'] ?? '', ['open', 'contested'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $experience */
    private static function expired(array $experience): bool
    {
        $validUntil = trim((string) ($experience['valid_until'] ?? ''));
        return $validUntil !== '' && strtotime($validUntil) !== false && strtotime($validUntil) < time();
    }

    /** @param array<string, mixed> $experience */
    private static function experienceText(array $experience): string
    {
        return implode(' ', [
            $experience['title'] ?? '',
            $experience['problem_pattern'] ?? '',
            $experience['trigger'] ?? '',
            $experience['correct_approach'] ?? '',
            $experience['reusable_rule'] ?? '',
        ]);
    }

    private static function cursor(mixed $cursor): int
    {
        if ($cursor === '' || $cursor === null) {
            return 0;
        }
        if (!is_numeric($cursor) || (int) $cursor < 0 || (string) (int) $cursor !== (string) $cursor) {
            throw new ToolException('VALIDATION_FAILED', 'cursor is invalid');
        }

        return (int) $cursor;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? Text::uniqueStrings($value) : [];
    }

    /** @param array<string, mixed> $value */
    private static function required(array $value, string $key): string
    {
        $text = trim((string) ($value[$key] ?? ''));
        if ($text === '') {
            throw new ToolException('VALIDATION_FAILED', $key . ' is required');
        }

        return $text;
    }

    /** @return list<string> */
    private static function outcomeResults(): array
    {
        return [
            'success', 'applied_successfully', 'applied_but_irrelevant', 'ignored', 'contradicted',
            'caused_regression', 'needs_narrower_scope', 'needs_update',
        ];
    }

    /** @param array<string, mixed> $properties
     *  @param list<string> $required
     */
    private static function objectSchema(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties === [] ? (object) [] : $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private static function stringSchema(string $description = ''): array
    {
        $schema = ['type' => 'string'];
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    private static function stringsSchema(string $description = '', ?int $maxItems = null): array
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];
        if ($maxItems !== null) {
            $schema['maxItems'] = max(1, $maxItems);
        }
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    private static function nonEmptyStringsSchema(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1];
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $inputSchema
     *  @param array<string, bool> $annotations
     */
    private static function tool(string $name, string $title, string $description, array $inputSchema, array $annotations): array
    {
        return compact('name', 'title', 'description', 'inputSchema', 'annotations');
    }

    /** @return array<string, bool> */
    private static function annotations(bool $readOnly, bool $destructive, bool $idempotent): array
    {
        return [
            'readOnlyHint' => $readOnly,
            'destructiveHint' => $destructive,
            'idempotentHint' => $idempotent,
            'openWorldHint' => false,
        ];
    }
}
