<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;
use Throwable;

/**
 * Session-bound readiness gate for Weline module knowledge.
 *
 * Readiness and temporary directives deliberately live only in this MCP process.
 * The durable project index remains SQLite-backed, but no session decision is written
 * to the repository or to the learning database.
 */
final class ProjectReadinessService
{
    private const REQUIRED_DOCUMENTS = ['README.md', '需求.md', '开发日志.md'];
    private const MAX_SESSIONS = 128;
    private const MAX_DIRECTIVES = 50;
    private const MAX_DIRECTIVE_CHARACTERS = 8_000;

    /** @var array<string,array<string,mixed>> */
    private array $readiness = [];

    /** @var array<string,array<string,mixed>> */
    private array $repairBundles = [];

    /** @var array<string,list<string>> */
    private array $sessionDirectives = [];

    public function __construct(
        private readonly Config $config,
        private readonly ProcessRunner $runner,
    ) {
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function prepare(ProjectIndex $index, array $input): array
    {
        $sessionId = $this->sessionId($input);
        $branchBlocker = FrameworkBranchGuard::developmentBlocker($index->root());
        if ($branchBlocker !== null) {
            return $this->blocked(
                $index,
                $branchBlocker['code'],
                $branchBlocker['message'],
                $branchBlocker['details'],
            );
        }

        try {
            $snapshot = $this->snapshot($index);
        } catch (Throwable $exception) {
            return $this->blocked($index, 'PROJECT_SCAN_FAILED', $exception->getMessage());
        }

        $indexResult = $this->refreshIndex($index);
        if ($indexResult['errors'] !== []) {
            return $this->blocked(
                $index,
                'PROJECT_INDEX_FAILED',
                'The project index contains errors and development is blocked.',
                ['index_errors' => $indexResult['errors']],
            );
        }

        if ($snapshot['conflicts'] !== []) {
            return $this->blocked(
                $index,
                'KNOWLEDGE_CONFLICT',
                'Module knowledge contains unresolved conflict markers or credential-shaped content.',
                ['conflicts' => $snapshot['conflicts']],
            );
        }

        if ($snapshot['missing_documents'] !== []) {
            $repair = $this->createRepairBundle($index, $sessionId, $snapshot);

            try {
                return $this->repair($index, [
                    'client_session_id' => $sessionId,
                    'repair_bundle_id' => $repair['bundle_id'],
                ]);
            } catch (ToolException $exception) {
                return $this->blocked(
                    $index,
                    (string) ($exception->errorCode ?? 'PROJECT_REPAIR_FAILED'),
                    $exception->getMessage(),
                    \is_array($exception->details ?? null) ? $exception->details : [
                        'missing_documents' => $snapshot['missing_documents'],
                        'repair_bundle' => $repair,
                    ],
                );
            } catch (Throwable $exception) {
                return $this->blocked(
                    $index,
                    'PROJECT_REPAIR_FAILED',
                    'Automatic documentation repair failed: ' . $exception->getMessage(),
                    [
                        'missing_documents' => $snapshot['missing_documents'],
                        'repair_bundle' => $repair,
                    ],
                );
            }
        }

        return $this->recordReady($index, $sessionId, $snapshot, $indexResult, null);
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function repair(ProjectIndex $index, array $input): array
    {
        $sessionId = $this->sessionId($input);
        $bundleId = trim((string) ($input['repair_bundle_id'] ?? ''));
        if ($bundleId === '' || !isset($this->repairBundles[$bundleId])) {
            throw new ToolException('REPAIR_BUNDLE_NOT_FOUND', 'The repair bundle is missing or expired. Run prepare_project again.');
        }
        $bundle = $this->repairBundles[$bundleId];
        if (!hash_equals((string) $bundle['project_id'], $index->projectId())) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'The repair bundle belongs to another project.');
        }
        if (!hash_equals((string) $bundle['client_session_id'], $sessionId)) {
            throw new ToolException('READINESS_SESSION_MISMATCH', 'The repair bundle belongs to another client session.');
        }

        $snapshot = $this->snapshot($index);
        if (!hash_equals((string) $bundle['snapshot_hash'], (string) $snapshot['snapshot_hash'])) {
            unset($this->repairBundles[$bundleId]);
            throw new ToolException(
                'REPAIR_BUNDLE_STALE',
                'Project documents changed after the repair bundle was created. Run prepare_project again.',
                true,
            );
        }

        $createdFiles = [];
        $createdDirectories = [];
        try {
            foreach ($bundle['operations'] as $operation) {
                $relative = (string) ($operation['path'] ?? '');
                $absolute = $index->absolutePath($relative);
                if (file_exists($absolute) || is_link($absolute)) {
                    throw new RuntimeException('Repair target now exists: ' . $relative);
                }
                $directory = dirname($absolute);
                if (!is_dir($directory)) {
                    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                        throw new RuntimeException('Unable to create documentation directory: ' . $relative);
                    }
                    $createdDirectories[] = $directory;
                }
                $handle = fopen($absolute, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Unable to create repair target: ' . $relative);
                }
                try {
                    $content = (string) ($operation['content'] ?? '');
                    $written = fwrite($handle, $content);
                    if ($written !== strlen($content) || !fflush($handle)) {
                        throw new RuntimeException('Unable to write complete repair target: ' . $relative);
                    }
                } finally {
                    fclose($handle);
                }
                $createdFiles[] = $relative;
            }

            $indexResult = (new ProjectIndexer($index, $this->config, $this->runner))->indexPaths($createdFiles);
            if (($indexResult['errors'] ?? []) !== []) {
                throw new RuntimeException('Targeted repair reindex failed: ' . implode('; ', $indexResult['errors']));
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($createdFiles) as $relative) {
                $absolute = $index->absolutePath($relative);
                if (is_file($absolute) && !is_link($absolute)) {
                    @unlink($absolute);
                }
            }
            foreach (array_reverse($createdDirectories) as $directory) {
                @rmdir($directory);
            }
            if ($createdFiles !== []) {
                try {
                    (new ProjectIndexer($index, $this->config, $this->runner))->indexPaths($createdFiles);
                } catch (Throwable) {
                    // The original exception is more useful; the next prepare performs a full freshness pass.
                }
            }
            throw new ToolException(
                'PROJECT_REPAIR_FAILED',
                'Documentation repair was rolled back: ' . $exception->getMessage(),
                true,
                ['rolled_back_paths' => $createdFiles],
            );
        }

        unset($this->repairBundles[$bundleId]);
        $result = $this->prepare($index, ['client_session_id' => $sessionId]);
        $result['repair'] = [
            'bundle_id' => $bundleId,
            'created_paths' => $createdFiles,
            'transactional_reindex' => true,
            'rolled_back' => false,
        ];

        return $result;
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function assertReady(ProjectIndex $index, array $input): array
    {
        $sessionId = $this->sessionId($input);
        $readinessId = trim((string) ($input['readiness_id'] ?? ''));
        if ($readinessId === '' || !isset($this->readiness[$readinessId])) {
            throw new ToolException(
                'PROJECT_NOT_PREPARED',
                'A valid readiness_id is required. Call prepare_project before knowledge or edit tools.',
                false,
            );
        }
        $record = $this->readiness[$readinessId];
        if (!hash_equals((string) $record['project_id'], $index->projectId())) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'readiness_id belongs to another project.');
        }
        if (!hash_equals((string) $record['client_session_id'], $sessionId)) {
            throw new ToolException('READINESS_SESSION_MISMATCH', 'readiness_id belongs to another client session.');
        }

        $branchBlocker = FrameworkBranchGuard::developmentBlocker($index->root());
        if ($branchBlocker !== null) {
            unset($this->readiness[$readinessId]);
            throw new ToolException(
                'PROJECT_BLOCKED',
                $branchBlocker['message'],
                false,
                array_merge($branchBlocker['details'], [
                    'blocker_code' => $branchBlocker['code'],
                    'development_allowed' => false,
                ]),
            );
        }

        $snapshot = $this->snapshot($index);
        if ($snapshot['conflicts'] !== []) {
            unset($this->readiness[$readinessId]);
            throw new ToolException(
                'PROJECT_BLOCKED',
                'Knowledge conflicts were detected before the tool call.',
                false,
                ['conflicts' => $snapshot['conflicts'], 'development_allowed' => false],
            );
        }
        if ($snapshot['missing_documents'] !== []) {
            unset($this->readiness[$readinessId]);
            $repair = $this->createRepairBundle($index, $sessionId, $snapshot);
            throw new ToolException(
                'PROJECT_NEEDS_REPAIR',
                'Required module documents changed or disappeared. Call prepare_project again to auto-repair.',
                false,
                [
                    'missing_documents' => $snapshot['missing_documents'],
                    'repair_bundle' => $repair,
                    'development_allowed' => false,
                ],
            );
        }

        $indexResult = $this->refreshIndex($index);
        if ($indexResult['errors'] !== []) {
            unset($this->readiness[$readinessId]);
            throw new ToolException(
                'PROJECT_BLOCKED',
                'The freshness reindex failed before the tool call.',
                true,
                ['index_errors' => $indexResult['errors'], 'development_allowed' => false],
            );
        }
        $refreshed = !hash_equals((string) $record['snapshot_hash'], (string) $snapshot['snapshot_hash'])
            || (int) $record['project_revision'] !== $index->revision()
            || ($indexResult['changed_paths'] ?? []) !== [];
        $record = [
            'readiness_id' => $readinessId,
            'project_id' => $index->projectId(),
            'repository' => $index->root(),
            'client_session_id' => $sessionId,
            'project_revision' => $index->revision(),
            'module_inventory_hash' => $snapshot['module_inventory_hash'],
            'documents_hash' => $snapshot['documents_hash'],
            'snapshot_hash' => $snapshot['snapshot_hash'],
            'documents' => $snapshot['documents'],
            'module_count' => count($snapshot['modules']),
            'validated_at' => Clock::now(),
        ];
        $this->readiness[$readinessId] = $record;

        return [
            'schema_version' => 'readiness-validation.v1',
            'status' => 'ready',
            'ready' => true,
            'readiness_id' => $readinessId,
            'project_id' => $index->projectId(),
            'project_revision' => $index->revision(),
            'client_session_id' => $sessionId,
            'module_inventory_hash' => $snapshot['module_inventory_hash'],
            'documents_hash' => $snapshot['documents_hash'],
            'documents' => $snapshot['documents'],
            'refreshed' => $refreshed,
            'changed_paths' => $indexResult['changed_paths'] ?? [],
            'validated_at' => $record['validated_at'],
        ];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function setDirectives(ProjectIndex $index, array $input): array
    {
        $readiness = $this->assertReady($index, $input);
        $directives = $input['directives'] ?? null;
        if (!is_array($directives) || !array_is_list($directives) || count($directives) > self::MAX_DIRECTIVES) {
            throw new ToolException(
                'VALIDATION_FAILED',
                'directives must be a list containing at most ' . self::MAX_DIRECTIVES . ' strings.',
            );
        }
        $normalized = Text::uniqueStrings($directives, false);
        $characters = 0;
        foreach ($normalized as $directive) {
            $characters += mb_strlen($directive, 'UTF-8');
            [, $secretCount] = Redactor::string($directive);
            if ($secretCount > 0) {
                throw new ToolException(
                    'SESSION_DIRECTIVE_SECRET_REJECTED',
                    'Session directives cannot contain credentials or credential-shaped values.',
                );
            }
        }
        if ($characters > self::MAX_DIRECTIVE_CHARACTERS) {
            throw new ToolException(
                'VALIDATION_FAILED',
                'Session directives exceed the ' . self::MAX_DIRECTIVE_CHARACTERS . ' character budget.',
            );
        }

        $sessionId = (string) $readiness['client_session_id'];
        $key = $this->sessionKey($index, $sessionId);
        if ($normalized === []) {
            unset($this->sessionDirectives[$key]);
        } else {
            $this->sessionDirectives[$key] = $normalized;
            $this->boundSessions();
        }

        return [
            'schema_version' => 'session-directives.v1',
            'status' => 'accepted',
            'project_id' => $index->projectId(),
            'client_session_id' => $sessionId,
            'readiness_id' => $readiness['readiness_id'],
            'directive_count' => count($normalized),
            'directives_hash' => Ids::hash(Json::canonical($normalized)),
            'persisted' => false,
            'repository_written' => false,
            'credentials_stored' => false,
        ];
    }

    /** @return list<string> */
    public function directives(ProjectIndex $index, string $sessionId): array
    {
        return $this->sessionDirectives[$this->sessionKey($index, $sessionId)] ?? [];
    }

    /** @param array<string,mixed> $snapshot
     *  @param array<string,mixed> $indexResult
     *  @return array<string,mixed>
     */
    private function recordReady(
        ProjectIndex $index,
        string $sessionId,
        array $snapshot,
        array $indexResult,
        ?string $existingId,
    ): array {
        $readinessId = $existingId ?? Ids::make('ready');
        $record = [
            'readiness_id' => $readinessId,
            'project_id' => $index->projectId(),
            'repository' => $index->root(),
            'client_session_id' => $sessionId,
            'project_revision' => $index->revision(),
            'module_inventory_hash' => $snapshot['module_inventory_hash'],
            'documents_hash' => $snapshot['documents_hash'],
            'snapshot_hash' => $snapshot['snapshot_hash'],
            'documents' => $snapshot['documents'],
            'module_count' => count($snapshot['modules']),
            'validated_at' => Clock::now(),
        ];
        $this->readiness[$readinessId] = $record;
        $this->boundSessions();

        return [
            'schema_version' => 'project-readiness.v1',
            'status' => 'ready',
            'ready' => true,
            'readiness_id' => $readinessId,
            'project_id' => $index->projectId(),
            'repository' => $index->root(),
            'project_revision' => $index->revision(),
            'client_session_id' => $sessionId,
            'module_count' => count($snapshot['modules']),
            'knowledge_unit_count' => count($snapshot['modules']),
            'modules' => $snapshot['modules'],
            'module_inventory_hash' => $snapshot['module_inventory_hash'],
            'documents_hash' => $snapshot['documents_hash'],
            'document_count' => count($snapshot['documents']),
            'freshness' => $indexResult['freshness'] ?? 'unknown',
            'gate' => [
                'development_allowed' => true,
                'required_on_every_tool' => ['readiness_id', 'client_session_id'],
                'development_branch' => FrameworkBranchGuard::DEVELOPMENT_BRANCH,
                'release_branch' => FrameworkBranchGuard::RELEASE_BRANCH,
            ],
            'git' => [
                'branch' => FrameworkBranchGuard::DEVELOPMENT_BRANCH,
                'development_branch' => FrameworkBranchGuard::DEVELOPMENT_BRANCH,
                'release_branch' => FrameworkBranchGuard::RELEASE_BRANCH,
            ],
            'validated_at' => $record['validated_at'],
            'agent_guidance' => [
                'session_startup_notices' => GuidanceWorkflowCatalog::sessionStartupNotices(),
                'workflow_doc' => 'app/code/Weline/Ai/doc/AI工程交付流程.md',
                'read_next' => ['resolve_task_context', 'workflow_contract.v1.session_startup_notices'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function refreshIndex(ProjectIndex $index): array
    {
        try {
            $result = (new ProjectIndexer($index, $this->config, $this->runner))->index([
                'mode' => $index->revision() > 0 ? 'incremental' : 'full',
            ]);
        } catch (Throwable $exception) {
            return [
                'freshness' => 'stale',
                'changed_paths' => [],
                'errors' => [Text::truncate($exception->getMessage(), 1_000)],
            ];
        }
        $errors = is_array($result['errors'] ?? null) ? Text::uniqueStrings($result['errors'], false) : [];

        return [
            'freshness' => (string) ($result['freshness'] ?? 'unknown'),
            'changed_paths' => is_array($result['changed_paths'] ?? null)
                ? Text::uniqueStrings($result['changed_paths'])
                : [],
            'errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    private function snapshot(ProjectIndex $index): array
    {
        $codeRoot = $index->root() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code';
        if (!is_dir($codeRoot) || is_link($codeRoot)) {
            throw new RuntimeException('Expected project module directory app/code is missing or unsafe.');
        }
        $modules = [];
        $documents = [];
        $missing = [];
        $conflicts = [];
        foreach (scandir($codeRoot) ?: [] as $vendor) {
            if ($vendor === '.' || $vendor === '..') {
                continue;
            }
            $vendorRoot = $codeRoot . DIRECTORY_SEPARATOR . $vendor;
            if (!is_dir($vendorRoot) || is_link($vendorRoot)) {
                continue;
            }
            foreach (scandir($vendorRoot) ?: [] as $module) {
                if ($module === '.' || $module === '..') {
                    continue;
                }
                $moduleRoot = $vendorRoot . DIRECTORY_SEPARATOR . $module;
                if (!is_dir($moduleRoot) || is_link($moduleRoot)) {
                    continue;
                }
                $relativeRoot = 'app/code/' . $vendor . '/' . $module;
                $moduleName = $vendor . '_' . $module;
                $type = is_file($moduleRoot . DIRECTORY_SEPARATOR . 'composer.json')
                    && !is_file($moduleRoot . DIRECTORY_SEPARATOR . 'register.php')
                    ? 'composer_package'
                    : 'runtime_module';
                $modules[] = [
                    'name' => $moduleName,
                    'path' => $relativeRoot,
                    'type' => $type,
                ];
                foreach (self::REQUIRED_DOCUMENTS as $filename) {
                    $relative = $relativeRoot . '/doc/' . $filename;
                    $absolute = $moduleRoot . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . $filename;
                    if (!is_file($absolute) || is_link($absolute)) {
                        $missing[] = [
                            'module' => $moduleName,
                            'path' => $relative,
                            'document' => $filename,
                        ];
                        continue;
                    }
                    $content = file_get_contents($absolute);
                    if ($content === false) {
                        throw new RuntimeException('Unable to read module document: ' . $relative);
                    }
                    $documents[$relative] = 'sha256:' . hash('sha256', $content);
                    if (preg_match('/^(?:<{7}|={7}|>{7})(?:\s|$)/m', $content) === 1) {
                        $conflicts[] = ['path' => $relative, 'reason' => 'merge_conflict_marker'];
                    }
                    [, $secretCount] = Redactor::string($content);
                    if ($secretCount > 0) {
                        $conflicts[] = ['path' => $relative, 'reason' => 'credential_shaped_content'];
                    }
                }
            }
        }
        usort($modules, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        ksort($documents, SORT_STRING);
        usort($missing, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        usort($conflicts, static fn (array $left, array $right): int => ($left['path'] <=> $right['path']) ?: ($left['reason'] <=> $right['reason']));
        $moduleHash = Ids::hash(Json::canonical($modules));
        $documentsHash = Ids::hash(Json::canonical($documents));

        return [
            'modules' => $modules,
            'documents' => $documents,
            'missing_documents' => $missing,
            'conflicts' => $conflicts,
            'module_inventory_hash' => $moduleHash,
            'documents_hash' => $documentsHash,
            'snapshot_hash' => Ids::hash(Json::canonical([
                'modules' => $moduleHash,
                'documents' => $documentsHash,
                'missing' => $missing,
                'conflicts' => $conflicts,
            ])),
        ];
    }

    /** @param array<string,mixed> $snapshot
     *  @return array<string,mixed>
     */
    private function createRepairBundle(ProjectIndex $index, string $sessionId, array $snapshot): array
    {
        $operations = [];
        foreach ($snapshot['missing_documents'] as $missing) {
            $operations[] = [
                'kind' => 'create_file',
                'path' => (string) $missing['path'],
                'expected_state' => 'missing',
                'content' => $this->documentTemplate(
                    (string) $missing['module'],
                    (string) $missing['document'],
                ),
            ];
        }
        $bundleId = Ids::deterministic('repair', Json::canonical([
            'project_id' => $index->projectId(),
            'client_session_id' => $sessionId,
            'snapshot_hash' => $snapshot['snapshot_hash'],
            'operations' => $operations,
        ]));
        $stored = [
            'schema_version' => 'project-repair-bundle.v1',
            'bundle_id' => $bundleId,
            'project_id' => $index->projectId(),
            'client_session_id' => $sessionId,
            'snapshot_hash' => $snapshot['snapshot_hash'],
            'operations' => $operations,
            'write_authorized' => true,
            'modifies_existing_files' => false,
        ];
        $this->repairBundles[$bundleId] = $stored;
        $this->boundSessions();

        return $stored;
    }

    private function documentTemplate(string $module, string $document): string
    {
        return match ($document) {
            'README.md' => "# {$module}\n\n## 模块定位\n\n本文件由 `prepare_project` 的自动修复流程创建。模块能力以当前源码、测试和后续人工维护的专题文档为准。\n\n## 知识维护约定\n\n- 长期事实写入本模块 `doc/`。\n- 不在本文复制全局规则或客户端规则。\n- 无法由当前证据确认的行为必须标记待确认。\n",
            '需求.md' => "# {$module} 需求\n\n## 当前需求\n\n- 保持模块现有公开行为；新增或变更需求须在实现前记录于此。\n\n## 待确认\n\n- 当前未从缺失文档中恢复出可证明的历史需求。\n",
            '开发日志.md' => "# {$module} 开发日志\n\n## 文档初始化\n\n- 由 MCP 自动修复流程补齐文档契约。\n- 本记录不推断或补造模块历史；后续变更应记录版本、范围与验证证据。\n",
            default => throw new RuntimeException('Unsupported required document: ' . $document),
        };
    }

    /** @param array<string,mixed> $input */
    private function sessionId(array $input): string
    {
        $sessionId = trim((string) ($input['client_session_id'] ?? ''));
        if ($sessionId === '' || strlen($sessionId) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $sessionId) !== 1) {
            throw new ToolException(
                'CLIENT_SESSION_REQUIRED',
                'client_session_id is required and must be a stable 1-160 character session identifier.',
            );
        }

        return $sessionId;
    }

    private function sessionKey(ProjectIndex $index, string $sessionId): string
    {
        return hash('sha256', $index->projectId() . "\0" . $sessionId);
    }

    private function boundSessions(): void
    {
        while (count($this->readiness) > self::MAX_SESSIONS) {
            $key = array_key_first($this->readiness);
            if (!is_string($key)) {
                break;
            }
            unset($this->readiness[$key]);
        }
        while (count($this->repairBundles) > self::MAX_SESSIONS) {
            $key = array_key_first($this->repairBundles);
            if (!is_string($key)) {
                break;
            }
            unset($this->repairBundles[$key]);
        }
        while (count($this->sessionDirectives) > self::MAX_SESSIONS) {
            $key = array_key_first($this->sessionDirectives);
            if (!is_string($key)) {
                break;
            }
            unset($this->sessionDirectives[$key]);
        }
    }

    /** @param array<string,mixed> $details
     *  @return array<string,mixed>
     */
    private function blocked(ProjectIndex $index, string $code, string $message, array $details = []): array
    {
        return [
            'schema_version' => 'project-readiness.v1',
            'status' => 'blocked',
            'ready' => false,
            'project_id' => $index->projectId(),
            'repository' => $index->root(),
            'project_revision' => $index->revision(),
            'blocker' => [
                'code' => $code,
                'message' => Text::truncate($message, 1_000),
                'details' => $details,
            ],
            'gate' => [
                'development_allowed' => false,
                'next_action' => 'Resolve the reported blocker, then call prepare_project again.',
            ],
            'checked_at' => Clock::now(),
        ];
    }
}
