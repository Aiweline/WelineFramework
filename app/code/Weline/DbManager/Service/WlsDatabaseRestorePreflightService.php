<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseRestorePreflightService
{
    private const ACTION_RESTORE_DATABASE = 'restore_database';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const CONFIRMATION_PHRASE = 'CHECK_DB_RESTORE';

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
     * @return array{success:bool,message:string,artifact_path:string,bytes:int,checksum:string}
     */
    public function preflightFromPanel(
        array $input,
        array $context,
        array $backupPlan,
        array $projectProfile,
        array $sourceProfile
    ): array {
        $artifactPath = '';
        $targetConfig = [];
        $auditPayload = [
            'action' => (string)($backupPlan['action'] ?? ''),
            'driver' => (string)($backupPlan['driver'] ?? ''),
            'artifact' => (string)($backupPlan['artifact'] ?? ''),
            'database' => (string)($backupPlan['database'] ?? ''),
            'profile' => $this->auditProfile($projectProfile),
        ];

        try {
            $this->assertPreflightGate($input, $backupPlan);

            $targetConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $sourceProfile);
            if ($targetConfig === null) {
                $targetConfig = [];
                throw new \InvalidArgumentException((string)__('Enable and save the Project Profile before restore preflight.'));
            }

            $config = $this->normalizeConnectionConfig($targetConfig);
            $driver = (string)$config['type'];
            $planDriver = (string)($backupPlan['driver'] ?? '');
            $missing = $this->missingConnectionFields($config);
            if ($missing !== []) {
                throw new \InvalidArgumentException((string)__('Project restore connection is incomplete: %{1}', [\implode(', ', $missing)]));
            }
            if (!$this->supportsRestorePreflightDriver($driver)) {
                throw new \InvalidArgumentException((string)__('Restore preflight currently supports mysql and pgsql profiles.'));
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
            $this->probeReadableArtifact($artifactPath);

            $this->profileService->appendAuditEvent('restore_preflight_passed', $auditPayload + [
                'success' => true,
                'artifact' => (string)$artifact['name'],
                'bytes' => $bytes,
                'sha256' => $checksum,
                'restore_execution' => 'disabled',
            ]);

            return [
                'success' => true,
                'message' => (string)__('Restore preflight passed; restore execution remains disabled.'),
                'artifact_path' => $artifactPath,
                'bytes' => $bytes,
                'checksum' => $checksum,
            ];
        } catch (\Throwable $throwable) {
            $message = $this->sanitizeDatabaseError($throwable->getMessage(), $sourceProfile, $targetConfig);
            $this->profileService->appendAuditEvent('restore_preflight_failed', $auditPayload + [
                'success' => false,
                'message' => $message,
                'artifact_path_state' => $artifactPath !== '' && \is_file($artifactPath) ? 'exists' : 'missing',
            ]);

            return [
                'success' => false,
                'message' => $message,
                'artifact_path' => $artifactPath,
                'bytes' => 0,
                'checksum' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $backupPlan
     */
    private function assertPreflightGate(array $input, array $backupPlan): void
    {
        if ((string)($input['confirm_restore_preflight'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm restore preflight before submitting.'));
        }

        if (\trim((string)($input['confirm_restore_phrase'] ?? '')) !== self::CONFIRMATION_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type CHECK_DB_RESTORE to run restore preflight.'));
        }

        if (empty($backupPlan['can_preflight'])) {
            throw new \InvalidArgumentException((string)__('Restore plan is not ready for preflight.'));
        }

        if ((string)($backupPlan['action'] ?? '') !== self::ACTION_RESTORE_DATABASE) {
            throw new \InvalidArgumentException((string)__('Only restore_database can run restore preflight.'));
        }
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
        if (!$this->isRestorePreflightArtifact($artifact, $driver)) {
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
            throw new \RuntimeException((string)__('Restore artifact metadata is required before restore preflight can pass.'));
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

    private function probeReadableArtifact(string $artifactPath): void
    {
        if (\str_ends_with(\strtolower($artifactPath), '.sql.gz')) {
            if (!\function_exists('gzopen') || !\function_exists('gzread') || !\function_exists('gzclose')) {
                throw new \RuntimeException((string)__('zlib is required to inspect compressed restore artifacts.'));
            }
            $handle = @\gzopen($artifactPath, 'rb');
            if (!\is_resource($handle)) {
                throw new \RuntimeException((string)__('Compressed restore artifact could not be opened.'));
            }
            $chunk = \gzread($handle, 1);
            \gzclose($handle);
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException((string)__('Compressed restore artifact could not be read.'));
            }
            return;
        }

        $handle = @\fopen($artifactPath, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException((string)__('Restore artifact could not be opened.'));
        }
        $chunk = \fread($handle, 1);
        \fclose($handle);
        if ($chunk === false || $chunk === '') {
            throw new \RuntimeException((string)__('Restore artifact could not be read.'));
        }
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $identifier) === 1;
    }

    private function supportsRestorePreflightDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
    }

    private function isSafeArtifactName(string $artifact): bool
    {
        return \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1
            && !\str_contains($artifact, '..')
            && !\str_contains($artifact, '/')
            && !\str_contains($artifact, '\\')
            && !\str_contains($artifact, ':');
    }

    private function isRestorePreflightArtifact(string $artifact, string $driver): bool
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

    private function restoreArtifactHelp(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL
            ? (string)__('Use a .sql or .sql.gz artifact before MySQL restore preflight.')
            : (string)__('Use a .sql, .sql.gz, .dump, or .backup artifact before restore preflight.');
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

        return \mb_substr($message !== '' ? $message : (string)__('Restore preflight failed.'), 0, 220);
    }

    private function defaultPort(string $driver): string
    {
        return $driver === self::DRIVER_PGSQL ? '5432' : '3306';
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
