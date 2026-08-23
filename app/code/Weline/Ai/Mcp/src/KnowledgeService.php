<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Compatibility facade for module-document freshness.
 *
 * Long-term knowledge lives only in module doc directories. Derived indexes live
 * in Project SQLite; this service never creates repository indexes or Skill files.
 */
final class KnowledgeService
{
    private const REQUIRED_DOCUMENTS = ['README.md', '需求.md', '开发日志.md'];

    public function __construct(
        private readonly ProjectIndex $index,
        private readonly ProjectIndexer $indexer,
        private readonly Config $config,
        private readonly ?CodexInvoker $codexInvoker = null,
    ) {
    }

    /** @return array<string,string|list<string>> */
    public function moduleFor(string $value): array
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || str_contains($value, "\0")) {
            throw new ToolException('VALIDATION_FAILED', 'module or path is required');
        }

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

        $moduleRoot = 'app/code/' . $vendor . '/' . $module;
        $docRoot = $moduleRoot . '/doc';

        return [
            'vendor' => $vendor,
            'module' => $module,
            'code' => $vendor . '_' . $module,
            'module_root' => $moduleRoot,
            'doc_root' => $docRoot,
            'readme_path' => $docRoot . '/README.md',
            'requirements_path' => $docRoot . '/需求.md',
            'development_log_path' => $docRoot . '/开发日志.md',
            'required_documents' => self::REQUIRED_DOCUMENTS,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function checkDrift(array $input): array
    {
        $modules = $this->modulesFromInput($input);
        if ($modules === []) {
            throw new ToolException('VALIDATION_FAILED', 'module, path, or paths is required');
        }

        $results = [];
        foreach ($modules as $module) {
            $results[] = $this->documentState($module);
        }
        $missing = array_values(array_filter(
            $results,
            static fn (array $state): bool => $state['status'] !== 'fresh',
        ));

        return [
            'schema_version' => 'knowledge-drift.v1',
            'project_id' => $this->index->projectId(),
            'project_revision' => $this->index->revision(),
            'status' => $missing === [] ? 'fresh' : 'stale',
            'modules' => $results,
            'knowledge_authority' => 'module_documents',
            'derived_index' => 'project_sqlite_only',
            'static_skill_files' => false,
        ];
    }

    /**
     * Legacy compatibility response. Repository repair is exclusively owned by
     * prepare_project -> repair_project_docs and therefore cannot be bypassed here.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function prepareSync(array $input): array
    {
        $moduleValue = trim((string) ($input['module'] ?? $input['path'] ?? ''));
        if ($moduleValue === '') {
            throw new ToolException('VALIDATION_FAILED', 'module is required');
        }
        $module = $this->moduleFor($moduleValue);
        $state = $this->documentState($module);

        return [
            'schema_version' => 'module-knowledge-compat.v1',
            'project_id' => $this->index->projectId(),
            'project_revision' => $this->index->revision(),
            'module' => $module,
            'drift' => $state,
            'operations' => [],
            'edit_plan' => null,
            'conflicts' => [],
            'warnings' => [
                'Static module indexes and Skill projections are retired.',
                'Run prepare_project; authorize only its deterministic repair bundle when documents are missing.',
            ],
            'replacement' => 'prepare_project',
            'repository_files_written' => false,
            'static_skill_files' => false,
        ];
    }

    /** @param list<string> $paths @return array<string,mixed> */
    public function afterIndexed(array $paths): array
    {
        $modules = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            try {
                $module = $this->moduleFor($path);
                $modules[$module['code']] = $this->documentState($module);
            } catch (ToolException) {
                // Non-module paths do not participate in the module document contract.
            }
        }

        return [
            'schema_version' => 'knowledge-index-reconcile.v1',
            'project_id' => $this->index->projectId(),
            'project_revision' => $this->index->revision(),
            'modules' => array_values($modules),
            'repository_files_written' => false,
            'derived_index' => 'project_sqlite_only',
            'static_skill_files' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function markSynchronized(string $module): array
    {
        $state = $this->documentState($this->moduleFor($module));

        return [
            'schema_version' => 'knowledge-sync-baseline.v1',
            'project_revision' => $this->index->revision(),
            'last_synced_at' => Clock::now(),
            'repository_files_written' => false,
            'derived_index' => 'project_sqlite_only',
        ] + $state;
    }

    /** @param array<string,mixed> $input @return list<array<string,string|list<string>>> */
    private function modulesFromInput(array $input): array
    {
        $values = [];
        foreach (['module', 'path'] as $field) {
            if (is_string($input[$field] ?? null) && trim((string) $input[$field]) !== '') {
                $values[] = (string) $input[$field];
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
            $modules[(string) $module['code']] = $module;
        }

        return array_values($modules);
    }

    /** @param array<string,string|list<string>> $module @return array<string,mixed> */
    private function documentState(array $module): array
    {
        $hashes = [];
        $missing = [];
        foreach (self::REQUIRED_DOCUMENTS as $document) {
            $relative = (string) $module['doc_root'] . '/' . $document;
            $absolute = $this->index->absolutePath($relative);
            if (!is_file($absolute) || is_link($absolute)) {
                $missing[] = $relative;
                continue;
            }
            $hash = hash_file('sha256', $absolute);
            $hashes[$relative] = is_string($hash) ? $hash : '';
        }
        ksort($hashes, SORT_STRING);

        return [
            'module' => $module['code'],
            'module_root' => $module['module_root'],
            'status' => $missing === [] ? 'fresh' : 'stale',
            'missing_documents' => $missing,
            'document_hashes' => $hashes,
            'documents_hash' => hash('sha256', Json::canonical($hashes)),
            'changed_fact_types' => $missing === [] ? [] : ['required_documents'],
        ];
    }

    private function relativeRepositoryPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $root = rtrim(str_replace('\\', '/', $this->index->root()), '/');
        if ($root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
            $path = ltrim(substr($path, strlen($root)), '/');
        }
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '../') || str_starts_with($path, '..')) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Path escapes the repository');
        }

        return $path;
    }
}
