<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

/**
 * After LEARNING_MCP_DATA_DIR moved into projects/<hash>/, leftover generations under
 * the shared ~/.learning-mcp/indexes are duplicate caches. Auto GC / legacy purge
 * remove them once the project-local index exists and the migration marker is present.
 *
 * Never touches sibling projects/<other-hash>/ trees.
 */
final class SharedIndexLeftoverPurge
{
    private const MIGRATION_MARKER = '.migrated-from-shared-v1';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function sweep(bool $allowMutation = true): array
    {
        $enabled = (bool) $this->config->get('index.gc.enabled', true)
            && (bool) $this->config->get('index.gc.purge_shared_leftover', true);
        $metrics = [
            'enabled' => $enabled,
            'eligible' => false,
            'reason' => '',
            'scanned' => 0,
            'candidates' => [],
            'removed' => [],
            'skipped_locked' => 0,
            'skipped_unsafe' => 0,
            'errors' => [],
        ];
        if (!$enabled) {
            $metrics['reason'] = 'disabled';

            return $metrics;
        }
        $dataDir = rtrim($this->config->dataDir(), DIRECTORY_SEPARATOR);
        $shared = $this->sharedLayoutRoot($dataDir);
        if ($shared === null) {
            $metrics['reason'] = 'not_project_isolated_data_dir';

            return $metrics;
        }
        $marker = $dataDir . DIRECTORY_SEPARATOR . self::MIGRATION_MARKER;
        if (!is_file($marker) || is_link($marker)) {
            $metrics['reason'] = 'migration_marker_missing';

            return $metrics;
        }
        if (!$this->projectHasLocalIndex($dataDir)) {
            $metrics['reason'] = 'project_local_index_missing';

            return $metrics;
        }
        $sharedIndexes = $shared . DIRECTORY_SEPARATOR . 'indexes';
        if (!is_dir($sharedIndexes) || is_link($sharedIndexes)) {
            $metrics['eligible'] = true;
            $metrics['reason'] = 'shared_indexes_absent';

            return $metrics;
        }
        $metrics['eligible'] = true;
        $metrics['reason'] = 'purge_shared_leftover';
        $entries = scandir($sharedIndexes);
        if (!is_array($entries)) {
            $metrics['errors'][] = 'unable_to_scan_shared_indexes';

            return $metrics;
        }
        $sharedReal = realpath($sharedIndexes);
        if ($sharedReal === false) {
            $metrics['errors'][] = 'shared_indexes_unresolved';

            return $metrics;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            ++$metrics['scanned'];
            $directory = $sharedIndexes . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($directory) || is_link($directory)) {
                ++$metrics['skipped_unsafe'];
                continue;
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $entry) !== 1) {
                ++$metrics['skipped_unsafe'];
                continue;
            }
            $metrics['candidates'][] = $directory;
            if (!$allowMutation) {
                continue;
            }
            $lock = $this->exclusiveGenerationLock($directory);
            if (!is_resource($lock)) {
                ++$metrics['skipped_locked'];
                continue;
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            try {
                if (!$this->removeTree($directory, $sharedReal)) {
                    $metrics['errors'][] = $directory . ': remove_failed';
                    continue;
                }
                $metrics['removed'][] = $directory;
            } catch (Throwable $exception) {
                $metrics['errors'][] = $directory . ': ' . $exception->getMessage();
            }
        }

        return $metrics;
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

    private function projectHasLocalIndex(string $dataDir): bool
    {
        $indexes = $dataDir . DIRECTORY_SEPARATOR . 'indexes';
        if (!is_dir($indexes) || is_link($indexes)) {
            return false;
        }
        $entries = scandir($indexes);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $entry) !== 1) {
                continue;
            }
            $sqlite = $indexes . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'project.sqlite';
            if (is_file($sqlite) && !is_link($sqlite)) {
                return true;
            }
        }

        return false;
    }

    /** @return resource|null */
    private function exclusiveGenerationLock(string $directory): mixed
    {
        $path = $directory . DIRECTORY_SEPARATOR . ProjectIndex::LEASE_FILE;
        if (is_link($path)) {
            return null;
        }
        if (!is_dir($directory)) {
            return null;
        }
        $handle = @fopen($path, 'c+');
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
}
