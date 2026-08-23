<?php

declare(strict_types=1);

namespace LearningMcp;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class IndexGarbageCollector
{
    private const TASK = 'derived_index_gc';

    private readonly Closure $clock;
    private readonly SessionIdentity $ownershipIdentity;

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null
            ? static fn(): string => Clock::now()
            : Closure::fromCallable($clock);
        $this->ownershipIdentity = new SessionIdentity($config);
    }

    /** @return array<string, mixed> */
    public function sweep(?string $owner = null, bool $force = false, ?bool $allowMutation = null): array
    {
        $owner = trim((string) $owner);
        if ($owner === '') {
            $owner = 'index-gc-' . getmypid() . '-' . bin2hex(random_bytes(5));
        }
        $interval = $this->config->duration('index.gc.sweep_interval');
        $enabled = $allowMutation ?? (bool) $this->config->get('index.gc.enabled', true);
        if (!$force && !$this->store->maintenanceDue(self::TASK, $interval)) {
            return [
                'enabled' => $enabled,
                'maintenance_due' => false,
                'lease_acquired' => false,
                'owner' => $owner,
            ];
        }
        if (!$this->store->acquireMaintenanceLease(self::TASK, $owner, max(60, $interval * 2))) {
            return [
                'enabled' => $enabled,
                'maintenance_due' => true,
                'lease_acquired' => false,
                'owner' => $owner,
            ];
        }
        $metrics = [
            'enabled' => $enabled,
            'maintenance_due' => true,
            'lease_acquired' => true,
            'owner' => $owner,
            'owned_scanned' => 0,
            'legacy_adopted' => 0,
            'unowned_skipped' => 0,
            'invalid_manifests' => 0,
            'active_leases' => 0,
            'active_or_recent' => 0,
            'dry_run_observed' => 0,
            'quarantined' => 0,
            'quarantine_skipped' => 0,
            'deleted' => 0,
            'errors' => [],
        ];
        try {
            $indexRoot = $this->indexRoot();
            $quarantineRoot = $this->quarantineRoot();
            $this->ensureDirectory($indexRoot);
            $this->ensureDirectory($quarantineRoot);
            $cursor = $this->store->maintenanceCursor(self::TASK);
            $candidates = is_array($cursor['candidates'] ?? null) ? $cursor['candidates'] : [];
            $now = $this->now();
            $retention = $this->config->duration('index.gc.retention');
            $dryRunPeriod = $this->config->duration('index.gc.dry_run_period');
            $limit = max(1, min(10_000, (int) $this->config->get('index.gc.max_generations', 100)));
            $entries = scandir($indexRoot);
            if (!is_array($entries)) {
                throw new RuntimeException('Unable to inventory project index generations');
            }
            $entries = array_values(array_filter(
                $entries,
                static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
            ));
            sort($entries, SORT_STRING);
            $scanAfter = trim((string) ($cursor['scan_after'] ?? ''));
            if ($scanAfter !== '') {
                $after = array_values(array_filter($entries, static fn(string $entry): bool => strcmp($entry, $scanAfter) > 0));
                $before = array_values(array_filter($entries, static fn(string $entry): bool => strcmp($entry, $scanAfter) <= 0));
                $entries = array_merge($after, $before);
            }
            $entries = array_slice($entries, 0, $limit);
            foreach ($entries as $entry) {
                $directory = $indexRoot . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($directory) || is_link($directory)) {
                    ++$metrics['unowned_skipped'];
                    continue;
                }
                $manifestPath = $directory . DIRECTORY_SEPARATOR . ProjectIndex::OWNER_MANIFEST;
                $manifest = $this->readOwnedManifest($directory, $entry);
                if ($manifest === null && is_file($manifestPath)) {
                    ++$metrics['invalid_manifests'];
                }
                if ($manifest === null && !is_file($manifestPath)
                    && preg_match('/^[a-f0-9]{64}$/D', $entry) === 1) {
                    $manifest = $this->adoptLegacy($directory, $entry);
                    if ($manifest !== null) {
                        ++$metrics['legacy_adopted'];
                    }
                }
                if ($manifest === null) {
                    ++$metrics['unowned_skipped'];
                    unset($candidates[$entry]);
                    continue;
                }
                ++$metrics['owned_scanned'];
                $lastUsed = trim((string) ($manifest['last_used_at'] ?? ''));
                if ($lastUsed === '' || !$this->olderThan($lastUsed, $retention, $now)) {
                    ++$metrics['active_or_recent'];
                    unset($candidates[$entry]);
                    continue;
                }
                $lock = $this->exclusiveGenerationLock($directory);
                if (!is_resource($lock)) {
                    ++$metrics['active_leases'];
                    unset($candidates[$entry]);
                    continue;
                }
                try {
                    $lockedManifest = $this->readOwnedManifest($directory, $entry);
                    if ($lockedManifest === null) {
                        ++$metrics['invalid_manifests'];
                        unset($candidates[$entry]);
                        continue;
                    }
                    $lockedLastUsed = trim((string) ($lockedManifest['last_used_at'] ?? ''));
                    if ($lockedLastUsed === '' || !$this->olderThan($lockedLastUsed, $retention, $now)) {
                        ++$metrics['active_or_recent'];
                        unset($candidates[$entry]);
                        continue;
                    }
                    $manifest = $lockedManifest;
                    $lastUsed = $lockedLastUsed;
                    if (!$this->safeGenerationFiles($directory)) {
                        ++$metrics['quarantine_skipped'];
                        unset($candidates[$entry]);
                        continue;
                    }
                    $firstEligibleAt = trim((string) ($candidates[$entry]['first_eligible_at'] ?? ''));
                    $previousLastUsed = trim((string) ($candidates[$entry]['last_used_at'] ?? ''));
                    if ($firstEligibleAt === '' || $previousLastUsed !== $lastUsed) {
                        $candidates[$entry] = [
                            'first_eligible_at' => $now,
                            'last_observed_at' => $now,
                            'last_used_at' => $lastUsed,
                        ];
                        ++$metrics['dry_run_observed'];
                        continue;
                    }
                    $candidates[$entry]['last_observed_at'] = $now;
                    $candidates[$entry]['last_used_at'] = $lastUsed;
                    if (!$this->olderThan($firstEligibleAt, $dryRunPeriod, $now)
                        || !$metrics['enabled']) {
                        ++$metrics['dry_run_observed'];
                        continue;
                    }
                    if (!$this->sameFilesystem($indexRoot, $quarantineRoot)) {
                        throw new RuntimeException('Index quarantine must be on the same filesystem');
                    }
                    $manifest['quarantined_at'] = $now;
                    $manifest['quarantine_source'] = $entry;
                    $this->writeManifest($directory, $manifest);
                    $destination = $quarantineRoot . DIRECTORY_SEPARATOR . $entry
                        . '--' . preg_replace('/[^0-9]/', '', $now)
                        . '--' . bin2hex(random_bytes(4));
                    if (!rename($directory, $destination)) {
                        throw new RuntimeException('Unable to move owned index generation to quarantine');
                    }
                    unset($candidates[$entry]);
                    ++$metrics['quarantined'];
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
            if ($metrics['enabled']) {
                $metrics = array_replace($metrics, $this->purgeQuarantine($quarantineRoot, $metrics));
            }
            $this->store->setMaintenanceCursor(self::TASK, $owner, [
                'candidates' => $candidates,
                'scan_after' => $entries === [] ? '' : (string) end($entries),
                'updated_at' => $now,
            ]);
            $metrics['completed_at'] = $now;
            $this->store->releaseMaintenanceLease(self::TASK, $owner, $metrics);

            return $metrics;
        } catch (Throwable $exception) {
            $metrics['errors'][] = Redactor::string($exception->getMessage())[0];
            $this->store->releaseMaintenanceLease(
                self::TASK,
                $owner,
                $metrics,
                $exception instanceof ToolException ? $exception->errorCode : 'INDEX_GC_FAILED',
            );
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function adoptLegacy(string $directory, string $generation): ?array
    {
        $allowed = [
            'project.sqlite',
            'project.sqlite-wal',
            'project.sqlite-shm',
            ProjectIndex::LEASE_FILE,
        ];
        $entries = scandir($directory);
        if (!is_array($entries)) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!in_array($entry, $allowed, true)
                || is_link($directory . DIRECTORY_SEPARATOR . $entry)
                || !is_file($directory . DIRECTORY_SEPARATOR . $entry)) {
                return null;
            }
        }
        $databasePath = $directory . DIRECTORY_SEPARATOR . 'project.sqlite';
        if (!is_file($databasePath) || is_link($databasePath)) {
            return null;
        }
        $before = stat($databasePath);
        if (!is_array($before)) {
            return null;
        }
        try {
            $mtime = (int) (filemtime($databasePath) ?: time());
            $database = new PDO('sqlite:' . $databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $database->exec('PRAGMA query_only = ON');
            $statement = $database->prepare(
                "SELECT metadata_key, value_json FROM metadata WHERE metadata_key IN ('project_id', 'root')"
            );
            $statement->execute();
            $metadata = [];
            foreach ($statement->fetchAll() as $row) {
                $metadata[(string) $row['metadata_key']] = Json::decode((string) $row['value_json'], '');
            }
            $database = null;
            $projectId = trim((string) ($metadata['project_id'] ?? ''));
            $root = trim((string) ($metadata['root'] ?? ''));
            if ($projectId === '' || $root === ''
                || !hash_equals($generation, hash('sha256', $projectId . "\0" . $root))) {
                return null;
            }
            $lock = $this->exclusiveGenerationLock($directory);
            if (!is_resource($lock)) {
                return null;
            }
            try {
                clearstatcache(true, $databasePath);
                $after = stat($databasePath);
                if (!is_array($after)
                    || $before['size'] !== $after['size']
                    || $before['mtime'] !== $after['mtime']) {
                    return null;
                }
                $manifest = [
                    'schema_version' => ProjectIndex::OWNER_SCHEMA,
                    'owner' => ProjectIndex::OWNER_NAME,
                    'generation' => $generation,
                    'project_id_hash' => hash('sha256', $projectId),
                    'repository_hash' => hash('sha256', $root),
                    'created_at' => $this->timestamp($mtime),
                    'last_used_at' => $this->now(),
                    'adopted_legacy_at' => $this->now(),
                ];
                $manifest['signature'] = $this->ownershipSignature($manifest);
                $this->writeManifest($directory, $manifest);

                return $manifest;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function readOwnedManifest(string $directory, string $generation): ?array
    {
        $path = $directory . DIRECTORY_SEPARATOR . ProjectIndex::OWNER_MANIFEST;
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        try {
            $manifest = Json::decode((string) file_get_contents($path), []);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($manifest) || !$this->validOwnershipManifest($manifest, $generation)) {
            return null;
        }

        return $manifest;
    }

    private function writeManifest(string $directory, array $manifest): void
    {
        $path = $directory . DIRECTORY_SEPARATOR . ProjectIndex::OWNER_MANIFEST;
        $temporary = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $encoded = Json::encode($manifest) . "\n";
        if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to update index ownership manifest');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish index ownership manifest');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validOwnershipManifest(array $manifest, string $generation): bool
    {
        $signature = trim((string) ($manifest['signature'] ?? ''));

        return ($manifest['schema_version'] ?? '') === ProjectIndex::OWNER_SCHEMA
            && ($manifest['owner'] ?? '') === ProjectIndex::OWNER_NAME
            && ($manifest['generation'] ?? '') === $generation
            && strlen((string) ($manifest['project_id_hash'] ?? '')) === 64
            && strlen((string) ($manifest['repository_hash'] ?? '')) === 64
            && $signature !== ''
            && hash_equals($signature, $this->ownershipSignature($manifest));
    }

    /** @param array<string, mixed> $manifest */
    private function ownershipSignature(array $manifest): string
    {
        return $this->ownershipIdentity->hash(implode("\n", [
            ProjectIndex::OWNER_SCHEMA,
            ProjectIndex::OWNER_NAME,
            (string) ($manifest['generation'] ?? ''),
            (string) ($manifest['project_id_hash'] ?? ''),
            (string) ($manifest['repository_hash'] ?? ''),
            (string) ($manifest['created_at'] ?? ''),
        ]));
    }

    private function safeGenerationFiles(string $directory): bool
    {
        $entries = scandir($directory);
        if (!is_array($entries)) {
            return false;
        }
        $allowed = [
            'project.sqlite',
            'project.sqlite-wal',
            'project.sqlite-shm',
            ProjectIndex::OWNER_MANIFEST,
            ProjectIndex::LEASE_FILE,
        ];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!in_array($entry, $allowed, true) || !is_file($path) || is_link($path)) {
                return false;
            }
        }

        return is_file($directory . DIRECTORY_SEPARATOR . 'project.sqlite')
            && is_file($directory . DIRECTORY_SEPARATOR . ProjectIndex::OWNER_MANIFEST);
    }

    /** @return resource|null */
    private function exclusiveGenerationLock(string $directory): mixed
    {
        $path = $directory . DIRECTORY_SEPARATOR . ProjectIndex::LEASE_FILE;
        if (is_link($path)) {
            return null;
        }
        $handle = fopen($path, 'c+');
        if (!is_resource($handle)) {
            return null;
        }
        @chmod($path, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /** @param array<string, mixed> $metrics
     *  @return array<string, mixed>
     */
    private function purgeQuarantine(string $root, array $metrics): array
    {
        $entries = scandir($root);
        if (!is_array($entries)) {
            return $metrics;
        }
        $now = $this->now();
        $period = $this->config->duration('index.gc.quarantine_period');
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $directory = $root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($directory) || is_link($directory)) {
                ++$metrics['quarantine_skipped'];
                continue;
            }
            $manifestPath = $directory . DIRECTORY_SEPARATOR . ProjectIndex::OWNER_MANIFEST;
            try {
                $manifest = is_file($manifestPath) && !is_link($manifestPath)
                    ? Json::decode((string) file_get_contents($manifestPath), [])
                    : [];
            } catch (Throwable) {
                $manifest = [];
            }
            $generation = is_array($manifest) ? trim((string) ($manifest['generation'] ?? '')) : '';
            if (!is_array($manifest)
                || !$this->validOwnershipManifest($manifest, $generation)
                || !str_starts_with($entry, $generation . '--')
                || trim((string) ($manifest['quarantined_at'] ?? '')) === ''
                || !$this->olderThan((string) $manifest['quarantined_at'], $period, $now)) {
                ++$metrics['quarantine_skipped'];
                continue;
            }
            $lock = $this->exclusiveGenerationLock($directory);
            if (!is_resource($lock)) {
                ++$metrics['quarantine_skipped'];
                continue;
            }
            try {
                $files = scandir($directory);
                $allowed = [
                    'project.sqlite',
                    'project.sqlite-wal',
                    'project.sqlite-shm',
                    ProjectIndex::OWNER_MANIFEST,
                    ProjectIndex::LEASE_FILE,
                ];
                $safe = is_array($files);
                foreach (is_array($files) ? $files : [] as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $path = $directory . DIRECTORY_SEPARATOR . $file;
                    if (!in_array($file, $allowed, true) || !is_file($path) || is_link($path)) {
                        $safe = false;
                        break;
                    }
                }
                if (!$safe) {
                    ++$metrics['quarantine_skipped'];
                    continue;
                }
                foreach (['project.sqlite-wal', 'project.sqlite-shm', 'project.sqlite'] as $file) {
                    $path = $directory . DIRECTORY_SEPARATOR . $file;
                    if (is_file($path) && !unlink($path)) {
                        throw new RuntimeException('Unable to delete quarantined index file');
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $leasePath = $directory . DIRECTORY_SEPARATOR . ProjectIndex::LEASE_FILE;
            if (is_file($leasePath) && !unlink($leasePath)) {
                ++$metrics['quarantine_skipped'];
                continue;
            }
            if (!unlink($manifestPath)) {
                ++$metrics['quarantine_skipped'];
                continue;
            }
            if (!rmdir($directory)) {
                ++$metrics['quarantine_skipped'];
                continue;
            }
            ++$metrics['deleted'];
        }

        return $metrics;
    }

    private function indexRoot(): string
    {
        return rtrim($this->config->dataDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'indexes';
    }

    private function quarantineRoot(): string
    {
        return rtrim($this->config->dataDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index-quarantine';
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create index GC directory');
        }
        @chmod($directory, 0700);
    }

    private function sameFilesystem(string $left, string $right): bool
    {
        $leftStat = stat($left);
        $rightStat = stat($right);

        return is_array($leftStat) && is_array($rightStat) && $leftStat['dev'] === $rightStat['dev'];
    }

    private function olderThan(string $timestamp, int $seconds, string $now): bool
    {
        try {
            $then = new DateTimeImmutable($timestamp);
            $current = new DateTimeImmutable($now);
        } catch (Throwable) {
            return false;
        }

        return $then->getTimestamp() + max(0, $seconds) <= $current->getTimestamp();
    }

    private function now(): string
    {
        return (string) ($this->clock)();
    }

    private function timestamp(int $unix): string
    {
        return (new DateTimeImmutable('@' . $unix))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.000\Z');
    }
}
