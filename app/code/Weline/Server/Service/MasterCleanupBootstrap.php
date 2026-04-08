<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Log\WlsLogger;

/**
 * Master 启动前的清理和诊断工具
 * 
 * 职责：
 * 1. 检查 control_port 是否被其他进程占用
 * 2. 尝试清理僵尸进程占用的端口
 * 3. 确保旧 Master 实例完全退出
 * 
 * @author Aiweline
 */
class MasterCleanupBootstrap
{
    /**
     * 执行启动前清理
     * 
     * @param string $instanceName  实例名
     * @param int $controlPort      IPC 控制端口
     * @param int $maxRetries       重试次数
     * @return bool 是否清理成功
     */
    public static function preBoot(string $instanceName, int $controlPort, int $maxRetries = 3): bool
    {
        WlsLogger::info_("[Master-Cleanup] 启动前检查与清理 (控制端口: {$controlPort})");

        // 1. 检查端口占用情况
        $occupant = self::checkPortOccupant($controlPort);
        if ($occupant === null) {
            WlsLogger::info_("[Master-Cleanup] 控制端口可用");
            return true;
        }

        WlsLogger::warning_("[Master-Cleanup] 控制端口已被占用：PID={$occupant['pid']}, 进程={$occupant['name']}");

        // 2. 判断占用进程是否真的还活着
        if (!self::isProcessRunning($occupant['pid'])) {
            WlsLogger::warning_("[Master-Cleanup] 检测到占用端口的进程已死亡（孤儿端口），尝试清理...");
            if (self::forceCleanPort($controlPort)) {
                WlsLogger::info_("[Master-Cleanup] 孤儿端口清理成功");
                return true;
            } else {
                WlsLogger::error_("[Master-Cleanup] 孤儿端口清理失败");
                return false;
            }
        }

        // 3. 进程仍然活着，尝试向其发送信号
        WlsLogger::warning_("[Master-Cleanup] 占用进程仍在运行，尝试优雅终止...");

        for ($i = 1; $i <= $maxRetries; $i++) {
            WlsLogger::info_("[Master-Cleanup] 第 {$i}/{$maxRetries} 次尝试清理占用进程");

            // 尝试 graceful shutdown
            if (function_exists('posix_kill')) {
                // Linux/Mac
                @\posix_kill($occupant['pid'], SIGTERM);
            } else {
                // Windows
                self::killProcessWindows($occupant['pid']);
            }

            \usleep(1000000); // 等待 1 秒

            if (!self::isProcessRunning($occupant['pid'])) {
                WlsLogger::info_("[Master-Cleanup] 占用进程已终止");
                \usleep(500000); // 额外等待 0.5 秒释放端口
                return self::isPortAvailable($controlPort);
            }
        }

        WlsLogger::error_("[Master-Cleanup] 无法清理占用进程，请手动杀死 PID={$occupant['pid']}");
        return false;
    }

    /**
     * 检查端口占用情况
     * 
     * @return array|null ['pid' => int, 'name' => string] 或 null
     */
    private static function checkPortOccupant(int $port): ?array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::checkPortOccupantWindows($port);
        } else {
            return self::checkPortOccupantUnix($port);
        }
    }

    /**
     * Windows 下检查端口占用
     */
    private static function checkPortOccupantWindows(int $port): ?array
    {
        @\ob_start();
        $output = @\exec("netstat -ano 2>nul | findstr :{$port}", $netstatOutput, $exitCode);
        ob_end_clean();

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        // 解析 netstat 输出：TCP    127.0.0.1:PORT  0.0.0.0:0  LISTENING  PID
        foreach ((array)$netstatOutput as $line) {
            if (\strpos($line, 'LISTENING') === false) {
                continue;
            }
            if (!\preg_match('/[:\.]' . $port . '\s/', $line)) {
                continue;
            }
            if (\preg_match('/\s+(\d+)\s*$/', $line, $matches)) {
                $pid = (int)$matches[1];
                if ($pid > 0) {
                    $name = @\shell_exec("tasklist /FI \"PID eq {$pid}\" /NH 2>nul");
                    $name = \is_string($name) && \trim($name) !== '' ? \trim($name) : 'unknown.exe';
                    return ['pid' => $pid, 'name' => \trim($name)];
                }
            }
        }

        return null;
    }

    /**
     * Unix 下检查端口占用
     */
    private static function checkPortOccupantUnix(int $port): ?array
    {
        $output = @\shell_exec("lsof -i :{$port} -sTCP:LISTEN 2>/dev/null | tail -1") ?: '';
        if (empty($output)) {
            return null;
        }

        $parts = \preg_split('/\s+/', $output);
        if (count($parts) >= 2) {
            $processName = $parts[0];
            $pid = (int)($parts[1] ?? 0);
            if ($pid > 0) {
                return ['pid' => $pid, 'name' => $processName];
            }
        }

        return null;
    }

    /**
     * 检查进程是否运行
     */
    private static function isProcessRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = @\shell_exec("tasklist /FI \"PID eq {$pid}\" /NH 2>nul") ?: '';
            return !empty($output) && \strpos($output, (string)$pid) !== false;
        } else {
            return function_exists('posix_kill') && @\posix_kill($pid, 0) !== false;
        }
    }

    /**
     * 检查端口是否可用
     */
    private static function isPortAvailable(int $port): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::checkPortOccupantWindows($port) === null;
        }

        $sock = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($sock) {
            @\fclose($sock);
            // 端口可以连接，说明还被占用
            return false;
        }
        // 无法连接 = 端口可用
        return true;
    }

    /**
     * 强制清理端口（仅当进程已死亡时）
     */
    private static function forceCleanPort(int $port): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows 下无法直接清理端口，只能等待 OS 释放（通常数秒）
            for ($i = 0; $i < 10; $i++) {
                \usleep(500000); // 每次等待 0.5 秒
                if (self::isPortAvailable($port)) {
                    return true;
                }
            }
            return false;
        } else {
            // Linux: 尝试 lsof -ti 获取 PID 并杀死所有占用进程
            $pids = @\shell_exec("lsof -ti :{$port}") ?: '';
            if (!empty($pids)) {
                foreach (\explode("\n", $pids) as $pid) {
                    $pid = \trim($pid);
                    if (!empty($pid) && \is_numeric($pid)) {
                        @\posix_kill((int)$pid, SIGKILL);
                    }
                }
                \usleep(500000);
                return self::isPortAvailable($port);
            }
            return true;
        }
    }

    /**
     * Windows 下杀死进程
     */
    private static function killProcessWindows(int $pid): void
    {
        @\exec("taskkill /PID {$pid} /T /F 2>nul");
    }

    /**
     * 清理实例的所有锁文件（Master 崩溃时无法完成的清理）
     */
    public static function cleanupLockFiles(string $instanceName): void
    {
        $lockDir = \defined('BP') ? BP : '';
        if (empty($lockDir)) {
            return;
        }

        $lockDir = $lockDir . 'var/locks/';
        if (!is_dir($lockDir)) {
            return;
        }

        // 删除实例相关的所有 *.lock 文件
        foreach (\glob($lockDir . '*' . $instanceName . '*.lock') as $lockFile) {
            $mtime = @\filemtime($lockFile) ?: 0;
            $age = \time() - $mtime;
            
            // 只删除尸体锁（超过 5 分钟未更新的）
            if ($age > 300) {
                @\unlink($lockFile);
                WlsLogger::debug_("[Master-Cleanup] 删除陈旧锁文件: {$lockFile}");
            }
        }
    }
}
