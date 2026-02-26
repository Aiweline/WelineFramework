<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

/**
 * 代码备份服务
 * 
 * 职责：
 * 1. 在修改文件前备份到 dev/ai/code_backup
 * 2. 保持与 app/ 相同的目录结构
 * 3. 支持版本化备份（带时间戳）
 * 4. 提供恢复功能
 */
class CodeBackupService
{
    private string $backupDir;
    private bool $enabled = true;
    private bool $verbose = false;
    private array $backupLog = [];
    
    public function __construct()
    {
        $this->backupDir = BP . 'dev' . DS . 'ai' . DS . 'code_backup' . DS;
        $this->ensureDirectoryExists($this->backupDir);
    }
    
    /**
     * 设置是否启用备份
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }
    
    /**
     * 设置详细输出
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    /**
     * 备份单个文件
     */
    public function backupFile(string $filePath): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        // 计算相对路径
        $relativePath = $this->getRelativePath($filePath);
        if (!$relativePath) {
            $this->log("跳过非项目文件: {$filePath}");
            return null;
        }
        
        // 构建备份路径
        $timestamp = date('Ymd_His');
        $backupPath = $this->backupDir . $relativePath . '.' . $timestamp . '.bak';
        
        // 确保目录存在
        $backupDir = dirname($backupPath);
        $this->ensureDirectoryExists($backupDir);
        
        // 复制文件
        if (copy($filePath, $backupPath)) {
            $this->log("✓ 备份: {$relativePath}");
            $this->recordBackup($filePath, $backupPath);
            return $backupPath;
        }
        
        $this->log("✗ 备份失败: {$relativePath}");
        return null;
    }
    
    /**
     * 批量备份文件
     */
    public function backupFiles(array $filePaths): array
    {
        $results = [];
        
        foreach ($filePaths as $filePath) {
            $backupPath = $this->backupFile($filePath);
            if ($backupPath) {
                $results[$filePath] = $backupPath;
            }
        }
        
        return $results;
    }
    
    /**
     * 备份目录
     */
    public function backupDirectory(string $dirPath, bool $recursive = true): array
    {
        $results = [];
        
        if (!is_dir($dirPath)) {
            return $results;
        }
        
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            )
            : new \DirectoryIterator($dirPath);
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $backupPath = $this->backupFile($file->getPathname());
                if ($backupPath) {
                    $results[$file->getPathname()] = $backupPath;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * 恢复文件
     */
    public function restoreFile(string $backupPath): bool
    {
        if (!file_exists($backupPath)) {
            $this->log("备份文件不存在: {$backupPath}");
            return false;
        }
        
        // 从备份路径推断原始路径
        $originalPath = $this->getOriginalPath($backupPath);
        if (!$originalPath) {
            $this->log("无法推断原始路径: {$backupPath}");
            return false;
        }
        
        // 确保目录存在
        $dir = dirname($originalPath);
        $this->ensureDirectoryExists($dir);
        
        // 恢复文件
        if (copy($backupPath, $originalPath)) {
            $this->log("✓ 恢复: {$originalPath}");
            return true;
        }
        
        $this->log("✗ 恢复失败: {$originalPath}");
        return false;
    }
    
    /**
     * 获取文件的所有备份
     */
    public function getBackups(string $filePath): array
    {
        $relativePath = $this->getRelativePath($filePath);
        if (!$relativePath) {
            return [];
        }
        
        $pattern = $this->backupDir . $relativePath . '.*.bak';
        $backups = glob($pattern);
        
        // 按时间排序（最新在前）
        usort($backups, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $result = [];
        foreach ($backups as $backup) {
            $result[] = [
                'path' => $backup,
                'time' => filemtime($backup),
                'size' => filesize($backup),
                'timestamp' => $this->extractTimestamp($backup),
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取最新备份
     */
    public function getLatestBackup(string $filePath): ?string
    {
        $backups = $this->getBackups($filePath);
        return $backups[0]['path'] ?? null;
    }
    
    /**
     * 清理旧备份（保留最近 N 个）
     */
    public function cleanupBackups(string $filePath, int $keepCount = 5): int
    {
        $backups = $this->getBackups($filePath);
        $deleted = 0;
        
        if (count($backups) <= $keepCount) {
            return 0;
        }
        
        $toDelete = array_slice($backups, $keepCount);
        
        foreach ($toDelete as $backup) {
            if (unlink($backup['path'])) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * 清理所有过期备份
     */
    public function cleanupAllBackups(int $keepCount = 5, int $maxAgeDays = 30): array
    {
        $stats = [
            'scanned' => 0,
            'deleted' => 0,
            'freed_bytes' => 0,
        ];
        
        $maxAge = time() - ($maxAgeDays * 24 * 60 * 60);
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backupDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $filesByOriginal = [];
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'bak') {
                $stats['scanned']++;
                $originalPath = $this->getOriginalPath($file->getPathname());
                
                if ($originalPath) {
                    $filesByOriginal[$originalPath][] = [
                        'path' => $file->getPathname(),
                        'time' => $file->getMTime(),
                        'size' => $file->getSize(),
                    ];
                }
            }
        }
        
        // 清理每个原始文件的备份
        foreach ($filesByOriginal as $original => $backups) {
            usort($backups, fn($a, $b) => $b['time'] - $a['time']);
            
            foreach ($backups as $idx => $backup) {
                // 保留最近 N 个，或删除过期的
                if ($idx >= $keepCount || $backup['time'] < $maxAge) {
                    if (unlink($backup['path'])) {
                        $stats['deleted']++;
                        $stats['freed_bytes'] += $backup['size'];
                    }
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * 比较文件与备份
     */
    public function compareWithBackup(string $filePath, ?string $backupPath = null): array
    {
        if (!$backupPath) {
            $backupPath = $this->getLatestBackup($filePath);
        }
        
        if (!$backupPath || !file_exists($backupPath)) {
            return ['has_backup' => false];
        }
        
        $currentContent = file_exists($filePath) ? file_get_contents($filePath) : '';
        $backupContent = file_get_contents($backupPath);
        
        return [
            'has_backup' => true,
            'backup_path' => $backupPath,
            'backup_time' => filemtime($backupPath),
            'is_different' => $currentContent !== $backupContent,
            'current_size' => strlen($currentContent),
            'backup_size' => strlen($backupContent),
        ];
    }
    
    /**
     * 获取备份统计
     */
    public function getStats(): array
    {
        $stats = [
            'total_backups' => 0,
            'total_size' => 0,
            'files_backed_up' => 0,
            'oldest_backup' => null,
            'newest_backup' => null,
        ];
        
        if (!is_dir($this->backupDir)) {
            return $stats;
        }
        
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backupDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'bak') {
                $stats['total_backups']++;
                $stats['total_size'] += $file->getSize();
                
                $mtime = $file->getMTime();
                if ($stats['oldest_backup'] === null || $mtime < $stats['oldest_backup']) {
                    $stats['oldest_backup'] = $mtime;
                }
                if ($stats['newest_backup'] === null || $mtime > $stats['newest_backup']) {
                    $stats['newest_backup'] = $mtime;
                }
                
                $originalPath = $this->getOriginalPath($file->getPathname());
                if ($originalPath) {
                    $files[$originalPath] = true;
                }
            }
        }
        
        $stats['files_backed_up'] = count($files);
        
        return $stats;
    }
    
    /**
     * 获取相对路径
     */
    private function getRelativePath(string $filePath): ?string
    {
        $filePath = str_replace('\\', '/', realpath($filePath) ?: $filePath);
        $basePath = str_replace('\\', '/', BP);
        
        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }
        
        return null;
    }
    
    /**
     * 从备份路径获取原始路径
     */
    private function getOriginalPath(string $backupPath): ?string
    {
        // 移除 .YYYYMMDD_HHMMSS.bak 后缀
        if (preg_match('/^(.+)\.\d{8}_\d{6}\.bak$/', $backupPath, $match)) {
            $relativePath = str_replace('\\', '/', $match[1]);
            $backupDirNormalized = str_replace('\\', '/', $this->backupDir);
            
            if (str_starts_with($relativePath, $backupDirNormalized)) {
                $relative = substr($relativePath, strlen($backupDirNormalized));
                return BP . $relative;
            }
        }
        
        return null;
    }
    
    /**
     * 提取时间戳
     */
    private function extractTimestamp(string $backupPath): ?string
    {
        if (preg_match('/\.(\d{8}_\d{6})\.bak$/', $backupPath, $match)) {
            return $match[1];
        }
        return null;
    }
    
    /**
     * 记录备份
     */
    private function recordBackup(string $originalPath, string $backupPath): void
    {
        $this->backupLog[] = [
            'original' => $originalPath,
            'backup' => $backupPath,
            'time' => time(),
        ];
        
        // 写入备份日志
        $logFile = $this->backupDir . 'backup.log';
        $logEntry = date('Y-m-d H:i:s') . " | {$originalPath} -> {$backupPath}\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 获取本次会话的备份记录
     */
    public function getBackupLog(): array
    {
        return $this->backupLog;
    }
    
    /**
     * 确保目录存在
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[Backup] {$message}\n";
        }
    }
}
