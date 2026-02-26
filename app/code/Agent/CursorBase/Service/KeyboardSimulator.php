<?php

declare(strict_types=1);

namespace Agent\CursorBase\Service;

use Agent\CursorBase\Api\KeyboardSimulatorInterface;
use Agent\CursorBase\Helper\PlatformHelper;

/**
 * 按键模拟器实现
 * 
 * 职责：模拟键盘按键操作，用于自动触发 Cursor 执行
 */
class KeyboardSimulator implements KeyboardSimulatorInterface
{
    private bool $verbose = false;

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * 触发 Cursor 执行（模拟 Ctrl+K / Cmd+K）
     */
    public function triggerCursorExecution(): bool
    {
        $this->log("正在模拟按键触发 Cursor...");

        if (PlatformHelper::isWindows()) {
            return $this->triggerWindows();
        }

        if (PlatformHelper::isMac()) {
            return $this->triggerMac();
        }

        return $this->triggerLinux();
    }

    /**
     * 发送按键组合
     */
    public function sendKeys(array $keys): bool
    {
        if (PlatformHelper::isWindows()) {
            return $this->sendKeysWindows($keys);
        }

        if (PlatformHelper::isMac()) {
            return $this->sendKeysMac($keys);
        }

        return $this->sendKeysLinux($keys);
    }

    /**
     * 激活 Cursor 窗口
     */
    public function activateCursorWindow(): bool
    {
        if (PlatformHelper::isWindows()) {
            return $this->activateWindowWindows();
        }

        if (PlatformHelper::isMac()) {
            return $this->activateWindowMac();
        }

        return $this->activateWindowLinux();
    }

    /**
     * 检查是否支持按键模拟
     */
    public function isSupported(): bool
    {
        if (PlatformHelper::isWindows()) {
            return true;
        }

        if (PlatformHelper::isMac()) {
            return true;
        }

        exec('which xdotool 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Windows 触发 Cursor
     */
    private function triggerWindows(): bool
    {
        $psScript = <<<'PS'
Add-Type -AssemblyName System.Windows.Forms
Start-Sleep -Milliseconds 500

$cursor = Get-Process -Name "Cursor" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($cursor) {
    $hwnd = $cursor.MainWindowHandle
    Add-Type @"
    using System;
    using System.Runtime.InteropServices;
    public class Win32 {
        [DllImport("user32.dll")]
        public static extern bool SetForegroundWindow(IntPtr hWnd);
    }
"@
    [Win32]::SetForegroundWindow($hwnd)
    Start-Sleep -Milliseconds 500
    
    [System.Windows.Forms.SendKeys]::SendWait("^k")
    Start-Sleep -Milliseconds 300
    [System.Windows.Forms.SendKeys]::SendWait("{ENTER}")
    
    Write-Output "SUCCESS"
}
PS;

        $tempScript = PlatformHelper::getTempDir() . DIRECTORY_SEPARATOR . 'cursor_trigger.ps1';
        file_put_contents($tempScript, $psScript);

        exec("powershell -ExecutionPolicy Bypass -File \"{$tempScript}\"", $output, $returnCode);

        @unlink($tempScript);

        $success = $returnCode === 0 && in_array('SUCCESS', $output);

        if ($success) {
            $this->log("已发送 Ctrl+K + Enter 到 Cursor");
        } else {
            $this->log("PowerShell 执行失败，请手动按 Ctrl+K");
        }

        return $success;
    }

    /**
     * macOS 触发 Cursor
     */
    private function triggerMac(): bool
    {
        $appleScript = <<<'AS'
tell application "Cursor" to activate
delay 0.5
tell application "System Events"
    keystroke "k" using {command down}
    delay 0.3
    key code 36
end tell
AS;

        $tempScript = '/tmp/cursor_trigger.scpt';
        file_put_contents($tempScript, $appleScript);

        exec("osascript {$tempScript}", $output, $returnCode);

        @unlink($tempScript);

        $success = $returnCode === 0;

        if ($success) {
            $this->log("已发送 Cmd+K + Enter 到 Cursor (AppleScript)");
        }

        return $success;
    }

    /**
     * Linux 触发 Cursor
     */
    private function triggerLinux(): bool
    {
        exec('which xdotool 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
            $this->log("未安装 xdotool，请手动按 Ctrl+K");
            return false;
        }

        $commands = [
            'sleep 0.5',
            'xdotool search --name "Cursor" windowactivate --sync',
            'xdotool key ctrl+k',
            'sleep 0.3',
            'xdotool key Return',
        ];

        foreach ($commands as $cmd) {
            exec($cmd);
        }

        $this->log("已发送 Ctrl+K + Enter 到 Cursor (xdotool)");
        return true;
    }

    /**
     * Windows 发送按键
     */
    private function sendKeysWindows(array $keys): bool
    {
        $keyString = implode('', $keys);

        $psScript = <<<PS
Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.SendKeys]::SendWait("{$keyString}")
PS;

        $tempScript = PlatformHelper::getTempDir() . DIRECTORY_SEPARATOR . 'send_keys.ps1';
        file_put_contents($tempScript, $psScript);

        exec("powershell -ExecutionPolicy Bypass -File \"{$tempScript}\"", $output, $returnCode);

        @unlink($tempScript);

        return $returnCode === 0;
    }

    /**
     * macOS 发送按键
     */
    private function sendKeysMac(array $keys): bool
    {
        $keyString = implode(' ', $keys);
        exec("osascript -e 'tell application \"System Events\" to keystroke \"{$keyString}\"'", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Linux 发送按键
     */
    private function sendKeysLinux(array $keys): bool
    {
        $keyString = implode('+', $keys);
        exec("xdotool key {$keyString}", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Windows 激活窗口
     */
    private function activateWindowWindows(): bool
    {
        $psScript = <<<'PS'
$cursor = Get-Process -Name "Cursor" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($cursor) {
    $hwnd = $cursor.MainWindowHandle
    Add-Type @"
    using System;
    using System.Runtime.InteropServices;
    public class Win32 {
        [DllImport("user32.dll")]
        public static extern bool SetForegroundWindow(IntPtr hWnd);
    }
"@
    [Win32]::SetForegroundWindow($hwnd)
    Write-Output "SUCCESS"
}
PS;

        $tempScript = PlatformHelper::getTempDir() . DIRECTORY_SEPARATOR . 'activate_cursor.ps1';
        file_put_contents($tempScript, $psScript);

        exec("powershell -ExecutionPolicy Bypass -File \"{$tempScript}\"", $output, $returnCode);

        @unlink($tempScript);

        return $returnCode === 0 && in_array('SUCCESS', $output);
    }

    /**
     * macOS 激活窗口
     */
    private function activateWindowMac(): bool
    {
        exec('osascript -e \'tell application "Cursor" to activate\'', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Linux 激活窗口
     */
    private function activateWindowLinux(): bool
    {
        exec('xdotool search --name "Cursor" windowactivate --sync', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[KeyboardSimulator] {$message}\n";
        }

        $logFile = BP . 'var/log/keyboard-simulator.log';
        PlatformHelper::ensureDirectoryExists(dirname($logFile));

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
