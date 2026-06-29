<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

use Weline\DbManager\Service\Adapter\WlsDatabasePostgreSqlPlainRestoreAdapter;

class WlsDatabaseRestoreExecutionService
{
    private const ACTION_RESTORE_DATABASE = 'restore_database';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const PREFLIGHT_PHRASE = 'CHECK_DB_RESTORE';
    private const EXECUTION_PHRASE = 'RUN_DB_RESTORE';
    private const PGSQL_RESET_PHRASE = 'RESET_PG_SCHEMA';
    private const ACTION_RESTORE_ROLLBACK = 'restore_rollback';
    private const ROLLBACK_PHRASE = 'ROLLBACK_DB_RESTORE';

    public function __construct(
        private readonly WlsDatabaseProfileService $profileService
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @param array<string, mixed> $backupPlan
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $sourceProfile
     * @return array{success:bool,message:string,artifact_path:string,pre_restore_artifact_path:string,bytes:int,checksum:string,verification_count:int}
     */
    public function executeFromPanel(
        array $input,
        array $context,
        array $backupPlan,
        array $projectProfile,
        array $sourceProfile
    ): array {
        $artifactPath = '';
        $preRestoreArtifactPath = '';
        $targetConfig = [];
        $restoreDetails = [];
        $auditPayload = [
            'action' => (string)($backupPlan['action'] ?? ''),
            'driver' => (string)($backupPlan['driver'] ?? ''),
            'artifact' => (string)($backupPlan['artifact'] ?? ''),
            'database' => (string)($backupPlan['database'] ?? ''),
            'profile' => $this->auditProfile($projectProfile),
        ];

        try {
            $this->assertExecutionGate($input, $backupPlan);

            $preflight = (new WlsDatabaseRestorePreflightService($this->profileService))->preflightFromPanel(
                $input,
                $context,
                $backupPlan,
                $projectProfile,
                $sourceProfile
            );
            if (empty($preflight['success'])) {
                throw new \RuntimeException((string)($preflight['message'] ?? __('Restore preflight failed.')));
            }

            $targetConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $sourceProfile);
            if ($targetConfig === null) {
                $targetConfig = [];
                throw new \InvalidArgumentException((string)__('Enable and save the Project Profile before restore execution.'));
            }

            $config = $this->normalizeConnectionConfig($targetConfig);
            $driver = (string)$config['type'];
            $planDriver = (string)($backupPlan['driver'] ?? '');
            $missing = $this->missingConnectionFields($config);
            if ($missing !== []) {
                throw new \InvalidArgumentException((string)__('Project restore connection is incomplete: %{1}', [\implode(', ', $missing)]));
            }
            if (!$this->supportsRestoreExecutionDriver($driver)) {
                throw new \InvalidArgumentException((string)__('Restore execution currently supports mysql and pgsql Project Profiles.'));
            }
            if ($planDriver !== '' && $planDriver !== $driver) {
                throw new \InvalidArgumentException((string)__('Submitted restore driver does not match the enabled Project Profile.'));
            }
            if (!$this->isSafeIdentifier((string)$config['database'])) {
                throw new \InvalidArgumentException((string)__('Database name must start with a letter or underscore and contain only letters, numbers, and underscores.'));
            }

            $artifact = $this->resolveExistingArtifactPath((string)($backupPlan['artifact'] ?? ''), $driver);
            $artifactPath = $artifact['path'];
            $bytes = \filesize($artifactPath);
            if (!\is_int($bytes) || $bytes <= 0) {
                throw new \RuntimeException((string)__('Restore artifact is empty.'));
            }

            $meta = $this->readMetadata($artifactPath);
            $checksum = \hash_file('sha256', $artifactPath);
            if (!\is_string($checksum) || $checksum === '') {
                throw new \RuntimeException((string)__('Restore artifact checksum could not be calculated.'));
            }
            $this->assertMetadataMatches($meta, $artifact['name'], $driver, (string)$config['database'], $bytes, $checksum);

            $isPostgreSqlPlainSqlRestore = $driver === self::DRIVER_PGSQL
                && $this->isPostgreSqlPlainSqlRestoreArtifact((string)$artifact['name']);
            $preRestoreBackup = $this->createPreRestoreBackup(
                $input,
                $context,
                $projectProfile,
                $sourceProfile,
                $isPostgreSqlPlainSqlRestore
            );
            $preRestoreArtifactPath = (string)($preRestoreBackup['artifact_path'] ?? '');
            if (empty($preRestoreBackup['success']) || $preRestoreArtifactPath === '') {
                throw new \RuntimeException((string)__('Pre-restore backup failed; restore execution was stopped.'));
            }

            $startedAt = \microtime(true);
            if ($driver === self::DRIVER_PGSQL) {
                if ($isPostgreSqlPlainSqlRestore) {
                    $restoreDetails = $this->runPostgreSqlPlainSqlRestore($config, $artifactPath);
                } else {
                    $this->runPgRestore($config, $artifactPath);
                    $restoreDetails = [
                        'adapter' => 'pg_restore',
                        'reset_mode' => 'none',
                        'schema_count' => 0,
                    ];
                }
            } else {
                $this->runMysqlRestore($config, $artifactPath);
                $restoreDetails = [
                    'adapter' => 'mysql',
                    'reset_mode' => 'none',
                    'schema_count' => 0,
                ];
            }
            $verificationCount = $this->verifyRestoredConnection($config);

            $this->profileService->appendAuditEvent('restore_executed', $auditPayload + [
                'success' => true,
                'artifact' => (string)$artifact['name'],
                'bytes' => $bytes,
                'sha256' => $checksum,
                'pre_restore_artifact' => \basename($preRestoreArtifactPath),
                'verification_count' => $verificationCount,
                'adapter' => (string)($restoreDetails['adapter'] ?? ''),
                'reset_mode' => (string)($restoreDetails['reset_mode'] ?? 'none'),
                'reset_schema_count' => (int)($restoreDetails['schema_count'] ?? 0),
                'duration_ms' => (int)\round((\microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'success' => true,
                'message' => (string)__('Database restore completed successfully.'),
                'artifact_path' => $artifactPath,
                'pre_restore_artifact_path' => $preRestoreArtifactPath,
                'bytes' => $bytes,
                'checksum' => $checksum,
                'verification_count' => $verificationCount,
            ];
        } catch (\Throwable $throwable) {
            $message = $this->sanitizeDatabaseError($throwable->getMessage(), $sourceProfile, $targetConfig);
            $this->profileService->appendAuditEvent('restore_execute_failed', $auditPayload + [
                'success' => false,
                'message' => $message,
                'artifact_path_state' => $artifactPath !== '' && \is_file($artifactPath) ? 'exists' : 'missing',
                'pre_restore_artifact_state' => $preRestoreArtifactPath !== '' && \is_file($preRestoreArtifactPath) ? 'exists' : 'missing',
                'adapter' => (string)($restoreDetails['adapter'] ?? ''),
                'reset_mode' => (string)($restoreDetails['reset_mode'] ?? 'none'),
            ]);

            return [
                'success' => false,
                'message' => $message,
                'artifact_path' => $artifactPath,
                'pre_restore_artifact_path' => $preRestoreArtifactPath,
                'bytes' => 0,
                'checksum' => '',
                'verification_count' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     * @return array<string, mixed>
     */
    public function getRollbackPlan(array $context, array $projectProfile): array
    {
        $candidate = $this->findRollbackCandidate($context, $projectProfile, '');
        $errors = [];
        $warnings = [];
        if (empty($projectProfile['enabled'])) {
            $errors[] = (string)__('Save and enable the Project Profile before restore rollback.');
        }
        if (empty($candidate['found'])) {
            $warnings[] = (string)($candidate['message'] ?? __('Run a guarded restore first so WLS can record a pre-restore backup.'));
        } elseif ((string)($candidate['error'] ?? '') !== '') {
            $errors[] = (string)$candidate['error'];
        }

        $canExecute = $errors === [] && !empty($candidate['found']);

        return [
            'action' => self::ACTION_RESTORE_ROLLBACK,
            'action_label' => (string)__('Restore Rollback'),
            'state' => $canExecute ? 'ready_to_rollback' : ($errors === [] ? 'guarded' : 'blocked'),
            'state_label' => $canExecute ? (string)__('Ready To Roll Back') : (string)__('Guarded'),
            'can_rollback_execute' => $canExecute,
            'confirmation_phrase' => self::ROLLBACK_PHRASE,
            'reset_confirmation_phrase' => self::PGSQL_RESET_PHRASE,
            'requires_pg_schema_reset' => !empty($candidate['requires_pg_schema_reset']),
            'artifact' => (string)($candidate['artifact'] ?? ''),
            'source_restore_artifact' => (string)($candidate['source_restore_artifact'] ?? ''),
            'restore_time' => (string)($candidate['restore_time'] ?? ''),
            'driver' => (string)($candidate['driver'] ?? ($projectProfile['type'] ?? '')),
            'database' => (string)($candidate['database'] ?? ($projectProfile['database'] ?? '')),
            'execution_label' => $canExecute
                ? (string)__('Rollback is available for the latest recorded pre-restore backup.')
                : (string)($errors[0] ?? $warnings[0] ?? __('No rollback backup is ready.')),
            'checks' => [
                [
                    'label' => (string)__('Rollback Artifact'),
                    'value' => (string)($candidate['artifact'] ?? '-'),
                    'detail' => (string)($candidate['message'] ?? __('WLS only offers artifacts recorded as pre-restore backups.')),
                    'state' => $canExecute ? 'ready' : 'attention',
                ],
                [
                    'label' => (string)__('Profile Guard'),
                    'value' => !empty($projectProfile['enabled']) ? (string)__('Enabled') : (string)__('Guarded'),
                    'detail' => (string)__('Rollback uses the current enabled Project Profile and re-runs restore verification.'),
                    'state' => !empty($projectProfile['enabled']) ? 'ready' : 'blocked',
                ],
            ],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $sourceProfile
     * @return array{success:bool,message:string,artifact_path:string,pre_restore_artifact_path:string,bytes:int,checksum:string,verification_count:int}
     */
    public function rollbackFromPanel(
        array $input,
        array $context,
        array $projectProfile,
        array $sourceProfile
    ): array {
        $artifact = \trim((string)($input['rollback_artifact'] ?? $input['backup_artifact'] ?? ''));
        $auditPayload = [
            'action' => self::ACTION_RESTORE_ROLLBACK,
            'artifact' => $artifact,
            'profile' => $this->auditProfile($projectProfile),
        ];

        try {
            $this->assertRollbackGate($input);
            $candidate = $this->findRollbackCandidate($context, $projectProfile, $artifact);
            if (empty($candidate['found']) || (string)($candidate['error'] ?? '') !== '') {
                $candidateMessage = (string)($candidate['error'] ?? '');
                if ($candidateMessage === '') {
                    $candidateMessage = (string)($candidate['message'] ?? __('Restore rollback backup was not found.'));
                }

                throw new \InvalidArgumentException($candidateMessage);
            }
            if (empty($projectProfile['enabled'])) {
                throw new \InvalidArgumentException((string)__('Save and enable the Project Profile before restore rollback.'));
            }

            $rollbackArtifact = (string)$candidate['artifact'];
            $restorePlan = (new WlsDatabaseBackupPlanService())->buildPlan(
                [
                    'backup_action' => self::ACTION_RESTORE_DATABASE,
                    'backup_scope' => 'schema_and_data',
                    'backup_artifact' => $rollbackArtifact,
                ],
                $projectProfile,
                $sourceProfile
            );
            if (empty($restorePlan['can_restore_execute'])) {
                throw new \InvalidArgumentException((string)($restorePlan['execution_label'] ?? __('Restore rollback plan is not ready.')));
            }
            $this->assertRollbackResetGate($input, $restorePlan);

            $restoreInput = $input;
            $restoreInput['backup_action'] = self::ACTION_RESTORE_DATABASE;
            $restoreInput['backup_scope'] = 'schema_and_data';
            $restoreInput['backup_artifact'] = $rollbackArtifact;
            $restoreInput['confirm_restore_preflight'] = '1';
            $restoreInput['confirm_restore_phrase'] = self::PREFLIGHT_PHRASE;
            $restoreInput['confirm_restore_execute'] = '1';
            $restoreInput['confirm_restore_execute_phrase'] = self::EXECUTION_PHRASE;
            if ($this->requiresPostgreSqlSchemaReset($restorePlan)) {
                $restoreInput['confirm_pg_schema_reset'] = '1';
                $restoreInput['confirm_pg_schema_reset_phrase'] = self::PGSQL_RESET_PHRASE;
            }

            $result = $this->executeFromPanel(
                $restoreInput,
                $context,
                $restorePlan,
                $projectProfile,
                $sourceProfile
            );
            if (empty($result['success'])) {
                throw new \RuntimeException((string)($result['message'] ?? __('Restore rollback failed.')));
            }

            $this->profileService->appendAuditEvent('restore_rollback_executed', $auditPayload + [
                'success' => true,
                'artifact' => $rollbackArtifact,
                'source_restore_artifact' => (string)($candidate['source_restore_artifact'] ?? ''),
                'pre_restore_artifact' => \basename((string)($result['pre_restore_artifact_path'] ?? '')),
                'verification_count' => (int)($result['verification_count'] ?? 0),
                'requires_pg_schema_reset' => $this->requiresPostgreSqlSchemaReset($restorePlan),
            ]);

            $result['message'] = (string)__('Database restore rollback completed successfully.');
            return $result;
        } catch (\Throwable $throwable) {
            $message = $this->sanitizeDatabaseError($throwable->getMessage(), $sourceProfile, $projectProfile);
            $this->profileService->appendAuditEvent('restore_rollback_failed', $auditPayload + [
                'success' => false,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'artifact_path' => '',
                'pre_restore_artifact_path' => '',
                'bytes' => 0,
                'checksum' => '',
                'verification_count' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $backupPlan
     */
    private function assertExecutionGate(array $input, array $backupPlan): void
    {
        if ((string)($input['confirm_restore_preflight'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm restore preflight before executing restore.'));
        }

        if (\trim((string)($input['confirm_restore_phrase'] ?? '')) !== self::PREFLIGHT_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type CHECK_DB_RESTORE before executing restore.'));
        }

        if ((string)($input['confirm_restore_execute'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm database restore execution before submitting.'));
        }

        if (\trim((string)($input['confirm_restore_execute_phrase'] ?? '')) !== self::EXECUTION_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type RUN_DB_RESTORE to execute the database restore.'));
        }

        if ($this->requiresPostgreSqlSchemaReset($backupPlan)) {
            if ((string)($input['confirm_pg_schema_reset'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm PostgreSQL public schema reset before executing plain SQL restore.'));
            }

            if (\trim((string)($input['confirm_pg_schema_reset_phrase'] ?? '')) !== self::PGSQL_RESET_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type RESET_PG_SCHEMA before executing PostgreSQL plain SQL restore.'));
            }
        }

        if (empty($backupPlan['can_restore_execute'])) {
            throw new \InvalidArgumentException((string)__('Restore plan is not ready for execution.'));
        }

        if ((string)($backupPlan['action'] ?? '') !== self::ACTION_RESTORE_DATABASE) {
            throw new \InvalidArgumentException((string)__('Only restore_database can execute in this slice.'));
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function assertRollbackGate(array $input): void
    {
        if ((string)($input['confirm_restore_rollback'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm database restore rollback before submitting.'));
        }

        if (\trim((string)($input['confirm_restore_rollback_phrase'] ?? '')) !== self::ROLLBACK_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type ROLLBACK_DB_RESTORE to execute restore rollback.'));
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $restorePlan
     */
    private function assertRollbackResetGate(array $input, array $restorePlan): void
    {
        if (!$this->requiresPostgreSqlSchemaReset($restorePlan)) {
            return;
        }

        if ((string)($input['confirm_pg_schema_reset'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm PostgreSQL public schema reset before restore rollback.'));
        }

        if (\trim((string)($input['confirm_pg_schema_reset_phrase'] ?? '')) !== self::PGSQL_RESET_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type RESET_PG_SCHEMA before PostgreSQL restore rollback.'));
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     * @return array<string, mixed>
     */
    private function findRollbackCandidate(array $context, array $projectProfile, string $requestedArtifact): array
    {
        $requestedArtifact = \trim($requestedArtifact);
        foreach ($this->profileService->getRecentAuditRecords(80) as $record) {
            if ((string)($record['event'] ?? '') !== 'restore_executed') {
                continue;
            }
            $payload = \is_array($record['payload'] ?? null) ? $record['payload'] : [];
            if (empty($payload['success']) || !$this->matchesRollbackAuditProfile($payload, $context, $projectProfile)) {
                continue;
            }
            $artifact = \trim((string)($payload['pre_restore_artifact'] ?? ''));
            if ($artifact === '' || ($requestedArtifact !== '' && $artifact !== $requestedArtifact)) {
                continue;
            }

            try {
                $details = $this->validatedRollbackArtifact($artifact, $projectProfile);
                return $details + [
                    'found' => true,
                    'source_restore_artifact' => (string)($payload['artifact'] ?? ''),
                    'restore_time' => (string)($record['time'] ?? ''),
                    'message' => (string)__('Rollback backup is available.'),
                    'error' => '',
                ];
            } catch (\Throwable $throwable) {
                if ($requestedArtifact !== '') {
                    return [
                        'found' => true,
                        'artifact' => $artifact,
                        'source_restore_artifact' => (string)($payload['artifact'] ?? ''),
                        'restore_time' => (string)($record['time'] ?? ''),
                        'message' => '',
                        'error' => $this->sanitizeDatabaseError($throwable->getMessage(), $projectProfile),
                    ];
                }
            }
        }

        return [
            'found' => false,
            'artifact' => $requestedArtifact,
            'source_restore_artifact' => '',
            'restore_time' => '',
            'message' => $requestedArtifact !== ''
                ? (string)__('Selected rollback backup was not found in recent restore audit records.')
                : (string)__('No recent restore pre-restore backup is available for rollback.'),
            'error' => '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     */
    private function matchesRollbackAuditProfile(array $payload, array $context, array $projectProfile): bool
    {
        $profile = \is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        $profileContext = $this->profileService->getProfileContext($context);
        foreach (['profile_key', 'project_id', 'domain', 'project_type'] as $key) {
            $expected = (string)($projectProfile[$key] ?? $profileContext[$key] ?? '');
            $actual = (string)($profile[$key] ?? '');
            if ($expected !== '' && $actual !== '' && $expected !== $actual) {
                return false;
            }
        }

        $expectedSource = (string)($projectProfile['source_connection_key'] ?? '');
        $actualSource = (string)($profile['source_connection_key'] ?? '');
        return $expectedSource === '' || $actualSource === '' || $expectedSource === $actualSource;
    }

    /**
     * @param array<string, mixed> $projectProfile
     * @return array<string, mixed>
     */
    private function validatedRollbackArtifact(string $artifact, array $projectProfile): array
    {
        $driver = \strtolower(\trim((string)($projectProfile['type'] ?? '')));
        $database = (string)($projectProfile['database'] ?? '');
        $resolved = $this->resolveExistingArtifactPath($artifact, $driver);
        $bytes = \filesize((string)$resolved['path']);
        if (!\is_int($bytes) || $bytes <= 0) {
            throw new \RuntimeException((string)__('Rollback backup artifact is empty.'));
        }
        $checksum = \hash_file('sha256', (string)$resolved['path']);
        if (!\is_string($checksum) || $checksum === '') {
            throw new \RuntimeException((string)__('Rollback backup checksum could not be calculated.'));
        }
        $meta = $this->readMetadata((string)$resolved['path']);
        $this->assertMetadataMatches($meta, (string)$resolved['name'], $driver, $database, $bytes, $checksum);

        return [
            'artifact' => (string)$resolved['name'],
            'artifact_path' => (string)$resolved['path'],
            'driver' => $driver,
            'database' => $database,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'requires_pg_schema_reset' => $driver === self::DRIVER_PGSQL
                && $this->isPostgreSqlPlainSqlRestoreArtifact((string)$resolved['name']),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $sourceProfile
     * @return array{success:bool,message:string,artifact_path:string,meta_path:string,bytes:int,checksum:string}
     */
    private function createPreRestoreBackup(
        array $input,
        array $context,
        array $projectProfile,
        array $sourceProfile,
        bool $forcePostgreSqlCustomArtifact = false
    ): array {
        $artifact = $this->preRestoreArtifactName(
            (string)($projectProfile['database'] ?? 'database'),
            (string)($projectProfile['type'] ?? ''),
            $forcePostgreSqlCustomArtifact
        );
        $plan = (new WlsDatabaseBackupPlanService())->buildPlan(
            [
                'backup_action' => 'backup_database',
                'backup_scope' => 'schema_and_data',
                'backup_artifact' => $artifact,
            ],
            $projectProfile,
            $sourceProfile
        );

        if (empty($plan['can_execute'])) {
            return [
                'success' => false,
                'message' => (string)($plan['execution_label'] ?? __('Pre-restore backup is not ready.')),
                'artifact_path' => '',
                'meta_path' => '',
                'bytes' => 0,
                'checksum' => '',
            ];
        }

        return (new WlsDatabaseBackupExecutionService($this->profileService))->executeFromPanel(
            [
                'confirm_backup_execute' => '1',
                'confirm_backup_phrase' => 'RUN_DB_BACKUP',
            ] + $input,
            $context,
            $plan,
            $projectProfile,
            $sourceProfile
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConnectionConfig(array $config): array
    {
        $type = \strtolower(\trim((string)($config['type'] ?? $config['driver'] ?? '')));
        $type = $type !== '' ? $type : self::DRIVER_MYSQL;

        return [
            'type' => $type,
            'hostname' => \trim((string)($config['hostname'] ?? $config['host'] ?? '')),
            'hostport' => \trim((string)($config['hostport'] ?? $config['port'] ?? $this->defaultPort($type))),
            'database' => \trim((string)($config['database'] ?? $config['dbname'] ?? $config['name'] ?? '')),
            'username' => \trim((string)($config['username'] ?? $config['user'] ?? '')),
            'password' => (string)($config['password'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function missingConnectionFields(array $config): array
    {
        $missing = [];
        foreach (['hostname', 'hostport', 'database', 'username'] as $field) {
            if (\trim((string)($config[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @return array{name:string,path:string}
     */
    private function resolveExistingArtifactPath(string $artifact, string $driver): array
    {
        $artifact = \trim($artifact);
        if (!$this->isExecutableRestoreArtifact($artifact, $driver)) {
            throw new \InvalidArgumentException($this->restoreArtifactHelp($driver));
        }

        $dir = $this->backupDir();
        $realDir = \realpath($dir);
        if (!\is_string($realDir) || $realDir === '') {
            throw new \RuntimeException((string)__('Database Manager backup directory does not exist.'));
        }

        $path = $realDir . \DIRECTORY_SEPARATOR . $artifact;
        if (!$this->pathWithin($path, $realDir) || \is_link($path)) {
            throw new \RuntimeException((string)__('The selected restore artifact is outside the Database Manager backup directory.'));
        }

        $realPath = \realpath($path);
        if (!\is_string($realPath) || $realPath === '' || !$this->pathWithin($realPath, $realDir)) {
            throw new \RuntimeException((string)__('Restore artifact was not found inside the Database Manager backup directory.'));
        }
        if (!\is_file($realPath) || !\is_readable($realPath)) {
            throw new \RuntimeException((string)__('Restore artifact is not readable.'));
        }

        return [
            'name' => $artifact,
            'path' => $realPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetadata(string $artifactPath): array
    {
        $metaPath = $artifactPath . '.json';
        if (!\is_file($metaPath) || !\is_readable($metaPath)) {
            throw new \RuntimeException((string)__('Restore artifact metadata is required before restore execution can run.'));
        }

        $raw = \file_get_contents($metaPath);
        if (!\is_string($raw) || \trim($raw) === '') {
            throw new \RuntimeException((string)__('Restore artifact metadata is empty.'));
        }

        $meta = \json_decode($raw, true);
        if (!\is_array($meta)) {
            throw new \RuntimeException((string)__('Restore artifact metadata is not valid JSON.'));
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function assertMetadataMatches(
        array $meta,
        string $artifact,
        string $driver,
        string $database,
        int $bytes,
        string $checksum
    ): void {
        if ((string)($meta['action'] ?? '') !== 'backup_database') {
            throw new \RuntimeException((string)__('Restore artifact metadata must come from a Database Manager backup.'));
        }
        if ((string)($meta['artifact'] ?? '') !== $artifact) {
            throw new \RuntimeException((string)__('Restore artifact metadata does not match the selected artifact name.'));
        }
        if ((string)($meta['driver'] ?? '') !== $driver) {
            throw new \RuntimeException((string)__('Restore artifact driver does not match the enabled Project Profile.'));
        }
        if ((string)($meta['database'] ?? '') !== $database) {
            throw new \RuntimeException((string)__('Restore artifact database does not match the enabled Project Profile.'));
        }
        if ((int)($meta['bytes'] ?? 0) !== $bytes) {
            throw new \RuntimeException((string)__('Restore artifact byte size does not match metadata.'));
        }
        if ((string)($meta['sha256'] ?? '') !== $checksum) {
            throw new \RuntimeException((string)__('Restore artifact checksum does not match metadata.'));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runMysqlRestore(array $config, string $artifactPath): void
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException((string)__('proc_open is required for database restore execution.'));
        }

        $command = [
            $this->findMysqlClientBinary(),
            '--host=' . (string)$config['hostname'],
            '--port=' . (string)$config['hostport'],
            '--user=' . (string)$config['username'],
            '--protocol=TCP',
            '--default-character-set=utf8mb4',
            (string)$config['database'],
        ];

        $env = \getenv();
        $env = \is_array($env) ? $env : [];
        if ((string)$config['password'] !== '') {
            $env['MYSQL_PWD'] = (string)$config['password'];
        }

        $this->runRestoreProcess($command, $env, $artifactPath);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runPgRestore(array $config, string $artifactPath): void
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException((string)__('proc_open is required for database restore execution.'));
        }

        $command = [
            $this->findPgRestoreBinary(),
            '--host',
            (string)$config['hostname'],
            '--port',
            (string)$config['hostport'],
            '--username',
            (string)$config['username'],
            '--dbname',
            (string)$config['database'],
            '--no-password',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--exit-on-error',
            '--single-transaction',
            $artifactPath,
        ];

        $env = \getenv();
        $env = \is_array($env) ? $env : [];
        if ((string)$config['password'] !== '') {
            $env['PGPASSWORD'] = (string)$config['password'];
        }

        $this->runRestoreCommandProcess(
            $command,
            $env,
            (string)__('Unable to start PostgreSQL restore command.'),
            (string)__('PostgreSQL restore command failed.')
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array{adapter:string,reset_mode:string,schema_count:int}
     */
    private function runPostgreSqlPlainSqlRestore(array $config, string $artifactPath): array
    {
        return (new WlsDatabasePostgreSqlPlainRestoreAdapter($this->backupDir(), $this->bpRoot()))
            ->restore($config, $artifactPath);
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env
     */
    private function runRestoreProcess(
        array $command,
        array $env,
        string $artifactPath,
        string $startError = '',
        string $failureFallback = ''
    ): void
    {
        $stdoutPath = $this->temporaryProcessOutputPath();
        $stderrPath = $this->temporaryProcessOutputPath();
        $pipes = [];
        $process = @\proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutPath, 'w'],
                2 => ['file', $stderrPath, 'w'],
            ],
            $pipes,
            $this->bpRoot(),
            $env,
            ['bypass_shell' => true]
        );
        if (!\is_resource($process)) {
            @\unlink($stdoutPath);
            @\unlink($stderrPath);
            throw new \RuntimeException($startError !== '' ? $startError : (string)__('Unable to start MySQL restore command.'));
        }

        $failure = null;
        try {
            if (!isset($pipes[0]) || !\is_resource($pipes[0])) {
                throw new \RuntimeException((string)__('Unable to open restore command input pipe.'));
            }
            $this->streamArtifactToProcess($artifactPath, $pipes[0]);
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }

        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }

        $exitCode = \proc_close($process);
        $stdout = \is_file($stdoutPath) ? (string)\file_get_contents($stdoutPath) : '';
        $stderr = \is_file($stderrPath) ? (string)\file_get_contents($stderrPath) : '';
        @\unlink($stdoutPath);
        @\unlink($stderrPath);

        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if ($exitCode !== 0) {
            $message = \trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException($message !== '' ? \mb_substr($message, 0, 220) : ($failureFallback !== '' ? $failureFallback : (string)__('MySQL restore command failed.')));
        }
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env
     */
    private function runRestoreCommandProcess(array $command, array $env, string $startError, string $failureFallback): void
    {
        $stdoutPath = $this->temporaryProcessOutputPath();
        $stderrPath = $this->temporaryProcessOutputPath();
        $pipes = [];
        $process = @\proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutPath, 'w'],
                2 => ['file', $stderrPath, 'w'],
            ],
            $pipes,
            $this->bpRoot(),
            $env,
            ['bypass_shell' => true]
        );
        if (!\is_resource($process)) {
            @\unlink($stdoutPath);
            @\unlink($stderrPath);
            throw new \RuntimeException($startError);
        }

        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }

        $exitCode = \proc_close($process);
        $stdout = \is_file($stdoutPath) ? (string)\file_get_contents($stdoutPath) : '';
        $stderr = \is_file($stderrPath) ? (string)\file_get_contents($stderrPath) : '';
        @\unlink($stdoutPath);
        @\unlink($stderrPath);

        if ($exitCode !== 0) {
            $message = \trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException($message !== '' ? \mb_substr($message, 0, 220) : $failureFallback);
        }
    }

    /**
     * @param resource $stdin
     */
    private function streamArtifactToProcess(string $artifactPath, $stdin): void
    {
        if (\str_ends_with(\strtolower($artifactPath), '.sql.gz')) {
            if (!\function_exists('gzopen') || !\function_exists('gzread') || !\function_exists('gzclose')) {
                throw new \RuntimeException((string)__('zlib is required for compressed database restore artifacts.'));
            }
            $handle = @\gzopen($artifactPath, 'rb');
            if (!\is_resource($handle)) {
                throw new \RuntimeException((string)__('Compressed restore artifact could not be opened.'));
            }
            try {
                while (!\gzeof($handle)) {
                    $chunk = \gzread($handle, 1024 * 1024);
                    if ($chunk === false) {
                        throw new \RuntimeException((string)__('Compressed restore artifact could not be read.'));
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $this->writeChunk($stdin, $chunk);
                }
            } finally {
                \gzclose($handle);
            }
            return;
        }

        $handle = @\fopen($artifactPath, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException((string)__('Restore artifact could not be opened.'));
        }
        try {
            while (!\feof($handle)) {
                $chunk = \fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('Restore artifact could not be read.'));
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeChunk($stdin, $chunk);
            }
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @param resource $stdin
     */
    private function writeChunk($stdin, string $chunk): void
    {
        $offset = 0;
        $length = \strlen($chunk);
        while ($offset < $length) {
            $written = \fwrite($stdin, \substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException((string)__('Unable to stream restore artifact into the database client.'));
            }
            $offset += $written;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function verifyRestoredConnection(array $config): int
    {
        if ((string)$config['type'] === self::DRIVER_PGSQL) {
            $dsn = \sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                (string)$config['hostname'],
                (string)$config['hostport'],
                (string)$config['database']
            );
        } else {
            $dsn = \sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string)$config['hostname'],
                (string)$config['hostport'],
                (string)$config['database']
            );
        }
        $pdo = new \PDO(
            $dsn,
            (string)$config['username'],
            (string)$config['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]
        );
        $statement = $pdo->query('SELECT 1');
        if ($statement === false) {
            throw new \RuntimeException((string)__('Restore verification query failed.'));
        }
        $statement->fetchColumn();

        return 1;
    }

    private function findMysqlClientBinary(): string
    {
        $names = \PHP_OS_FAMILY === 'Windows'
            ? ['mysql.exe', 'mariadb.exe', 'mysql', 'mariadb']
            : ['mysql', 'mariadb'];
        $path = (string)\getenv('PATH');
        foreach (\explode(\PATH_SEPARATOR, $path) as $dir) {
            $dir = \trim($dir, " \t\n\r\0\x0B\"");
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return 'mysql';
    }

    private function findPgRestoreBinary(): string
    {
        $names = \PHP_OS_FAMILY === 'Windows' ? ['pg_restore.exe', 'pg_restore'] : ['pg_restore'];
        $path = (string)\getenv('PATH');
        foreach (\explode(\PATH_SEPARATOR, $path) as $dir) {
            $dir = \trim($dir, " \t\n\r\0\x0B\"");
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return 'pg_restore';
    }

    private function temporaryProcessOutputPath(): string
    {
        $dir = $this->backupDir();
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('Unable to create the Database Manager backup directory.'));
        }

        $path = \tempnam($dir, 'restore-process-');
        if (!\is_string($path) || $path === '') {
            throw new \RuntimeException((string)__('Unable to reserve restore process output storage.'));
        }

        return $path;
    }

    private function preRestoreArtifactName(string $database, string $driver = '', bool $forcePostgreSqlCustomArtifact = false): string
    {
        $seed = \preg_replace('/[^A-Za-z0-9_.-]+/', '-', \trim($database)) ?: 'database';
        $seed = \mb_substr(\trim($seed, '.-') ?: 'database', 0, 64);
        $extension = $forcePostgreSqlCustomArtifact && \strtolower(\trim($driver)) === self::DRIVER_PGSQL
            ? '.dump'
            : '.sql';

        return 'pre-restore-' . $seed . '-' . \date('Ymd-His') . '-' . \bin2hex(\random_bytes(4)) . $extension;
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $identifier) === 1;
    }

    private function supportsRestoreExecutionDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
    }

    /**
     * @param array<string, mixed> $backupPlan
     */
    private function requiresPostgreSqlSchemaReset(array $backupPlan): bool
    {
        return (string)($backupPlan['driver'] ?? '') === self::DRIVER_PGSQL
            && $this->isPostgreSqlPlainSqlRestoreArtifact((string)($backupPlan['artifact'] ?? ''));
    }

    private function isSafeArtifactName(string $artifact): bool
    {
        return \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1
            && !\str_contains($artifact, '..')
            && !\str_contains($artifact, '/')
            && !\str_contains($artifact, '\\')
            && !\str_contains($artifact, ':');
    }

    private function isExecutableRestoreArtifact(string $artifact, string $driver): bool
    {
        if (!$this->isSafeArtifactName($artifact)) {
            return false;
        }

        return match ($driver) {
            self::DRIVER_MYSQL => \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1,
            self::DRIVER_PGSQL => \preg_match('/\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1,
            default => false,
        };
    }

    private function isPostgreSqlPlainSqlRestoreArtifact(string $artifact): bool
    {
        return $this->isSafeArtifactName($artifact)
            && \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1;
    }

    private function restoreArtifactHelp(string $driver): string
    {
        return match ($driver) {
            self::DRIVER_MYSQL => (string)__('Use a .sql or .sql.gz artifact before MySQL restore execution.'),
            self::DRIVER_PGSQL => (string)__('Use a .sql, .sql.gz, .dump, or .backup artifact before PostgreSQL restore execution.'),
            default => (string)__('Restore execution currently supports mysql and pgsql artifacts.'),
        };
    }

    /**
     * @param array<string, mixed> $projectProfile
     * @return array<string, mixed>
     */
    private function auditProfile(array $projectProfile): array
    {
        return [
            'profile_key' => (string)($projectProfile['profile_key'] ?? ''),
            'project_id' => (string)($projectProfile['project_id'] ?? ''),
            'domain' => (string)($projectProfile['domain'] ?? ''),
            'project_type' => (string)($projectProfile['project_type'] ?? ''),
            'enabled' => !empty($projectProfile['enabled']),
            'source_connection_key' => (string)($projectProfile['source_connection_key'] ?? ''),
            'password_state' => !empty($projectProfile['password_configured']) || !empty($projectProfile['env_password_configured'])
                ? 'configured'
                : 'empty',
        ];
    }

    /**
     * @param array<string, mixed> $sourceProfile
     * @param array<string, mixed> $targetConfig
     */
    private function sanitizeDatabaseError(string $message, array $sourceProfile, array $targetConfig = []): string
    {
        $message = \trim($message);
        $sensitiveValues = [
            (string)($sourceProfile['password'] ?? ''),
            (string)($sourceProfile['username'] ?? $sourceProfile['user'] ?? ''),
            (string)($targetConfig['password'] ?? ''),
            (string)($targetConfig['username'] ?? $targetConfig['user'] ?? ''),
        ];
        foreach ($sensitiveValues as $value) {
            if ($value !== '') {
                $message = \str_replace($value, '[secret]', $message);
            }
        }
        foreach ([$this->backupDir(), $this->bpRoot()] as $pathValue) {
            if ($pathValue !== '') {
                $message = \str_replace($pathValue, '[path]', $message);
                $message = \str_replace(\str_replace('\\', '/', $pathValue), '[path]', $message);
            }
        }

        return \mb_substr($message !== '' ? $message : (string)__('Database restore execution failed.'), 0, 220);
    }

    private function defaultPort(string $driver): string
    {
        return match ($driver) {
            self::DRIVER_MYSQL => '3306',
            self::DRIVER_PGSQL => '5432',
            default => '',
        };
    }

    private function backupDir(): string
    {
        return $this->bpPath('var' . \DIRECTORY_SEPARATOR . 'backups' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'db-manager' . \DIRECTORY_SEPARATOR . 'database');
    }

    private function bpPath(string $relative): string
    {
        return $this->bpRoot() . \DIRECTORY_SEPARATOR . \ltrim($relative, '\\/');
    }

    private function bpRoot(): string
    {
        $root = \defined('BP') ? (string)BP : (string)\getcwd();
        return \rtrim($root, '\\/');
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = \strtolower(\str_replace('\\', '/', \rtrim($path, '\\/')));
        $root = \strtolower(\str_replace('\\', '/', \rtrim($root, '\\/')));
        return $path === $root || \str_starts_with($path, $root . '/');
    }
}
