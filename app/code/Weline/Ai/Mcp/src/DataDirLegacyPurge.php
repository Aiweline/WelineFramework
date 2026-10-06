<?php

declare(strict_types=1);

namespace LearningMcp;

use Closure;
use Throwable;

/**
 * File-edit snapshots and one-off git clones are not learning or index data.
 * Worker / prepare_project expire them after storage.legacy_ttl.
 */
final class DataDirLegacyPurge
{
    private const DIRECTORY_NAMES = [
        'edit-journal',
        'history-backups',
        'edit-locks',
    ];

    private readonly Closure $clock;

    public function __construct(private readonly Config $config, ?callable $clock = null)
    {
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    /** @return array<string, mixed> */
    public function sweep(): array
    {
        if (!(bool) $this->config->get('storage.purge_legacy', true)) {
            return [
                'enabled' => false,
                'ttl_seconds' => 0,
                'removed' => [],
                'errors' => [],
            ];
        }
        $ttl = $this->config->duration('storage.legacy_ttl');
        $cutoff = ($this->clock)() - $ttl;
        $removed = [];
        $errors = [];
        $dataDir = $this->config->dataDir();
        foreach ($this->roots($dataDir) as $root) {
            foreach (self::DIRECTORY_NAMES as $name) {
                $path = $root . DIRECTORY_SEPARATOR . $name;
                try {
                    $removed = array_merge($removed, $this->expireContained($path, $root, $cutoff));
                } catch (Throwable $exception) {
                    $errors[] = $path . ': ' . $exception->getMessage();
                }
            }
        }
        $shared = $this->sharedLayoutRoot($dataDir);
        if ($shared !== null) {
            $unscoped = $shared . DIRECTORY_SEPARATOR . 'learning.db';
            $scoped = $dataDir . DIRECTORY_SEPARATOR . 'learning.db';
            if (is_file($unscoped) && is_file($scoped)) {
                foreach ([$unscoped, $unscoped . '-wal', $unscoped . '-shm'] as $file) {
                    try {
                        if ($this->removeContainedPath($file, $shared)) {
                            $removed[] = $file;
                        }
                    } catch (Throwable $exception) {
                        $errors[] = $file . ': ' . $exception->getMessage();
                    }
                }
            }
        }

        return [
            'enabled' => true,
            'ttl_seconds' => $ttl,
            'removed' => $removed,
            'errors' => $errors,
        ];
    }

    /** @return list<string> */
    private function roots(string $dataDir): array
    {
        $roots = [$dataDir];
        $shared = $this->sharedLayoutRoot($dataDir);
        if ($shared !== null) {
            $roots[] = $shared;
        }

        return array_values(array_unique($roots));
    }

    private function sharedLayoutRoot(string $dataDir): ?string
    {
        $projects = dirname($dataDir);
        $shared = dirname($projects);
        if (basename($projects) !== 'projects') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', basename($dataDir)) !== 1) {
            return null;
        }

        return $shared;
    }

    /** @return list<string> */
    private function expireContained(string $path, string $root, int $cutoff): array
    {
        if (!file_exists($path) && !is_link($path)) {
            return [];
        }
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return [];
        }
        $parentReal = realpath(dirname($path));
        if ($parentReal === false || $parentReal !== $rootReal) {
            return [];
        }
        $base = basename($path);
        if (!in_array($base, self::DIRECTORY_NAMES, true)) {
            return [];
        }
        if (is_link($path) || is_file($path)) {
            if ($this->mtime($path) <= $cutoff && @unlink($path)) {
                return [$path];
            }

            return [];
        }
        $removed = [];
        $entries = scandir($path);
        if (!is_array($entries)) {
            return [];
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if ($this->newestMtime($child) > $cutoff) {
                continue;
            }
            if ($this->removeTree($child, $rootReal)) {
                $removed[] = $child;
            }
        }
        $remaining = scandir($path);
        if (is_array($remaining) && count($remaining) === 2) {
            @rmdir($path);
        }

        return $removed;
    }

    private function removeContainedPath(string $path, string $root): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return false;
        }
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return false;
        }
        $parentReal = realpath(dirname($path));
        if ($parentReal === false || $parentReal !== $rootReal) {
            return false;
        }
        $base = basename($path);
        if (!str_starts_with($base, 'learning.db')) {
            return false;
        }
        if (is_link($path) || is_file($path)) {
            return @unlink($path) === true;
        }

        return false;
    }

    private function removeTree(string $path, string $rootReal): bool
    {
        $real = realpath($path);
        if ($real === false || ($real !== $rootReal && !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR))) {
            return false;
        }
        if (is_link($path) || is_file($path)) {
            return @unlink($path) === true;
        }
        $entries = scandir($path);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry, $rootReal);
        }
        @rmdir($path);

        return !file_exists($path);
    }

    private function newestMtime(string $path): int
    {
        if (is_link($path) || is_file($path)) {
            return $this->mtime($path);
        }
        if (!is_dir($path)) {
            return 0;
        }
        $newest = 0;
        $entries = scandir($path);
        if (!is_array($entries)) {
            return $this->mtime($path);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $newest = max($newest, $this->newestMtime($path . DIRECTORY_SEPARATOR . $entry));
        }

        return $newest > 0 ? $newest : $this->mtime($path);
    }

    private function mtime(string $path): int
    {
        $mtime = @filemtime($path);

        return is_int($mtime) ? $mtime : 0;
    }
}
