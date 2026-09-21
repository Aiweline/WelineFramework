<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\System\Process\DarwinTerminalWindow;

/**
 * macOS --win：按进程名打开/回收 Terminal 日志跟随窗口。
 *
 * 生命周期：开窗登记 → 同名再开先关旧窗 → stop/restart 回收本实例全部窗口。
 */
final class DarwinWinModeLogWindow
{
    public static function isSupported(): bool
    {
        return \PHP_OS_FAMILY === 'Darwin'
            && \is_file('/usr/bin/osascript')
            && \is_executable('/usr/bin/osascript');
    }

    /**
     * @param string|null $logInstanceName 日志目录用的实例名；共享 Session/Memory 须传
     *        service_instance_name（如 shared-session-pxxx-26277），不能传 requester 的 default。
     * @param string|null $explicitLogFile 若已知真实日志路径则直接跟随，避免跟到空文件。
     */
    public static function openForProcess(
        string $windowTitle,
        string $processName,
        ?string $instanceName = null,
        ?string $logInstanceName = null,
        ?string $explicitLogFile = null
    ): bool {
        if (!self::isSupported()) {
            return false;
        }

        $processName = \trim($processName);
        $windowTitle = \trim($windowTitle);
        $registryInstance = \trim((string)$instanceName);
        if ($processName === '' || $windowTitle === '' || $registryInstance === '') {
            return false;
        }

        // 同进程名再开前先关旧窗，避免叠窗。
        self::closeForProcess($registryInstance, $processName);

        $logScope = \trim((string)$logInstanceName);
        if ($logScope === '') {
            $logScope = $registryInstance;
        }

        $logFile = \trim((string)$explicitLogFile);
        if ($logFile === '') {
            $logFile = WlsLogService::ensureProcessLogFile($processName, $logScope);
        } else {
            $logDir = \dirname($logFile);
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0777, true);
            }
            if (!\is_file($logFile)) {
                @\touch($logFile);
            }
        }

        $opened = DarwinTerminalWindow::openLogFollow($windowTitle, $logFile);
        if (!($opened['ok'] ?? false)) {
            return false;
        }

        (new DarwinWinModeWindowRegistry())->remember($registryInstance, [
            'process_name' => $processName,
            'title' => $windowTitle,
            'log_file' => $logFile,
            'window_id' => (int)($opened['window_id'] ?? 0),
            'shell_pid' => (int)($opened['shell_pid'] ?? 0),
            'opened_at' => \date('c'),
        ]);

        return true;
    }

    public static function closeForProcess(string $instanceName, string $processName): bool
    {
        $instanceName = \trim($instanceName);
        $processName = \trim($processName);
        if ($instanceName === '' || $processName === '' || !self::isSupported()) {
            return false;
        }

        $registry = new DarwinWinModeWindowRegistry();
        $record = $registry->load($instanceName);
        $entry = $record['windows'][$processName] ?? null;
        if (!\is_array($entry)) {
            return false;
        }

        $closed = DarwinTerminalWindow::closeTrackedWindow(
            (int)($entry['window_id'] ?? 0),
            (int)($entry['shell_pid'] ?? 0)
        );
        $registry->forget($instanceName, $processName);

        return $closed;
    }

    /**
     * 停服 / -r -f 重启前：关闭本实例登记的全部 --win 观察窗口。
     */
    public static function closeAllForInstance(string $instanceName): int
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return 0;
        }
        if (!self::isSupported()) {
            // 非 Darwin 也清登记，避免跨机拷贝残留。
            (new DarwinWinModeWindowRegistry())->clear($instanceName);

            return 0;
        }

        $registry = new DarwinWinModeWindowRegistry();
        $record = $registry->load($instanceName);
        $closed = 0;
        foreach ($record['windows'] as $processName => $entry) {
            if (!\is_string($processName) || $processName === '' || !\is_array($entry)) {
                continue;
            }
            if (DarwinTerminalWindow::closeTrackedWindow(
                (int)($entry['window_id'] ?? 0),
                (int)($entry['shell_pid'] ?? 0)
            )) {
                $closed++;
            }
        }
        $registry->clear($instanceName);

        // 登记表出现前遗留的孤儿窗：按标题中的项目 scope 兜底关闭。
        $closed += DarwinTerminalWindow::closeWindowsMatchingProjectScope(
            MasterProcess::getProjectScopeToken()
        );

        return $closed;
    }
}
