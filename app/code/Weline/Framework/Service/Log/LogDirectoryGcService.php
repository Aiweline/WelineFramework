<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Log;

use Weline\Framework\App\Env;

/**
 * 应用日志目录 GC：对超限活日志 copytruncate，并 prune 轮转归档 / cron history。
 *
 * 与框架 Logger 写路径轮转互补：覆盖 php_error.log、var/cron.log 等绕过 Handler 的文件，
 * 以及 WLS 长驻进程已打开 FD 的 inode（copytruncate 截断原路径）。
 */
final class LogDirectoryGcService
{
    public const DEFAULT_MAX_SIZE = 52428800; // 50 MiB
    public const DEFAULT_MAX_FILES = 7;
    public const DEFAULT_HISTORY_RETENTION_DAYS = 7;

    /**
     * @return array{
     *   scanned:int,
     *   rotated:int,
     *   pruned_archives:int,
     *   pruned_history:int,
     *   bytes_before:int,
     *   errors:list<string>
     * }
     */
    public function run(?string $logDir = null, ?string $cronLogFile = null, ?int $maxSize = null, ?int $maxFiles = null, ?int $historyRetentionDays = null): array
    {
        $logDir = $this->resolveLogDir($logDir);
        $cronLogFile = $this->resolveCronLogFile($cronLogFile);
        $maxSize = $maxSize ?? $this->resolveMaxSize();
        $maxFiles = $maxFiles ?? $this->resolveMaxFiles();
        $historyRetentionDays = $historyRetentionDays ?? self::DEFAULT_HISTORY_RETENTION_DAYS;

        $stats = [
            'scanned' => 0,
            'rotated' => 0,
            'pruned_archives' => 0,
            'pruned_history' => 0,
            'bytes_before' => 0,
            'errors' => [],
        ];

        if ($maxSize <= 0) {
            $stats['errors'][] = 'max_size_disabled';
            return $stats;
        }

        $liveFiles = [];
        if (is_dir($logDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($logDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }
                $path = $fileInfo->getPathname();
                if (!str_ends_with(strtolower($path), '.log')) {
                    continue;
                }
                // cron/history 托管快照走独立年龄清理，不参与 copytruncate
                if ($this->isUnderCronHistory($logDir, $path)) {
                    continue;
                }
                $stats['scanned']++;
                $size = (int)@$fileInfo->getSize();
                $stats['bytes_before'] += $size;
                if ($this->isRotatedArchiveName(basename($path))) {
                    continue;
                }
                $liveFiles[] = $path;
                if ($size >= $maxSize) {
                    try {
                        $this->copyTruncate($path);
                        $stats['rotated']++;
                    } catch (\Throwable $e) {
                        $stats['errors'][] = $path . ': ' . $e->getMessage();
                    }
                }
            }
        }

        if (is_file($cronLogFile)) {
            $stats['scanned']++;
            $size = (int)@filesize($cronLogFile);
            $stats['bytes_before'] += max(0, $size);
            $liveFiles[] = $cronLogFile;
            if ($size >= $maxSize) {
                try {
                    $this->copyTruncate($cronLogFile);
                    $stats['rotated']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = $cronLogFile . ': ' . $e->getMessage();
                }
            }
        }

        foreach (array_unique($liveFiles) as $livePath) {
            $stats['pruned_archives'] += $this->pruneArchivesForLive($livePath, $maxFiles);
        }

        if (is_dir($logDir)) {
            $stats['pruned_history'] += $this->pruneCronHistory($logDir, $historyRetentionDays);
        }

        return $stats;
    }

    private function resolveLogDir(?string $logDir): string
    {
        if ($logDir !== null && $logDir !== '') {
            return rtrim($logDir, "/\\");
        }
        if (defined('BP')) {
            return rtrim(BP, "/\\") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_log';
    }

    private function resolveCronLogFile(?string $cronLogFile): string
    {
        if ($cronLogFile !== null && $cronLogFile !== '') {
            return $cronLogFile;
        }
        if (defined('BP')) {
            return rtrim(BP, "/\\") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cron.log';
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_cron.log';
    }

    private function resolveMaxSize(): int
    {
        try {
            $configured = Env::getInstance()->getConfig('log.rotate.max_size', self::DEFAULT_MAX_SIZE);
            if (is_numeric($configured)) {
                return (int)$configured;
            }
        } catch (\Throwable) {
        }
        return self::DEFAULT_MAX_SIZE;
    }

    private function resolveMaxFiles(): int
    {
        try {
            $configured = Env::getInstance()->getConfig('log.rotate.max_files', self::DEFAULT_MAX_FILES);
            if (is_numeric($configured)) {
                return max(1, (int)$configured);
            }
        } catch (\Throwable) {
        }
        return self::DEFAULT_MAX_FILES;
    }

    private function isUnderCronHistory(string $logDir, string $path): bool
    {
        $historyRoot = $logDir . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'history';
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = str_replace('\\', '/', $historyRoot);
        return str_starts_with($normalizedPath, rtrim($normalizedRoot, '/') . '/');
    }

    /**
     * 轮转归档名：{base}-{Y-m-d}.log / {base}-{Y-m-d_H-i-s}.log / 同秒冲突后缀 -N
     */
    public function isRotatedArchiveName(string $basename): bool
    {
        return (bool)preg_match(
            '/^.+-\d{4}-\d{2}-\d{2}(?:_\d{2}-\d{2}-\d{2})?(?:-\d+)?\.log$/i',
            $basename
        );
    }

    /**
     * @throws \RuntimeException
     */
    public function copyTruncate(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $dir = dirname($path);
        $info = pathinfo($path);
        $base = $info['filename'] ?? 'log';
        $ext = $info['extension'] ?? 'log';
        $suffix = date('Y-m-d_H-i-s');
        $archive = $dir . DIRECTORY_SEPARATOR . $base . '-' . $suffix . '.' . $ext;
        $n = 0;
        while (is_file($archive)) {
            $n++;
            $archive = $dir . DIRECTORY_SEPARATOR . $base . '-' . $suffix . '-' . $n . '.' . $ext;
        }

        if (!@copy($path, $archive)) {
            throw new \RuntimeException('copy_failed');
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            @unlink($archive);
            throw new \RuntimeException('open_failed');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('lock_failed');
            }
            if (!ftruncate($handle, 0)) {
                throw new \RuntimeException('truncate_failed');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } catch (\Throwable $e) {
            @unlink($archive);
            throw $e;
        } finally {
            fclose($handle);
        }
    }

    private function pruneArchivesForLive(string $livePath, int $maxFiles): int
    {
        $dir = dirname($livePath);
        $info = pathinfo($livePath);
        $base = $info['filename'] ?? '';
        $ext = $info['extension'] ?? 'log';
        if ($base === '') {
            return 0;
        }
        $pattern = $dir . DIRECTORY_SEPARATOR . $base . '-*.' . $ext;
        $files = glob($pattern) ?: [];
        $archives = array_values(array_filter(
            $files,
            fn(string $f): bool => $this->isRotatedArchiveName(basename($f))
        ));
        if (count($archives) <= $maxFiles) {
            return 0;
        }
        usort($archives, static fn(string $a, string $b): int => (filemtime($a) ?: 0) <=> (filemtime($b) ?: 0));
        $toDelete = count($archives) - $maxFiles;
        $removed = 0;
        for ($i = 0; $i < $toDelete; $i++) {
            if (@unlink($archives[$i])) {
                $removed++;
            }
        }
        return $removed;
    }

    private function pruneCronHistory(string $logDir, int $retentionDays): int
    {
        $historyRoot = $logDir . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'history';
        if (!is_dir($historyRoot) || $retentionDays < 1) {
            return 0;
        }
        $cutoff = time() - ($retentionDays * 86400);
        $removed = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($historyRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            $mtime = (int)$fileInfo->getMTime();
            if ($mtime > 0 && $mtime < $cutoff) {
                if (@unlink($fileInfo->getPathname())) {
                    $removed++;
                }
            }
        }
        return $removed;
    }
}
