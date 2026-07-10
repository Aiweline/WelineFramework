<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;

/**
 * 正式站强制整站备份（业务 + 核心代码），供发布/回滚/核心更新前使用。
 */
class DeploySiteBackupService
{
    private bool $isWindows;

    public function __construct()
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * @return array{backup_id:string,archive_path:string,manifest_path:string}
     */
    public function createSiteBackup(string $deployRoot, string $trigger, array $meta = []): array
    {
        $deployRoot = rtrim($deployRoot, "\\/");
        if ($deployRoot === '' || !is_dir($deployRoot)) {
            throw new \RuntimeException((string)__('部署根目录不存在，无法创建备份。'));
        }

        $backupDir = Env::backup_dir . 'deploy' . DS;
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new \RuntimeException((string)__('无法创建备份目录。'));
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupId = 'backup_' . $timestamp;
        $backupPath = $backupDir . $backupId;
        $archivePath = $this->isWindows ? $backupPath . '.zip' : $backupPath . '.tar.gz';

        if ($this->isWindows) {
            $this->backupWithPowerShell($deployRoot, $backupPath);
        } else {
            $this->backupWithTar($deployRoot, $backupPath);
        }

        if (!is_file($archivePath)) {
            throw new \RuntimeException((string)__('整站备份失败：未生成归档文件。'));
        }

        $manifest = [
            'backup_id' => $backupId,
            'created_at' => time(),
            'trigger' => $trigger,
            'environment' => (string)($meta['environment'] ?? 'prod'),
            'deploy_root' => $deployRoot,
            'archive_path' => $archivePath,
            'archive_size' => (int)filesize($archivePath),
            'git_commit' => (string)($meta['git_commit'] ?? ''),
            'deploy_version' => (string)($meta['deploy_version'] ?? ''),
            'ref' => (string)($meta['ref'] ?? ''),
        ];
        $manifestPath = $backupPath . '.manifest.json';
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return [
            'backup_id' => $backupId,
            'archive_path' => $archivePath,
            'manifest_path' => $manifestPath,
        ];
    }

    private function backupWithPowerShell(string $deployRoot, string $backupPath): void
    {
        $sourcePath = str_replace('\\', '/', $deployRoot);
        $backupZip = str_replace('\\', '/', $backupPath . '.zip');
        $excludePatterns = [
            '*\\var\\cache*',
            '*\\var\\session*',
            '*\\var\\log*',
            '*\\.git*',
            '*\\vendor*',
            '*\\node_modules*',
        ];
        $where = implode(' -and ', array_map(
            static fn(string $pattern): string => "\$_.FullName -notlike '$pattern'",
            $excludePatterns
        ));
        $psScript = "Get-ChildItem -Path '$sourcePath' -Recurse | Where-Object { $where } | " .
            "Compress-Archive -DestinationPath '$backupZip' -Force";
        exec('powershell -Command "' . str_replace('"', '`"', $psScript) . '"', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException((string)__('PowerShell 备份失败。'));
        }
    }

    private function backupWithTar(string $deployRoot, string $backupPath): void
    {
        $command = 'cd ' . escapeshellarg($deployRoot)
            . " && tar --exclude='var/cache' --exclude='var/session' --exclude='var/log'"
            . " --exclude='.git' --exclude='vendor' --exclude='node_modules'"
            . ' -czf ' . escapeshellarg($backupPath . '.tar.gz') . ' . 2>&1';
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException((string)__('tar 备份失败：%{1}', [implode("\n", $output)]));
        }
    }
}
