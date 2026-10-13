<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Durable solidify job intents under generated/theme-layout-entities/{theme}/.solidify-jobs/.
 * Cron (and optional WLS post-response) drain these; FPM cannot rely on in-memory queues.
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
     * @return array{enqueued:bool,coalesced:bool}
     */
    public function put(ThemeLayoutEntitySolidifySerialKey $key, string $expectedFingerprint): array
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            return ['enqueued' => false, 'coalesced' => true];
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('theme_layout_solidify_job_dir_failed');
        }
        $payload = json_encode([
            'serial' => $key->toString(),
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
            // Lost the race — another writer created the job.
            @unlink($tmp);
            if (is_file($path)) {
                return ['enqueued' => false, 'coalesced' => true];
            }
            throw new \RuntimeException('theme_layout_solidify_job_rename_failed');
        }

        return ['enqueued' => true, 'coalesced' => false];
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
        $data['failed_at'] = gmdate('c');
        $data['fail_reason'] = trim($reason);
        $dest = $failedDir . DIRECTORY_SEPARATOR . basename($path);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($dest, $encoded, LOCK_EX) === false) {
            return false;
        }
        @unlink($path);

        return true;
    }

    /**
     * @return list<array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string}>
     */
    public function listPending(int $limit = 32): array
    {
        $root = rtrim($this->paths->root(), '/\\');
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

    private function pathFor(ThemeLayoutEntitySolidifySerialKey $key): string
    {
        return rtrim($this->paths->root(), '/\\')
            . DIRECTORY_SEPARATOR . (string)$key->themeId
            . DIRECTORY_SEPARATOR . '.solidify-jobs'
            . DIRECTORY_SEPARATOR . $key->hash() . '.json';
    }
}
