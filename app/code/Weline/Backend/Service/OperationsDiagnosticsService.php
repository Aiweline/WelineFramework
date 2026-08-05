<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

/**
 * Read-only projection for the commerce operations diagnostics pages.
 *
 * The service deliberately reads only allowlisted, non-secret fields from
 * migration and release artifacts. It never creates directories, runs a
 * command, opens a second database connection, or mutates an artifact.
 */
final class OperationsDiagnosticsService
{
    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = \rtrim($root ?? BP, "\\/") . DIRECTORY_SEPARATOR;
    }

    /** @return array<string,mixed> */
    public function migration(): array
    {
        $environment = $this->environment();
        $clones = [];
        foreach ($this->jsonFiles('var/mig/clones') as $row) {
            $fingerprint = \trim((string)($row['fingerprint'] ?? ''));
            $clones[] = [
                'clone_id' => (string)($row['clone_id'] ?? ''),
                'database' => (string)($row['database'] ?? ''),
                'mode' => (string)($row['mode'] ?? ''),
                'source_database' => (string)($row['source_database'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'fingerprint' => $fingerprint === '' ? '' : \substr($fingerprint, 0, 12),
            ];
        }

        $checkpoints = [];
        foreach ($this->jsonFiles('var/mig/checkpoints') as $row) {
            $checkpointId = \trim((string)($row['checkpoint_id'] ?? ''));
            if ($checkpointId === '' && \is_array($row['manifest'] ?? null)) {
                $checkpointId = (string)($row['manifest']['checkpoint_id'] ?? '');
            }
            $checkpoints[] = [
                'checkpoint_id' => $checkpointId,
                'phase' => (string)($row['phase'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'journal_entries' => \is_array($row['journal'] ?? null) ? \count($row['journal']) : 0,
            ];
        }

        return [
            'environment' => [
                'connector' => $environment['connector'],
                'database' => $environment['database'],
                'is_clone' => \str_starts_with($environment['database'], 'mig_clone_'),
            ],
            'clones' => \array_slice($clones, 0, 50),
            'checkpoints' => \array_slice($checkpoints, 0, 50),
        ];
    }

    /** @return array<string,mixed> */
    public function release(): array
    {
        $environment = $this->environment();
        $current = $this->jsonFile('var/deploy/current.json');
        $core = $this->jsonFile('var/deploy/core-version.json');

        return [
            'environment' => [
                'deploy_mode' => $environment['deploy_mode'],
                'deploy_version' => $environment['deploy_version'],
                'worker_build_id' => $environment['worker_build_id'],
            ],
            'current_marker_present' => $current !== null,
            'current' => $current === null ? [] : $this->allowlist(
                $current,
                [
                    'release_id', 'deploy_version', 'worker_build_id', 'git_ref_type',
                    'git_ref', 'git_tag', 'git_branch', 'status', 'started_at',
                    'finished_at', 'duration_ms',
                ],
            ),
            'core' => [
                'check' => \is_scalar($core['check'] ?? null) ? (string)$core['check'] : '',
                'last_notified_fingerprint' => \substr(
                    \trim((string)($core['last_notified_fingerprint'] ?? '')),
                    0,
                    12,
                ),
                'last_notified_at' => (string)($core['last_notified_at'] ?? ''),
            ],
        ];
    }

    /** @return array{connector:string,database:string,deploy_mode:string,deploy_version:string,worker_build_id:string} */
    private function environment(): array
    {
        $envPath = $this->root . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        $env = \is_file($envPath) ? require $envPath : [];
        $env = \is_array($env) ? $env : [];
        $master = \is_array($env['db']['master'] ?? null) ? $env['db']['master'] : [];

        return [
            'connector' => (string)($master['type'] ?? ''),
            'database' => (string)($master['database'] ?? ''),
            'deploy_mode' => (string)($env['deploy'] ?? ''),
            'deploy_version' => (string)($env['deploy_version'] ?? ''),
            'worker_build_id' => (string)($env['worker_build_id'] ?? ''),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function jsonFiles(string $relativeDirectory): array
    {
        $directory = $this->root . \str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!\is_dir($directory)) {
            return [];
        }
        $files = \glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        \usort($files, static fn(string $left, string $right): int =>
            ((int)@\filemtime($right)) <=> ((int)@\filemtime($left))
        );
        $rows = [];
        foreach (\array_slice($files, 0, 50) as $file) {
            $decoded = $this->decodeFile($file);
            if ($decoded !== null) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function jsonFile(string $relativePath): ?array
    {
        return $this->decodeFile($this->root . \str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    }

    /** @return array<string,mixed>|null */
    private function decodeFile(string $path): ?array
    {
        if (!\is_file($path) || !\is_readable($path)) {
            return null;
        }
        $raw = \file_get_contents($path);
        if ($raw === false || \trim($raw) === '') {
            return null;
        }
        try {
            $decoded = \json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        return \is_array($decoded) && !\array_is_list($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $row @param list<string> $keys @return array<string,scalar|null> */
    private function allowlist(array $row, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            $result[$key] = \is_scalar($value) || $value === null ? $value : null;
        }
        return $result;
    }
}
