<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Backup;

use Agent\CursorSupervisor\Service\CodeBackupService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 备份管理命令
 */
class Lists extends CommandAbstract
{
    private CodeBackupService $backupService;
    
    public function __construct(CodeBackupService $backupService)
    {
        $this->backupService = $backupService;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        $verbose = isset($args['v']) || isset($args['verbose']);
        $cleanup = isset($args['cleanup']) || isset($args['c']);
        $restore = isset($args['restore']) || isset($args['r']);
        
        $this->backupService->setVerbose($verbose);
        
        // 提取路径参数
        $path = null;
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-') && !str_contains((string)$arg, ':')) {
                $path = $arg;
                break;
            }
        }
        
        if ($cleanup) {
            $this->cleanup();
            return;
        }
        
        if ($restore && $path) {
            $this->restore($path);
            return;
        }
        
        if ($path) {
            $this->showFileBackups($path);
        } else {
            $this->showStats();
        }
    }
    
    /**
     * 显示备份统计
     */
    private function showStats(): void
    {
        $stats = $this->backupService->getStats();
        
        $this->printer->success('📦 备份统计');
        $this->printer->printing('');
        $this->printer->printing("   备份总数: {$stats['total_backups']}");
        $this->printer->printing("   备份文件数: {$stats['files_backed_up']}");
        $this->printer->printing("   总大小: " . $this->formatBytes($stats['total_size']));
        
        if ($stats['oldest_backup']) {
            $this->printer->printing("   最旧备份: " . date('Y-m-d H:i:s', $stats['oldest_backup']));
        }
        if ($stats['newest_backup']) {
            $this->printer->printing("   最新备份: " . date('Y-m-d H:i:s', $stats['newest_backup']));
        }
        
        $this->printer->printing('');
        $this->printer->printing('备份目录: dev/ai/code_backup');
        $this->printer->printing('');
        $this->printer->note('使用方法:');
        $this->printer->printing('   查看文件备份: cursor:backup:list {file_path}');
        $this->printer->printing('   恢复备份: cursor:backup:list {file_path} --restore');
        $this->printer->printing('   清理备份: cursor:backup:list --cleanup');
    }
    
    /**
     * 显示文件的备份列表
     */
    private function showFileBackups(string $path): void
    {
        // 确保是绝对路径
        if (!str_starts_with($path, BP)) {
            $path = BP . ltrim($path, '/\\');
        }
        
        $backups = $this->backupService->getBackups($path);
        $relativePath = $this->getRelativePath($path);
        
        if (empty($backups)) {
            $this->printer->note("文件 {$relativePath} 暂无备份");
            return;
        }
        
        $this->printer->success("📦 文件备份: {$relativePath}");
        $this->printer->printing('');
        
        foreach ($backups as $idx => $backup) {
            $time = date('Y-m-d H:i:s', $backup['time']);
            $size = $this->formatBytes($backup['size']);
            $current = $idx === 0 ? ' [最新]' : '';
            
            $this->printer->printing("   {$idx}. {$time} ({$size}){$current}");
            $this->printer->printing("      路径: {$backup['path']}");
        }
        
        $this->printer->printing('');
        $this->printer->note("恢复最新备份: cursor:backup:list {$relativePath} --restore");
    }
    
    /**
     * 恢复备份
     */
    private function restore(string $path): void
    {
        // 确保是绝对路径
        if (!str_starts_with($path, BP)) {
            $path = BP . ltrim($path, '/\\');
        }
        
        $relativePath = $this->getRelativePath($path);
        $latestBackup = $this->backupService->getLatestBackup($path);
        
        if (!$latestBackup) {
            $this->printer->error("文件 {$relativePath} 暂无备份可恢复");
            return;
        }
        
        $comparison = $this->backupService->compareWithBackup($path);
        
        if (!$comparison['is_different']) {
            $this->printer->note("文件与备份相同，无需恢复");
            return;
        }
        
        $this->printer->printing("恢复文件: {$relativePath}");
        $this->printer->printing("   备份时间: " . date('Y-m-d H:i:s', $comparison['backup_time']));
        $this->printer->printing("   当前大小: " . $this->formatBytes($comparison['current_size']));
        $this->printer->printing("   备份大小: " . $this->formatBytes($comparison['backup_size']));
        
        if ($this->backupService->restoreFile($latestBackup)) {
            $this->printer->success("✅ 恢复成功！");
        } else {
            $this->printer->error("❌ 恢复失败");
        }
    }
    
    /**
     * 清理旧备份
     */
    private function cleanup(): void
    {
        $this->printer->printing('🧹 清理旧备份...');
        
        $stats = $this->backupService->cleanupAllBackups(5, 30);
        
        $this->printer->printing("   扫描备份: {$stats['scanned']}");
        $this->printer->printing("   删除备份: {$stats['deleted']}");
        $this->printer->printing("   释放空间: " . $this->formatBytes($stats['freed_bytes']));
        
        if ($stats['deleted'] > 0) {
            $this->printer->success("✅ 清理完成！");
        } else {
            $this->printer->note("无需清理");
        }
    }
    
    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * 获取相对路径
     */
    private function getRelativePath(string $filePath): string
    {
        $filePath = str_replace('\\', '/', $filePath);
        $basePath = str_replace('\\', '/', BP);
        
        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }
        
        return $filePath;
    }
    
    public function tip(): string
    {
        return __('管理代码备份（查看、恢复、清理）');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:backup:list',
            '管理 dev/ai/code_backup 目录下的代码备份',
            [
                '{file_path}' => '要查看备份的文件路径',
                '-v, --verbose' => '详细输出模式',
                '-r, --restore' => '恢复最新备份',
                '-c, --cleanup' => '清理旧备份（保留最近 5 个，删除 30 天前的）',
            ],
            [],
            [
                '查看统计' => 'php bin/w cursor:backup:list',
                '文件备份' => 'php bin/w cursor:backup:list app/code/Module/Service/Test.php',
                '恢复备份' => 'php bin/w cursor:backup:list app/code/Module/Service/Test.php --restore',
                '清理备份' => 'php bin/w cursor:backup:list --cleanup',
            ]
        );
    }
}
