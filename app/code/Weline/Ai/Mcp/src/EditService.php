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
    private const RECOVERY_BATCH_LIMIT = 20;

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
        if ($baseCommit === '') {
            $baseCommit = $currentCommit;
        }
        if (!hash_equals($currentCommit, $baseCommit)) {
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
                try {
                    $symbol = $this->resolveSymbol($operation);
                } catch (ToolException $exception) {
                    throw $this->operationFailureException($exception, $operation, $operationIndex);
                }
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
                $failure = $this->operationFailureException($exception, $operation, $operationIndex);
                if (!$fileHashChanged || !$allowTargetRebase) {
                    throw $failure;
                }
                throw new ToolException(
                    'EDIT_REBASE_TARGET_CHANGED',
                    'The latest file no longer contains the previously planned target',
                    true,
                    [
                        'path' => $path,
                        'operation' => $failure->details['operation'] ?? [],
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
                    $path = (string) $snapshot['path'];
                    $stage = is_array($staging['files'][$path] ?? null) ? $staging['files'][$path] : [];
                    $snapshot['stage_strategy'] = $staging['strategy'];
                    $snapshot['stage_workers'] = $staging['workers'];
                    $snapshot['stage_fork_fallbacks'] = $staging['fork_fallbacks'];
                    $snapshot['stage_temporary'] = (string) ($stage['temporary'] ?? '');
                    $snapshot['stage_error_ref'] = (string) ($stage['error_ref'] ?? '');
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
                $result = $this->transactionResult($row);
                $result['paths'] = $paths;
                $result['index_pending'] = true;
                $result['index_reason'] = 'index_error';
                $this->updateTransaction($row, [
                    'result_json' => Json::encode($result),
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
            $result = $this->transactionResult($row);
            $result['paths'] = $paths;
            $result['index_pending'] = false;
            $result['index_reason'] = null;
            $result['index_revision'] = $this->index->revision();
            $this->updateTransaction($row, [
                $this->editStateColumn() => $nextState,
                'result_json' => Json::encode($result),
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
    public function status(string $idOrToken, string $reviewCursor = ''): array
    {
        return $this->publicStatus($this->findTransaction($idOrToken), $reviewCursor);
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
        $checks = $this->validationChecks($profile, $paths, $snapshots);
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
            if ($check['type'] === 'diff_check') {
                $result = $this->transactionDiffCheck($check['snapshot']);
                $commands[] = $result['command'];
                $checkPassed = (bool) $result['passed'];
                $passed = $passed && $checkPassed;
                $results[] = [
                    'check' => 'diff_check',
                    'path' => $check['path'] ?? null,
                    'status' => $checkPassed ? 'passed' : 'failed',
                    'exit_code' => $result['exit_code'],
                    'output' => $result['output'],
                    'duration_ms' => $result['duration_ms'],
                ];
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
            if (!in_array($state, [
                'applied', 'applied_index_pending', 'validated', 'validation_failed',
                'recovery_required', 'rollback_blocked',
            ], true)) {
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
            $result = $this->transactionResult($row);
            $result['paths'] = $paths;
            $result['index_pending'] = true;
            $result['index_reason'] = 'rollback';
            $this->updateTransaction($row, [
                $this->editStateColumn() => 'rolled_back',
                'result_json' => Json::encode($result),
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
            $result['index_pending'] = false;
            $result['index_reason'] = null;
            $result['index_revision'] = $this->index->revision();
            $this->updateTransaction($row, [
                'result_json' => Json::encode($result),
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
     * Reconcile transactions left between durable state changes by a crashed
     * MCP process. File locks are acquired before the project lock, matching
     * the compact-edit lock order and preventing recovery/apply deadlocks.
     *
     * @return array<string, mixed>
     */
    public function recoverInterruptedTransactions(?string $idOrToken = null): array
    {
        $this->assertEnabled();
        $rows = $this->interruptedTransactions($idOrToken);
        $transactions = [];

        foreach ($rows as $row) {
            $editId = (string) $row[$this->editIdColumn()];
            try {
                $snapshots = $this->decodeSnapshots($row);
                $paths = [];
                foreach ($snapshots as $snapshot) {
                    $paths[] = $this->safePath((string) $snapshot['path']);
                }
                $paths = array_values(array_unique($paths));
                sort($paths, SORT_STRING);

                $transactions[] = $this->withFileLocks(
                    $paths,
                    fn (array $fileLock): array => $this->recoverInterruptedTransaction($editId, $fileLock),
                    'crash_recovery',
                );
            } catch (Throwable $exception) {
                if ($exception instanceof ToolException && $exception->errorCode === 'EDIT_LOCK_TIMEOUT') {
                    throw $exception;
                }
                [$message] = Redactor::string($exception->getMessage());
                try {
                    $this->withProjectLock(function () use ($editId, $message): void {
                        $latest = $this->findTransaction($editId);
                        if (in_array(
                            (string) $latest[$this->editStateColumn()],
                            ['applying', 'rolling_back', 'recovery_required', 'rollback_blocked'],
                            true,
                        )) {
                            $this->updateTransaction($latest, [
                                $this->editStateColumn() => 'recovery_required',
                                'error_json' => Json::encode([
                                    'crash_recovery' => [
                                        'message' => Text::truncate($message, 2_000),
                                        'checked_at' => Clock::now(),
                                    ],
                                ]),
                            ]);
                        }
                    }, 'crash_recovery');
                } catch (Throwable) {
                }
                $transactions[] = [
                    'edit_id' => $editId,
                    'outcome' => 'recovery_required',
                    'error' => Text::truncate($message, 2_000),
                ];
            }
        }

        $requiresAttention = count(array_filter(
            $transactions,
            static fn (array $transaction): bool => ($transaction['outcome'] ?? '') === 'recovery_required',
        ));
        $recovered = count(array_filter(
            $transactions,
            static fn (array $transaction): bool => !in_array(
                (string) ($transaction['outcome'] ?? ''),
                ['skipped', 'recovery_required'],
                true,
            ),
        ));
        $remaining = $this->interruptedTransactionCount();
        $hasMore = $remaining > 0;

        return [
            'status' => $requiresAttention > 0
                ? 'attention_required'
                : ($hasMore ? 'partial' : ($rows === [] ? 'clean' : 'completed')),
            'checked' => count($rows),
            'recovered' => $recovered,
            'requires_attention' => $requiresAttention > 0,
            'has_more' => $hasMore,
            'remaining' => $remaining,
            'transactions' => $transactions,
        ];
    }

    private function interruptedTransactionCount(): int
    {
        $statement = $this->index->pdo()->query(
            "SELECT COUNT(*) FROM edit_transactions WHERE " . $this->editStateColumn()
            . " IN ('applying', 'rolling_back', 'recovery_required', 'rollback_blocked')"
        );
        return max(0, (int) $statement->fetchColumn());
    }

    /** @return list<array<string, mixed>> */
    private function interruptedTransactions(?string $idOrToken): array
    {
        $states = ['applying', 'rolling_back', 'recovery_required', 'rollback_blocked'];
        if ($idOrToken !== null && trim($idOrToken) !== '') {
            $row = $this->findTransaction($idOrToken);
            return in_array((string) $row[$this->editStateColumn()], $states, true) ? [$row] : [];
        }

        $placeholders = [];
        $parameters = [];
        foreach ($states as $index => $state) {
            $placeholder = ':state_' . $index;
            $placeholders[] = $placeholder;
            $parameters['state_' . $index] = $state;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT * FROM edit_transactions WHERE ' . $this->editStateColumn()
            . ' IN (' . implode(', ', $placeholders) . ') ORDER BY updated_at ASC LIMIT '
            . self::RECOVERY_BATCH_LIMIT
        );
        $statement->execute($parameters);
        return array_values(array_filter($statement->fetchAll(), 'is_array'));
    }

    /** @param array<string, mixed> $fileLock
     *  @return array<string, mixed>
     */
    private function recoverInterruptedTransaction(string $editId, array $fileLock): array
    {
        $decision = $this->withProjectLock(function () use ($editId): array {
            $row = $this->findTransaction($editId);
            $state = (string) $row[$this->editStateColumn()];
            if (!in_array($state, ['applying', 'rolling_back', 'recovery_required', 'rollback_blocked'], true)) {
                return [
                    'edit_id' => $editId,
                    'outcome' => 'skipped',
                    'state' => $state,
                ];
            }

            $snapshots = $this->decodeSnapshots($row);
            $paths = array_values(array_map(
                static fn (array $snapshot): string => (string) $snapshot['path'],
                $snapshots,
            ));
            $classification = $this->classifySnapshotImages($snapshots);
            $this->cleanupStagedSnapshots($this->recordedStagedSnapshots($snapshots));
            $recovery = [
                'trigger' => 'process_interruption',
                'previous_state' => $state,
                'checked_at' => Clock::now(),
                'file_images' => $classification['counts'],
            ];

            if (($classification['counts']['unknown'] ?? 0) > 0) {
                $unknown = array_values(array_filter(
                    $classification['files'],
                    static fn (array $file): bool => ($file['image'] ?? '') === 'unknown',
                ));
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'recovery_required',
                    'error_json' => Json::encode([
                        'crash_recovery' => $recovery + ['unknown_files' => $unknown],
                    ]),
                ]);
                return [
                    'edit_id' => $editId,
                    'outcome' => 'recovery_required',
                    'state' => 'recovery_required',
                    'unknown_files' => $unknown,
                ];
            }

            $allPostimages = ($classification['counts']['post'] ?? 0) === count($snapshots);
            if ($state === 'applying' && $allPostimages) {
                $plan = Json::decode((string) ($row['plan_json'] ?? ''), []);
                $profile = is_array($plan) ? (string) ($plan['validation_profile'] ?? 'default') : 'default';
                $result = $this->transactionResult($row);
                $result['paths'] = $paths;
                $result['index_pending'] = true;
                $result['index_reason'] = 'crash_recovery_validation_first';
                $result['recovery'] = $recovery + ['outcome' => 'postimage_complete'];
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'applied_index_pending',
                    'applied_at' => $row['applied_at'] ?? Clock::now(),
                    'result_json' => Json::encode($result),
                    'error_json' => null,
                ]);
                return [
                    'action' => 'finalize_postimage',
                    'edit_id' => $editId,
                    'profile' => $profile,
                    'previous_state' => $state,
                ];
            }

            $errors = $this->restoreSnapshots(array_reverse($snapshots), true);
            $this->cleanupSnapshotDirectories($snapshots);
            if ($errors !== []) {
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'recovery_required',
                    'error_json' => Json::encode([
                        'crash_recovery' => $recovery + ['restore_errors' => $errors],
                    ]),
                ]);
                return [
                    'edit_id' => $editId,
                    'outcome' => 'recovery_required',
                    'state' => 'recovery_required',
                    'restore_errors' => $errors,
                ];
            }

            $result = $this->transactionResult($row);
            $result['paths'] = $paths;
            $result['index_pending'] = true;
            $result['index_reason'] = 'crash_recovery_rollback';
            $result['recovery'] = $recovery + ['outcome' => 'preimage_restored'];
            $this->updateTransaction($row, [
                $this->editStateColumn() => 'rolled_back',
                'result_json' => Json::encode($result),
                'error_json' => null,
            ]);
            return [
                'action' => 'index_preimage',
                'edit_id' => $editId,
                'paths' => $paths,
                'previous_state' => $state,
            ];
        }, 'crash_recovery');

        if (($decision['action'] ?? '') === 'finalize_postimage') {
            return $this->completeRecoveredPostimage(
                (string) $decision['edit_id'],
                (string) $decision['profile'],
                (string) $decision['previous_state'],
            );
        }
        if (($decision['action'] ?? '') === 'index_preimage') {
            return $this->completeRecoveredPreimage(
                (string) $decision['edit_id'],
                is_array($decision['paths'] ?? null) ? $decision['paths'] : [],
                (string) $decision['previous_state'],
            );
        }
        return $decision;
    }

    /** @param list<string> $paths
     *  @return array<string, mixed>
     */
    private function completeRecoveredPreimage(string $editId, array $paths, string $previousState): array
    {
        $startedAt = hrtime(true);
        try {
            $indexResult = $this->indexer->indexPaths($paths);
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            $this->withProjectLock(function () use ($editId, $message): void {
                $row = $this->findTransaction($editId);
                $result = $this->transactionResult($row);
                $result['index_pending'] = true;
                $result['index_reason'] = 'crash_recovery_index_error';
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'rolled_back_index_pending',
                    'result_json' => Json::encode($result),
                    'error_json' => Json::encode(['index_error' => Text::truncate($message, 2_000)]),
                ]);
            }, 'crash_recovery');
            $status = $this->status($editId);
            $status['outcome'] = 'rolled_back_index_pending';
            $status['previous_state'] = $previousState;
            $status['index_refresh']['duration_ms'] = self::elapsedMilliseconds($startedAt);
            return $status;
        }

        $this->withProjectLock(function () use ($editId): void {
            $row = $this->findTransaction($editId);
            $result = $this->transactionResult($row);
            $result['index_pending'] = false;
            $result['index_reason'] = null;
            $result['index_revision'] = $this->index->revision();
            $this->updateTransaction($row, [
                $this->editStateColumn() => 'rolled_back',
                'result_json' => Json::encode($result),
                'error_json' => null,
            ]);
        }, 'crash_recovery');
        $status = $this->status($editId);
        $status['outcome'] = 'rolled_back';
        $status['previous_state'] = $previousState;
        $status['index_refresh'] = [
            'status' => 'completed',
            'result' => $indexResult,
            'duration_ms' => self::elapsedMilliseconds($startedAt),
        ];
        return $status;
    }

    /** @return array<string, mixed> */
    private function completeRecoveredPostimage(string $editId, string $profile, string $previousState): array
    {
        try {
            $validation = $this->validate([
                'edit_id' => $editId,
                'profile' => $profile,
            ]);
            if (($validation['status'] ?? '') !== 'passed') {
                $status = $this->rollback($editId);
                $status['outcome'] = 'rolled_back_after_recovered_validation_failure';
                $status['previous_state'] = $previousState;
                $status['recovered_validation'] = [
                    'id' => $validation['validation_id'] ?? null,
                    'profile' => $validation['profile'] ?? $profile,
                    'status' => $validation['status'] ?? 'failed',
                ];
                return $status;
            }

            $indexRefresh = $this->refreshIndex($editId);
            $status = $this->status($editId);
            $status['outcome'] = 'postimage_validated';
            $status['previous_state'] = $previousState;
            $status['recovered_validation'] = [
                'id' => $validation['validation_id'] ?? null,
                'profile' => $validation['profile'] ?? $profile,
                'status' => 'passed',
            ];
            $status['index_refresh'] = $indexRefresh;
            return $status;
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            $this->withProjectLock(function () use ($editId, $message): void {
                $row = $this->findTransaction($editId);
                $this->updateTransaction($row, [
                    $this->editStateColumn() => 'recovery_required',
                    'error_json' => Json::encode([
                        'crash_recovery' => [
                            'message' => Text::truncate($message, 2_000),
                            'checked_at' => Clock::now(),
                        ],
                    ]),
                ]);
            }, 'crash_recovery');
            throw new ToolException(
                'EDIT_RECOVERY_FAILED',
                'Interrupted edit postimage recovery failed and requires inspection',
                false,
                ['edit_id' => $editId],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return array{counts:array{pre:int,post:int,unknown:int},files:list<array<string, mixed>>}
     */
    private function classifySnapshotImages(array $snapshots): array
    {
        $counts = ['pre' => 0, 'post' => 0, 'unknown' => 0];
        $files = [];
        foreach ($snapshots as $snapshot) {
            $path = $this->safePath((string) $snapshot['path']);
            $this->assertNoSymlinkComponents($path);
            $absolute = $this->index->root() . '/' . $path;
            $actual = is_file($absolute) && !is_link($absolute) ? hash_file('sha256', $absolute) : false;
            $image = 'unknown';

            if (($snapshot['action'] ?? '') === 'create') {
                if (!file_exists($absolute) && !is_link($absolute)) {
                    $image = 'pre';
                } elseif (is_string($actual) && $this->hashEquals((string) $snapshot['post_sha256'], $actual)) {
                    $image = 'post';
                }
            } elseif (is_string($actual)) {
                if (isset($snapshot['pre_sha256']) && $this->hashEquals((string) $snapshot['pre_sha256'], $actual)) {
                    $image = 'pre';
                } elseif ($this->hashEquals((string) $snapshot['post_sha256'], $actual)) {
                    $image = 'post';
                }
            }

            $counts[$image]++;
            $files[] = [
                'path' => $path,
                'image' => $image,
                'actual_sha256' => is_string($actual) ? 'sha256:' . $actual : null,
            ];
        }
        return ['counts' => $counts, 'files' => $files];
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return array<string, array<string, mixed>>
     */
    private function recordedStagedSnapshots(array $snapshots): array
    {
        $staged = [];
        foreach ($snapshots as $snapshot) {
            $path = $this->safePath((string) $snapshot['path']);
            $temporary = (string) ($snapshot['stage_temporary'] ?? '');
            if ($temporary === '' || !str_starts_with(basename($temporary), '.learning-mcp-stage-')) {
                continue;
            }
            $targetParent = realpath(dirname($this->index->root() . '/' . $path));
            $temporaryParent = realpath(dirname($temporary));
            if (!is_string($targetParent) || !is_string($temporaryParent) || !hash_equals($targetParent, $temporaryParent)) {
                continue;
            }
            $errorRef = (string) ($snapshot['stage_error_ref'] ?? ($temporary . '.error'));
            if (!hash_equals($temporary . '.error', $errorRef)) {
                continue;
            }
            $staged[$path] = [
                'path' => $path,
                'temporary' => $temporary,
                'error_ref' => $errorRef,
            ];
        }
        return $staged;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function transactionResult(array $row): array
    {
        $result = Json::decode((string) ($row['result_json'] ?? ''), []);
        return is_array($result) ? $result : [];
    }

    /**
     * Hold every target file lock for the complete compact edit lifecycle.
     *
     * @param array<string, mixed> $draft
     * @param callable(array<string, mixed>): mixed $callback
     */
    public function withPlanFileLocks(array $draft, callable $callback): mixed
    {
        return $this->withFileLocks($this->planPaths($draft), $callback, 'apply_compact_edit');
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
    private function withFileLocks(array $paths, callable $callback, string $operation = 'edit_transaction'): mixed
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
        $owner = null;
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
                try {
                    $lock = $this->acquireExclusiveLock($handle, 'file', $path, $operation, $startedAt);
                } catch (Throwable $exception) {
                    fclose($handle);
                    throw $exception;
                }
                if ($lock['contended']) {
                    $contended[] = $path;
                }
                $owner = $lock['owner'];
                $handles[] = ['path' => $path, 'handle' => $handle];
            }

            $lockContext = [
                'strategy' => 'sorted_per_file_flock',
                'queue' => 'bounded_nonblocking_flock',
                'paths' => $paths,
                'contended_paths' => $contended,
                'wait_ms' => self::elapsedMilliseconds($startedAt),
                'timeout_ms' => $this->lockTimeoutMs(),
                'owner' => $owner,
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


    /**
     * @param resource $handle
     * @return array{contended:bool,wait_ms:int,owner:array<string, mixed>}
     */
    private function acquireExclusiveLock(
        $handle,
        string $scope,
        string $target,
        string $operation,
        int $startedAt,
    ): array {
        $timeoutMs = $this->lockTimeoutMs();
        $pollMs = $this->lockPollIntervalMs();
        $contended = false;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            $contended = true;
            $waitMs = self::elapsedMilliseconds($startedAt);
            if ($waitMs >= $timeoutMs) {
                $owner = $this->readLockOwner($handle);
                throw new ToolException(
                    'EDIT_LOCK_TIMEOUT',
                    'Timed out waiting for an active edit lock',
                    true,
                    [
                        'scope' => $scope,
                        'target' => $target,
                        'operation' => $operation,
                        'wait_ms' => $waitMs,
                        'timeout_ms' => $timeoutMs,
                        'owner' => $owner,
                        'lock_truth' => 'kernel_flock',
                        'persistent_lock_file_is_ownership' => false,
                    ],
                );
            }
            $remainingMs = max(1, $timeoutMs - $waitMs);
            usleep(min($pollMs, $remainingMs) * 1_000);
        }

        $owner = $this->currentLockOwner($scope, $target, $operation);
        $this->writeLockOwner($handle, $owner);
        return [
            'contended' => $contended,
            'wait_ms' => self::elapsedMilliseconds($startedAt),
            'owner' => $owner,
        ];
    }

    /** @return array<string, mixed> */
    private function currentLockOwner(string $scope, string $target, string $operation): array
    {
        $host = gethostname();
        return [
            'pid' => getmypid(),
            'host' => is_string($host) ? $host : '',
            'started_at' => Clock::now(),
            'project_id' => $this->index->projectId(),
            'scope' => $scope,
            'target' => $target,
            'operation' => $operation,
        ];
    }

    /** @param resource $handle
     *  @param array<string, mixed> $owner
     */
    private function writeLockOwner($handle, array $owner): void
    {
        $payload = Json::encode($owner);
        if (!@rewind($handle) || !@ftruncate($handle, 0)) {
            return;
        }
        if (@fwrite($handle, $payload) === strlen($payload)) {
            @fflush($handle);
        }
    }

    /** @param resource $handle
     *  @return array<string, mixed>|null
     */
    private function readLockOwner($handle): ?array
    {
        if (!@rewind($handle)) {
            return null;
        }
        $payload = stream_get_contents($handle, 4_096);
        if (!is_string($payload) || trim($payload) === '') {
            return null;
        }
        try {
            $owner = Json::decode($payload, []);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($owner)) {
            return null;
        }
        $owner['process_likely_alive'] = $this->lockOwnerProcessLikelyAlive($owner);
        return $owner;
    }

    /** @param array<string, mixed> $owner */
    private function lockOwnerProcessLikelyAlive(array $owner): ?bool
    {
        $pid = (int) ($owner['pid'] ?? 0);
        $ownerHost = trim((string) ($owner['host'] ?? ''));
        $host = gethostname();
        if ($pid < 1 || !is_string($host) || $ownerHost === '' || !hash_equals($host, $ownerHost)) {
            return null;
        }
        if ($pid === getmypid()) {
            return true;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return null;
    }

    private function lockTimeoutMs(): int
    {
        return max(100, min(300_000, (int) $this->config->get('editing.lock_timeout_ms', 30_000)));
    }

    private function lockPollIntervalMs(): int
    {
        return max(5, min(
            $this->lockTimeoutMs(),
            (int) $this->config->get('editing.lock_poll_interval_ms', 50),
        ));
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
        foreach (['op_id', 'symbol_uid', 'target_ref', 'heading', 'search'] as $key) {
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
    private function operationFailureException(
        ToolException $exception,
        array $operation,
        int $operationIndex,
    ): ToolException {
        $target = ['operation_index' => $operationIndex];
        foreach (['op_id', 'kind', 'path', 'symbol_uid', 'target_ref', 'heading'] as $key) {
            $value = $operation[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $target[$key] = trim($value);
            }
        }
        $details = $exception->details;
        $details['operation'] = $target;

        return new ToolException(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->retryable,
            $details,
        );
    }

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
        foreach (['op_id', 'symbol_uid', 'target_ref', 'heading', 'expected_digest', 'occurrence'] as $key) {
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
        $allowedRoots = [];
        foreach ((array) $this->config->get('editing.allowed_roots', ['.']) as $root) {
            $root = trim(str_replace('\\', '/', (string) $root));
            $root = preg_replace('~^\./+~', '', $root) ?? $root;
            $root = trim($root, '/');
            if ($root === '') {
                continue;
            }
            $allowedRoots[] = $root;
            if ($root === '.' || $relative === $root || str_starts_with($relative, $root . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new ToolException(
                'EDIT_PATH_DENIED',
                'Edit path is outside the configured repository edit policy; filesystem permissions were not evaluated',
                false,
                [
                    'path' => $relative,
                    'policy_reason' => 'outside_configured_allowed_roots',
                    'allowed_roots' => $allowedRoots,
                    'filesystem_permissions_evaluated' => false,
                ],
            );
        }
        foreach (['.git/', '.codex/', '.agents/', '.gitnexus/', 'generated/', 'vendor/'] as $deniedPrefix) {
            if (str_starts_with($relative, $deniedPrefix) || str_contains('/' . $relative . '/', '/' . trim($deniedPrefix, '/') . '/')) {
                throw new ToolException(
                    'EDIT_PATH_DENIED',
                    'Edit path is security-sensitive; filesystem permissions were not evaluated',
                    false,
                    [
                        'path' => $relative,
                        'policy_reason' => 'security_sensitive_path',
                        'filesystem_permissions_evaluated' => false,
                    ],
                );
            }
        }
        if (preg_match('~(?:^|/)view/tpl(?:/|$)~i', $relative) === 1) {
            throw new ToolException(
                'EDIT_PATH_DENIED',
                'Template directories are excluded by the repository edit policy; filesystem permissions were not evaluated',
                false,
                [
                    'path' => $relative,
                    'policy_reason' => 'template_directory',
                    'filesystem_permissions_evaluated' => false,
                ],
            );
        }
        foreach ((array) $this->config->get('editing.denied_paths', []) as $pattern) {
            if (Text::globMatches((string) $pattern, $relative)) {
                throw new ToolException(
                    'EDIT_PATH_DENIED',
                    'Edit path matches a denied repository policy rule; filesystem permissions were not evaluated',
                    false,
                    [
                        'path' => $relative,
                        'policy_reason' => 'denied_path_rule',
                        'matched_rule' => (string) $pattern,
                        'filesystem_permissions_evaluated' => false,
                    ],
                );
            }
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
    /**
     * Build a bounded, redacted report from the sealed journal images.
     *
     * @param list<array<string, mixed>> $snapshots
     * @return array<string, mixed>
     */
    private function changeReport(
        array $snapshots,
        string $state,
        string $reviewCursor,
        string $cursorKey,
    ): array
    {
        $totalDiffLimit = 32_000;
        $fileDiffLimit = 8_000;
        $diffFileLimit = 20;
        $snapshotDigest = $this->reviewSnapshotDigest($snapshots);
        [$startFileIndex, $startDiffOffset] = $this->decodeReviewCursor(
            $reviewCursor,
            $snapshotDigest,
            count($snapshots),
            $cursorKey,
        );
        $remainingDiffBytes = $totalDiffLimit;
        $insertions = 0;
        $deletions = 0;
        $binaryFiles = 0;
        $unavailableFiles = 0;
        $diffs = [];
        $files = [];
        $pagePaths = [];
        $includedChunks = 0;
        $nextCursor = '';
        $pageClosed = false;

        foreach ($snapshots as $fileIndex => $snapshot) {
            $diffOffset = $fileIndex === $startFileIndex ? $startDiffOffset : 0;
            $inReviewRange = $fileIndex >= $startFileIndex && !$pageClosed;
            if ($inReviewRange
                && $includedChunks > 0
                && ($remainingDiffBytes < $fileDiffLimit || $includedChunks >= $diffFileLimit)) {
                $nextCursor = $this->encodeReviewCursor(
                    $fileIndex,
                    $diffOffset,
                    $snapshotDigest,
                    $cursorKey,
                );
                $pageClosed = true;
                $inReviewRange = false;
            }
            $previewLimit = $inReviewRange ? min($fileDiffLimit, $remainingDiffBytes) : 0;
            try {
                $detail = $this->snapshotDiff($snapshot, $previewLimit, $diffOffset);
            } catch (Throwable $exception) {
                [$message] = Redactor::string($exception->getMessage());
                $detail = [
                    'path' => (string) ($snapshot['path'] ?? ''),
                    'status' => ($snapshot['action'] ?? '') === 'create' ? 'created' : 'modified',
                    'available' => false,
                    'insertions' => null,
                    'deletions' => null,
                    'changed_lines' => null,
                    'hunks' => null,
                    'binary' => false,
                    'diff_truncated' => false,
                    'diff_page_has_more' => false,
                    'diff_offset' => $diffOffset,
                    'diff_next_offset' => $diffOffset,
                    'diff_total_bytes' => null,
                    'error' => Text::truncate($message, 500),
                    'diff' => '',
                ];
            }

            if ((bool) ($detail['available'] ?? false)) {
                $insertions += (int) ($detail['insertions'] ?? 0);
                $deletions += (int) ($detail['deletions'] ?? 0);
                $binaryFiles += (bool) ($detail['binary'] ?? false) ? 1 : 0;
            } else {
                $unavailableFiles++;
            }

            $diff = (string) ($detail['diff'] ?? '');
            $detail['diff'] = '';
            $included = $inReviewRange && $diff !== '';
            if ($included) {
                $diffs[] = $diff;
                $detail['diff'] = $diff;
                $remainingDiffBytes = max(0, $remainingDiffBytes - strlen($diff));
                $includedChunks++;
                $pagePaths[] = (string) ($detail['path'] ?? '');
            }
            $detail['diff_included'] = $included;
            $detail['review_file_index'] = $fileIndex;
            if (!$inReviewRange) {
                $detail['diff_truncated'] = false;
                $detail['diff_page_has_more'] = false;
            } elseif ((bool) ($detail['diff_page_has_more'] ?? false)) {
                $nextOffset = (int) ($detail['diff_next_offset'] ?? $diffOffset);
                if ($nextOffset <= $diffOffset) {
                    throw new ToolException(
                        'REVIEW_CURSOR_STALLED',
                        'The bounded review page did not advance its sealed diff cursor',
                        false,
                        ['path' => $detail['path'] ?? '', 'offset' => $diffOffset],
                    );
                }
                $nextCursor = $this->encodeReviewCursor(
                    $fileIndex,
                    $nextOffset,
                    $snapshotDigest,
                    $cursorKey,
                );
                $pageClosed = true;
            } elseif ($fileIndex + 1 < count($snapshots)
                && ($remainingDiffBytes < $fileDiffLimit || $includedChunks >= $diffFileLimit)) {
                $nextCursor = $this->encodeReviewCursor(
                    $fileIndex + 1,
                    0,
                    $snapshotDigest,
                    $cursorKey,
                );
                $pageClosed = true;
            }
            $files[] = $detail;
        }

        $workspaceEffect = match ($state) {
            'applied', 'applied_index_pending', 'validated' => 'applied',
            'validation_failed' => 'applied_validation_failed',
            'rolled_back', 'rolled_back_index_pending' => 'rolled_back',
            'prepared' => 'preview',
            default => 'pending_or_unknown',
        };
        $hasMore = $nextCursor !== '';
        $unrecoverable = $unavailableFiles > 0 || $binaryFiles > 0;

        return [
            'summary' => sprintf(
                '%d files changed, %d insertions(+), %d deletions(-)',
                count($snapshots),
                $insertions,
                $deletions,
            ),
            'workspace_effect' => $workspaceEffect,
            'files_changed' => count($snapshots),
            'insertions' => $insertions,
            'deletions' => $deletions,
            'changed_lines' => $insertions + $deletions,
            'binary_files' => $binaryFiles,
            'unavailable_files' => $unavailableFiles,
            'files' => $files,
            'unified_diff' => implode("\n", $diffs),
            'diff_truncated' => $hasMore,
            'review_contract' => [
                'mode' => $hasMore || $reviewCursor !== ''
                    ? 'cursor_paged_all_changed_files'
                    : 'all_changed_files',
                'source' => 'sealed_preimage_postimage',
                'changed_paths' => array_values(array_map(
                    static fn (array $file): string => (string) ($file['path'] ?? ''),
                    $files,
                )),
                'page_paths' => array_values(array_unique(array_filter($pagePaths))),
                'require_all_files' => true,
                'complete' => !$hasMore && !$unrecoverable,
                'has_more' => $hasMore,
                'current_cursor' => $reviewCursor,
                'next_cursor' => $nextCursor,
                'continuation_tool' => $hasMore ? 'get_edit_status' : null,
                'continuation_argument' => $hasMore ? ['review_cursor' => $nextCursor] : null,
                'cursor_schema' => 'sealed-review-cursor.v1',
                'page_file_index' => $startFileIndex,
                'page_diff_offset' => $startDiffOffset,
                'page_chunk_count' => $includedChunks,
                'unrecoverable_paths' => array_values(array_map(
                    static fn (array $file): string => (string) ($file['path'] ?? ''),
                    array_filter(
                        $files,
                        static fn (array $file): bool => !(bool) ($file['available'] ?? false)
                            || (bool) ($file['binary'] ?? false),
                    ),
                )),
                'finding_order' => ['critical', 'high', 'medium', 'low'],
                'finding_fields' => ['severity', 'path', 'line', 'rationale', 'suggested_fix'],
                'no_findings' => 'State explicitly that no findings were found and identify any residual validation gaps.',
                'follow_up' => $hasMore
                    ? 'Call get_edit_status with the same edit id and exact next_cursor until complete=true. This is one logical MCP review stream, not a per-file fallback.'
                    : 'If fixes are needed, combine all fixes into one new edit-plan.v1 transaction.',
            ],
            'limits' => [
                'total_diff_bytes' => $totalDiffLimit,
                'per_file_diff_bytes' => $fileDiffLimit,
                'diff_files' => $diffFileLimit,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function snapshotDiff(array $snapshot, int $previewLimit, int $bodyOffset = 0): array
    {
        $path = $this->safePath((string) ($snapshot['path'] ?? ''));
        $action = (string) ($snapshot['action'] ?? 'modify');
        $afterReference = (string) ($snapshot['after_ref'] ?? '');
        $this->readJournal($afterReference, (string) ($snapshot['post_sha256'] ?? ''));

        $temporaryBefore = null;
        $beforeReference = (string) ($snapshot['before_ref'] ?? '');
        if ($action === 'create') {
            $temporaryBefore = tempnam(dirname($afterReference), '.change-report-empty-');
            if (!is_string($temporaryBefore)) {
                throw new RuntimeException('Unable to allocate the change report preimage');
            }
            $beforeReference = $temporaryBefore;
        } else {
            $this->readJournal($beforeReference, (string) ($snapshot['pre_sha256'] ?? ''));
        }

        $output = tempnam(dirname($afterReference), '.change-report-diff-');
        if (!is_string($output)) {
            if (is_string($temporaryBefore)) {
                @unlink($temporaryBefore);
            }
            throw new RuntimeException('Unable to allocate the change report output');
        }

        try {
            $pipes = [];
            $process = proc_open(
                [
                    'git',
                    '--no-pager',
                    'diff',
                    '--no-index',
                    '--no-color',
                    '--no-ext-diff',
                    '--no-textconv',
                    '--unified=3',
                    '--',
                    $beforeReference,
                    $afterReference,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $output, 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $this->index->root(),
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start the fixed git diff command');
            }
            fclose($pipes[0]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if (!in_array($exitCode, [0, 1], true)) {
                [$safeError] = Redactor::string(is_string($error) ? $error : '');
                throw new RuntimeException('Fixed git diff failed: ' . Text::truncate(trim($safeError), 500));
            }

            return $this->parseSnapshotDiff($output, $path, $action, $previewLimit, $bodyOffset);
        } finally {
            @unlink($output);
            if (is_string($temporaryBefore)) {
                @unlink($temporaryBefore);
            }
        }
    }

    /** @return array<string, mixed> */
    private function parseSnapshotDiff(
        string $output,
        string $path,
        string $action,
        int $previewLimit,
        int $bodyOffset = 0,
    ): array
    {
        $stream = fopen($output, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to read the fixed git diff output');
        }

        $insertions = 0;
        $deletions = 0;
        $hunks = 0;
        $binary = false;
        $collect = false;
        $bodyPage = '';
        $bodyBytes = 0;
        $pageFilled = false;
        $redactingPrivateKey = false;
        $headerReserve = strlen(
            'diff --git a/' . $path . ' b/' . $path . "\n"
            . '--- a/' . $path . "\n+++ b/" . $path . "\n",
        );
        $bodyPageLimit = max(0, $previewLimit - $headerReserve);

        try {
            while (($line = fgets($stream)) !== false) {
                if (str_starts_with($line, '@@ ')) {
                    $collect = true;
                    $hunks++;
                } elseif (str_starts_with($line, 'Binary files ')) {
                    $collect = true;
                    $binary = true;
                    $line = 'Binary files a/' . $path . ' and b/' . $path . " differ\n";
                }
                if (!$collect) {
                    continue;
                }
                if (!$binary && !str_starts_with($line, '@@ ')) {
                    if (str_starts_with($line, '+')) {
                        $insertions++;
                    } elseif (str_starts_with($line, '-')) {
                        $deletions++;
                    }
                }
                $startsPrivateKey = preg_match(
                    '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
                    $line,
                ) === 1;
                $endsPrivateKey = preg_match(
                    '/-----END [A-Z ]*PRIVATE KEY-----/',
                    $line,
                ) === 1;
                if (!$redactingPrivateKey && $startsPrivateKey) {
                    $redactingPrivateKey = !$endsPrivateKey;
                    $prefix = isset($line[0]) && in_array($line[0], ['+', '-', ' '], true)
                        ? $line[0]
                        : '';
                    $safeLine = $prefix . "[REDACTED]\n";
                } elseif ($redactingPrivateKey) {
                    $redactingPrivateKey = !$endsPrivateKey;
                    $safeLine = '';
                } else {
                    [$safeLine] = Redactor::string($line);
                }
                $lineStart = $bodyBytes;
                $lineBytes = strlen($safeLine);
                $bodyBytes += $lineBytes;
                if ($pageFilled
                    || $bodyPageLimit <= 0
                    || strlen($bodyPage) >= $bodyPageLimit
                    || $bodyBytes <= $bodyOffset) {
                    continue;
                }
                $sliceStart = max(0, $bodyOffset - $lineStart);
                $remaining = $bodyPageLimit - strlen($bodyPage);
                if ($sliceStart === 0 && $bodyPage !== '' && $lineBytes > $remaining) {
                    $pageFilled = true;
                    continue;
                }
                $piece = $this->sliceDiffBytes($safeLine, $sliceStart, $remaining);
                $bodyPage .= $piece;
                if ($sliceStart + strlen($piece) < $lineBytes) {
                    $pageFilled = true;
                }
            }
        } finally {
            fclose($stream);
        }
        if ($bodyOffset > $bodyBytes) {
            throw new ToolException(
                'REVIEW_CURSOR_INVALID',
                'Review cursor offset exceeds the sealed diff length',
                false,
                ['path' => $path],
            );
        }

        $diff = '';
        if ($bodyPage !== '') {
            $header = 'diff --git a/' . $path . ' b/' . $path . "\n";
            if (!$binary) {
                $header .= $action === 'create'
                    ? "--- /dev/null\n+++ b/" . $path . "\n"
                    : '--- a/' . $path . "\n+++ b/" . $path . "\n";
            }
            $diff = $header . $bodyPage;
        }
        $nextOffset = min($bodyBytes, $bodyOffset + strlen($bodyPage));
        $pageHasMore = $nextOffset < $bodyBytes;

        return [
            'path' => $path,
            'status' => $action === 'create' ? 'created' : 'modified',
            'available' => true,
            'insertions' => $insertions,
            'deletions' => $deletions,
            'changed_lines' => $insertions + $deletions,
            'hunks' => $hunks,
            'binary' => $binary,
            'diff_truncated' => $pageHasMore,
            'diff_page_has_more' => $pageHasMore,
            'diff_offset' => $bodyOffset,
            'diff_next_offset' => $nextOffset,
            'diff_total_bytes' => $bodyBytes,
            'error' => null,
            'diff' => $diff,
        ];
    }

    /** @param list<array<string, mixed>> $snapshots */
    private function reviewSnapshotDigest(array $snapshots): string
    {
        return hash('sha256', Json::canonical(array_map(
            static fn (array $snapshot): array => [
                'path' => (string) ($snapshot['path'] ?? ''),
                'action' => (string) ($snapshot['action'] ?? ''),
                'pre_sha256' => (string) ($snapshot['pre_sha256'] ?? ''),
                'post_sha256' => (string) ($snapshot['post_sha256'] ?? ''),
            ],
            $snapshots,
        )));
    }

    private function encodeReviewCursor(
        int $fileIndex,
        int $diffOffset,
        string $snapshotDigest,
        string $cursorKey,
    ): string {
        $payload = [
            'schema' => 'sealed-review-cursor.v1',
            'snapshot_digest' => $snapshotDigest,
            'file_index' => $fileIndex,
            'diff_offset' => $diffOffset,
        ];
        $payload['signature'] = hash_hmac('sha256', Json::canonical($payload), $cursorKey);
        return rtrim(strtr(base64_encode(Json::encode($payload)), '+/', '-_'), '=');
    }

    /** @return array{0:int,1:int} */
    private function decodeReviewCursor(
        string $cursor,
        string $snapshotDigest,
        int $fileCount,
        string $cursorKey,
    ): array {
        $cursor = trim($cursor);
        if ($cursor === '') {
            return [0, 0];
        }
        if (strlen($cursor) > 1_024) {
            throw new ToolException('REVIEW_CURSOR_INVALID', 'Review cursor exceeds the maximum length');
        }
        $encoded = strtr($cursor, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($encoded, true);
        $payload = is_string($decoded) ? Json::decode($decoded, null) : null;
        $signature = is_array($payload) ? (string) ($payload['signature'] ?? '') : '';
        $unsignedPayload = is_array($payload) ? $payload : [];
        unset($unsignedPayload['signature']);
        $validSignature = $signature !== '' && hash_equals(
            hash_hmac('sha256', Json::canonical($unsignedPayload), $cursorKey),
            $signature,
        );
        if (!is_array($payload)
            || ($payload['schema'] ?? '') !== 'sealed-review-cursor.v1'
            || !is_int($payload['file_index'] ?? null)
            || !is_int($payload['diff_offset'] ?? null)
            || !$validSignature
            || !hash_equals($snapshotDigest, (string) ($payload['snapshot_digest'] ?? ''))) {
            throw new ToolException('REVIEW_CURSOR_INVALID', 'Review cursor is malformed or belongs to another sealed edit');
        }
        $fileIndex = (int) $payload['file_index'];
        $diffOffset = (int) $payload['diff_offset'];
        if ($fileIndex < 0 || $fileIndex >= $fileCount || $diffOffset < 0) {
            throw new ToolException('REVIEW_CURSOR_INVALID', 'Review cursor is outside the sealed diff range');
        }
        return [$fileIndex, $diffOffset];
    }

    private function sliceDiffBytes(string $value, int $start, int $length): string
    {
        if ($length <= 0 || $start >= strlen($value)) {
            return '';
        }
        if (function_exists('mb_strcut')) {
            return (string) mb_strcut($value, $start, $length, 'UTF-8');
        }
        return substr($value, $start, $length);
    }

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
        if ($result['exit_code'] === 0 && preg_match('/^[a-f0-9]{40,64}$/iD', $commit) === 1) {
            return $commit;
        }

        return 'directory:sha256:' . hash('sha256', $this->index->root());
    }

    private function withProjectLock(callable $callback, string $operation = 'edit_transaction'): mixed
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
        if (!is_resource($handle) || !chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ToolException('EDIT_LOCK_FAILED', 'Unable to open the project edit lock', true);
        }
        $acquired = false;
        $startedAt = hrtime(true);
        try {
            $this->acquireExclusiveLock(
                $handle,
                'project',
                $this->index->projectId(),
                $operation,
                $startedAt,
            );
            $acquired = true;
            return $callback();
        } finally {
            if ($acquired) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function publicStatus(array $row, string $reviewCursor = ''): array
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
        $recovery = is_array($result['recovery'] ?? null)
            ? $result['recovery']
            : (is_array($error['crash_recovery'] ?? null) ? $error['crash_recovery'] : null);
        $indexPending = (bool) ($result['index_pending'] ?? in_array(
            (string) $row[$this->editStateColumn()],
            ['applied_index_pending', 'rolled_back_index_pending'],
            true,
        ));
        $cursorKey = trim((string) ($row['token_hash'] ?? ''));
        if ($cursorKey === '') {
            $cursorKey = (new SessionIdentity($this->config))->hash(
                'edit-review:' . (string) ($row[$this->editIdColumn()] ?? '')
                . ':' . (string) ($row['plan_digest'] ?? ''),
            );
        }
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
            'change_report' => $this->changeReport(
                $snapshotList,
                (string) $row[$this->editStateColumn()],
                $reviewCursor,
                $cursorKey,
            ),
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
            'recovery' => $recovery,
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

    /** @param array<string, mixed> $snapshot
     *  @return array{passed: bool, exit_code: int, output: string, duration_ms: int, command: list<string>}
     */
    private function transactionDiffCheck(array $snapshot): array
    {
        $path = $this->safePath((string) ($snapshot['path'] ?? ''));
        $action = (string) ($snapshot['action'] ?? 'modify');
        $afterReference = (string) ($snapshot['after_ref'] ?? '');
        $this->readJournal($afterReference, (string) ($snapshot['post_sha256'] ?? ''));

        $temporaryBefore = null;
        $beforeReference = (string) ($snapshot['before_ref'] ?? '');
        if ($action === 'create') {
            $temporaryBefore = tempnam(dirname($afterReference), '.validation-empty-');
            if (!is_string($temporaryBefore)
                || file_put_contents($temporaryBefore, '', LOCK_EX) === false) {
                if (is_string($temporaryBefore) && is_file($temporaryBefore)) {
                    @unlink($temporaryBefore);
                }
                throw new ToolException('VALIDATION_FAILED', 'Unable to allocate a fixed diff-check preimage');
            }
            @chmod($temporaryBefore, 0600);
            $beforeReference = $temporaryBefore;
        } else {
            $this->readJournal($beforeReference, (string) ($snapshot['pre_sha256'] ?? ''));
        }

        $argv = [
            'git', '--no-pager', 'diff', '--no-index', '--check',
            '--no-color', '--no-ext-diff', '--no-textconv', '--',
            $beforeReference, $afterReference,
        ];
        try {
            $result = $this->runner->run($argv, $this->index->root(), '', 60, ['NO_COLOR' => '1']);
        } finally {
            if (is_string($temporaryBefore) && is_file($temporaryBefore)) {
                @unlink($temporaryBefore);
            }
        }
        $diagnostic = trim((string) $result['stdout'] . "\n" . (string) $result['stderr']);
        $passed = in_array((int) $result['exit_code'], [0, 1], true) && $diagnostic === '';

        return [
            'passed' => $passed,
            'exit_code' => (int) $result['exit_code'],
            'output' => $passed ? '' : 'The sealed transaction diff introduces whitespace errors or the fixed diff checker failed.',
            'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
            'command' => ['git', 'diff', '--no-index', '--check', '--', $path . ':preimage', $path . ':postimage'],
        ];
    }

    /** @param list<string> $paths
     *  @param list<array<string, mixed>> $snapshots
     *  @return list<array<string, mixed>>
     */
    private function validationChecks(string $profile, array $paths, array $snapshots): array
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
            foreach ($snapshots as $snapshot) {
                if (!is_array($snapshot)) {
                    continue;
                }
                $checks[] = [
                    'type' => 'diff_check',
                    'path' => (string) ($snapshot['path'] ?? ''),
                    'snapshot' => $snapshot,
                ];
            }
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
