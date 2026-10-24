<?php

declare(strict_types=1);

namespace LearningMcp;

final class CognitionService
{
    private string $dir;

    private string $repository;

    public function __construct(string $projectDataDir, ?string $repository = null)
    {
        $this->dir = rtrim($projectDataDir, '/\\') . '/cognition';
        $this->repository = rtrim($repository ?? (\defined('BP') ? \BP : (string)\getcwd()), '/\\');
    }

    public function maintain(array $entries): array
    {
        $written = 0;
        $skipped = 0;
        $byModule = [];
        $counts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)
                || !isset($entry['path']) || !is_string($entry['path']) || $entry['path'] === ''
                || !isset($entry['f']) || !is_string($entry['f'])
            ) {
                $skipped++;
                continue;
            }
            if (!isset($entry['hash'])) {
                $file = $this->repository . '/' . ltrim((string)$entry['path'], '/');
                $entry['hash'] = is_file($file) ? 'sha256:' . hash_file('sha256', $file) : 'sha256:';
            }
            $module = $this->moduleFor($entry['path']);
            $byModule[$module][] = $entry;
            $counts[$module] = ($counts[$module] ?? 0) + 1;
            $written++;
        }
        foreach ($byModule as $module => $rows) {
            $file = $this->shardPath($module);
            $existing = $this->readShard($module);
            foreach ($rows as $row) {
                $existing[$row['path']] = $row;
            }
            $counts[$module] = count($existing);
            if (!$this->atomicWrite($file, implode("\n", array_map(
                static fn(array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                array_values($existing)
            )) . "\n")) {
                return ['written' => 0, 'skipped' => count($entries)];
            }
        }
        $manifest = [
            'version' => 1,
            'updated_at' => date('c'),
            'modules' => $counts,
        ];
        if (!$this->atomicWrite($this->dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES) . "\n")) {
            return ['written' => 0, 'skipped' => count($entries)];
        }
        return ['written' => $written, 'skipped' => $skipped, 'modules' => $counts];
    }

    public function query(array $args): array
    {
        $module = isset($args['module']) && is_string($args['module']) && $args['module'] !== '' ? $args['module'] : null;
        $path = isset($args['path']) && is_string($args['path']) && $args['path'] !== '' ? $args['path'] : null;
        $needle = isset($args['query']) && is_string($args['query']) && $args['query'] !== '' ? mb_strtolower($args['query']) : null;
        $limit = isset($args['limit']) && is_int($args['limit']) ? max(1, min(100, $args['limit'])) : 20;

        $modules = [];
        if ($module !== null) {
            $modules[] = $module;
        } elseif ($path !== null) {
            $modules[] = $this->moduleFor($path);
        } else {
            foreach (glob($this->dir . '/*.jsonl') ?: [] as $file) {
                $modules[] = basename($file, '.jsonl');
            }
        }

        $out = [];
        foreach ($modules as $m) {
            foreach ($this->readShard($m) as $row) {
                if ($path !== null && ($row['path'] ?? null) !== $path) {
                    continue;
                }
                if ($needle !== null) {
                    $hay = mb_strtolower(json_encode(
                        [$row['f'] ?? '', $row['r'] ?? '', $row['a'] ?? '', $row['s'] ?? ''],
                        JSON_UNESCAPED_UNICODE
                    ));
                    if (!str_contains($hay, $needle)) {
                        continue;
                    }
                }
                $out[] = $this->withStale($row);
                if (count($out) >= $limit) {
                    return ['count' => count($out), 'entries' => $out];
                }
            }
        }
        return ['count' => count($out), 'entries' => $out];
    }

    public function overview(): array
    {
        $manifest = $this->manifest();
        $total = array_sum($manifest['modules'] ?? []);
        return [
            'version' => $manifest['version'] ?? 1,
            'updated_at' => $manifest['updated_at'] ?? null,
            'total' => $total,
            'modules' => $manifest['modules'] ?? [],
        ];
    }

    public function status(): array
    {
        $manifest = $this->manifest();
        $total = 0;
        $size = 0;
        $shardCount = 0;
        foreach (glob($this->dir . '/*.jsonl') ?: [] as $file) {
            $shardCount++;
            $size += (int) filesize($file);
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && isset($row['path'])) {
                    $total++;
                }
            }
        }
        return [
            'manifest_version' => $manifest['version'] ?? 1,
            'total' => $total,
            'shards' => ['count' => $shardCount, 'bytes' => $size, 'entries' => $total],
        ];
    }

    private function moduleFor(string $path): string
    {
        if (preg_match('#^(?:\\./)?app/code/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/#', $path, $m)) {
            return $m[1] . '_' . $m[2];
        }
        return '_root';
    }

    private function shardPath(string $module): string
    {
        return $this->dir . '/' . $module . '.jsonl';
    }

    private function readShard(string $module): array
    {
        $file = $this->shardPath($module);
        if (!is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['path']) && is_string($row['path']) && $row['path'] !== '') {
                $rows[$row['path']] = $row;
            }
        }
        return $rows;
    }

    private function manifest(): array
    {
        $file = $this->dir . '/manifest.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function withStale(array $row): array
    {
        $file = $this->repository . '/' . ltrim((string)($row['path'] ?? ''), '/');
        $hash = (string) ($row['hash'] ?? '');
        $hash = str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash;
        $current = is_file($file) ? (string) hash_file('sha256', $file) : null;
        $row['stale'] = $current === null || !hash_equals($hash, $current);
        return $row;
    }

    private function atomicWrite(string $file, string $content): bool
    {
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0777, true) && !is_dir(dirname($file))) {
            return false;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $file)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
        return true;
    }
}
