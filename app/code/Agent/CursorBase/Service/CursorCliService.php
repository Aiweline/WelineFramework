<?php

declare(strict_types=1);

namespace Agent\CursorBase\Service;

use Agent\CursorBase\Api\CursorCliInterface;
use Agent\CursorBase\Helper\PlatformHelper;

/**
 * Cursor CLI 服务实现
 * 
 * 职责：Cursor 编辑器的 CLI 命令操作
 */
class CursorCliService implements CursorCliInterface
{
    private bool $verbose = false;

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * 唤醒 Cursor 并定位到指定文件和行
     */
    public function wake(string $filePath, int $line = 1): bool
    {
        $normalizedPath = PlatformHelper::toUnixPath($filePath);

        if (PlatformHelper::isWindows()) {
            $command = "start \"\" cursor --goto \"{$normalizedPath}:{$line}\"";
        } else {
            $command = "cursor --goto \"{$normalizedPath}:{$line}\" &";
        }

        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            $this->log("已唤醒 Cursor: cursor --goto {$normalizedPath}:{$line}");
            return true;
        }

        // 尝试 VSCode 兼容命令
        if (PlatformHelper::isWindows()) {
            $command = "start \"\" code --goto \"{$normalizedPath}:{$line}\"";
        } else {
            $command = "code --goto \"{$normalizedPath}:{$line}\" &";
        }

        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            $this->log("已唤醒 VSCode: code --goto {$normalizedPath}:{$line}");
            return true;
        }

        $this->log("唤醒编辑器失败");
        return false;
    }

    /**
     * 检查 Cursor 是否正在运行
     */
    public function isRunning(): bool
    {
        if (PlatformHelper::isWindows()) {
            exec('tasklist /FI "IMAGENAME eq Cursor.exe" 2>NUL', $output, $returnCode);
            foreach ($output as $line) {
                if (stripos($line, 'Cursor.exe') !== false) {
                    return true;
                }
            }
            return false;
        }

        exec('pgrep -x "Cursor" 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * 获取 Cursor 窗口句柄（Windows）
     */
    public function getWindowHandle(): ?int
    {
        if (!PlatformHelper::isWindows()) {
            return null;
        }

        $psScript = <<<'PS'
$cursor = Get-Process -Name "Cursor" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($cursor) {
    Write-Output $cursor.MainWindowHandle
}
PS;

        $tempScript = PlatformHelper::getTempDir() . DIRECTORY_SEPARATOR . 'get_cursor_hwnd.ps1';
        file_put_contents($tempScript, $psScript);

        exec("powershell -ExecutionPolicy Bypass -File \"{$tempScript}\"", $output, $returnCode);

        @unlink($tempScript);

        if ($returnCode === 0 && !empty($output[0])) {
            return (int) $output[0];
        }

        return null;
    }

    /**
     * 获取 Cursor 可执行文件路径
     */
    public function getExecutablePath(): ?string
    {
        if (PlatformHelper::isWindows()) {
            $possiblePaths = [
                getenv('LOCALAPPDATA') . '\\Programs\\cursor\\Cursor.exe',
                getenv('PROGRAMFILES') . '\\Cursor\\Cursor.exe',
                getenv('PROGRAMFILES(X86)') . '\\Cursor\\Cursor.exe',
            ];

            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }

            exec('where cursor 2>NUL', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                return $output[0];
            }
        } else {
            exec('which cursor 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                return $output[0];
            }
        }

        return null;
    }

    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[CursorCli] {$message}\n";
        }

        $logFile = BP . 'var/log/cursor-cli.log';
        PlatformHelper::ensureDirectoryExists(dirname($logFile));

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
