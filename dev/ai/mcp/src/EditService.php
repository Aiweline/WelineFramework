<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use RuntimeException;
use Throwable;

final class EditService
{
    /** @var array<string, true> */
    private const EDIT_KINDS = [
        'replace_text' => true,
        'replace_range' => true,
        'replace_symbol' => true,
        'insert_before_symbol' => true,
        'insert_after_symbol' => true,
        'replace_document_section' => true,
        'create_file' => true,
    ];

    private const MAX_PARALLEL_STAGE_WORKERS = 4;

    private ProcessRunner $runner;

    /** @var array<string, string> */
    private array $editColumns = [];

    /** @var array<string, string> */
    private array $validationColumns = [];

    public function __construct(
        private readonly ProjectIndex $index,
        private readonly ProjectIndexer $indexer,
        private readonly Config $config,
    ) {
        $this->runner = new ProcessRunner();
        $this->ensureDatabaseShape();
    }

    /**
     * Resolve and journal a guarded plan without changing the workspace.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public function prepare(array $draft, bool $allowTargetRebase = false): array
    {
        $this->assertEnabled();
        $schemaVersion = (string) ($draft['schema_version'] ?? '');
        if (!in_array($schemaVersion, ['edit-plan.v1', 'edit-plan-draft.v1'], true)) {
            throw new ToolException('EDIT_PLAN_INVALID', 'Edit plan must use edit-plan.v1');
        }
        $projectId = trim((string) ($draft['project_id'] ?? ''));
        if ($projectId === '' || !hash_equals($this->index->projectId(), $projectId)) {
            throw new ToolException('EDIT_PROJECT_MISMATCH', 'Edit plan project_id does not match the active index');
        }
        $revision = $draft['project_revision'] ?? $draft['index_revision'] ?? null;
        if (!is_int($revision) || $revision < 0 || $revision !== $this->index->revision()) {
            throw new ToolException(
                'EDIT_REVISION_STALE',
                'Edit plan index revision is missing or stale',
                true,
                ['expected' => $this->index->revision(), 'received' => $revision],
            );
        }
        $baseCommit = trim((string) ($draft['base_commit'] ?? ''));
        $currentCommit = $this->currentCommit();
        if ($baseCommit === '' || !hash_equals($currentCommit, $baseCommit)) {
            throw new ToolException(
                'EDIT_COMMIT_STALE',
                'Edit plan base commit does not match the workspace',
                true,
                ['expected' => $currentCommit, 'received' => $baseCommit],
            );
        }
        $rawOperations = $draft['operations'] ?? null;
        if (!is_array($rawOperations) || !array_is_list($rawOperations) || $rawOperations === []) {
            throw new ToolException('EDIT_PLAN_INVALID', 'Edit plan operations must be a non-empty list');
        }
        if (count($rawOperations) > $this->maxFiles() * 20) {
            throw new ToolException('EDIT_BUDGET_EXCEEDED', 'Edit plan contains too many operations');
        }

        $files = [];
        $ranges = [];
        $resolvedOperations = [];
        $rebasedFiles = [];
        foreach ($rawOperations as $operationIndex => $rawOperation) {
            if (!is_array($rawOperation)) {
                throw new ToolException('EDIT_PLAN_INVALID', 'Each edit operation must be an object');
            }
            $operation = $this->normalizeOperation($rawOperation);
            if (in_array($operation['kind'], ['replace_symbol', 'insert_before_symbol', 'insert_after_symbol'], true)) {
                $symbol = $this->resolveSymbol($operation);
                $operation['path'] = $symbol['path'];
                $operation['_symbol'] = $symbol;
            }
            $path = $this->safePath((string) ($operation['path'] ?? ''));
            $operation['path'] = $path;

            if ($operation['kind'] === 'create_file') {
                if (isset($files[$path]) || isset($ranges[$path])) {
                    throw new ToolException('EDIT_PLAN_CONFLICT', 'create_file cannot share a path with another operation');
                }
                $absolute = $this->index->absolutePath($path, false);
                $this->assertNoSymlinkComponents($path);
                $this->assertAllowedExtension($path);
                if (file_exists($absolute) || is_link($absolute)) {
                    throw new ToolException('EDIT_TARGET_EXISTS', 'create_file target already exists', false, ['path' => $path]);
                }
                $content = $this->textValue($operation, 'content');
                $this->assertFileBudget($path, $content);
                $files[$path] = [
                    'path' => $path,
                    'absolute' => $absolute,
                    'content' => '',
                    'hash' => null,
                    'mode' => 0644,
                    'create' => true,
                    'post_content' => $content,
                ];
                $resolvedOperations[] = ['kind' => 'create_file', 'path' => $path, 'content' => $content];
                continue;
            }

            if (!isset($files[$path])) {
                $files[$path] = $this->loadExistingFile($path);
            }
            if (($files[$path]['create'] ?? false) === true) {
                throw new ToolException('EDIT_PLAN_CONFLICT', 'Existing-file operation conflicts with create_file');
            }
            $expectedFileHash = $this->requiredExpectedFileHash($operation);
            $actualFileHash = (string) $files[$path]['hash'];
            $fileHashChanged = !$this->hashEquals($expectedFileHash, $actualFileHash);
            if ($fileHashChanged && !$allowTargetRebase) {
                throw new ToolException(
                    'EDIT_FILE_STALE',
                    'Edit operation expected file hash does not match the workspace',
                    true,
                    ['path' => $path, 'actual_sha256' => $actualFileHash],
                );
            }

            try {
                $resolved = $this->resolveRange($operation, (string) $files[$path]['content']);
                if ($fileHashChanged) {
                    $this->assertTargetCanRebase($operation, (string) $files[$path]['content'], $resolved);
                }
            } catch (ToolException $exception) {
                if (!$fileHashChanged || !$allowTargetRebase) {
                    throw $exception;
                }
                throw new ToolException(
                    'EDIT_REBASE_TARGET_CHANGED',
                    'The latest file no longer contains the previously planned target',
                    true,
                    [
                        'path' => $path,
                        'cause_code' => $exception->errorCode,
                        'cause_details' => $exception->details,
                        'expected_sha256' => $this->plainHash($expectedFileHash),
                        'actual_sha256' => $actualFileHash,
                    ],
                );
            }

            if ($fileHashChanged) {
                $rebasedFiles[$path] = [
                    'path' => $path,
                    'from_sha256' => $this->plainHash($expectedFileHash),
                    'to_sha256' => $actualFileHash,
                ];
            }
            $resolved['operation_index'] = $operationIndex;
            foreach ($ranges[$path] ?? [] as $existingRange) {
                if ($this->rangesConflict($resolved, $existingRange)) {
                    throw new ToolException(
                        'EDIT_RANGE_OVERLAP',
                        'Edit operations overlap or have an ambiguous shared boundary',
                        false,
                        ['path' => $path, 'operations' => [$existingRange['operation_index'], $operationIndex]],
                    );
                }
            }
            $ranges[$path][] = $resolved;
            $resolvedOperations[] = $this->publicResolvedOperation($operation, $resolved, $actualFileHash);
        }

        if (count($files) > $this->maxFiles()) {
            throw new ToolException(
                'EDIT_BUDGET_EXCEEDED',
                'Edit plan exceeds the maximum changed-file count',
                false,
                ['files' => count($files), 'limit' => $this->maxFiles()],
            );
        }

        $totalBytes = 0;
        foreach ($ranges as $path => $pathRanges) {
            usort(
                $pathRanges,
                static fn (array $left, array $right): int => [$right['start'], $right['end']] <=> [$left['start'], $left['end']],
            );
            $post = (string) $files[$path]['content'];
            foreach ($pathRanges as $range) {
                $post = substr($post, 0, $range['start'])
                    . $range['replacement']
                    . substr($post, $range['end']);
            }
            $this->assertFileBudget($path, $post);
            $files[$path]['post_content'] = $post;
        }
        ksort($files, SORT_STRING);
        foreach ($files as $file) {
            $totalBytes += strlen((string) $file['post_content']);
        }
        if ($totalBytes > $this->maxTotalBytes()) {
            throw new ToolException(
                'EDIT_BUDGET_EXCEEDED',
                'Edit plan exceeds the total output byte budget',
                false,
                ['bytes' => $totalBytes, 'limit' => $this->maxTotalBytes()],
            );
        }

        $transactionId = Ids::make('edit');
        $journalDirectory = $this->journalDirectory($transactionId);
        $snapshots = [];
        try {
            foreach ($files as $path => $file) {
                $stem = hash('sha256', $path);
                $beforeReference = null;
                if (($file['create'] ?? false) !== true) {
                    $beforeReference = $journalDirectory . '/' . $stem . '.before';
                    $this->writeJournal($beforeReference, (string) $file['content']);
                }
                $afterReference = $journalDirectory . '/' . $stem . '.after';
                $this->writeJournal($afterReference, (string) $file['post_content']);
                $snapshots[] = [
                    'path' => $path,
                    'action' => ($file['create'] ?? false) === true ? 'create' : 'modify',
                    'pre_sha256' => $file['hash'],
                    'post_sha256' => hash('sha256', (string) $file['post_content']),
                    'pre_bytes' => strlen((string) $file['content']),
                    'post_bytes' => strlen((string) $file['post_content']),
                    'operation_count' => ($file['create'] ?? false) === true ? 1 : count($ranges[$path] ?? []),
                    'mode' => (int) $file['mode'],
                    'before_ref' => $beforeReference,
                    'after_ref' => $afterReference,
                    'missing_parent_dirs' => $this->missingParentDirectories($path),
                ];
            }

            $validationProfile = $this->normalizeProfile((string) ($draft['validation_profile'] ?? 'default'));
            $plan = [
                'schema_version' => 'edit-plan.v1',
                'project_id' => $this->index->projectId(),
                'project_revision' => $revision,
                'base_commit' => $currentCommit,
                'operations' => $resolvedOperations,
                'validation_profile' => $validationProfile,
            ];
            if ($rebasedFiles !== []) {
                $plan['rebased_files'] = array_values($rebasedFiles);
            }
            if (isset($draft['metadata']) && is_array($draft['metadata'])) {
                [$metadata] = Redactor::value($draft['metadata']);
                $plan['metadata'] = $metadata;
            }
            $planDigest = Ids::hash(Json::canonical($plan));
            $token = bin2hex(random_bytes(32));
            $tokenHash = Ids::hash($token);
            $now = Clock::now();
            $expiresAt = self::timestamp(time() + $this->ttlSeconds());

            $this->insertTransaction([
                $this->editIdColumn() => $transactionId,
                $this->editRevisionColumn() => $revision,
                $this->editStateColumn() => 'prepared',
                'token_hash' => $tokenHash,
                'base_commit' => $currentCommit,
                'plan_digest' => $planDigest,
                'request_json' => Json::encode($this->redactedDraftSummary($draft)),
                'plan_json' => Json::encode($plan),
                'snapshots_json' => Json::encode($snapshots),
                'result_json' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $expiresAt,
                'applied_at' => null,
                'error_json' => null,
            ]);

            return [
                'edit_id' => $transactionId,
                'apply_token' => $token,
                'plan_digest' => $planDigest,
                'state' => 'prepared',
                'expires_at' => $expiresAt,
                'project_revision' => $revision,
                'base_commit' => $currentCommit,
                'rebased_files' => array_values($rebasedFiles),
                'preview' => $this->snapshotPreview($snapshots),
            ];
        } catch (Throwable $exception) {
            $this->removeTree($journalDirectory);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function apply(
        string $token,
        string $planDigest = '',
        bool $deferIndex = false,
        bool $allowRevisionAdvance = false,
    ): array {
        $this->assertEnabled();
        if (trim($token) === '') {
            throw new ToolException('EDIT_TOKEN_REQUIRED', 'An edit apply token is required');
        }

        return $this->withProjectLock(function () use ($token, $planDigest, $deferIndex, $allowRevisionAdvance): array {
            $row = $this->findTransactionByToken($token);
            $state = (string) $row[$this->editStateColumn()];
            if (in_array($state, ['applied', 'applied_index_pending', 'validated', 'validation_failed'], true)) {
                $status = $this->publicStatus($row);
                $status['already_applied'] = true;
                return $status;
            }
            if ($state !== 'prepared') {
                throw new ToolException('EDIT_NOT_APPLICABLE', 'Edit transaction is not in the prepared state', false, ['state' => $state]);
            }
            if ($this->isExpired((string) ($row['expires_at'] ?? ''))) {
                $this->updateTransaction($row, [$this->editStateColumn() => 'expired']);
                throw new ToolException('EDIT_TOKEN_EXPIRED', 'Edit apply token has expired');
            }
            if ($planDigest !== '' && !hash_equals((string) $row['plan_digest'], $planDigest)) {
                throw new ToolException('EDIT_PLAN_DIGEST_MISMATCH', 'Edit plan digest does not match the prepared transaction');
            }
            $this->assertTransactionFresh($row, $allowRevisionAdvance);
            $snapshots = $this->decodeSnapshots($row);
            $this->assertWorkspaceMatches($snapshots, 'pre');
            $this->updateTransaction($row, [$this->editStateColumn() => 'applying', 'error_json' => null]);

            $applied = [];
            $staging = [
                'files' => [],
                'strategy' => 'sequential_temp_stage',
                'workers' => 1,
                'fork_fallbacks' => 0,
            ];
            try {
                $staging = $this->stageSnapshots($snapshots);
                foreach ($snapshots as &$snapshot) {
                    $snapshot['stage_strategy'] = $staging['strategy'];
                    $snapshot['stage_workers'] = $staging['workers'];
                    $snapshot['stage_fork_fallbacks'] = $staging['fork_fallbacks'];
                }
                unset($snapshot);
                $this->updateTransaction($row, ['snapshots_json' => Json::encode($snapshots)]);

                foreach ($snapshots as $snapshot) {
                    $applied[] = $snapshot;
                    $path = (string) $snapshot['path'];
                    $this->commitStagedSnapshot($snapshot, $staging['files'][$path] ?? []);
                }
            } catch (Throwable $exception) {
                $recoveryErrors = $this->restoreSnapshots(array_reverse($applied), true);
                $this->cleanupSnapshotDirectories($snapshots);
                $recovered = $recoveryErrors === [];
                $error = [
                    'message' => $exception->getMessage(),
                    'recovery_errors' => $recoveryErrors,
                ];
                [$error] = Redactor::value($error);
                $this->updateTransaction($row, [
                    $this->editStateColumn() => $recovered ? 'rolled_back' : 'recovery_required',
                    'error_json' => Json::encode($error),
                ]);
                throw new ToolException(
                    'EDIT_APPLY_FAILED',
                    $recovered ? 'Edit apply failed and workspace changes were restored' : 'Edit apply failed and manual recovery is required',
                    false,
                    ['edit_id' => $row[$this->editIdColumn()], 'recovered' => $recovered],
                );
            } finally {
                $this->cleanupStagedSnapshots((array) ($staging['files'] ?? []));
            }

            $paths = array_column($snapshots, 'path');
            $this->updateTransaction($row, [
                $this->editStateColumn() => $deferIndex ? 'applied_index_pending' : 'applied',
                'applied_at' => Clock::now(),
                'result_json' => Json::encode([
                    'paths' => $paths,
                    'index_pending' => $deferIndex,
                    'index_reason' => $deferIndex ? 'validation_first' : null,
                ]),
            ]);
            if ($deferIndex) {
                $status = $this->status((string) $row[$this->editIdColumn()]);
                $status['index_refresh'] = [
                    'status' => 'pending',
                    'reason' => 'validation_first',
                    'recoverable' => true,
                    'duration_ms' => 0,
                ];
                return $status;
            }
            $indexStartedAt = hrtime(true);
            try {
                $indexResult = $this->indexer->indexPaths($paths);
            } catch (Throwable $exception) {
                $indexDurationMs = self::elapsedMilliseconds($indexStartedAt);
                [$message] = Redactor::string($exception->getMessage());
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'applied_index_pending',
                    'result_json' => Json::encode([
                        'paths' => $paths,
                        'index_pending' => true,
                        'index_reason' => 'index_error',
                    ]),
                    'error_json' => Json::encode(['index_error' => Text::truncate($message, 2_000)]),
                ]);
                $status = $this->status((string) $row[$this->editIdColumn()]);
                $status['index_refresh'] = [
                    'status' => 'pending',
                    'error' => Text::truncate($message, 2_000),
                    'recoverable' => true,
                    'duration_ms' => $indexDurationMs,
                ];
                return $status;
            }

            $this->updateTransaction($row, [
                'result_json' => Json::encode([
                    'paths' => $paths,
                    'index_pending' => false,
                    'index_revision' => $this->index->revision(),
                ]),
            ]);
            $status = $this->status((string) $row[$this->editIdColumn()]);
            $status['index_refresh'] = [
                'status' => 'completed',
                'result' => $indexResult,
                'duration_ms' => self::elapsedMilliseconds($indexStartedAt),
            ];
            return $status;
        });
    }

    /**
     * Complete a deliberately deferred postimage index refresh. This never
     * changes workspace files and rechecks every postimage hash first.
     *
     * @return array<string, mixed>
     */
    public function refreshIndex(string $idOrToken): array
    {
        return $this->withProjectLock(function () use ($idOrToken): array {
            $row = $this->findTransaction($idOrToken);
            $state = (string) $row[$this->editStateColumn()];
            if (!in_array($state, ['applied', 'applied_index_pending', 'validated', 'validation_failed'], true)) {
                throw new ToolException(
                    'EDIT_NOT_INDEXABLE',
                    'Only a currently applied edit can refresh its deferred index',
                    false,
                    ['state' => $state],
                );
            }
            $snapshots = $this->decodeSnapshots($row);
            $this->assertWorkspaceMatches($snapshots, 'post');
            $paths = array_column($snapshots, 'path');
            $indexStartedAt = hrtime(true);
            try {
                $indexResult = $this->indexer->indexPaths($paths);
            } catch (Throwable $exception) {
                [$message] = Redactor::string($exception->getMessage());
                $this->updateTransaction($row, [
                    'result_json' => Json::encode([
                        'paths' => $paths,
                        'index_pending' => true,
                        'index_reason' => 'index_error',
                    ]),
                    'error_json' => Json::encode(['index_error' => Text::truncate($message, 2_000)]),
                ]);
                return [
                    'status' => 'pending',
                    'error' => Text::truncate($message, 2_000),
                    'recoverable' => true,
                    'duration_ms' => self::elapsedMilliseconds($indexStartedAt),
                ];
            }

            $nextState = $state === 'applied_index_pending' ? 'applied' : $state;
            $this->updateTransaction($row, [
                $this->editStateColumn() => $nextState,
                'result_json' => Json::encode([
                    'paths' => $paths,
                    'index_pending' => false,
                    'index_revision' => $this->index->revision(),
                ]),
                'error_json' => null,
            ]);
            return [
                'status' => 'completed',
                'result' => $indexResult,
                'duration_ms' => self::elapsedMilliseconds($indexStartedAt),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function status(string $idOrToken): array
    {
        return $this->publicStatus($this->findTransaction($idOrToken));
    }

    /**
     * Run only the built-in validation profiles. Callers cannot supply commands.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        foreach (['command', 'commands', 'argv', 'shell', 'script'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new ToolException('VALIDATION_COMMAND_FORBIDDEN', 'Custom validation commands are not supported');
            }
        }
        $idOrToken = trim((string) ($input['edit_id'] ?? $input['token'] ?? $input['id_or_token'] ?? ''));
        if ($idOrToken === '') {
            throw new ToolException('EDIT_ID_REQUIRED', 'Validation requires an edit id or apply token');
        }
        $row = $this->findTransaction($idOrToken);
        $state = (string) $row[$this->editStateColumn()];
        if (!in_array($state, ['applied', 'applied_index_pending', 'validated', 'validation_failed'], true)) {
            throw new ToolException('EDIT_NOT_VALIDATABLE', 'Only an applied edit can be validated', false, ['state' => $state]);
        }
        $plan = Json::decode((string) $row['plan_json'], []);
        $requestedProfile = (string) ($input['profile'] ?? (is_array($plan) ? ($plan['validation_profile'] ?? 'default') : 'default'));
        $profile = $this->normalizeProfile($requestedProfile);
        $snapshots = $this->decodeSnapshots($row);
        $this->assertWorkspaceMatches($snapshots, 'post');
        $paths = array_column($snapshots, 'path');
        $checks = $this->validationChecks($profile, $paths);
        $startedAt = Clock::now();
        $results = [];
        $commands = [];
        $passed = true;

        foreach ($checks as $check) {
            if ($check['type'] === 'json') {
                try {
                    Json::decode((string) file_get_contents($check['absolute']), new \stdClass());
                    json_decode((string) file_get_contents($check['absolute']), true, 128, JSON_THROW_ON_ERROR);
                    $results[] = ['check' => 'json', 'path' => $check['path'], 'status' => 'passed'];
                } catch (Throwable $exception) {
                    $passed = false;
                    $results[] = ['check' => 'json', 'path' => $check['path'], 'status' => 'failed', 'output' => $exception->getMessage()];
                }
                continue;
            }
            $argv = $check['argv'];
            $commands[] = $argv;
            $result = $this->runner->run($argv, $this->index->root(), '', 60, ['NO_COLOR' => '1']);
            [$stdout] = Redactor::string($result['stdout']);
            [$stderr] = Redactor::string($result['stderr']);
            $checkPassed = $result['exit_code'] === 0;
            $passed = $passed && $checkPassed;
            $results[] = [
                'check' => $check['type'],
                'path' => $check['path'] ?? null,
                'status' => $checkPassed ? 'passed' : 'failed',
                'exit_code' => $result['exit_code'],
                'output' => Text::truncate(trim($stdout . "\n" . $stderr), 4_000),
                'duration_ms' => $result['duration_ms'],
            ];
        }

        $completedAt = Clock::now();
        $validationId = Ids::make('validation');
        $this->insertValidation([
            $this->validationIdColumn() => $validationId,
            $this->validationEditColumn() => $row[$this->editIdColumn()],
            'revision' => $this->index->revision(),
            'profile' => $profile,
            'status' => $passed ? 'passed' : 'failed',
            'command_json' => Json::encode($commands),
            'result_json' => Json::encode($results),
            'output_redacted' => Json::encode($results),
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);
        $this->updateTransaction($row, [$this->editStateColumn() => $passed ? 'validated' : 'validation_failed']);

        return [
            'validation_id' => $validationId,
            'edit_id' => $row[$this->editIdColumn()],
            'profile' => $profile,
            'status' => $passed ? 'passed' : 'failed',
            'results' => $results,
            'rollback_available' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function rollback(string $idOrToken): array
    {
        $this->assertEnabled();
        return $this->withProjectLock(function () use ($idOrToken): array {
            $row = $this->findTransaction($idOrToken);
            $state = (string) $row[$this->editStateColumn()];
            if ($state === 'rolled_back' || $state === 'rolled_back_index_pending') {
                $status = $this->publicStatus($row);
                $status['already_rolled_back'] = true;
                return $status;
            }
            if (!in_array($state, ['applied', 'applied_index_pending', 'validated', 'validation_failed'], true)) {
                throw new ToolException('EDIT_NOT_ROLLBACKABLE', 'Edit transaction is not rollbackable', false, ['state' => $state]);
            }
            $snapshots = $this->decodeSnapshots($row);
            try {
                $this->assertWorkspaceMatches($snapshots, 'post');
            } catch (ToolException $exception) {
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'rollback_blocked',
                    'error_json' => Json::encode(['rollback_guard' => $exception->details]),
                ]);
                throw new ToolException(
                    'ROLLBACK_STALE',
                    'Rollback refused because a current file no longer matches the applied postimage',
                    false,
                    $exception->details,
                );
            }
            $this->updateTransaction($row, [$this->editStateColumn() => 'rolling_back']);
            $errors = $this->restoreSnapshots(array_reverse($snapshots), false);
            if ($errors !== []) {
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'recovery_required',
                    'error_json' => Json::encode(['rollback_errors' => $errors]),
                ]);
                throw new ToolException('ROLLBACK_FAILED', 'Rollback encountered errors and requires manual recovery');
            }
            $paths = array_column($snapshots, 'path');
            $this->updateTransaction($row, [
                $this->editStateColumn() => 'rolled_back',
                'result_json' => Json::encode([
                    'paths' => $paths,
                    'index_pending' => true,
                    'index_reason' => 'rollback',
                ]),
                'error_json' => null,
            ]);
            $indexStartedAt = hrtime(true);
            try {
                $indexResult = $this->indexer->indexPaths($paths);
            } catch (Throwable $exception) {
                $indexDurationMs = self::elapsedMilliseconds($indexStartedAt);
                [$message] = Redactor::string($exception->getMessage());
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'rolled_back_index_pending',
                    'error_json' => Json::encode(['index_error' => Text::truncate($message, 2_000)]),
                ]);
                $status = $this->status((string) $row[$this->editIdColumn()]);
                $status['index_refresh'] = [
                    'status' => 'pending',
                    'error' => Text::truncate($message, 2_000),
                    'recoverable' => true,
                    'duration_ms' => $indexDurationMs,
                ];
                return $status;
            }
            $this->updateTransaction($row, [
                'result_json' => Json::encode([
                    'paths' => $paths,
                    'index_pending' => false,
                    'index_revision' => $this->index->revision(),
                ]),
            ]);
            $status = $this->status((string) $row[$this->editIdColumn()]);
            $status['index_refresh'] = [
                'status' => 'completed',
                'result' => $indexResult,
                'duration_ms' => self::elapsedMilliseconds($indexStartedAt),
            ];
            return $status;
        });
    }

    /**
     * Hold every target file lock for the complete compact edit lifecycle.
     *
     * @param array<string, mixed> $draft
     * @param callable(array<string, mixed>): mixed $callback
     */
    public function withPlanFileLocks(array $draft, callable $callback): mixed
    {
        return $this->withFileLocks($this->planPaths($draft), $callback);
    }

    /** @param array<string, mixed> $draft
     *  @return list<string>
     */
    public function planPaths(array $draft): array
    {
        $rawOperations = $draft['operations'] ?? null;
        if (!is_array($rawOperations) || !array_is_list($rawOperations) || $rawOperations === []) {
            throw new ToolException('EDIT_PLAN_INVALID', 'Edit plan operations must be a non-empty list');
        }

        $paths = [];
        foreach ($rawOperations as $rawOperation) {
            if (!is_array($rawOperation)) {
                throw new ToolException('EDIT_PLAN_INVALID', 'Each edit operation must be an object');
            }
            $operation = $this->normalizeOperation($rawOperation);
            $path = trim((string) ($operation['path'] ?? ''));
            if ($path === '' && in_array(
                (string) $operation['kind'],
                ['replace_symbol', 'insert_before_symbol', 'insert_after_symbol'],
                true,
            )) {
                $symbol = $this->resolveSymbol($operation);
                $path = (string) ($symbol['path'] ?? '');
            }
            $path = $this->safePath($path);
            $paths[$path] = true;
        }

        $paths = array_keys($paths);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * The kernel wait queue serializes equal paths across MCP processes. All
     * paths are sorted before acquisition so multi-file plans cannot deadlock.
     *
     * @param list<string> $paths
     * @param callable(array<string, mixed>): mixed $callback
     */
    private function withFileLocks(array $paths, callable $callback): mixed
    {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new ToolException('EDIT_PLAN_INVALID', 'Edit plan did not resolve any file paths');
        }

        $directory = rtrim($this->config->dataDir(), '/') . '/edit-locks';
        if (is_link($directory)) {
            throw new ToolException('EDIT_LOCK_UNSAFE', 'Edit lock directory cannot be a symbolic link');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ToolException('EDIT_LOCK_FAILED', 'Unable to create edit lock directory');
        }
        @chmod($directory, 0700);

        $projectDirectory = $directory . '/' . hash('sha256', $this->index->projectId());
        if (is_link($projectDirectory)) {
            throw new ToolException('EDIT_LOCK_UNSAFE', 'Project file-lock directory cannot be a symbolic link');
        }
        if (!is_dir($projectDirectory) && !mkdir($projectDirectory, 0700) && !is_dir($projectDirectory)) {
            throw new ToolException('EDIT_LOCK_FAILED', 'Unable to create project file-lock directory');
        }
        @chmod($projectDirectory, 0700);

        $handles = [];
        $contended = [];
        $startedAt = hrtime(true);
        try {
            foreach ($paths as $path) {
                $lockPath = $projectDirectory . '/' . hash('sha256', $path) . '.lock';
                if (is_link($lockPath)) {
                    throw new ToolException('EDIT_LOCK_UNSAFE', 'File edit lock cannot be a symbolic link', false, ['path' => $path]);
                }
                $handle = fopen($lockPath, 'c+b');
                if (!is_resource($handle) || !chmod($lockPath, 0600)) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                    throw new ToolException('EDIT_LOCK_FAILED', 'Unable to open a file edit lock', true, ['path' => $path]);
                }
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    $contended[] = $path;
                    if (!flock($handle, LOCK_EX)) {
                        fclose($handle);
                        throw new ToolException('EDIT_LOCK_FAILED', 'Unable to wait for a file edit lock', true, ['path' => $path]);
                    }
                }
                $handles[] = ['path' => $path, 'handle' => $handle];
            }

            $lockContext = [
                'strategy' => 'sorted_per_file_flock',
                'queue' => 'kernel_wait_queue',
                'paths' => $paths,
                'contended_paths' => $contended,
                'wait_ms' => self::elapsedMilliseconds($startedAt),
            ];
            $result = $callback($lockContext);
        } finally {
            for ($index = count($handles) - 1; $index >= 0; $index--) {
                $handle = $handles[$index]['handle'];
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        if (is_array($result)) {
            $result['file_lock'] = array_merge($lockContext, ['status' => 'released']);
        }
        return $result;
    }

    private function assertEnabled(): void
    {
        if ($this->config->get('editing.enabled', true) !== true) {
            throw new ToolException('EDITING_DISABLED', 'Local guarded editing is disabled');
        }
    }

    /** @param array<string, mixed> $raw
     *  @return array<string, mixed>
     */
    private function normalizeOperation(array $raw): array
    {
        $kind = strtolower(trim((string) ($raw['kind'] ?? $raw['operation'] ?? '')));
        if ($kind === 'create') {
            $kind = 'create_file';
        } elseif ($kind === 'replace') {
            if (isset($raw['heading']) || isset($raw['section_heading'])) {
                $kind = 'replace_document_section';
            } elseif (isset($raw['search']) || isset($raw['old_text'])) {
                $kind = 'replace_text';
            } else {
                throw new ToolException(
                    'EDIT_PLAN_INVALID',
                    'Legacy replace operations require a heading, search text, or explicit byte range',
                );
            }
        }
        if (!isset(self::EDIT_KINDS[$kind])) {
            throw new ToolException('EDIT_KIND_UNSUPPORTED', 'Unsupported edit operation kind', false, ['kind' => $kind]);
        }
        $path = $raw['path'] ?? $raw['relative_path'] ?? '';
        $operation = ['kind' => $kind, 'path' => is_string($path) ? $path : ''];
        $expectedFileHash = $raw['expected_file_sha256'] ?? $raw['expected_sha256'] ?? $raw['expected_hash'] ?? null;
        if (is_string($expectedFileHash)) {
            $operation['expected_file_sha256'] = $expectedFileHash;
        }
        $expectedDigest = $raw['expected_digest'] ?? $raw['body_hash'] ?? $raw['section_hash'] ?? null;
        if (is_string($expectedDigest)) {
            $operation['expected_digest'] = $expectedDigest;
        }
        foreach (['symbol_uid', 'target_ref', 'heading', 'search'] as $key) {
            $aliases = ['heading' => 'section_heading', 'search' => 'old_text'];
            $value = $raw[$key] ?? (isset($aliases[$key]) ? ($raw[$aliases[$key]] ?? null) : null);
            if (is_string($value)) {
                $operation[$key] = $value;
            }
        }
        if (isset($raw['occurrence'])) {
            $operation['occurrence'] = $raw['occurrence'];
        }
        $replacement = $raw['replacement'] ?? $raw['new_text'] ?? $raw['content'] ?? null;
        if (is_string($replacement)) {
            $operation[$kind === 'create_file' ? 'content' : 'replacement'] = $replacement;
        }
        foreach (['start_byte', 'end_byte'] as $key) {
            if (isset($raw[$key])) {
                $operation[$key] = $raw[$key];
            }
        }

        return $operation;
    }

    /** @param array<string, mixed> $operation
     *  @return array{start:int,end:int,replacement:string}
     */
    private function resolveRange(array $operation, string $content): array
    {
        $kind = (string) $operation['kind'];
        $replacement = $this->textValue($operation, 'replacement');
        if ($kind === 'replace_text') {
            $search = $this->textValue($operation, 'search', false);
            if ($search === '') {
                throw new ToolException('EDIT_PLAN_INVALID', 'replace_text search cannot be empty');
            }
            $positions = [];
            $offset = 0;
            while (($position = strpos($content, $search, $offset)) !== false) {
                $positions[] = $position;
                $offset = $position + max(1, strlen($search));
                if (count($positions) > 10_000) {
                    break;
                }
            }
            if ($positions === []) {
                throw new ToolException('EDIT_TEXT_NOT_FOUND', 'replace_text search text was not found');
            }
            $occurrence = $operation['occurrence'] ?? null;
            if ($occurrence === null && count($positions) !== 1) {
                throw new ToolException('EDIT_TEXT_AMBIGUOUS', 'replace_text requires occurrence when search text is not unique');
            }
            if ($occurrence !== null && (!is_int($occurrence) || $occurrence < 1 || !isset($positions[$occurrence - 1]))) {
                throw new ToolException('EDIT_PLAN_INVALID', 'replace_text occurrence is out of range');
            }
            $start = $positions[$occurrence === null ? 0 : $occurrence - 1];
            return ['start' => $start, 'end' => $start + strlen($search), 'replacement' => $replacement];
        }
        if ($kind === 'replace_range') {
            $start = $operation['start_byte'] ?? null;
            $end = $operation['end_byte'] ?? null;
            if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start || $end > strlen($content)) {
                throw new ToolException('EDIT_RANGE_INVALID', 'replace_range byte offsets are invalid');
            }
            return ['start' => $start, 'end' => $end, 'replacement' => $replacement];
        }
        if (in_array($kind, ['replace_symbol', 'insert_before_symbol', 'insert_after_symbol'], true)) {
            $symbol = $operation['_symbol'] ?? null;
            if (!is_array($symbol)) {
                throw new ToolException('EDIT_SYMBOL_NOT_FOUND', 'Symbol metadata is unavailable');
            }
            [$symbolStart, $symbolEnd] = $this->symbolOffsets($symbol, $content);
            $this->assertExpectedDigest($operation, substr($content, $symbolStart, $symbolEnd - $symbolStart), (string) ($symbol['body_hash'] ?? ''));
            return match ($kind) {
                'insert_before_symbol' => ['start' => $symbolStart, 'end' => $symbolStart, 'replacement' => $replacement],
                'insert_after_symbol' => ['start' => $symbolEnd, 'end' => $symbolEnd, 'replacement' => $replacement],
                default => ['start' => $symbolStart, 'end' => $symbolEnd, 'replacement' => $replacement],
            };
        }
        if ($kind === 'replace_document_section') {
            if (preg_match('~^app/code/[^/]+/[^/]+/doc(?:/|$)~D', (string) $operation['path']) !== 1) {
                throw new ToolException('EDIT_PATH_DENIED', 'Documentation section edits are limited to module doc directories');
            }
            if (isset($operation['heading'])) {
                [$start, $end] = $this->markdownSection($content, (string) $operation['heading']);
            } else {
                $start = $operation['start_byte'] ?? null;
                $end = $operation['end_byte'] ?? null;
                if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start || $end > strlen($content)) {
                    throw new ToolException('EDIT_RANGE_INVALID', 'Document section byte offsets are invalid');
                }
            }
            $this->assertExpectedDigest($operation, substr($content, $start, $end - $start));
            return ['start' => $start, 'end' => $end, 'replacement' => $replacement];
        }

        throw new ToolException('EDIT_KIND_UNSUPPORTED', 'Unsupported edit operation');
    }

    /** @param array<string, mixed> $operation
     *  @param array{start:int,end:int,replacement:string} $range
     */
    private function assertTargetCanRebase(array $operation, string $content, array $range): void
    {
        $kind = (string) $operation['kind'];
        if ($kind === 'replace_text') {
            if (array_key_exists('occurrence', $operation)) {
                throw new ToolException(
                    'EDIT_REBASE_UNSAFE',
                    'A changed file cannot safely rebase an occurrence-based text replacement',
                    true,
                );
            }
            $search = $this->textValue($operation, 'search', false);
            if ($search === '' || substr_count($content, $search) !== 1) {
                throw new ToolException(
                    'EDIT_TEXT_AMBIGUOUS',
                    'The latest file does not contain one unique copy of the previous text target',
                    true,
                );
            }
            return;
        }
        if ($kind === 'replace_range') {
            if (!isset($operation['expected_digest']) || trim((string) $operation['expected_digest']) === '') {
                throw new ToolException(
                    'EDIT_DIGEST_REQUIRED',
                    'A changed file requires expected_digest before a byte range can be rebased',
                    true,
                );
            }
            $this->assertExpectedDigest(
                $operation,
                substr($content, $range['start'], $range['end'] - $range['start']),
            );
        }
    }

    /** @param array<string, mixed> $operation
     *  @param array{start:int,end:int,replacement:string} $range
     *  @return array<string, mixed>
     */
    private function publicResolvedOperation(array $operation, array $range, string $expectedFileHash): array
    {
        $result = [
            'kind' => $operation['kind'],
            'path' => $operation['path'],
            'expected_file_sha256' => $this->plainHash($expectedFileHash),
            'start_byte' => $range['start'],
            'end_byte' => $range['end'],
            'replacement' => $range['replacement'],
        ];
        foreach (['symbol_uid', 'target_ref', 'heading', 'expected_digest', 'occurrence'] as $key) {
            if (array_key_exists($key, $operation)) {
                $result[$key] = $operation[$key];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $left
     *  @param array<string, mixed> $right
     */
    private function rangesConflict(array $left, array $right): bool
    {
        $leftInsert = $left['start'] === $left['end'];
        $rightInsert = $right['start'] === $right['end'];
        if ($leftInsert && $rightInsert) {
            return $left['start'] === $right['start'];
        }
        if ($leftInsert) {
            return $left['start'] >= $right['start'] && $left['start'] <= $right['end'];
        }
        if ($rightInsert) {
            return $right['start'] >= $left['start'] && $right['start'] <= $left['end'];
        }
        return $left['start'] < $right['end'] && $right['start'] < $left['end'];
    }

    /** @param array<string, mixed> $operation
     *  @return array<string, mixed>
     */
    private function resolveSymbol(array $operation): array
    {
        $columns = $this->tableColumns('symbols');
        $uidColumn = isset($columns['symbol_uid']) ? 'symbol_uid' : (isset($columns['uid']) ? 'uid' : '');
        $bodyColumn = isset($columns['body_hash']) ? 'body_hash' : (isset($columns['fingerprint']) ? 'fingerprint' : '');
        if ($uidColumn === '' || !isset($columns['file_id'], $columns['name'], $columns['fq_name'])) {
            throw new ToolException('EDIT_SYMBOL_INDEX_UNAVAILABLE', 'Symbol index does not expose the required columns');
        }
        $uid = trim((string) ($operation['symbol_uid'] ?? ''));
        $reference = trim((string) ($operation['target_ref'] ?? ''));
        if ($uid === '' && $reference === '') {
            throw new ToolException('EDIT_PLAN_INVALID', 'Symbol operation requires symbol_uid or target_ref');
        }
        $where = $uid !== ''
            ? 's.' . $uidColumn . ' = :reference'
            : '(s.fq_name = :reference COLLATE NOCASE OR s.name = :reference COLLATE NOCASE)';
        $parameters = ['reference' => $uid !== '' ? $uid : $reference];
        $path = trim((string) ($operation['path'] ?? ''));
        if ($path !== '') {
            $path = $this->safePath($path);
            $where .= ' AND f.path = :path';
            $parameters['path'] = $path;
        }
        $sql = 'SELECT s.*, f.path AS indexed_path'
            . ($bodyColumn !== '' ? ', s.' . $bodyColumn . ' AS indexed_body_hash' : '')
            . ' FROM symbols AS s JOIN indexed_files AS f ON f.id = s.file_id WHERE ' . $where . ' LIMIT 3';
        $statement = $this->index->pdo()->prepare($sql);
        $statement->execute($parameters);
        $matches = $statement->fetchAll();
        if (count($matches) !== 1) {
            throw new ToolException(
                count($matches) === 0 ? 'EDIT_SYMBOL_NOT_FOUND' : 'EDIT_SYMBOL_AMBIGUOUS',
                count($matches) === 0 ? 'Indexed symbol was not found' : 'Symbol reference matches multiple indexed symbols',
            );
        }
        $symbol = $matches[0];
        $symbol['path'] = (string) $symbol['indexed_path'];
        $symbol['body_hash'] = (string) ($symbol['indexed_body_hash'] ?? '');
        return $symbol;
    }

    /** @param array<string, mixed> $symbol
     *  @return array{0:int,1:int}
     */
    private function symbolOffsets(array $symbol, string $content): array
    {
        $startByte = $symbol['start_byte'] ?? null;
        $endByte = $symbol['end_byte'] ?? null;
        if (is_int($startByte) && is_int($endByte) && $startByte >= 0 && $endByte > $startByte && $endByte <= strlen($content)) {
            return [$startByte, $endByte];
        }
        $startLine = (int) ($symbol['start_line'] ?? 0);
        $endLine = (int) ($symbol['end_line'] ?? 0);
        if ($startLine < 1 || $endLine < $startLine) {
            throw new ToolException('EDIT_SYMBOL_RANGE_INVALID', 'Indexed symbol range is invalid');
        }
        $offsets = [0];
        $cursor = 0;
        while (($newline = strpos($content, "\n", $cursor)) !== false) {
            $offsets[] = $newline + 1;
            $cursor = $newline + 1;
        }
        $start = $offsets[$startLine - 1] ?? null;
        $end = $offsets[$endLine] ?? strlen($content);
        if (!is_int($start) || $end < $start) {
            throw new ToolException('EDIT_SYMBOL_RANGE_INVALID', 'Indexed symbol lines are outside the file');
        }
        return [$start, $end];
    }

    /** @return array{0:int,1:int} */
    private function markdownSection(string $content, string $heading): array
    {
        $target = trim((string) preg_replace('/^#{1,6}\s+/', '', trim($heading)));
        preg_match_all('/^(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*(?:\R|$)/m', $content, $matches, PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches[0] ?? [] as $index => $full) {
            $title = trim((string) ($matches[2][$index][0] ?? ''));
            if (strcasecmp($title, $target) === 0) {
                $found[] = $index;
            }
        }
        if (count($found) !== 1) {
            throw new ToolException(
                $found === [] ? 'EDIT_SECTION_NOT_FOUND' : 'EDIT_SECTION_AMBIGUOUS',
                $found === [] ? 'Markdown section heading was not found' : 'Markdown section heading is not unique',
            );
        }
        $index = $found[0];
        $start = (int) $matches[0][$index][1];
        $level = strlen((string) $matches[1][$index][0]);
        $end = strlen($content);
        for ($next = $index + 1, $count = count($matches[0]); $next < $count; ++$next) {
            if (strlen((string) $matches[1][$next][0]) <= $level) {
                $end = (int) $matches[0][$next][1];
                break;
            }
        }
        return [$start, $end];
    }

    /** @param array<string, mixed> $operation */
    private function assertExpectedDigest(array $operation, string $body, string $indexedDigest = ''): void
    {
        $expected = trim((string) ($operation['expected_digest'] ?? ''));
        if ($expected === '') {
            throw new ToolException('EDIT_DIGEST_REQUIRED', 'Symbol and document-section edits require expected_digest');
        }
        $plain = $this->plainHash($expected);
        $indexed = $indexedDigest === '' ? '' : $this->plainHash($indexedDigest);
        if (!hash_equals($plain, hash('sha256', $body)) && ($indexed === '' || !hash_equals($plain, $indexed))) {
            throw new ToolException('EDIT_DIGEST_STALE', 'Target digest does not match the indexed or current target body', true);
        }
    }

    /** @return array<string, mixed> */
    private function loadExistingFile(string $path): array
    {
        $absolute = $this->index->absolutePath($path, true);
        $this->assertNoSymlinkComponents($path);
        if (!is_file($absolute) || is_link($absolute)) {
            throw new ToolException('EDIT_TARGET_INVALID', 'Edit target must be a regular non-symlink file', false, ['path' => $path]);
        }
        $size = filesize($absolute);
        if (!is_int($size) || $size > $this->maxFileBytes()) {
            throw new ToolException('EDIT_BUDGET_EXCEEDED', 'Edit target exceeds the per-file byte limit', false, ['path' => $path]);
        }
        $content = file_get_contents($absolute);
        if (!is_string($content)) {
            throw new ToolException('EDIT_READ_FAILED', 'Unable to read edit target', false, ['path' => $path]);
        }
        if (str_contains($content, "\0")) {
            throw new ToolException('EDIT_BINARY_FORBIDDEN', 'Binary files cannot be edited');
        }
        $hash = hash('sha256', $content);
        $indexedHash = $this->indexedFileHash($path);
        if ($indexedHash !== null && !$this->hashEquals($indexedHash, $hash)) {
            throw new ToolException('EDIT_INDEX_STALE', 'Indexed file hash is stale; refresh the project index first', true, ['path' => $path]);
        }
        return [
            'path' => $path,
            'absolute' => $absolute,
            'content' => $content,
            'hash' => $hash,
            'mode' => (int) (fileperms($absolute) & 0777),
            'create' => false,
        ];
    }

    private function indexedFileHash(string $path): ?string
    {
        $columns = $this->tableColumns('indexed_files');
        $hashColumn = isset($columns['content_hash']) ? 'content_hash' : (isset($columns['sha256']) ? 'sha256' : '');
        if ($hashColumn === '') {
            return null;
        }
        $statement = $this->index->pdo()->prepare('SELECT ' . $hashColumn . ' FROM indexed_files WHERE path = :path');
        $statement->execute(['path' => $path]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private function safePath(string $path): string
    {
        $portablePath = str_replace('\\', '/', $path);
        if (preg_match('~(?:^|/)\.\.(?:/|$)~D', $portablePath) === 1) {
            throw new ToolException('EDIT_PATH_INVALID', 'Edit path cannot contain parent traversal segments');
        }
        try {
            $relative = $this->index->normalizeRelativePath($path);
        } catch (Throwable $exception) {
            throw new ToolException('EDIT_PATH_INVALID', 'Edit path is invalid', false, ['reason' => $exception->getMessage()]);
        }
        if ($relative === '' || str_starts_with($path, '/') || str_contains($relative, "\0")) {
            throw new ToolException('EDIT_PATH_INVALID', 'Edit path must be a non-empty project-relative path');
        }
        $allowed = false;
        foreach ((array) $this->config->get('editing.allowed_roots', ['app/code', 'dev/ai/mcp']) as $root) {
            $root = trim(str_replace('\\', '/', (string) $root), '/');
            if ($relative === $root || str_starts_with($relative, $root . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new ToolException('EDIT_PATH_DENIED', 'Edit path is outside configured allowed roots', false, ['path' => $relative]);
        }
        foreach ((array) $this->config->get('editing.denied_paths', []) as $pattern) {
            if (Text::globMatches((string) $pattern, $relative)) {
                throw new ToolException('EDIT_PATH_DENIED', 'Edit path matches a denied path rule', false, ['path' => $relative]);
            }
        }
        foreach (['.git/', '.codex/', '.agents/', '.gitnexus/', 'generated/', 'vendor/'] as $deniedPrefix) {
            if (str_starts_with($relative, $deniedPrefix) || str_contains('/' . $relative . '/', '/' . trim($deniedPrefix, '/') . '/')) {
                throw new ToolException('EDIT_PATH_DENIED', 'Edit path is security-sensitive', false, ['path' => $relative]);
            }
        }
        if (preg_match('~(?:^|/)view/tpl(?:/|$)~i', $relative) === 1) {
            throw new ToolException('EDIT_PATH_DENIED', 'Template directories are excluded from local editing', false, ['path' => $relative]);
        }
        return $relative;
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        $cursor = $this->index->root();
        foreach (explode('/', $path) as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                throw new ToolException('EDIT_SYMLINK_FORBIDDEN', 'Edit paths cannot traverse symbolic links', false, ['path' => $path]);
            }
        }
    }

    /** @param array<string, mixed> $operation */
    private function requiredExpectedFileHash(array $operation): string
    {
        $expected = trim((string) ($operation['expected_file_sha256'] ?? ''));
        if ($expected === '' || preg_match('/^(?:sha256:)?[a-f0-9]{64}$/iD', $expected) !== 1) {
            throw new ToolException('EDIT_HASH_REQUIRED', 'Existing-file operations require expected_file_sha256');
        }
        return $expected;
    }

    private function plainHash(string $hash): string
    {
        return strtolower(str_starts_with(strtolower($hash), 'sha256:') ? substr($hash, 7) : $hash);
    }

    private function hashEquals(string $expected, string $actual): bool
    {
        $expected = $this->plainHash($expected);
        $actual = $this->plainHash($actual);
        return strlen($expected) === 64 && strlen($actual) === 64 && hash_equals($expected, $actual);
    }

    /** @param array<string, mixed> $source */
    private function textValue(array $source, string $key, bool $allowEmpty = true): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || (!$allowEmpty && $value === '') || str_contains((string) $value, "\0")) {
            throw new ToolException('EDIT_PLAN_INVALID', $key . ' must be valid text');
        }
        return $value;
    }

    private function assertFileBudget(string $path, string $content): void
    {
        if (str_contains($content, "\0")) {
            throw new ToolException('EDIT_BINARY_FORBIDDEN', 'Binary output is not supported', false, ['path' => $path]);
        }
        if (strlen($content) > $this->maxFileBytes()) {
            throw new ToolException('EDIT_BUDGET_EXCEEDED', 'Output exceeds the per-file byte limit', false, ['path' => $path]);
        }
    }

    private function assertAllowedExtension(string $path): void
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $allowed = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(ltrim(trim((string) $value), '.')),
            (array) $this->config->get('index.allowed_extensions', []),
        )));
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new ToolException(
                'EDIT_EXTENSION_DENIED',
                'Created files must use an extension allowed by the project index',
                false,
                ['path' => $path, 'extension' => $extension],
            );
        }
    }

    private function maxFiles(): int
    {
        return max(1, min(200, (int) $this->config->get('editing.max_files', 20)));
    }

    private function maxFileBytes(): int
    {
        return max(1_024, min(16_777_216, (int) $this->config->get('editing.max_file_bytes', 1_048_576)));
    }

    private function maxTotalBytes(): int
    {
        return max(1_024, min(67_108_864, (int) $this->config->get('editing.max_total_bytes', 4_194_304)));
    }

    private function ttlSeconds(): int
    {
        try {
            return min(86_400, max(30, $this->config->duration('editing.ticket_ttl')));
        } catch (Throwable) {
            return 600;
        }
    }

    private function journalDirectory(string $transactionId): string
    {
        $root = rtrim($this->config->dataDir(), '/') . '/edit-journal';
        $project = $root . '/' . hash('sha256', $this->index->projectId());
        $directory = $project . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $transactionId);
        foreach ([$root, $project, $directory] as $candidate) {
            if (is_link($candidate)) {
                throw new ToolException('EDIT_JOURNAL_UNSAFE', 'Edit journal cannot use symbolic links');
            }
            if (!is_dir($candidate) && !mkdir($candidate, 0700, false) && !is_dir($candidate)) {
                throw new ToolException('EDIT_JOURNAL_FAILED', 'Unable to create private edit journal');
            }
            @chmod($candidate, 0700);
        }
        return $directory;
    }

    private function writeJournal(string $path, string $content): void
    {
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new ToolException('EDIT_JOURNAL_FAILED', 'Unable to create edit journal snapshot');
        }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $content) !== strlen($content)) {
                throw new ToolException('EDIT_JOURNAL_FAILED', 'Unable to write edit journal snapshot');
            }
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    private function readJournal(string $path, string $expectedHash): string
    {
        $journalRoot = realpath(rtrim($this->config->dataDir(), '/') . '/edit-journal');
        $resolved = realpath($path);
        if (!is_string($journalRoot) || !is_string($resolved) || is_link($path)
            || ($resolved !== $journalRoot && !str_starts_with($resolved, $journalRoot . '/'))) {
            throw new ToolException('EDIT_JOURNAL_UNSAFE', 'Edit journal reference is outside the private journal');
        }
        $content = file_get_contents($resolved);
        if (!is_string($content) || !$this->hashEquals($expectedHash, hash('sha256', $content))) {
            throw new ToolException('EDIT_JOURNAL_CORRUPT', 'Edit journal snapshot hash is invalid');
        }
        return $content;
    }

    /** @return list<string> */
    private function missingParentDirectories(string $path): array
    {
        $parent = dirname($path);
        if ($parent === '.' || $parent === '') {
            return [];
        }
        $missing = [];
        $cursor = '';
        foreach (explode('/', $parent) as $segment) {
            $cursor = $cursor === '' ? $segment : $cursor . '/' . $segment;
            $absolute = $this->index->root() . '/' . $cursor;
            if (!file_exists($absolute)) {
                $missing[] = $cursor;
            } elseif (!is_dir($absolute) || is_link($absolute)) {
                throw new ToolException('EDIT_PARENT_INVALID', 'Edit parent path is not a regular directory', false, ['path' => $cursor]);
            }
        }
        return $missing;
    }

    /** @param list<string> $directories */
    private function createParentDirectories(array $directories): void
    {
        foreach ($directories as $relative) {
            $relative = $this->safePath($relative . '/.placeholder');
            $relative = dirname($relative);
            $absolute = $this->index->root() . '/' . $relative;
            if (is_link($absolute)) {
                throw new ToolException('EDIT_SYMLINK_FORBIDDEN', 'Edit parent directory cannot be a symbolic link');
            }
            if (!is_dir($absolute) && !mkdir($absolute, 0755, false) && !is_dir($absolute)) {
                throw new ToolException('EDIT_PARENT_CREATE_FAILED', 'Unable to create edit parent directory');
            }
        }
    }

    /** @param list<string> $directories */
    private function removeEmptyDirectories(array $directories): void
    {
        foreach (array_reverse($directories) as $relative) {
            $absolute = $this->index->root() . '/' . $relative;
            if (is_dir($absolute) && !is_link($absolute)) {
                @rmdir($absolute);
            }
        }
    }

    /**
     * Stage one same-directory temporary file per target. Same-path operations
     * have already been merged into one snapshot by prepare(), so only distinct
     * target files are eligible for concurrent writes.
     *
     * @param list<array<string, mixed>> $snapshots
     * @return array{
     *     files: array<string, array<string, mixed>>,
     *     strategy: string,
     *     workers: int,
     *     fork_fallbacks: int
     * }
     */
    private function stageSnapshots(array $snapshots): array
    {
        $staged = [];
        try {
            foreach ($snapshots as $snapshot) {
                $path = $this->safePath((string) $snapshot['path']);
                if (isset($staged[$path])) {
                    throw new ToolException(
                        'EDIT_PLAN_CONFLICT',
                        'A compact transaction must contain only one postimage per target path',
                        false,
                        ['path' => $path],
                    );
                }
                $this->assertNoSymlinkComponents($path);
                $directories = is_array($snapshot['missing_parent_dirs'] ?? null)
                    ? $snapshot['missing_parent_dirs']
                    : [];
                $this->createParentDirectories($directories);

                $target = $this->index->root() . '/' . $path;
                $parent = dirname($target);
                if (!is_dir($parent) || is_link($parent)) {
                    throw new ToolException('EDIT_PARENT_INVALID', 'Atomic write parent is invalid', false, ['path' => $path]);
                }
                $post = $this->readJournal(
                    (string) $snapshot['after_ref'],
                    (string) $snapshot['post_sha256'],
                );
                $temporary = tempnam($parent, '.learning-mcp-stage-');
                if (!is_string($temporary)) {
                    throw new ToolException(
                        'EDIT_WRITE_FAILED',
                        'Unable to allocate a same-directory staged file',
                        false,
                        ['path' => $path],
                    );
                }
                $staged[$path] = [
                    'path' => $path,
                    'target' => $target,
                    'temporary' => $temporary,
                    'error_ref' => $temporary . '.error',
                    'content' => $post,
                    'post_sha256' => (string) $snapshot['post_sha256'],
                    'mode' => (int) ($snapshot['mode'] ?? 0644),
                ];
            }

            $canFork = count($staged) > 1
                && function_exists('pcntl_fork')
                && function_exists('pcntl_waitpid')
                && function_exists('pcntl_wifexited')
                && function_exists('pcntl_wexitstatus');
            $workers = $canFork ? min(self::MAX_PARALLEL_STAGE_WORKERS, count($staged)) : 1;
            $forkFallbacks = 0;
            if ($canFork) {
                $forkFallbacks = $this->writeStagesInParallel(array_values($staged), $workers);
            } else {
                foreach ($staged as $stage) {
                    $this->writeStagedFile($stage);
                }
            }

            foreach ($staged as &$stage) {
                $actualHash = hash_file('sha256', (string) $stage['temporary']);
                if (!is_string($actualHash) || !$this->hashEquals((string) $stage['post_sha256'], $actualHash)) {
                    throw new ToolException(
                        'EDIT_STAGE_HASH_MISMATCH',
                        'A staged postimage failed its hash verification',
                        false,
                        ['path' => $stage['path']],
                    );
                }
                unset($stage['content']);
            }
            unset($stage);

            return [
                'files' => $staged,
                'strategy' => $canFork ? 'bounded_parallel_temp_stage' : 'sequential_temp_stage',
                'workers' => $workers,
                'fork_fallbacks' => $forkFallbacks,
            ];
        } catch (Throwable $exception) {
            $this->cleanupStagedSnapshots($staged);
            $this->cleanupSnapshotDirectories($snapshots);
            throw $exception;
        }
    }

    /**
     * @param list<array<string, mixed>> $stages
     */
    private function writeStagesInParallel(array $stages, int $workers): int
    {
        $forkFallbacks = 0;
        foreach (array_chunk($stages, max(1, $workers)) as $batch) {
            $children = [];
            $fallback = [];
            foreach ($batch as $stage) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $fallback[] = $stage;
                    continue;
                }
                if ($pid === 0) {
                    $exitCode = 0;
                    try {
                        $this->writeStagedFile($stage);
                    } catch (Throwable $exception) {
                        $message = Text::truncate($exception->getMessage(), 1_000);
                        @file_put_contents((string) $stage['error_ref'], $message, LOCK_EX);
                        @chmod((string) $stage['error_ref'], 0600);
                        $exitCode = 1;
                    }
                    exit($exitCode);
                }
                $children[$pid] = $stage;
            }

            $failures = [];
            foreach ($children as $pid => $stage) {
                $status = 0;
                $waited = pcntl_waitpid($pid, $status);
                $succeeded = $waited === $pid
                    && pcntl_wifexited($status)
                    && pcntl_wexitstatus($status) === 0;
                $errorRef = (string) $stage['error_ref'];
                if (!$succeeded) {
                    $message = is_file($errorRef) ? trim((string) file_get_contents($errorRef)) : '';
                    $failures[] = [
                        'path' => (string) $stage['path'],
                        'error' => $message !== '' ? Text::truncate($message, 1_000) : 'Staging worker exited unsuccessfully',
                    ];
                }
                if (is_file($errorRef)) {
                    @unlink($errorRef);
                }
            }
            if ($failures !== []) {
                throw new ToolException(
                    'EDIT_STAGE_FAILED',
                    'One or more parallel staging workers failed',
                    false,
                    ['failures' => $failures],
                );
            }
            foreach ($fallback as $stage) {
                $this->writeStagedFile($stage);
                $forkFallbacks++;
            }
        }

        return $forkFallbacks;
    }

    /** @param array<string, mixed> $stage */
    private function writeStagedFile(array $stage): void
    {
        $temporary = (string) $stage['temporary'];
        if (!is_file($temporary) || is_link($temporary)) {
            throw new ToolException('EDIT_WRITE_FAILED', 'Atomic staged file is unavailable');
        }
        $handle = @fopen($temporary, 'wb');
        if (!is_resource($handle)) {
            throw new ToolException('EDIT_WRITE_FAILED', 'Unable to open the staged postimage');
        }
        try {
            $remaining = (string) $stage['content'];
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new ToolException('EDIT_WRITE_FAILED', 'Unable to write the staged postimage');
                }
                $remaining = (string) substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ToolException('EDIT_WRITE_FAILED', 'Unable to flush the staged postimage');
            }
            fclose($handle);
            $handle = null;
            if (!chmod($temporary, ((int) $stage['mode']) & 0777)) {
                throw new ToolException('EDIT_WRITE_FAILED', 'Unable to set staged postimage permissions');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Commit one already verified staged file. Renames remain ordered in the
     * parent process so a batch has one deterministic journal/rollback order.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $stage
     */
    private function commitStagedSnapshot(array $snapshot, array $stage): void
    {
        $path = $this->safePath((string) $snapshot['path']);
        if ($stage === [] || !hash_equals($path, (string) ($stage['path'] ?? ''))) {
            throw new ToolException('EDIT_STAGE_MISSING', 'The staged postimage is unavailable', false, ['path' => $path]);
        }
        $this->assertNoSymlinkComponents($path);
        $target = $this->index->root() . '/' . $path;
        if (is_link($target)) {
            throw new ToolException('EDIT_SYMLINK_FORBIDDEN', 'Atomic write target cannot be a symbolic link');
        }
        $expectedCurrentHash = $snapshot['action'] === 'create' ? null : (string) $snapshot['pre_sha256'];
        if ($expectedCurrentHash === null) {
            if (file_exists($target)) {
                throw new ToolException('EDIT_FILE_STALE', 'Atomic create target now exists', true, ['path' => $path]);
            }
        } else {
            if (!is_file($target)) {
                throw new ToolException('EDIT_FILE_STALE', 'Atomic write target is missing', true, ['path' => $path]);
            }
            $currentHash = hash_file('sha256', $target);
            if (!is_string($currentHash) || !$this->hashEquals($expectedCurrentHash, $currentHash)) {
                throw new ToolException(
                    'EDIT_FILE_STALE',
                    'Atomic write target changed after preparation',
                    true,
                    ['path' => $path],
                );
            }
        }

        $temporary = (string) $stage['temporary'];
        $stagedHash = is_file($temporary) && !is_link($temporary)
            ? hash_file('sha256', $temporary)
            : false;
        if (!is_string($stagedHash) || !$this->hashEquals((string) $snapshot['post_sha256'], $stagedHash)) {
            throw new ToolException('EDIT_STAGE_HASH_MISMATCH', 'Staged postimage changed before commit', false, ['path' => $path]);
        }
        if (!rename($temporary, $target)) {
            throw new ToolException('EDIT_WRITE_FAILED', 'Unable to atomically replace edit target', false, ['path' => $path]);
        }
    }

    /** @param array<string, array<string, mixed>> $staged */
    private function cleanupStagedSnapshots(array $staged): void
    {
        foreach ($staged as $stage) {
            foreach (['temporary', 'error_ref'] as $key) {
                $path = (string) ($stage[$key] ?? '');
                if ($path !== '' && file_exists($path) && !is_link($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $snapshots */
    private function cleanupSnapshotDirectories(array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            $directories = is_array($snapshot['missing_parent_dirs'] ?? null)
                ? $snapshot['missing_parent_dirs']
                : [];
            $this->removeEmptyDirectories($directories);
        }
    }

    /** @param list<array<string, mixed>> $snapshots
     *  @return list<array<string, string>>
     */
    private function restoreSnapshots(array $snapshots, bool $duringFailedApply): array
    {
        $errors = [];
        foreach ($snapshots as $snapshot) {
            try {
                $path = $this->safePath((string) $snapshot['path']);
                $absolute = $this->index->root() . '/' . $path;
                if ($snapshot['action'] === 'create') {
                    if (is_file($absolute) && $this->hashEquals((string) $snapshot['post_sha256'], hash_file('sha256', $absolute) ?: '')) {
                        if (!unlink($absolute)) {
                            throw new RuntimeException('Unable to remove created file');
                        }
                    } elseif (file_exists($absolute)) {
                        throw new RuntimeException('Created file no longer matches postimage');
                    }
                    $this->removeEmptyDirectories((array) ($snapshot['missing_parent_dirs'] ?? []));
                    continue;
                }
                $before = $this->readJournal((string) $snapshot['before_ref'], (string) $snapshot['pre_sha256']);
                $currentHash = is_file($absolute) ? hash_file('sha256', $absolute) : false;
                if (is_string($currentHash) && $this->hashEquals((string) $snapshot['pre_sha256'], $currentHash)) {
                    continue;
                }
                $this->atomicWrite($absolute, $before, (int) $snapshot['mode'], (string) $snapshot['post_sha256']);
            } catch (Throwable $exception) {
                $errors[] = ['path' => (string) ($snapshot['path'] ?? ''), 'error' => $exception->getMessage()];
                if (!$duringFailedApply) {
                    continue;
                }
            }
        }
        return $errors;
    }

    private function atomicWrite(string $target, string $content, int $mode, ?string $expectedCurrentHash): void
    {
        if (is_link($target)) {
            throw new ToolException('EDIT_SYMLINK_FORBIDDEN', 'Atomic write target cannot be a symbolic link');
        }
        if ($expectedCurrentHash === null) {
            if (file_exists($target)) {
                throw new ToolException('EDIT_FILE_STALE', 'Atomic create target now exists', true);
            }
        } else {
            if (!is_file($target)) {
                throw new ToolException('EDIT_FILE_STALE', 'Atomic write target is missing', true);
            }
            $currentHash = hash_file('sha256', $target);
            if (!is_string($currentHash) || !$this->hashEquals($expectedCurrentHash, $currentHash)) {
                throw new ToolException('EDIT_FILE_STALE', 'Atomic write target changed after preparation', true);
            }
        }
        $parent = dirname($target);
        if (!is_dir($parent) || is_link($parent)) {
            throw new ToolException('EDIT_PARENT_INVALID', 'Atomic write parent is invalid');
        }
        $temporary = tempnam($parent, '.learning-mcp-');
        if (!is_string($temporary)) {
            throw new ToolException('EDIT_WRITE_FAILED', 'Unable to allocate same-directory temporary file');
        }
        $handle = null;
        try {
            $handle = @fopen($temporary, 'wb');
            if (!is_resource($handle)) {
                throw new ToolException('EDIT_WRITE_FAILED', 'Unable to open the atomic temporary file');
            }
            $remaining = $content;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new ToolException('EDIT_WRITE_FAILED', 'Unable to write the atomic temporary file');
                }
                $remaining = (string) substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ToolException('EDIT_WRITE_FAILED', 'Unable to flush the atomic temporary file');
            }
            fclose($handle);
            $handle = null;
            if (!chmod($temporary, $mode & 0777) || !rename($temporary, $target)) {
                throw new ToolException('EDIT_WRITE_FAILED', 'Unable to atomically replace edit target');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param list<array<string, mixed>> $snapshots */
    private function assertWorkspaceMatches(array $snapshots, string $image): void
    {
        $mismatches = [];
        foreach ($snapshots as $snapshot) {
            $path = $this->safePath((string) $snapshot['path']);
            $this->assertNoSymlinkComponents($path);
            $absolute = $this->index->root() . '/' . $path;
            if ($image === 'pre' && $snapshot['action'] === 'create') {
                if (file_exists($absolute) || is_link($absolute)) {
                    $mismatches[] = $path;
                }
                continue;
            }
            $expected = (string) ($image === 'pre' ? $snapshot['pre_sha256'] : $snapshot['post_sha256']);
            $actual = is_file($absolute) && !is_link($absolute) ? hash_file('sha256', $absolute) : false;
            if (!is_string($actual) || !$this->hashEquals($expected, $actual)) {
                $mismatches[] = $path;
            }
        }
        if ($mismatches !== []) {
            throw new ToolException(
                $image === 'pre' ? 'EDIT_FILE_STALE' : 'EDIT_POSTIMAGE_STALE',
                'Workspace files do not match the guarded ' . $image . 'image',
                true,
                ['paths' => $mismatches],
            );
        }
    }

    /** @param list<array<string, mixed>> $snapshots
     *  @return list<array<string, mixed>>
     */
    private function snapshotPreview(array $snapshots): array
    {
        return array_map(static fn (array $snapshot): array => [
            'path' => $snapshot['path'],
            'action' => $snapshot['action'],
            'pre_sha256' => $snapshot['pre_sha256'],
            'post_sha256' => $snapshot['post_sha256'],
            'byte_delta' => (int) $snapshot['post_bytes'] - (int) $snapshot['pre_bytes'],
            'operation_count' => (int) ($snapshot['operation_count'] ?? 1),
            'stage_strategy' => $snapshot['stage_strategy'] ?? null,
            'stage_workers' => (int) ($snapshot['stage_workers'] ?? 0),
            'stage_fork_fallbacks' => (int) ($snapshot['stage_fork_fallbacks'] ?? 0),
        ], $snapshots);
    }

    private function ensureDatabaseShape(): void
    {
        $database = $this->index->pdo();
        $this->editColumns = $this->tableColumns('edit_transactions');
        foreach ([
            'token_hash' => 'TEXT',
            'base_commit' => "TEXT NOT NULL DEFAULT ''",
            'plan_digest' => "TEXT NOT NULL DEFAULT ''",
            'request_json' => "TEXT NOT NULL DEFAULT '{}'",
            'plan_json' => "TEXT NOT NULL DEFAULT '{}'",
            'result_json' => "TEXT NOT NULL DEFAULT '{}'",
            'snapshots_json' => "TEXT NOT NULL DEFAULT '[]'",
            'error_json' => 'TEXT',
        ] as $column => $definition) {
            if (!isset($this->editColumns[$column])) {
                $database->exec('ALTER TABLE edit_transactions ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $database->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS edit_transactions_token_hash_unique '
            . 'ON edit_transactions(token_hash) WHERE token_hash IS NOT NULL'
        );
        $this->editColumns = $this->tableColumns('edit_transactions');
        $this->validationColumns = $this->tableColumns('validation_runs');
        if (!isset($this->validationColumns['result_json']) && !isset($this->validationColumns['output_redacted'])) {
            $database->exec("ALTER TABLE validation_runs ADD COLUMN result_json TEXT NOT NULL DEFAULT '{}'");
        }
        $this->validationColumns = $this->tableColumns('validation_runs');
    }

    /** @return array<string, string> */
    private function tableColumns(string $table): array
    {
        if (!in_array($table, ['edit_transactions', 'validation_runs', 'indexed_files', 'symbols'], true)) {
            throw new RuntimeException('Unsupported table metadata request');
        }
        $columns = [];
        $statement = $this->index->pdo()->query('PRAGMA table_info(' . $table . ')');
        foreach ($statement->fetchAll() as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name !== '') {
                $columns[$name] = (string) ($column['type'] ?? '');
            }
        }
        return $columns;
    }

    private function editIdColumn(): string
    {
        return isset($this->editColumns['transaction_id']) ? 'transaction_id' : 'id';
    }

    private function editStateColumn(): string
    {
        return isset($this->editColumns['status']) ? 'status' : 'state';
    }

    private function editRevisionColumn(): string
    {
        return isset($this->editColumns['base_revision']) ? 'base_revision' : 'project_revision';
    }

    private function validationIdColumn(): string
    {
        return isset($this->validationColumns['validation_id']) ? 'validation_id' : 'id';
    }

    private function validationEditColumn(): string
    {
        return isset($this->validationColumns['transaction_id']) ? 'transaction_id' : 'edit_id';
    }

    /** @param array<string, mixed> $values */
    private function insertTransaction(array $values): void
    {
        $values = array_intersect_key($values, $this->editColumns);
        $columns = array_keys($values);
        $sql = 'INSERT INTO edit_transactions(' . implode(', ', $columns) . ') VALUES('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')';
        $statement = $this->index->pdo()->prepare($sql);
        $statement->execute($values);
    }

    /** @param array<string, mixed> $values */
    private function insertValidation(array $values): void
    {
        $values = array_intersect_key($values, $this->validationColumns);
        $columns = array_keys($values);
        $sql = 'INSERT INTO validation_runs(' . implode(', ', $columns) . ') VALUES('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')';
        $statement = $this->index->pdo()->prepare($sql);
        $statement->execute($values);
    }

    /** @param array<string, mixed> $row
     *  @param array<string, mixed> $values
     */
    private function updateTransaction(array $row, array $values): void
    {
        $values['updated_at'] = Clock::now();
        $values = array_intersect_key($values, $this->editColumns);
        $assignments = [];
        foreach (array_keys($values) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }
        $values['_id'] = $row[$this->editIdColumn()];
        $statement = $this->index->pdo()->prepare(
            'UPDATE edit_transactions SET ' . implode(', ', $assignments)
            . ' WHERE ' . $this->editIdColumn() . ' = :_id'
        );
        $statement->execute($values);
    }

    /** @return array<string, mixed> */
    private function findTransactionByToken(string $token): array
    {
        $tokenHash = Ids::hash($token);
        $statement = $this->index->pdo()->prepare('SELECT * FROM edit_transactions WHERE token_hash = :token_hash LIMIT 1');
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ToolException('EDIT_TOKEN_INVALID', 'Edit apply token is invalid');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function findTransaction(string $idOrToken): array
    {
        $idOrToken = trim($idOrToken);
        if ($idOrToken === '') {
            throw new ToolException('EDIT_ID_REQUIRED', 'Edit id or token is required');
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT * FROM edit_transactions WHERE ' . $this->editIdColumn() . ' = :id LIMIT 1'
        );
        $statement->execute(['id' => $idOrToken]);
        $row = $statement->fetch();
        if (is_array($row)) {
            return $row;
        }
        return $this->findTransactionByToken($idOrToken);
    }

    /** @param array<string, mixed> $row
     *  @return list<array<string, mixed>>
     */
    private function decodeSnapshots(array $row): array
    {
        $snapshots = Json::decode((string) ($row['snapshots_json'] ?? ''), []);
        if (!is_array($snapshots) || !array_is_list($snapshots) || $snapshots === []) {
            throw new ToolException('EDIT_JOURNAL_CORRUPT', 'Edit transaction snapshots are unavailable');
        }
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot) || !isset($snapshot['path'], $snapshot['action'], $snapshot['post_sha256'])) {
                throw new ToolException('EDIT_JOURNAL_CORRUPT', 'Edit transaction contains an invalid snapshot');
            }
        }
        return $snapshots;
    }

    /** @param array<string, mixed> $row */
    private function assertTransactionFresh(array $row, bool $allowRevisionAdvance = false): void
    {
        $revision = (int) $row[$this->editRevisionColumn()];
        $currentRevision = $this->index->revision();
        $safeRevisionAdvance = $allowRevisionAdvance && $currentRevision > $revision;
        if ($revision !== $currentRevision && !$safeRevisionAdvance) {
            throw new ToolException(
                'EDIT_REVISION_STALE',
                'Project index changed after edit preparation',
                true,
                ['prepared' => $revision, 'current' => $currentRevision],
            );
        }
        $commit = $this->currentCommit();
        if (!hash_equals((string) $row['base_commit'], $commit)) {
            throw new ToolException('EDIT_COMMIT_STALE', 'Workspace commit changed after edit preparation', true);
        }
    }

    private function isExpired(string $expiresAt): bool
    {
        $timestamp = strtotime($expiresAt);
        return $timestamp === false || $timestamp < time();
    }

    private function currentCommit(): string
    {
        $state = $this->index->state();
        foreach (['head_commit', 'git_head', 'base_commit', 'commit'] as $key) {
            $value = trim((string) ($state[$key] ?? ''));
            if (preg_match('/^[a-f0-9]{7,64}$/iD', $value) === 1) {
                return $value;
            }
        }
        $result = $this->runner->run(
            ['git', '-C', $this->index->root(), 'rev-parse', '--verify', 'HEAD'],
            $this->index->root(),
            '',
            15,
            ['NO_COLOR' => '1'],
        );
        $commit = trim($result['stdout']);
        if ($result['exit_code'] !== 0 || preg_match('/^[a-f0-9]{40,64}$/iD', $commit) !== 1) {
            throw new ToolException('EDIT_COMMIT_UNAVAILABLE', 'Unable to determine the workspace HEAD commit');
        }
        return $commit;
    }

    private function withProjectLock(callable $callback): mixed
    {
        $directory = rtrim($this->config->dataDir(), '/') . '/edit-locks';
        if (is_link($directory)) {
            throw new ToolException('EDIT_LOCK_UNSAFE', 'Edit lock directory cannot be a symbolic link');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ToolException('EDIT_LOCK_FAILED', 'Unable to create edit lock directory');
        }
        @chmod($directory, 0700);
        $path = $directory . '/' . hash('sha256', $this->index->projectId()) . '.lock';
        $handle = fopen($path, 'c+b');
        if (!is_resource($handle) || !chmod($path, 0600) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ToolException('EDIT_LOCK_FAILED', 'Unable to acquire the project edit lock', true);
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function publicStatus(array $row): array
    {
        $snapshots = Json::decode((string) ($row['snapshots_json'] ?? ''), []);
        $snapshotList = is_array($snapshots) && array_is_list($snapshots) ? $snapshots : [];
        $validations = [];
        $statement = $this->index->pdo()->prepare(
            'SELECT * FROM validation_runs WHERE ' . $this->validationEditColumn() . ' = :id ORDER BY started_at DESC'
        );
        $statement->execute(['id' => $row[$this->editIdColumn()]]);
        foreach ($statement->fetchAll() as $validation) {
            $validations[] = [
                'validation_id' => $validation[$this->validationIdColumn()] ?? null,
                'profile' => $validation['profile'] ?? null,
                'status' => $validation['status'] ?? null,
                'started_at' => $validation['started_at'] ?? null,
                'completed_at' => $validation['completed_at'] ?? null,
            ];
        }
        $error = Json::decode((string) ($row['error_json'] ?? ''), null);
        $result = Json::decode((string) ($row['result_json'] ?? ''), []);
        $result = is_array($result) ? $result : [];
        $indexPending = (bool) ($result['index_pending'] ?? in_array(
            (string) $row[$this->editStateColumn()],
            ['applied_index_pending', 'rolled_back_index_pending'],
            true,
        ));
        return [
            'edit_id' => $row[$this->editIdColumn()],
            'state' => $row[$this->editStateColumn()],
            'project_revision' => (int) $row[$this->editRevisionColumn()],
            'base_commit' => $row['base_commit'] ?? '',
            'plan_digest' => $row['plan_digest'] ?? '',
            'created_at' => $row['created_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'applied_at' => $row['applied_at'] ?? null,
            'files' => $this->snapshotPreview($snapshotList),
            'validations' => $validations,
            'apply_pipeline' => [
                'strategy' => $snapshotList[0]['stage_strategy'] ?? 'not_applied',
                'workers' => (int) ($snapshotList[0]['stage_workers'] ?? 0),
                'fork_fallbacks' => (int) ($snapshotList[0]['stage_fork_fallbacks'] ?? 0),
                'file_count' => count($snapshotList),
                'operation_count' => array_sum(array_map(
                    static fn (array $snapshot): int => (int) ($snapshot['operation_count'] ?? 1),
                    $snapshotList,
                )),
                'same_file_operations' => 'merged_into_one_postimage',
                'commit' => 'ordered_atomic_rename',
            ],
            'index_refresh' => [
                'status' => $indexPending ? 'pending' : (isset($result['index_revision']) ? 'completed' : 'unknown'),
                'reason' => $result['index_reason'] ?? null,
                'index_revision' => $result['index_revision'] ?? null,
                'recoverable' => $indexPending,
            ],
            'error' => $error,
        ];
    }

    private function normalizeProfile(string $profile): string
    {
        $profile = strtolower(trim($profile));
        $profile = match ($profile) {
            'php', 'php-lint' => 'php_lint',
            'diff-check' => 'diff_check',
            'auto', 'weline_safe' => 'default',
            '' => 'default',
            default => $profile,
        };
        if (!in_array($profile, ['default', 'weline.php.module', 'php_lint', 'json', 'diff_check'], true)) {
            throw new ToolException('VALIDATION_PROFILE_UNSUPPORTED', 'Unsupported fixed validation profile', false, ['profile' => $profile]);
        }
        return $profile;
    }

    /** @param list<string> $paths
     *  @return list<array<string, mixed>>
     */
    private function validationChecks(string $profile, array $paths): array
    {
        $checks = [];
        $all = in_array($profile, ['default', 'weline.php.module'], true);
        if ($all || $profile === 'php_lint') {
            foreach ($paths as $path) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($extension, ['php', 'phtml'], true) && is_file($this->index->root() . '/' . $path)) {
                    $checks[] = [
                        'type' => 'php_lint',
                        'path' => $path,
                        'argv' => [PHP_BINARY, '-l', $this->index->root() . '/' . $path],
                    ];
                }
            }
        }
        if ($all || $profile === 'json') {
            foreach ($paths as $path) {
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json' && is_file($this->index->root() . '/' . $path)) {
                    $checks[] = ['type' => 'json', 'path' => $path, 'absolute' => $this->index->root() . '/' . $path];
                }
            }
        }
        if ($all || $profile === 'diff_check') {
            $checks[] = [
                'type' => 'diff_check',
                'argv' => array_merge(['git', '-C', $this->index->root(), 'diff', '--check', '--'], $paths),
            ];
        }
        return $checks;
    }

    /** @param array<string, mixed> $draft
     *  @return array<string, mixed>
     */
    private function redactedDraftSummary(array $draft): array
    {
        $operations = [];
        foreach ((array) ($draft['operations'] ?? []) as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $operations[] = array_filter([
                'kind' => $operation['kind'] ?? $operation['operation'] ?? null,
                'path' => $operation['path'] ?? $operation['relative_path'] ?? null,
                'expected_file_sha256' => $operation['expected_file_sha256'] ?? $operation['expected_sha256'] ?? null,
                'expected_digest' => $operation['expected_digest'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }
        return [
            'schema_version' => $draft['schema_version'] ?? null,
            'project_id' => $draft['project_id'] ?? null,
            'project_revision' => $draft['project_revision'] ?? $draft['index_revision'] ?? null,
            'base_commit' => $draft['base_commit'] ?? null,
            'operations' => $operations,
            'validation_profile' => $draft['validation_profile'] ?? null,
        ];
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private static function timestamp(int $timestamp): string
    {
        return gmdate('Y-m-d\\TH:i:s', $timestamp) . '.000Z';
    }

    private static function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
