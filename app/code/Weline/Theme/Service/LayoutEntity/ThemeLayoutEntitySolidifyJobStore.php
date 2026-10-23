<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Durable solidify job intents under generated/theme-layout-entities/{theme}/.solidify-jobs/.
 * Cron (and optional WLS post-response) drain these; FPM cannot rely on in-memory queues.
 *
 * Contract: one pending JSON per layout slot (hash without V/R). Unconsumed updates
 * overwrite that file; consumed delete empties the slot so gate may enqueue again.
 */
final class ThemeLayoutEntitySolidifyJobStore
{
    public const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    public function has(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        return is_file($this->pathFor($key));
    }

    /**
     * Upsert the slot file. Unconsumed → overwrite payload (last-write-wins).
     *
     * @return array{enqueued:bool,coalesced:bool,overwritten:bool}
     */
    public function put(ThemeLayoutEntitySolidifySerialKey $key, string $expectedFingerprint): array
    {
        $path = $this->pathFor($key);
        $existed = is_file($path);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('theme_layout_solidify_job_dir_failed');
        }
        $payload = json_encode([
            'serial' => $key->toString(),
            'slot' => $key->slotString(),
            'theme_id' => $key->themeId,
            'area' => $key->area,
            'scope' => $key->canonicalScope,
            'store_mode' => $key->storeMode,
            'layout_type' => $key->layoutType,
            'layout_option' => $key->layoutOption,
            'theme_version_id' => $key->themeVersionId,
            'content_revision' => $key->contentRevision,
            'expected_fp' => trim($expectedFingerprint),
            'queued_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('theme_layout_solidify_job_write_failed');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            // Lost the race — retry once by reading winner then rewriting (still single file).
            if (is_file($path) && @file_put_contents($path, $payload, LOCK_EX) !== false) {
                return ['enqueued' => false, 'coalesced' => true, 'overwritten' => true];
            }
            throw new \RuntimeException('theme_layout_solidify_job_rename_failed');
        }

        return [
            'enqueued' => !$existed,
            'coalesced' => $existed,
            'overwritten' => $existed,
        ];
    }

    /**
     * @return null|array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string}
     */
    public function read(ThemeLayoutEntitySolidifySerialKey $key): ?array
    {
        return $this->readJobFile($this->pathFor($key));
    }

    public function delete(ThemeLayoutEntitySolidifySerialKey $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Move a failed job into `.solidify-jobs/failed/` (dead-letter) and remove from pending.
     *
     * The dead-letter file is slot-scoped and **accumulates** `fail_count` across
     * consecutive failures so the request gate can open a circuit breaker instead of
     * re-enqueueing (and forbidding shared FPC) forever while a bake keeps failing.
     */
    public function moveToFailed(ThemeLayoutEntitySolidifySerialKey $key, string $reason = ''): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return false;
        }
        $failedDir = dirname($path) . DIRECTORY_SEPARATOR . 'failed';
        if (!is_dir($failedDir) && !@mkdir($failedDir, 0775, true) && !is_dir($failedDir)) {
            return false;
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = ['serial' => $key->toString()];
        }
        $previous = $this->failureState($key);
        $data['fail_count'] = ($previous['fail_count'] ?? 0) + 1;
        $data['first_failed_at'] = $previous['first_failed_at'] ?? gmdate('c');
        $data['failed_at'] = gmdate('c');
        $data['fail_reason'] = trim($reason);
        $dest = $this->failedPathFor($key);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($dest, $encoded, LOCK_EX) === false) {
            return false;
        }
        @unlink($path);

        return true;
    }

    /**
     * Consecutive-failure record for the slot (null when the slot never failed or was cleared).
     *
     * @return null|array{fail_count:int,failed_at:int,first_failed_at:string,fail_reason:string}
     */
    public function failureState(ThemeLayoutEntitySolidifySerialKey $key): ?array
    {
        $dest = $this->failedPathFor($key);
        if (!is_file($dest)) {
            return null;
        }
        $raw = @file_get_contents($dest);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $failedAt = strtotime((string)($data['failed_at'] ?? '')) ?: (int)(@filemtime($dest) ?: 0);

        return [
            'fail_count' => max(1, (int)($data['fail_count'] ?? 1)),
            'failed_at' => $failedAt,
            'first_failed_at' => (string)($data['first_failed_at'] ?? ($data['failed_at'] ?? '')),
            'fail_reason' => (string)($data['fail_reason'] ?? ''),
        ];
    }

    /**
     * Forget the dead-letter record after a successful bake. Returns true when a record existed
     * (caller may need to invalidate pages that were served from the source template meanwhile).
     */
    public function clearFailure(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        $dest = $this->failedPathFor($key);
        if (!is_file($dest)) {
            return false;
        }
        @unlink($dest);

        return true;
    }

    private function failedPathFor(ThemeLayoutEntitySolidifySerialKey $key): string
    {
        return dirname($this->pathFor($key)) . DIRECTORY_SEPARATOR . 'failed'
            . DIRECTORY_SEPARATOR . $key->slotHash() . '.json';
    }

    /**
     * Fold legacy multi-V job files (old path = hash(full serial incl V/R)) into one slot file.
     *
     * @return array{folded:int,removed:int,slots:int}
     */
    public function foldLegacyMultiVersionJobs(): array
    {
        $root = rtrim($this->paths->stateRoot(), '/\\');
        if (!is_dir($root)) {
            return ['folded' => 0, 'removed' => 0, 'slots' => 0];
        }
        $folded = 0;
        $removed = 0;
        $slotCount = 0;
        $themeDirs = @scandir($root) ?: [];
        foreach ($themeDirs as $themeName) {
            if ($themeName === '.' || $themeName === '..' || !ctype_digit($themeName)) {
                continue;
            }
            $jobDir = $root . DIRECTORY_SEPARATOR . $themeName . DIRECTORY_SEPARATOR . '.solidify-jobs';
            if (!is_dir($jobDir)) {
                continue;
            }
            /** @var array<string, list<array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string}>> $bySlot */
            $bySlot = [];
            $files = @scandir($jobDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                    continue;
                }
                $path = $jobDir . DIRECTORY_SEPARATOR . $file;
                if (!is_file($path)) {
                    continue;
                }
                $parsed = $this->readJobFile($path);
                if ($parsed === null) {
                    continue;
                }
                $slotPath = $this->pathFor($parsed['key']);
                $bySlot[$slotPath][] = $parsed;
            }
            foreach ($bySlot as $slotPath => $jobs) {
                $slotCount++;
                usort(
                    $jobs,
                    static function (array $a, array $b): int {
                        return strcmp((string)$b['queued_at'], (string)$a['queued_at']);
                    },
                );
                $winner = $jobs[0];
                /** @var ThemeLayoutEntitySolidifySerialKey $winnerKey */
                $winnerKey = $winner['key'];
                // Rewrite winner onto stable slot path (may already be there).
                $this->put($winnerKey, (string)$winner['expected_fp']);
                $folded++;
                foreach ($jobs as $job) {
                    $jobPath = (string)$job['path'];
                    if (!is_file($jobPath)) {
                        continue;
                    }
                    if (hash_equals($slotPath, $jobPath)) {
                        continue;
                    }
                    $this->archiveSuperseded($jobPath, 'superseded_multi_v');
                    $removed++;
                }
            }
        }

        return ['folded' => $folded, 'removed' => $removed, 'slots' => $slotCount];
    }

    /**
     * @return list<array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string}>
     */
    public function listPending(int $limit = 32): array
    {
        $root = rtrim($this->paths->stateRoot(), '/\\');
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $limit = max(1, min(200, $limit));
        $themeDirs = @scandir($root) ?: [];
        foreach ($themeDirs as $themeName) {
            if ($themeName === '.' || $themeName === '..' || !ctype_digit($themeName)) {
                continue;
            }
            $jobDir = $root . DIRECTORY_SEPARATOR . $themeName . DIRECTORY_SEPARATOR . '.solidify-jobs';
            if (!is_dir($jobDir)) {
                continue;
            }
            $files = @scandir($jobDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                    continue;
                }
                $path = $jobDir . DIRECTORY_SEPARATOR . $file;
                if (!is_file($path)) {
                    continue;
                }
                $parsed = $this->readJobFile($path);
                if ($parsed === null) {
                    continue;
                }
                $out[] = $parsed;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * GC: remove pending jobs matching $isStale (theme/version gone or TTL).
     * Default dry-run — set $apply=true to delete / move.
     *
     * @param callable(array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string}):bool $isStale
     * @return array{candidates:list<array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string,reason:string}>,purged:int}
     */
    public function purgeStale(callable $isStale, bool $apply = false): array
    {
        $candidates = [];
        foreach ($this->listPending(200) as $job) {
            $reason = '';
            $stale = false;
            try {
                $result = $isStale($job);
                if (is_array($result)) {
                    $stale = !empty($result['stale']);
                    $reason = (string)($result['reason'] ?? '');
                } else {
                    $stale = (bool)$result;
                    $reason = $stale ? 'predicate' : '';
                }
            } catch (\Throwable $e) {
                $stale = true;
                $reason = 'predicate_error:' . $e->getMessage();
            }
            if (!$stale) {
                continue;
            }
            $candidates[] = [
                'key' => $job['key'],
                'expected_fp' => $job['expected_fp'],
                'path' => $job['path'],
                'queued_at' => $job['queued_at'],
                'reason' => $reason !== '' ? $reason : 'stale',
            ];
        }
        $purged = 0;
        if ($apply) {
            foreach ($candidates as $item) {
                /** @var ThemeLayoutEntitySolidifySerialKey $key */
                $key = $item['key'];
                if ($this->moveToFailed($key, 'purge_stale:' . (string)$item['reason'])) {
                    $purged++;
                } else {
                    $this->delete($key);
                    $purged++;
                }
            }
        }

        return ['candidates' => $candidates, 'purged' => $purged];
    }

    /**
     * Default survival predicate: theme missing, version missing/mismatched, or past TTL.
     *
     * @param callable(int):bool $themeExists
     * @param callable(int,int,string,string,string):bool $versionAlive  (themeId, versionId, scope, area, storeMode)
     * @return callable(array):array{stale:bool,reason:string}
     */
    public static function defaultStalePredicate(
        callable $themeExists,
        callable $versionAlive,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?int $now = null,
    ): callable {
        $now ??= time();
        $ttlSeconds = max(60, $ttlSeconds);

        return static function (array $job) use ($themeExists, $versionAlive, $ttlSeconds, $now): array {
            /** @var ThemeLayoutEntitySolidifySerialKey $key */
            $key = $job['key'];
            // Test/history preview scopes must not accumulate in shared generated/ (architect-confirmed GC).
            if (str_starts_with($key->canonicalScope, 'history.test.')) {
                return ['stale' => true, 'reason' => 'history_test_scope'];
            }
            if (!$themeExists($key->themeId)) {
                return ['stale' => true, 'reason' => 'theme_missing'];
            }
            if ($key->themeVersionId > 0 && !$versionAlive(
                $key->themeId,
                $key->themeVersionId,
                $key->canonicalScope,
                $key->area,
                $key->storeMode,
            )) {
                return ['stale' => true, 'reason' => 'version_missing'];
            }
            $queuedAt = (string)($job['queued_at'] ?? '');
            if ($queuedAt !== '') {
                $ts = strtotime($queuedAt);
                if ($ts !== false && ($now - $ts) > $ttlSeconds) {
                    return ['stale' => true, 'reason' => 'ttl_exceeded'];
                }
            }

            return ['stale' => false, 'reason' => ''];
        };
    }

    /**
     * @return null|array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string}
     */
    private function readJobFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $key = ThemeLayoutEntitySolidifySerialKey::fromParts(
            (int)($data['theme_id'] ?? 0),
            (string)($data['area'] ?? 'frontend'),
            (string)($data['scope'] ?? 'default.default.default'),
            (string)($data['store_mode'] ?? 'normal'),
            (string)($data['layout_type'] ?? 'homepage'),
            (string)($data['layout_option'] ?? 'default'),
            (int)($data['theme_version_id'] ?? 0),
            (int)($data['content_revision'] ?? 0),
        );
        if ($key->themeId < 1) {
            return null;
        }

        return [
            'key' => $key,
            'expected_fp' => trim((string)($data['expected_fp'] ?? '')),
            'path' => $path,
            'queued_at' => trim((string)($data['queued_at'] ?? '')),
        ];
    }

    private function archiveSuperseded(string $path, string $reason): void
    {
        if (!is_file($path)) {
            return;
        }
        $failedDir = dirname($path) . DIRECTORY_SEPARATOR . 'failed';
        if (!is_dir($failedDir) && !@mkdir($failedDir, 0775, true) && !is_dir($failedDir)) {
            @unlink($path);

            return;
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }
        $data['failed_at'] = gmdate('c');
        $data['fail_reason'] = $reason;
        $dest = $failedDir . DIRECTORY_SEPARATOR . basename($path);
        if ($dest === $path) {
            @unlink($path);

            return;
        }
        @file_put_contents(
            $dest,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
        @unlink($path);
    }

    private function pathFor(ThemeLayoutEntitySolidifySerialKey $key): string
    {
        return rtrim($this->paths->stateRoot(), '/\\')
            . DIRECTORY_SEPARATOR . (string)$key->themeId
            . DIRECTORY_SEPARATOR . '.solidify-jobs'
            . DIRECTORY_SEPARATOR . $key->slotHash() . '.json';
    }
}
