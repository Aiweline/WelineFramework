<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use Throwable;

/**
 * Project-local documentation and generated-skill coordination.
 *
 * Module source and module doc files are the sources of truth. This service only
 * reads the project index, records derived freshness state in the index database,
 * and returns edit-plan drafts for the outer prepare/apply pipeline.
 */
final class KnowledgeService
{
    private const INDEX_MARKER = 'weline:mcp-index:auto-generated';
    private const SKILL_MARKER = '<!-- weline:mcp-skill:auto-generated -->';
    private const META_MARKER = 'weline:mcp-skill-meta:auto-generated';
    private const STATE_PREFIX = 'knowledge.module.';

    /** @var array<string, list<string>> */
    private array $columnCache = [];
    private bool $planningWithCodex = false;

    public function __construct(
        private readonly ProjectIndex $index,
        private readonly ProjectIndexer $indexer,
        private readonly Config $config,
        private readonly ?CodexInvoker $codexInvoker = null,
    ) {
    }

    /**
     * Resolve a module code or repository path without scanning the filesystem.
     *
     * @return array<string, string>
     */
    public function moduleFor(string $value): array
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || str_contains($value, "\0")) {
            throw new ToolException('VALIDATION_FAILED', 'module or path is required');
        }

        $vendor = '';
        $module = '';
        if (preg_match('/^([A-Za-z][A-Za-z0-9]*)_([A-Za-z][A-Za-z0-9]*)$/', $value, $match) === 1) {
            $vendor = $match[1];
            $module = $match[2];
        } else {
            $relative = $this->relativeRepositoryPath($value);
            if (preg_match('#^app/code/([A-Za-z][A-Za-z0-9]*)/([A-Za-z][A-Za-z0-9]*)(?:/|$)#', $relative, $match) !== 1) {
                throw new ToolException(
                    'VALIDATION_FAILED',
                    'Path must identify app/code/{Vendor}/{Module}',
                    false,
                    ['path' => $value],
                );
            }
            $vendor = $match[1];
            $module = $match[2];
        }

        $code = $vendor . '_' . $module;
        $moduleRoot = 'app/code/' . $vendor . '/' . $module;
        $docRoot = $moduleRoot . '/doc';
        $skillName = strtolower($vendor . '-' . $module . '-knowledge');
        $skillDir = $docRoot . '/ai/skills/' . $skillName;

        return [
            'vendor' => $vendor,
            'module' => $module,
            'code' => $code,
            'module_root' => $moduleRoot,
            'doc_root' => $docRoot,
            'module_ai_index_path' => $docRoot . '/AI-INDEX.md',
            'readme_path' => $docRoot . '/README.md',
            'knowledge_index_path' => $docRoot . '/ai/INDEX.json',
            'skill_name' => $skillName,
            'skill_dir' => $skillDir,
            'skill_path' => $skillDir . '/SKILL.md',
            'skill_meta_path' => $skillDir . '/.skill-meta.json',
        ];
    }

    /**
     * Compare indexed code/doc/skill digests with the persisted knowledge state.
     * Vector similarity is deliberately absent from the stale decision.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function checkDrift(array $input): array
    {
        $modules = $this->modulesFromInput($input);
        if ($modules === []) {
            throw new ToolException('VALIDATION_FAILED', 'module, path, or paths is required');
        }

        $results = [];
        foreach ($modules as $moduleInfo) {
            $snapshot = $this->snapshot($moduleInfo);
            $state = $this->loadKnowledgeState($moduleInfo['code']);
            $skills = $this->skillsForModule($moduleInfo);
            $results[] = $this->assessDrift($moduleInfo, $snapshot, $state, $skills);
        }

        $overall = $this->highestStatus(array_column($results, 'status'));

        return [
            'schema_version' => 'knowledge-drift.v1',
            'project_id' => $this->projectId(),
            'project_revision' => $this->projectRevision(),
            'status' => $overall,
            'modules' => $results,
            'decision_boundary' => [
                'deterministic_facts' => [
                    'public_api',
                    'schema_col',
                    'schema_index',
                    'config',
                    'route',
                    'controller',
                    'event',
                    'hook',
                    'cli',
                    'module_tree',
                ],
                'vector_candidates' => 'advisory_only',
                'vector_can_mark_stale' => false,
            ],
        ];
    }

    /**
     * Produce a documentation/skill edit-plan draft. No repository file is
     * created or changed here.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepareSync(array $input): array
    {
        $moduleValue = trim((string) ($input['module'] ?? $input['path'] ?? ''));
        if ($moduleValue === '') {
            throw new ToolException('VALIDATION_FAILED', 'module is required');
        }
        $moduleInfo = $this->moduleFor($moduleValue);
        $task = trim((string) ($input['task'] ?? 'Synchronize module documentation with the indexed code facts.'));
        if (mb_strlen($task, 'UTF-8') > 20_000) {
            throw new ToolException('VALIDATION_FAILED', 'task exceeds 20000 characters');
        }

        $snapshot = $this->snapshot($moduleInfo);
        $driftResponse = $this->checkDrift(['module' => $moduleInfo['code']]);
        $drift = $driftResponse['modules'][0] ?? [];
        $sourceEvidence = $this->sourceEvidence($snapshot);
        $warnings = [];
        $conflicts = [];
        $codexOperations = [];
        $codexSkillContent = null;

        if (!empty($input['use_codex'])) {
            $codex = $this->requestCodexPlan($input, $moduleInfo, $task, $drift, $sourceEvidence);
            array_push($warnings, ...$codex['warnings']);
            array_push($conflicts, ...$codex['conflicts']);
            $codexOperations = $codex['operations'];
            $codexSkillContent = $codex['skill_content'];
        }

        $includeSkill = array_key_exists('include_skill', $input)
            ? (bool) $input['include_skill']
            : (bool) $this->config->get('knowledge.auto_generate_skills', true);
        $configuredStatus = strtolower(trim((string) $this->config->get('knowledge.generated_skill_status', 'validated')));
        $skillStatus = $configuredStatus === 'validated' ? 'validated' : 'draft';
        if (is_string($codexSkillContent) && $codexSkillContent !== '') {
            $skillStatus = 'draft';
        }

        $skillContent = is_string($codexSkillContent) && $codexSkillContent !== ''
            ? $codexSkillContent
            : $this->renderLocatorSkill($moduleInfo, $snapshot, $sourceEvidence);
        $skillMeta = $this->renderSkillMeta($moduleInfo, $snapshot, $sourceEvidence, $skillStatus);
        $moduleIndex = $this->renderModuleIndex($moduleInfo, $snapshot, $sourceEvidence, $skillStatus, $includeSkill, $drift);

        $operations = [];
        $ownership = [];
        $generated = [
            [$moduleInfo['knowledge_index_path'], $moduleIndex, self::INDEX_MARKER],
        ];
        if ($includeSkill) {
            $generated[] = [$moduleInfo['skill_path'], $skillContent, self::SKILL_MARKER];
            $generated[] = [$moduleInfo['skill_meta_path'], $skillMeta, self::META_MARKER];
        }

        foreach ($generated as [$path, $content, $marker]) {
            $prepared = $this->generatedFileOperation($moduleInfo, $path, $content, $marker);
            $ownership[$path] = $prepared['ownership'];
            if ($prepared['operation'] !== null) {
                $operations[] = $prepared['operation'];
            }
            if ($prepared['conflict'] !== null) {
                $conflicts[] = $prepared['conflict'];
            }
        }

        foreach ($codexOperations as $operation) {
            $operations[] = $operation;
        }
        if ($conflicts !== []) {
            $warnings[] = 'One or more target files are hand-written or cannot be ownership-verified; no overwrite operation was emitted for them.';
        }
        if (!(bool) $this->config->get('knowledge.auto_doc_sync', false)) {
            $warnings[] = 'knowledge.auto_doc_sync is disabled; this explicit draft still requires the outer prepare/apply workflow.';
        }
        $warnings[] = 'Module docs and source remain authoritative; generated skills are derived caches.';
        $warnings[] = 'This method does not call EditService and does not write repository files.';

        $status = $this->indexStatus();
        $baseCommit = $this->baseCommit($input, $status);
        $editPlan = null;
        if ($operations === []) {
            $warnings[] = 'No repository edit operations are required or safely available.';
        } elseif ($baseCommit === '') {
            $warnings[] = 'No Git base commit could be resolved; an EditService-compatible plan was not emitted.';
        } else {
            $editPlan = [
                'schema_version' => 'edit-plan.v1',
                'project_id' => $this->projectId(),
                'project_revision' => $this->projectRevision(),
                'base_commit' => $baseCommit,
                'operations' => $operations,
                'validation_profile' => 'weline.php.module',
                'metadata' => [
                    'origin' => 'knowledge-service',
                    'module' => $moduleInfo['code'],
                    'source_digest' => $snapshot['source_digest'],
                    'draft_only' => true,
                ],
            ];
        }

        return [
            'schema_version' => 'edit-plan-draft.v1',
            'project_id' => $this->projectId(),
            'project_revision' => $this->projectRevision(),
            'base_commit' => $baseCommit,
            'validation_profile' => 'weline.php.module',
            'module' => $moduleInfo,
            'task' => $task,
            'drift' => $drift,
            'source_evidence' => $sourceEvidence,
            'operations' => $operations,
            'edit_plan' => $editPlan,
            'conflicts' => $conflicts,
            'warnings' => Text::uniqueStrings($warnings, false),
            'metadata' => [
                'source_digest' => $snapshot['source_digest'],
                'code_digest' => $snapshot['code_digest'],
                'docs_digest' => $snapshot['docs_digest'],
                'generated_skill_status' => $includeSkill ? $skillStatus : null,
                'ownership' => $ownership,
                'requires_outer_prepare_apply' => true,
            ],
        ];
    }

    /**
     * Reconcile module digests and derived skill freshness after files have been
     * indexed. This only updates the project index database.
     *
     * @param list<string> $paths
     * @return array<string, mixed>
     */
    public function afterIndexed(array $paths): array
    {
        $modules = [];
        $ignored = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            try {
                $module = $this->moduleFor($path);
                $modules[$module['code']] = $module;
            } catch (ToolException) {
                $ignored[] = $path;
            }
        }

        $updated = [];
        $pdo = $this->index->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($modules as $moduleInfo) {
                $snapshot = $this->snapshot($moduleInfo);
                $previous = $this->loadKnowledgeState($moduleInfo['code']);
                $hydratedSkill = $this->hydrateGeneratedSkillMetadata($moduleInfo, $snapshot['source_digest']);
                $skills = $this->skillsForModule($moduleInfo);
                $assessment = $this->assessDrift($moduleInfo, $snapshot, $previous, $skills);
                $staleSkills = $this->markStaleSkills($moduleInfo, $snapshot['source_digest']);

                $previousMetadata = is_array($previous['metadata'] ?? null) ? $previous['metadata'] : [];
                $digestChanged = $previous === null
                    || !hash_equals((string) ($previous['code_digest'] ?? ''), $snapshot['code_digest'])
                    || !hash_equals((string) ($previous['docs_digest'] ?? ''), $snapshot['docs_digest']);
                $evidenceBaseline = $digestChanged
                    ? ($previousMetadata['fact_digests'] ?? [])
                    : ($previousMetadata['previous_fact_digests'] ?? []);
                $state = [
                    'module' => $moduleInfo['code'],
                    'code_digest' => $snapshot['code_digest'],
                    'docs_digest' => $snapshot['docs_digest'],
                    'source_digest' => $snapshot['source_digest'],
                    'status' => $assessment['status'],
                    'last_synced_revision' => (int) ($previous['last_synced_revision'] ?? 0),
                    'last_synced_at' => $previous['last_synced_at'] ?? null,
                    'metadata' => array_merge($previousMetadata, [
                        'previous_code_digest' => $previous['code_digest'] ?? null,
                        'previous_docs_digest' => $previous['docs_digest'] ?? null,
                        'previous_fact_digests' => is_array($evidenceBaseline) ? $evidenceBaseline : [],
                        'fact_digests' => $snapshot['fact_digests'],
                        'changed_fact_types' => $assessment['changed_fact_types'],
                        'last_index_revision' => $this->projectRevision(),
                        'last_indexed_at' => Clock::now(),
                        'stale_skill_ids' => $staleSkills,
                    ]),
                ];
                $this->saveKnowledgeState($state);
                $updated[] = [
                    'module' => $moduleInfo['code'],
                    'status' => $assessment['status'],
                    'source_digest' => $snapshot['source_digest'],
                    'changed_fact_types' => $assessment['changed_fact_types'],
                    'stale_skill_ids' => $staleSkills,
                    'hydrated_skill_id' => $hydratedSkill,
                ];
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $ignored = Text::uniqueStrings($ignored);
        $ignoredCount = count($ignored);

        return [
            'schema_version' => 'knowledge-index-reconcile.v1',
            'project_id' => $this->projectId(),
            'project_revision' => $this->projectRevision(),
            'modules' => $updated,
            'ignored_path_count' => $ignoredCount,
            'ignored_paths' => array_slice($ignored, 0, 10),
            'ignored_paths_truncated' => $ignoredCount > 10,
            'repository_files_written' => false,
        ];
    }

    /**
     * Seal the current indexed code/docs/skill snapshot as the accepted
     * baseline after sync_module_knowledge has completed its approved edit.
     *
     * @return array<string, mixed>
     */
    public function markSynchronized(string $module): array
    {
        $moduleInfo = $this->moduleFor($module);
        $snapshot = $this->snapshot($moduleInfo);
        if (!$snapshot['indexed']) {
            throw new ToolException('INDEX_NOT_READY', 'The synchronized module is not present in the current project index');
        }

        $previous = $this->loadKnowledgeState($moduleInfo['code']);
        $previousMetadata = is_array($previous['metadata'] ?? null) ? $previous['metadata'] : [];
        $synchronizedAt = Clock::now();
        $state = [
            'module' => $moduleInfo['code'],
            'code_digest' => $snapshot['code_digest'],
            'docs_digest' => $snapshot['docs_digest'],
            'source_digest' => $snapshot['source_digest'],
            'status' => 'fresh',
            'last_synced_revision' => $this->projectRevision(),
            'last_synced_at' => $synchronizedAt,
            'metadata' => array_merge($previousMetadata, [
                'previous_code_digest' => $previous['code_digest'] ?? null,
                'previous_docs_digest' => $previous['docs_digest'] ?? null,
                'previous_fact_digests' => $snapshot['fact_digests'],
                'fact_digests' => $snapshot['fact_digests'],
                'changed_fact_types' => [],
                'last_index_revision' => $this->projectRevision(),
                'last_indexed_at' => $synchronizedAt,
                'stale_skill_ids' => [],
                'synchronized_by' => 'sync_module_knowledge',
            ]),
        ];
        $this->saveKnowledgeState($state);

        $assessment = $this->assessDrift(
            $moduleInfo,
            $snapshot,
            $state,
            $this->skillsForModule($moduleInfo),
        );
        if ($assessment['status'] !== 'fresh') {
            $state['status'] = $assessment['status'];
            $this->saveKnowledgeState($state);
        }

        return [
            'schema_version' => 'knowledge-sync-baseline.v1',
            'project_revision' => $this->projectRevision(),
            'last_synced_at' => $synchronizedAt,
        ] + $assessment;
    }

    /** @param array<string, mixed> $input
     *  @return list<array<string, string>>
     */
    private function modulesFromInput(array $input): array
    {
        $values = [];
        foreach (['module', 'path'] as $field) {
            if (isset($input[$field]) && is_string($input[$field]) && trim($input[$field]) !== '') {
                $values[] = $input[$field];
            }
        }
        if (is_array($input['paths'] ?? null)) {
            foreach ($input['paths'] as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $values[] = $path;
                }
            }
        }

        $modules = [];
        foreach ($values as $value) {
            $module = $this->moduleFor($value);
            $modules[$module['code']] = $module;
        }

        return array_values($modules);
    }

    /** @param array<string, string> $moduleInfo
     *  @return array<string, mixed>
     */
    private function snapshot(array $moduleInfo): array
    {
        $files = $this->filesForModule($moduleInfo);
        $sourceDocs = [];
        $codeFiles = [];
        foreach ($files as $file) {
            $path = (string) $file['path'];
            if (str_starts_with($path, $moduleInfo['doc_root'] . '/')) {
                if (!str_starts_with($path, $moduleInfo['doc_root'] . '/ai/')) {
                    $sourceDocs[] = $file;
                }
                continue;
            }
            $codeFiles[] = $file;
        }

        $symbols = $this->symbolsForModule($moduleInfo);
        $facts = $this->deterministicFacts($moduleInfo, $codeFiles, $symbols);
        $factDigests = [];
        foreach ($facts as $type => $fact) {
            $factDigests[$type] = $fact['digest'];
        }
        ksort($factDigests);

        $codeDigest = $this->fileDigest($codeFiles);
        $docsDigest = $this->fileDigest($sourceDocs);

        return [
            'indexed' => $files !== [],
            'files' => $files,
            'code_files' => $codeFiles,
            'source_docs' => $sourceDocs,
            'symbols' => $symbols,
            'facts' => $facts,
            'fact_digests' => $factDigests,
            'code_digest' => $codeDigest,
            'docs_digest' => $docsDigest,
            'source_digest' => hash('sha256', $codeDigest . "\0" . $docsDigest),
        ];
    }

    /** @param array<string, string> $moduleInfo
     *  @return list<array<string, mixed>>
     */
    private function filesForModule(array $moduleInfo): array
    {
        $columns = $this->columns('indexed_files');
        $id = $this->firstColumn($columns, ['id', 'file_id']);
        $hash = $this->firstColumn($columns, ['content_hash', 'sha256']);
        if ($id === null || $hash === null || !in_array('path', $columns, true)) {
            return [];
        }
        $kind = in_array('kind', $columns, true) ? 'kind' : "''";
        $metadata = in_array('metadata_json', $columns, true) ? 'metadata_json' : "'{}'";
        $sql = "SELECT {$id} AS file_id, path, {$kind} AS kind, {$hash} AS content_hash, {$metadata} AS metadata_json
                FROM indexed_files WHERE path LIKE :prefix ORDER BY path";
        $statement = $this->index->pdo()->prepare($sql);
        $statement->execute(['prefix' => $moduleInfo['module_root'] . '/%']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['path'] = $this->relativeRepositoryPath((string) $row['path']);
            $row['content_hash'] = (string) $row['content_hash'];
            $row['metadata'] = $this->decodeObject((string) ($row['metadata_json'] ?? '{}'));
            unset($row['metadata_json']);
        }

        return $rows;
    }

    /** @param array<string, string> $moduleInfo
     *  @return list<array<string, mixed>>
     */
    private function symbolsForModule(array $moduleInfo): array
    {
        $symbolColumns = $this->columns('symbols');
        $fileColumns = $this->columns('indexed_files');
        $fileId = $this->firstColumn($fileColumns, ['id', 'file_id']);
        $symbolId = $this->firstColumn($symbolColumns, ['symbol_uid', 'uid']);
        if ($fileId === null || $symbolId === null || !in_array('file_id', $symbolColumns, true)) {
            return [];
        }

        $select = [
            "s.{$symbolId} AS symbol_uid",
            'f.path AS path',
        ];
        foreach (['name', 'fq_name', 'kind', 'namespace', 'signature', 'start_line', 'end_line', 'visibility', 'metadata_json'] as $column) {
            $select[] = in_array($column, $symbolColumns, true)
                ? "s.{$column} AS {$column}"
                : "NULL AS {$column}";
        }
        $fingerprint = $this->firstColumn($symbolColumns, ['fingerprint', 'body_hash', 'content_hash']);
        $select[] = $fingerprint !== null ? "s.{$fingerprint} AS fingerprint" : "'' AS fingerprint";
        $sql = 'SELECT ' . implode(', ', $select)
            . " FROM symbols s JOIN indexed_files f ON f.{$fileId} = s.file_id"
            . ' WHERE f.path LIKE :prefix ORDER BY f.path, s.start_line, s.symbol_uid';
        $statement = $this->index->pdo()->prepare($sql);
        $statement->execute(['prefix' => $moduleInfo['module_root'] . '/%']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['metadata'] = $this->decodeObject((string) ($row['metadata_json'] ?? '{}'));
            unset($row['metadata_json']);
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $files
     *  @param list<array<string, mixed>> $symbols
     *  @return array<string, array{digest:string,evidence:list<array<string,mixed>>}>
     */
    private function deterministicFacts(array $moduleInfo, array $files, array $symbols): array
    {
        $facts = [];
        $textByFile = $this->indexedTextForFiles($files);
        foreach ($files as $file) {
            $path = (string) $file['path'];
            $hash = (string) $file['content_hash'];
            $text = $textByFile[(string) $file['file_id']] ?? '';
            $baseEvidence = ['path' => $path, 'content_hash' => $hash, 'basis' => 'indexed_file_hash'];

            if (str_contains($path, '/Api/') || str_contains($path, '/Interface/')) {
                $this->appendFact($facts, 'public_api', $path, $hash, $baseEvidence);
            }
            if (str_contains($path, '/etc/') || preg_match('#/(?:composer\.json|register\.php|module\.(?:php|xml|json))$#i', $path) === 1) {
                $this->appendFact($facts, 'config', $path, $hash, $baseEvidence);
            }
            if (str_contains($path, '/Controller/')) {
                $this->appendFact($facts, 'controller', $path, $hash, $baseEvidence);
                $this->appendFact($facts, 'route', $path, $hash, $baseEvidence);
            }
            if (str_contains($path, '/Observer/') || preg_match('#/(?:event|events)\.(?:php|xml)$#i', $path) === 1 || str_contains($text, 'dispatch(')) {
                $this->appendFact($facts, 'event', $path, $hash, $baseEvidence);
            }
            if (str_contains($path, '/Hook/') || str_contains(strtolower($path), '/hook/') || stripos($text, 'hook') !== false) {
                $this->appendFact($facts, 'hook', $path, $hash, $baseEvidence);
            }
            if (str_contains($path, '/Console/')) {
                $this->appendFact($facts, 'cli', $path, $hash, $baseEvidence);
            }
            if ($text !== '') {
                if (preg_match_all('/#\[\s*Col\b[^\]]*\]/u', $text, $matches) > 0) {
                    foreach ($matches[0] as $index => $attribute) {
                        $attributeHash = hash('sha256', $attribute);
                        $this->appendFact($facts, 'schema_col', $path . '#col-' . $index, $attributeHash, [
                            'path' => $path,
                            'attribute_hash' => $attributeHash,
                            'basis' => 'indexed_attribute_text',
                        ]);
                    }
                }
                if (preg_match_all('/#\[\s*Index\b[^\]]*\]/u', $text, $matches) > 0) {
                    foreach ($matches[0] as $index => $attribute) {
                        $attributeHash = hash('sha256', $attribute);
                        $this->appendFact($facts, 'schema_index', $path . '#index-' . $index, $attributeHash, [
                            'path' => $path,
                            'attribute_hash' => $attributeHash,
                            'basis' => 'indexed_attribute_text',
                        ]);
                    }
                }
            }
        }

        foreach ($symbols as $symbol) {
            $path = (string) ($symbol['path'] ?? '');
            $kind = strtolower((string) ($symbol['kind'] ?? ''));
            $visibility = strtolower((string) ($symbol['visibility'] ?? $symbol['metadata']['visibility'] ?? ''));
            $fingerprint = (string) ($symbol['fingerprint'] ?? '');
            if ($fingerprint === '') {
                $fingerprint = hash('sha256', (string) ($symbol['signature'] ?? '') . '|' . (string) ($symbol['symbol_uid'] ?? ''));
            }
            $evidence = [
                'path' => $path,
                'symbol_uid' => (string) ($symbol['symbol_uid'] ?? ''),
                'kind' => $kind,
                'start_line' => (int) ($symbol['start_line'] ?? 0),
                'end_line' => (int) ($symbol['end_line'] ?? 0),
                'fingerprint' => $fingerprint,
                'basis' => 'indexed_symbol_fingerprint',
            ];
            if (str_contains($path, '/Api/') || str_contains($path, '/Interface/') || $visibility === 'public') {
                $this->appendFact($facts, 'public_api', (string) ($symbol['symbol_uid'] ?? $path), $fingerprint, $evidence);
            }
            if (str_contains($path, '/Controller/') && in_array($kind, ['method', 'function'], true)) {
                $this->appendFact($facts, 'route', (string) ($symbol['symbol_uid'] ?? $path), $fingerprint, $evidence);
            }
            if (str_contains($path, '/Console/') && in_array(strtolower((string) ($symbol['name'] ?? '')), ['execute', 'help', 'tip'], true)) {
                $this->appendFact($facts, 'cli', (string) ($symbol['symbol_uid'] ?? $path), $fingerprint, $evidence);
            }
        }

        $treeEntries = [];
        foreach ($files as $file) {
            $treeEntries[] = (string) $file['path'];
        }
        sort($treeEntries, SORT_STRING);
        $facts['module_tree'] = [
            'digest' => hash('sha256', implode("\n", $treeEntries)),
            'evidence' => array_map(
                static fn(string $path): array => ['path' => $path, 'basis' => 'indexed_path'],
                array_slice($treeEntries, 0, 20),
            ),
        ];

        foreach ($facts as $type => &$fact) {
            if ($type === 'module_tree') {
                continue;
            }
            ksort($fact['items']);
            $fact['digest'] = hash('sha256', Json::encode($fact['items']));
            $fact['evidence'] = array_slice($fact['evidence'], 0, 20);
            unset($fact['items']);
        }
        ksort($facts);

        return $facts;
    }

    /** @param array<string, mixed> $facts
     *  @param array<string, mixed> $evidence
     */
    private function appendFact(array &$facts, string $type, string $key, string $hash, array $evidence): void
    {
        if (!isset($facts[$type])) {
            $facts[$type] = ['items' => [], 'evidence' => []];
        }
        $facts[$type]['items'][$key] = $hash;
        $facts[$type]['evidence'][] = $evidence;
    }

    /** @param list<array<string, mixed>> $files
     *  @return array<string, string>
     */
    private function indexedTextForFiles(array $files): array
    {
        $chunkColumns = $this->columns('chunks');
        if (!in_array('file_id', $chunkColumns, true)) {
            return [];
        }
        $content = $this->firstColumn($chunkColumns, ['content', 'text', 'body']);
        if ($content === null) {
            return [];
        }
        $idColumn = $this->firstColumn($chunkColumns, ['chunk_id', 'id']);
        $order = in_array('start_byte', $chunkColumns, true)
            ? 'start_byte'
            : (in_array('start_line', $chunkColumns, true) ? 'start_line' : ($idColumn ?? 'file_id'));
        $ids = array_values(array_unique(array_map(static fn(array $file): string => (string) $file['file_id'], $files)));
        $texts = [];
        foreach (array_chunk($ids, 400) as $batch) {
            if ($batch === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $statement = $this->index->pdo()->prepare(
                "SELECT file_id, {$content} AS content FROM chunks WHERE file_id IN ({$placeholders}) ORDER BY file_id, {$order}",
            );
            $statement->execute($batch);
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $fileId = (string) $row['file_id'];
                $texts[$fileId] = ($texts[$fileId] ?? '') . "\n" . (string) $row['content'];
            }
        }

        return $texts;
    }

    /** @param list<array<string, mixed>> $files */
    private function fileDigest(array $files): string
    {
        $entries = [];
        foreach ($files as $file) {
            $entries[(string) $file['path']] = (string) $file['content_hash'];
        }
        ksort($entries);

        return hash('sha256', Json::encode($entries));
    }

    /** @param array<string, string> $moduleInfo
     *  @param array<string, mixed> $snapshot
     *  @param array<string, mixed>|null $state
     *  @param list<array<string, mixed>> $skills
     *  @return array<string, mixed>
     */
    private function assessDrift(array $moduleInfo, array $snapshot, ?array $state, array $skills): array
    {
        $reasons = [];
        $changedFacts = [];
        $stateMetadata = is_array($state['metadata'] ?? null) ? $state['metadata'] : [];
        $previousFacts = is_array($stateMetadata['fact_digests'] ?? null)
            ? $stateMetadata['fact_digests']
            : [];
        foreach ($snapshot['fact_digests'] as $type => $digest) {
            if (array_key_exists($type, $previousFacts) && !hash_equals((string) $previousFacts[$type], (string) $digest)) {
                $changedFacts[] = $type;
            }
        }
        foreach ($previousFacts as $type => $_digest) {
            if (!array_key_exists($type, $snapshot['fact_digests'])) {
                $changedFacts[] = (string) $type;
            }
        }
        $changedFacts = Text::uniqueStrings($changedFacts, false);

        // afterIndexed stores the current digest as the new baseline but keeps
        // unresolved deterministic deltas in metadata. Reuse that evidence on
        // later reads until a synchronization/validation workflow clears it.
        $evidencePreviousFacts = $previousFacts;
        if ($changedFacts === [] && is_array($stateMetadata['changed_fact_types'] ?? null)) {
            $storedStatus = strtolower((string) ($state['status'] ?? ''));
            if (in_array($storedStatus, ['stale', 'suspect'], true)) {
                $changedFacts = Text::uniqueStrings($stateMetadata['changed_fact_types'], false);
                if (is_array($stateMetadata['previous_fact_digests'] ?? null)) {
                    $evidencePreviousFacts = $stateMetadata['previous_fact_digests'];
                }
            }
        }

        $staleSkills = [];
        foreach ($skills as $skill) {
            $sourceHash = (string) ($skill['source_hash'] ?? '');
            if ($sourceHash !== '' && !hash_equals($sourceHash, $snapshot['source_digest'])) {
                $staleSkills[] = [
                    'skill_id' => (string) ($skill['skill_id'] ?? ''),
                    'path' => (string) ($skill['path'] ?? ''),
                    'status' => (string) ($skill['status'] ?? ''),
                    'source_hash' => $sourceHash,
                    'current_source_hash' => $snapshot['source_digest'],
                ];
            }
        }

        if (!$snapshot['indexed']) {
            $status = 'unknown';
            $reasons[] = 'No indexed files were found for the module.';
        } elseif ($state === null) {
            $status = 'unknown';
            $reasons[] = 'No persisted module knowledge baseline exists.';
        } else {
            $codeChanged = !hash_equals((string) ($state['code_digest'] ?? ''), $snapshot['code_digest']);
            $docsChanged = !hash_equals((string) ($state['docs_digest'] ?? ''), $snapshot['docs_digest']);
            if (!$codeChanged && !$docsChanged) {
                $stored = strtolower((string) ($state['status'] ?? 'fresh'));
                $status = in_array($stored, ['fresh', 'suspect', 'stale', 'unknown'], true) ? $stored : 'fresh';
                $reasons[] = 'Indexed code and source-document digests match the persisted state.';
            } elseif ($codeChanged && !$docsChanged && $changedFacts !== []) {
                $status = 'stale';
                $reasons[] = 'Deterministic code facts changed while source-document digest remained unchanged.';
            } elseif ($codeChanged && !$docsChanged) {
                $status = 'suspect';
                $reasons[] = 'Code changed without a provable deterministic fact delta; review is required.';
            } elseif ($codeChanged) {
                $status = 'suspect';
                $reasons[] = 'Code and docs both changed; semantic consistency is not proven by matching timestamps or vectors.';
            } else {
                $status = 'suspect';
                $reasons[] = 'Source docs changed without a code change; derived skills require regeneration and review.';
            }
        }
        if ($staleSkills !== []) {
            $status = 'stale';
            $reasons[] = 'One or more derived skills reference an older source digest.';
        }

        $factEvidence = [];
        foreach ($changedFacts as $type) {
            $fact = $snapshot['facts'][$type] ?? null;
            $factEvidence[] = [
                'fact_type' => $type,
                'current_digest' => $snapshot['fact_digests'][$type] ?? null,
                'previous_digest' => $evidencePreviousFacts[$type] ?? null,
                'locators' => is_array($fact['evidence'] ?? null) ? $fact['evidence'] : [],
                'basis' => 'deterministic_index_fact',
            ];
        }

        return [
            'module' => $moduleInfo['code'],
            'status' => $status,
            'code_digest' => $snapshot['code_digest'],
            'docs_digest' => $snapshot['docs_digest'],
            'source_digest' => $snapshot['source_digest'],
            'previous_code_digest' => $state['code_digest'] ?? null,
            'previous_docs_digest' => $state['docs_digest'] ?? null,
            'changed_fact_types' => $changedFacts,
            'evidence' => $factEvidence,
            'stale_skills' => $staleSkills,
            'reasons' => $reasons,
            'vector_candidates_used_for_status' => false,
        ];
    }

    /** @param array<string, string> $moduleInfo
     *  @return list<array<string, mixed>>
     */
    private function skillsForModule(array $moduleInfo): array
    {
        $columns = $this->columns('skills');
        $id = $this->firstColumn($columns, ['skill_id', 'id']);
        $source = $this->firstColumn($columns, ['source_hash', 'source_digest']);
        if ($id === null || $source === null || !in_array('path', $columns, true)) {
            return [];
        }
        $status = in_array('status', $columns, true) ? 'status' : "'unknown'";
        $metadata = in_array('metadata_json', $columns, true) ? 'metadata_json' : "'{}'";
        $statement = $this->index->pdo()->prepare(
            "SELECT {$id} AS skill_id, path, {$status} AS status, {$source} AS source_hash, {$metadata} AS metadata_json
             FROM skills WHERE path LIKE :prefix ORDER BY path",
        );
        $statement->execute(['prefix' => $moduleInfo['doc_root'] . '/ai/skills/%']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['metadata'] = $this->decodeObject((string) ($row['metadata_json'] ?? '{}'));
            unset($row['metadata_json']);
        }
        unset($row);

        $generatedMeta = $this->generatedSkillMetadata($moduleInfo);
        if ($generatedMeta !== null) {
            foreach ($rows as &$row) {
                if ((string) $row['path'] !== $moduleInfo['skill_path']) {
                    continue;
                }
                $row['status'] = $generatedMeta['status'];
                $row['source_hash'] = $generatedMeta['source_digest'];
                $row['metadata'] = array_merge(
                    is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                    [
                        'generated' => true,
                        'meta_path' => $moduleInfo['skill_meta_path'],
                        'actionable' => $generatedMeta['status'] === 'validated',
                    ],
                );
            }
            unset($row);
        }

        return $rows;
    }

    /** @param array<string, string> $moduleInfo
     *  @return array{status:string,source_digest:string}|null
     */
    private function generatedSkillMetadata(array $moduleInfo): ?array
    {
        $file = $this->indexedFile($moduleInfo['skill_meta_path']);
        if ($file === null) {
            return null;
        }
        $content = $this->indexedFileContent((string) $file['file_id']);
        if ($content === null) {
            return null;
        }
        $meta = $this->decodeObject($content);
        $sourceDigest = preg_replace('/^sha256:/i', '', trim((string) ($meta['source_digest'] ?? ''))) ?? '';
        $status = strtolower(trim((string) ($meta['status'] ?? 'draft')));
        if (($meta['ownership_marker'] ?? null) !== self::META_MARKER
            || ($meta['module'] ?? null) !== $moduleInfo['code']
            || ($meta['path'] ?? null) !== $moduleInfo['skill_path']
            || preg_match('/^[a-f0-9]{64}$/iD', $sourceDigest) !== 1
            || !in_array($status, ['validated', 'draft', 'stale', 'contested', 'deprecated'], true)) {
            return null;
        }

        return ['status' => $status, 'source_digest' => strtolower($sourceDigest)];
    }

    /** @param array<string, string> $moduleInfo */
    private function hydrateGeneratedSkillMetadata(array $moduleInfo, string $currentSourceDigest): ?string
    {
        $meta = $this->generatedSkillMetadata($moduleInfo);
        $columns = $this->columns('skills');
        $idColumn = $this->firstColumn($columns, ['skill_id', 'id']);
        $sourceColumn = $this->firstColumn($columns, ['source_hash', 'source_digest']);
        if ($meta === null || $idColumn === null || $sourceColumn === null || !in_array('path', $columns, true)) {
            return null;
        }
        $metadataColumn = in_array('metadata_json', $columns, true) ? 'metadata_json' : null;
        $select = $idColumn . ' AS skill_id' . ($metadataColumn !== null ? ', metadata_json' : '');
        $statement = $this->index->pdo()->prepare("SELECT {$select} FROM skills WHERE path = :path");
        $statement->execute(['path' => $moduleInfo['skill_path']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $status = $meta['status'];
        if ($status !== 'deprecated' && !hash_equals($meta['source_digest'], $currentSourceDigest)) {
            $status = 'stale';
        }
        $sets = ["{$sourceColumn} = :source_hash"];
        $params = [
            'source_hash' => $meta['source_digest'],
            'path' => $moduleInfo['skill_path'],
        ];
        if (in_array('status', $columns, true)) {
            $sets[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($metadataColumn !== null) {
            $metadata = $this->decodeObject((string) ($row['metadata_json'] ?? '{}'));
            $metadata = array_merge($metadata, [
                'source' => 'knowledge-service',
                'generated' => true,
                'meta_path' => $moduleInfo['skill_meta_path'],
                'source_digest' => $meta['source_digest'],
                'actionable' => $status === 'validated' && hash_equals($meta['source_digest'], $currentSourceDigest),
            ]);
            $sets[] = 'metadata_json = :metadata';
            $params['metadata'] = Json::encode($metadata);
        }
        if (in_array('revision', $columns, true)) {
            $sets[] = 'revision = :revision';
            $params['revision'] = $this->projectRevision();
        }
        $this->index->pdo()->prepare(
            'UPDATE skills SET ' . implode(', ', $sets) . ' WHERE path = :path',
        )->execute($params);

        return (string) $row['skill_id'];
    }

    /** @param array<string, string> $moduleInfo
     *  @return list<string>
     */
    private function markStaleSkills(array $moduleInfo, string $sourceDigest): array
    {
        $columns = $this->columns('skills');
        $idColumn = $this->firstColumn($columns, ['skill_id', 'id']);
        $sourceColumn = $this->firstColumn($columns, ['source_hash', 'source_digest']);
        if ($idColumn === null || $sourceColumn === null || !in_array('status', $columns, true)) {
            return [];
        }

        $skills = $this->skillsForModule($moduleInfo);
        $stale = [];
        foreach ($skills as $skill) {
            $oldHash = (string) ($skill['source_hash'] ?? '');
            if ($oldHash === '' || hash_equals($oldHash, $sourceDigest) || (string) ($skill['status'] ?? '') === 'deprecated') {
                continue;
            }
            $metadata = is_array($skill['metadata'] ?? null) ? $skill['metadata'] : [];
            $metadata['stale_reason'] = 'source_digest_changed';
            $metadata['previous_source_hash'] = $oldHash;
            $metadata['current_source_hash'] = $sourceDigest;
            $metadata['stale_at'] = Clock::now();
            $sets = ['status = :status'];
            $params = ['status' => 'stale', 'id' => $skill['skill_id']];
            if (in_array('metadata_json', $columns, true)) {
                $sets[] = 'metadata_json = :metadata';
                $params['metadata'] = Json::encode($metadata);
            }
            if (in_array('updated_at', $columns, true)) {
                $sets[] = 'updated_at = :updated_at';
                $params['updated_at'] = Clock::now();
            }
            if (in_array('revision', $columns, true)) {
                $sets[] = 'revision = :revision';
                $params['revision'] = $this->projectRevision();
            }
            $statement = $this->index->pdo()->prepare(
                'UPDATE skills SET ' . implode(', ', $sets) . " WHERE {$idColumn} = :id",
            );
            $statement->execute($params);
            $stale[] = (string) $skill['skill_id'];
        }

        return $stale;
    }

    /** @return array<string, mixed>|null */
    private function loadKnowledgeState(string $module): ?array
    {
        $columns = $this->columns('knowledge_state');
        if (in_array('state_key', $columns, true) && in_array('value_json', $columns, true)) {
            $statement = $this->index->pdo()->prepare('SELECT value_json FROM knowledge_state WHERE state_key = :key');
            $statement->execute(['key' => self::STATE_PREFIX . $module]);
            $value = $statement->fetchColumn();
            if (!is_string($value) || $value === '') {
                return null;
            }
            return $this->decodeObject($value);
        }
        if (!in_array('module', $columns, true)) {
            return null;
        }
        $statement = $this->index->pdo()->prepare('SELECT * FROM knowledge_state WHERE module = :module');
        $statement->execute(['module' => $module]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['metadata'] = $this->decodeObject((string) ($row['metadata_json'] ?? '{}'));
        return $row;
    }

    /** @param array<string, mixed> $state */
    private function saveKnowledgeState(array $state): void
    {
        $columns = $this->columns('knowledge_state');
        if (in_array('state_key', $columns, true) && in_array('value_json', $columns, true)) {
            $fields = ['state_key', 'value_json'];
            $values = [':key', ':value'];
            $updates = ['value_json = excluded.value_json'];
            $params = [
                'key' => self::STATE_PREFIX . (string) $state['module'],
                'value' => Json::encode($state),
            ];
            if (in_array('revision', $columns, true)) {
                $fields[] = 'revision';
                $values[] = ':revision';
                $updates[] = 'revision = excluded.revision';
                $params['revision'] = $this->projectRevision();
            }
            if (in_array('updated_at', $columns, true)) {
                $fields[] = 'updated_at';
                $values[] = ':updated_at';
                $updates[] = 'updated_at = excluded.updated_at';
                $params['updated_at'] = Clock::now();
            }
            $sql = 'INSERT INTO knowledge_state (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values)
                . ') ON CONFLICT(state_key) DO UPDATE SET ' . implode(', ', $updates);
            $this->index->pdo()->prepare($sql)->execute($params);
            return;
        }

        if (!in_array('module', $columns, true)) {
            return;
        }
        $sql = 'INSERT INTO knowledge_state '
            . '(module, code_digest, docs_digest, status, last_synced_revision, last_synced_at, metadata_json) '
            . 'VALUES (:module, :code_digest, :docs_digest, :status, :last_synced_revision, :last_synced_at, :metadata) '
            . 'ON CONFLICT(module) DO UPDATE SET code_digest=excluded.code_digest, docs_digest=excluded.docs_digest, '
            . 'status=excluded.status, last_synced_revision=excluded.last_synced_revision, '
            . 'last_synced_at=excluded.last_synced_at, metadata_json=excluded.metadata_json';
        $this->index->pdo()->prepare($sql)->execute([
            'module' => $state['module'],
            'code_digest' => $state['code_digest'],
            'docs_digest' => $state['docs_digest'],
            'status' => $state['status'],
            'last_synced_revision' => $state['last_synced_revision'],
            'last_synced_at' => $state['last_synced_at'],
            'metadata' => Json::encode($state['metadata'] ?? []),
        ]);
    }

    /** @param array<string, mixed> $snapshot
     *  @return list<array<string, mixed>>
     */
    private function sourceEvidence(array $snapshot): array
    {
        $evidence = [];
        $maximumContext = (int) $this->config->get('knowledge.codex.max_context_chars', 60_000);
        $remaining = max(8_000, min(50_000, $maximumContext - 8_000));
        foreach ($snapshot['source_docs'] as $document) {
            $evidence[] = [
                'kind' => 'module_document',
                'path' => $document['path'],
                'content_hash' => $document['content_hash'],
                'sections' => $this->evidenceChunks((int) $document['file_id'], $remaining, 6_000),
                'authority' => 'source_of_truth',
            ];
        }
        foreach ($snapshot['facts'] as $type => $fact) {
            $evidence[] = [
                'kind' => 'deterministic_code_fact',
                'fact_type' => $type,
                'digest' => $fact['digest'],
                'locators' => $fact['evidence'],
                'authority' => 'indexed_code_fact',
            ];
        }
        foreach ($snapshot['code_files'] as $file) {
            if ($remaining <= 0) {
                break;
            }
            $chunks = $this->evidenceChunks((int) $file['file_id'], $remaining, 2_500, ['symbol', 'text']);
            if ($chunks === []) {
                continue;
            }
            $evidence[] = [
                'kind' => 'indexed_code_excerpt',
                'path' => $file['path'],
                'content_hash' => $file['content_hash'],
                'excerpts' => $chunks,
                'authority' => 'indexed_source_excerpt',
            ];
        }

        return $evidence;
    }

    /** @param list<string> $kinds
     *  @return list<array<string, mixed>>
     */
    private function evidenceChunks(int $fileId, int &$remaining, int $perChunk, array $kinds = []): array
    {
        if ($remaining <= 0 || $fileId < 1) {
            return [];
        }
        $sql = 'SELECT kind, title, start_line, end_line, content_hash, content FROM chunks WHERE file_id = :file_id';
        $params = ['file_id' => $fileId];
        if ($kinds !== []) {
            $placeholders = [];
            foreach ($kinds as $index => $kind) {
                $placeholders[] = ':kind_' . $index;
                $params['kind_' . $index] = $kind;
            }
            $sql .= ' AND kind IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY start_line, chunk_id LIMIT 200';
        $statement = $this->index->pdo()->prepare($sql);
        $statement->execute($params);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $chunk) {
            if ($remaining <= 0) {
                break;
            }
            $limit = min($remaining, $perChunk);
            $content = mb_substr((string) $chunk['content'], 0, $limit, 'UTF-8');
            $used = mb_strlen($content, 'UTF-8');
            $remaining -= $used;
            $result[] = [
                'kind' => $chunk['kind'],
                'title' => $chunk['title'],
                'start_line' => (int) $chunk['start_line'],
                'end_line' => (int) $chunk['end_line'],
                'content_hash' => $chunk['content_hash'],
                'content' => $content,
                'truncated' => $used >= $limit && mb_strlen((string) $chunk['content'], 'UTF-8') > $used,
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $input
     *  @param array<string, string> $moduleInfo
     *  @param array<string, mixed> $drift
     *  @param list<array<string, mixed>> $sourceEvidence
     *  @return array{operations:list<array<string,mixed>>,conflicts:list<array<string,mixed>>,warnings:list<string>,skill_content:?string}
     */
    private function requestCodexPlan(array $input, array $moduleInfo, string $task, array $drift, array $sourceEvidence): array
    {
        $result = ['operations' => [], 'conflicts' => [], 'warnings' => [], 'skill_content' => null];
        if (!(bool) $this->config->get('knowledge.codex.enabled', false)) {
            $result['warnings'][] = 'use_codex was requested, but knowledge.codex.enabled is false.';
            return $result;
        }
        if ($this->codexInvoker === null || !method_exists($this->codexInvoker, 'planDocumentation')) {
            $result['warnings'][] = 'use_codex was requested, but no CodexInvoker is available.';
            return $result;
        }
        if ($this->planningWithCodex || !empty($input['codex_recursive']) || ($input['_origin'] ?? '') === 'codex_invoker') {
            $result['warnings'][] = 'Recursive Codex documentation planning was refused.';
            return $result;
        }

        $this->planningWithCodex = true;
        try {
            $plan = $this->codexInvoker->planDocumentation([
                'schema_version' => 'doc-sync-request.v1',
                'project_id' => $this->projectId(),
                'project_revision' => $this->projectRevision(),
                'repository_root' => $this->index->root(),
                'module' => $moduleInfo['code'],
                'allowed_root' => $moduleInfo['doc_root'],
                'task' => $task,
                'drift' => $drift,
                'source_evidence' => $sourceEvidence,
                'constraints' => [
                    'return_structured_operations_only' => true,
                    'do_not_write_files' => true,
                    'do_not_run_commands' => true,
                    'module_docs_and_source_are_authoritative' => true,
                    'generated_technical_skill_status' => 'draft',
                ],
            ]);
        } catch (Throwable $error) {
            $result['warnings'][] = 'Codex documentation planning failed: ' . Text::truncate($error->getMessage(), 400);
            return $result;
        } finally {
            $this->planningWithCodex = false;
        }

        if (!is_array($plan)) {
            $result['warnings'][] = 'Codex documentation planning returned a non-object result.';
            return $result;
        }
        $changes = is_array($plan['operations'] ?? null)
            ? $plan['operations']
            : (is_array($plan['changes'] ?? null) ? $plan['changes'] : []);
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $path = trim((string) ($change['path'] ?? ''));
            try {
                $path = $this->assertWritableDocPath($moduleInfo, $path);
            } catch (ToolException $error) {
                $result['conflicts'][] = ['path' => $path, 'reason' => $error->getMessage(), 'origin' => 'codex'];
                continue;
            }
            if ($path === $moduleInfo['knowledge_index_path'] || $path === $moduleInfo['skill_meta_path']) {
                $result['conflicts'][] = ['path' => $path, 'reason' => 'Codex cannot replace deterministic generated metadata.', 'origin' => 'codex'];
                continue;
            }
            $content = $change['content'] ?? $change['replacement'] ?? null;
            if ($path === $moduleInfo['skill_path'] && is_string($content)) {
                $validation = $this->validateGeneratedSkill($content, $moduleInfo['skill_name']);
                if ($validation !== null) {
                    $result['conflicts'][] = ['path' => $path, 'reason' => $validation, 'origin' => 'codex'];
                } else {
                    $result['skill_content'] = $content;
                }
                continue;
            }

            $kind = (string) ($change['kind'] ?? $change['operation'] ?? '');
            if ($kind === 'replace_document_section' || isset($change['heading'])) {
                $heading = trim((string) ($change['heading'] ?? ''));
                $replacement = (string) ($change['replacement'] ?? $change['content'] ?? '');
                if ($heading === '' || $replacement === '') {
                    $result['conflicts'][] = ['path' => $path, 'reason' => 'Codex section edit requires heading and replacement.', 'origin' => 'codex'];
                    continue;
                }
                $result['operations'][] = [
                    'kind' => 'replace_document_section',
                    'path' => $path,
                    'heading' => $heading,
                    'replacement' => $replacement,
                    'expected_file_sha256' => (string) ($change['expected_file_sha256'] ?? $change['expected_sha256'] ?? ''),
                    'expected_digest' => (string) ($change['expected_digest'] ?? ''),
                ];
                continue;
            }
            if ($kind === 'replace_text' || isset($change['search'])) {
                $search = (string) ($change['search'] ?? '');
                $replacement = (string) ($change['replacement'] ?? $change['content'] ?? '');
                if ($search === '') {
                    $result['conflicts'][] = ['path' => $path, 'reason' => 'Codex text edit requires a non-empty search anchor.', 'origin' => 'codex'];
                    continue;
                }
                $result['operations'][] = [
                    'kind' => 'replace_text',
                    'path' => $path,
                    'search' => $search,
                    'replacement' => $replacement,
                    'expected_file_sha256' => (string) ($change['expected_file_sha256'] ?? $change['expected_sha256'] ?? ''),
                    'occurrence' => max(1, (int) ($change['occurrence'] ?? 1)),
                ];
                continue;
            }
            if (($kind === 'create_file' || $kind === 'create') && is_string($content)) {
                if ($this->indexedFile($path) !== null) {
                    $result['conflicts'][] = ['path' => $path, 'reason' => 'Codex create target already exists in the index.', 'origin' => 'codex'];
                    continue;
                }
                $result['operations'][] = ['kind' => 'create_file', 'path' => $path, 'content' => $content];
                continue;
            }
            $result['conflicts'][] = [
                'path' => $path,
                'reason' => 'Codex whole-file replacement of a hand-written document is not allowed; use a section or text anchor.',
                'origin' => 'codex',
            ];
        }

        return $result;
    }

    /** @param array<string, string> $moduleInfo
     *  @param array<string, mixed> $snapshot
     *  @param list<array<string, mixed>> $sourceEvidence
     */
    private function renderLocatorSkill(array $moduleInfo, array $snapshot, array $sourceEvidence): string
    {
        $description = 'Deterministic documentation and source locator for ' . $moduleInfo['code'] . '; use for tasks owned by this module.';
        $documents = array_values(array_filter(
            $sourceEvidence,
            static fn(array $item): bool => ($item['kind'] ?? '') === 'module_document',
        ));
        usort($documents, static function (array $left, array $right) use ($moduleInfo): int {
            $priority = static function (string $path) use ($moduleInfo): int {
                return match ($path) {
                    $moduleInfo['module_ai_index_path'] => 0,
                    $moduleInfo['readme_path'] => 1,
                    default => 2,
                };
            };
            return ($priority((string) $left['path']) <=> $priority((string) $right['path']))
                ?: strcmp((string) $left['path'], (string) $right['path']);
        });
        $documents = array_slice($documents, 0, 30);
        $load = [
            '- `AI-ENTRY.md`',
            '- `dev/ai/global-constraints.md`',
            '- `' . $moduleInfo['module_ai_index_path'] . '`',
        ];
        foreach ($documents as $document) {
            if ($document['path'] === $moduleInfo['module_ai_index_path']) {
                continue;
            }
            $contentHash = strtolower((string) $document['content_hash']);
            $contentHash = str_starts_with($contentHash, 'sha256:') ? $contentHash : 'sha256:' . $contentHash;
            $load[] = '- `' . $document['path'] . '` (`' . $contentHash . '`)';
        }

        return "---\n"
            . 'name: ' . $moduleInfo['skill_name'] . "\n"
            . 'description: ' . json_encode($description, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
            . "---\n\n"
            . self::SKILL_MARKER . "\n"
            . '# ' . $moduleInfo['code'] . " knowledge locator\n\n"
            . "## Role\n\nRoute work to the exact module documentation and indexed source facts. This skill is a derived locator, not an independent policy source.\n\n"
            . "## When To Use\n\nUse for tasks whose owning path or symbol is inside `" . $moduleInfo['module_root'] . "`.\n\n"
            . "## Load First\n\n" . implode("\n", $load) . "\n\n"
            . "## Workflow\n\n1. Confirm the owning module is `" . $moduleInfo['code'] . "`.\n2. Read the returned exact document paths and hashes; do not scan unrelated files.\n3. Ask the MCP for indexed symbols and deterministic drift evidence before proposing changes.\n4. Treat source code and module docs as authoritative when this derived skill disagrees.\n\n"
            . "## Guardrails\n\n- Never treat vector similarity alone as proof that documentation is stale.\n- Never edit outside `" . $moduleInfo['doc_root'] . "/**` through the documentation pipeline.\n- Draft, stale, contested, or deprecated skills are not actionable guidance.\n- Do not overwrite a hand-written skill that lacks the generated marker.\n\n"
            . "## Validation\n\n- Source digest: `sha256:" . $snapshot['source_digest'] . "`.\n- Code digest: `sha256:" . $snapshot['code_digest'] . "`.\n- Docs digest: `sha256:" . $snapshot['docs_digest'] . "`.\n- Re-resolve this skill after any indexed source hash changes.\n\n"
            . "## Output\n\nReturn exact repository-relative paths, symbol or heading locators, content hashes, freshness status, and the required validation entrypoints.\n";
    }

    /** @param array<string, string> $moduleInfo
     *  @param array<string, mixed> $snapshot
     *  @param list<array<string, mixed>> $sourceEvidence
     */
    private function renderSkillMeta(array $moduleInfo, array $snapshot, array $sourceEvidence, string $status): string
    {
        $sources = [];
        foreach ($sourceEvidence as $item) {
            if (($item['kind'] ?? '') !== 'module_document') {
                continue;
            }
            $sources[] = ['path' => $item['path'], 'content_hash' => $item['content_hash']];
        }
        return $this->prettyJson([
            'schema_version' => 'module-skill-meta.v1',
            'ownership_marker' => self::META_MARKER,
            'name' => $moduleInfo['skill_name'],
            'module' => $moduleInfo['code'],
            'path' => $moduleInfo['skill_path'],
            'status' => $status,
            'deterministic_locator_only' => $status === 'validated',
            'source_digest' => $snapshot['source_digest'],
            'code_digest' => $snapshot['code_digest'],
            'docs_digest' => $snapshot['docs_digest'],
            'source_documents' => $sources,
        ]);
    }

    /** @param array<string, string> $moduleInfo
     *  @param array<string, mixed> $snapshot
     *  @param list<array<string, mixed>> $sourceEvidence
     *  @param array<string, mixed> $drift
     */
    private function renderModuleIndex(
        array $moduleInfo,
        array $snapshot,
        array $sourceEvidence,
        string $skillStatus,
        bool $includeSkill,
        array $drift,
    ): string {
        $documents = [];
        foreach ($sourceEvidence as $item) {
            if (($item['kind'] ?? '') === 'module_document') {
                $documents[] = ['path' => $item['path'], 'content_hash' => $item['content_hash']];
            }
        }
        $skills = $includeSkill ? [[
            'name' => $moduleInfo['skill_name'],
            'path' => $moduleInfo['skill_path'],
            'meta_path' => $moduleInfo['skill_meta_path'],
            'status' => $skillStatus,
            'source_digest' => $snapshot['source_digest'],
        ]] : [];

        return $this->prettyJson([
            'schema_version' => 'module-knowledge-index.v1',
            'ownership_marker' => self::INDEX_MARKER,
            'module' => $moduleInfo['code'],
            'module_root' => $moduleInfo['module_root'],
            'source_of_truth' => ['module_source', $moduleInfo['doc_root']],
            'source_digest' => $snapshot['source_digest'],
            'code_digest' => $snapshot['code_digest'],
            'docs_digest' => $snapshot['docs_digest'],
            'drift_status' => $drift['status'] ?? 'unknown',
            'documents' => $documents,
            'skills' => $skills,
        ]);
    }

    /** @param array<string, string> $moduleInfo
     *  @return array{operation:?array<string,mixed>,conflict:?array<string,mixed>,ownership:array<string,mixed>}
     */
    private function generatedFileOperation(array $moduleInfo, string $path, string $content, string $marker): array
    {
        $path = $this->assertWritableDocPath($moduleInfo, $path);
        $existing = $this->indexedFile($path);
        if ($existing === null) {
            return [
                'operation' => ['kind' => 'create_file', 'path' => $path, 'content' => $content],
                'conflict' => null,
                'ownership' => ['state' => 'not_indexed', 'required_marker' => $marker, 'apply_must_verify_absence' => true],
            ];
        }

        $oldContent = $this->indexedFileContent((string) $existing['file_id']);
        if ($oldContent === null) {
            $conflict = ['path' => $path, 'reason' => 'Existing file content is not reconstructable from indexed chunks.', 'required_marker' => $marker];
            return ['operation' => null, 'conflict' => $conflict, 'ownership' => ['state' => 'unknown', 'required_marker' => $marker]];
        }
        if (!$this->contentHasMarker($oldContent, $marker)) {
            $conflict = ['path' => $path, 'reason' => 'Existing file has no recognized generated marker.', 'required_marker' => $marker];
            return ['operation' => null, 'conflict' => $conflict, 'ownership' => ['state' => 'hand_written', 'required_marker' => $marker]];
        }
        if ($oldContent === $content) {
            return [
                'operation' => null,
                'conflict' => null,
                'ownership' => [
                    'state' => 'current',
                    'required_marker' => $marker,
                    'content_hash' => (string) $existing['content_hash'],
                ],
            ];
        }

        return [
            'operation' => [
                'kind' => 'replace_text',
                'path' => $path,
                'search' => $oldContent,
                'replacement' => $content,
                'expected_file_sha256' => (string) $existing['content_hash'],
                'occurrence' => 1,
            ],
            'conflict' => null,
            'ownership' => [
                'state' => 'generated',
                'required_marker' => $marker,
                'content_hash' => (string) $existing['content_hash'],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function indexedFile(string $path): ?array
    {
        $columns = $this->columns('indexed_files');
        $id = $this->firstColumn($columns, ['id', 'file_id']);
        $hash = $this->firstColumn($columns, ['content_hash', 'sha256']);
        if ($id === null || $hash === null || !in_array('path', $columns, true)) {
            return null;
        }
        $statement = $this->index->pdo()->prepare(
            "SELECT {$id} AS file_id, path, {$hash} AS content_hash FROM indexed_files WHERE path = :path",
        );
        $statement->execute(['path' => $path]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function indexedFileContent(string $fileId): ?string
    {
        $columns = $this->columns('indexed_file_contents');
        if (!in_array('file_id', $columns, true)
            || !in_array('content_blob', $columns, true)
            || !in_array('encoding', $columns, true)) {
            return null;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT content_blob, encoding FROM indexed_file_contents WHERE file_id = :file_id',
        );
        $statement->execute(['file_id' => $fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $content = $row['content_blob'] ?? null;
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }
        if (!is_string($content)) {
            return null;
        }
        $encoding = (string) ($row['encoding'] ?? '');
        if ($encoding === 'raw') {
            return $content;
        }
        if ($encoding === 'gzip') {
            $decoded = gzdecode($content);
            return is_string($decoded) ? $decoded : null;
        }

        return null;
    }

    private function validateGeneratedSkill(string $content, string $expectedName): ?string
    {
        if (!str_contains($content, self::SKILL_MARKER)) {
            return 'Generated skill content is missing the required marker.';
        }
        if (preg_match('/\A---\R(.*?)\R---\R/s', $content, $match) !== 1) {
            return 'Generated skill requires YAML frontmatter.';
        }
        $keys = [];
        $name = '';
        foreach (preg_split('/\R/', trim($match[1])) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $parts) !== 1) {
                return 'Generated skill frontmatter must contain scalar name and description only.';
            }
            $keys[] = $parts[1];
            if ($parts[1] === 'name') {
                $name = trim($parts[2], " \t\n\r\0\x0B\"'");
            }
        }
        sort($keys);
        if ($keys !== ['description', 'name'] || $name !== $expectedName) {
            return 'Generated skill frontmatter must contain only the expected name and description.';
        }
        foreach (['Role', 'When To Use', 'Load First', 'Workflow', 'Guardrails', 'Validation', 'Output'] as $section) {
            if (!str_contains($content, '## ' . $section)) {
                return 'Generated skill is missing required section: ' . $section;
            }
        }

        return null;
    }

    /** @param array<string, string> $moduleInfo */
    private function assertWritableDocPath(array $moduleInfo, string $path): string
    {
        $path = $this->relativeRepositoryPath($path);
        if (!str_starts_with($path, $moduleInfo['doc_root'] . '/') || str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new ToolException(
                'PATH_SCOPE_VIOLATION',
                'Documentation writes are restricted to ' . $moduleInfo['doc_root'] . '/**',
                false,
                ['path' => $path],
            );
        }
        if (preg_match('#^app/code/[A-Za-z][A-Za-z0-9]*/[A-Za-z][A-Za-z0-9]*/doc/.+#', $path) !== 1) {
            throw new ToolException('PATH_SCOPE_VIOLATION', 'Invalid module documentation path', false, ['path' => $path]);
        }

        return $path;
    }

    private function relativeRepositoryPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($path, "\0")) {
            throw new ToolException('VALIDATION_FAILED', 'Path cannot be empty');
        }
        $root = rtrim(str_replace('\\', '/', $this->index->root()), '/');
        if (str_starts_with($path, $root . '/')) {
            $path = substr($path, strlen($root) + 1);
        } elseif (preg_match('#(?:^|/)(app/code/.+)$#', $path, $match) === 1) {
            $path = $match[1];
        }
        $segments = explode('/', ltrim($path, '/'));
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($normalized === []) {
                    throw new ToolException('PATH_SCOPE_VIOLATION', 'Path escapes the repository root', false, ['path' => $path]);
                }
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }

        return implode('/', $normalized);
    }

    private function contentHasMarker(string $content, string $marker): bool
    {
        if (str_contains($content, $marker)) {
            return true;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) && ($decoded['ownership_marker'] ?? null) === $marker;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            return [];
        }
        $exists = $this->index->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name");
        $exists->execute(['name' => $table]);
        if ($exists->fetchColumn() === false) {
            return $this->columnCache[$table] = [];
        }
        $rows = $this->index->pdo()->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $this->columnCache[$table] = array_values(array_map(
            static fn(array $row): string => (string) $row['name'],
            $rows,
        ));
    }

    /** @param list<string> $columns
     *  @param list<string> $candidates
     */
    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $value */
    private function prettyJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @param list<mixed> $statuses */
    private function highestStatus(array $statuses): string
    {
        $rank = ['fresh' => 0, 'unknown' => 1, 'suspect' => 2, 'stale' => 3];
        $highest = 'fresh';
        foreach ($statuses as $status) {
            $status = is_string($status) && isset($rank[$status]) ? $status : 'unknown';
            if ($rank[$status] > $rank[$highest]) {
                $highest = $status;
            }
        }
        return $highest;
    }

    /** @return array<string, mixed> */
    private function indexStatus(): array
    {
        try {
            $status = $this->index->status();
            return is_array($status) ? $status : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $input
     *  @param array<string, mixed> $status
     */
    private function baseCommit(array $input, array $status): string
    {
        foreach ([
            $input['base_commit'] ?? null,
            $status['git_head'] ?? null,
            $status['head_commit'] ?? null,
            $status['state']['git_head'] ?? null,
            $status['state']['head_commit'] ?? null,
        ] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if (preg_match('/^[a-f0-9]{7,64}$/iD', $candidate) === 1) {
                return $candidate;
            }
        }

        try {
            $resolved = ProjectResolver::resolve($this->index->root(), false);
            $candidate = trim((string) ($resolved['head_commit'] ?? ''));
            return preg_match('/^[a-f0-9]{7,64}$/iD', $candidate) === 1 ? $candidate : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function projectId(): string
    {
        return $this->index->projectId();
    }

    private function projectRevision(): int
    {
        return $this->index->revision();
    }
}
