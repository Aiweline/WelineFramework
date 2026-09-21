<?php
declare(strict_types=1);

namespace Weline\Framework\System\Process;

/**
 * macOS --win：为已运行的 WLS PHP 进程打开 Terminal 跟随日志窗口。
 *
 * Direct/shared_fd 下 Worker 必须继承 Master 监听 FD，不能像 Windows 那样把进程
 * 整进新控制台；因此这里弹的是观察窗口（tail -F），不是进程迁入。
 *
 * 打开时返回 Terminal window id + wrapper shell pid，供停服/重启关闭，避免杀进程后窗口残留。
 */
final class DarwinTerminalWindow
{
    private const WRAPPER_PREFIX = 'weline-wls-win-tail-';

    /**
     * @return array{ok:bool, window_id:int, shell_pid:int, wrapper:string, pid_file:string}
     */
    public static function openLogFollow(string $windowTitle, string $logFile): array
    {
        $empty = [
            'ok' => false,
            'window_id' => 0,
            'shell_pid' => 0,
            'wrapper' => '',
            'pid_file' => '',
        ];

        if (\PHP_OS_FAMILY !== 'Darwin') {
            return $empty;
        }
        if (!\is_file('/usr/bin/osascript') || !\is_executable('/usr/bin/osascript')) {
            return $empty;
        }

        $title = \trim($windowTitle);
        $logFile = \trim($logFile);
        if ($title === '' || $logFile === '') {
            return $empty;
        }

        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0777, true);
        }
        if (!\is_file($logFile)) {
            @\touch($logFile);
        }

        $digest = \substr(\hash('sha256', $title . "\0" . $logFile . "\0" . (string)\microtime(true)), 0, 16);
        $pidFile = self::asciiTempPath(self::WRAPPER_PREFIX . $digest . '.pid');
        $wrapper = self::writeAsciiWrapperScript($title, $logFile, $pidFile, $digest);
        if ($wrapper === '') {
            return $empty;
        }

        $appleScript = self::buildTerminalDoScriptAppleScript($wrapper, $title);
        $command = '/usr/bin/osascript -e ' . \escapeshellarg($appleScript) . ' 2>/dev/null';
        $output = [];
        $exitCode = 1;
        @\exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            return $empty;
        }

        $windowId = 0;
        foreach ($output as $line) {
            $line = \trim((string)$line);
            if ($line !== '' && \ctype_digit($line)) {
                $windowId = (int)$line;
                break;
            }
        }

        $shellPid = self::waitForShellPid($pidFile, 1.5);

        return [
            'ok' => true,
            'window_id' => $windowId,
            'shell_pid' => $shellPid,
            'wrapper' => $wrapper,
            'pid_file' => $pidFile,
        ];
    }

    /**
     * Best-effort: close Terminal windows whose title contains both weline-wls-
     * and the project scope token (covers pre-registry orphan windows).
     */
    public static function closeWindowsMatchingProjectScope(string $projectScopeToken): int
    {
        $token = \trim($projectScopeToken);
        if ($token === '' || \PHP_OS_FAMILY !== 'Darwin') {
            return 0;
        }
        if (!\is_file('/usr/bin/osascript') || !\is_executable('/usr/bin/osascript')) {
            return 0;
        }

        $script = self::buildCloseWindowsByTitleNeedleAppleScript('weline-wls-', $token);
        $command = '/usr/bin/osascript -e ' . \escapeshellarg($script) . ' 2>/dev/null';
        $output = [];
        $exitCode = 1;
        @\exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            return 0;
        }
        foreach ($output as $line) {
            $line = \trim((string)$line);
            if ($line !== '' && \ctype_digit($line)) {
                return (int)$line;
            }
        }

        return 0;
    }

    /**
     * @internal exposed for unit tests
     */
    public static function buildCloseWindowsByTitleNeedleAppleScript(string $needleA, string $needleB): string
    {
        $a = self::escapeAppleScriptString($needleA);
        $b = self::escapeAppleScriptString($needleB);

        return 'tell application "Terminal"' . "\n"
            . '  set closedCount to 0' . "\n"
            . '  set windowList to every window' . "\n"
            . '  repeat with w in windowList' . "\n"
            . '    try' . "\n"
            . '      set t to custom title of w as string' . "\n"
            . '      if t contains "' . $a . '" and t contains "' . $b . '" then' . "\n"
            . '        close w' . "\n"
            . '        set closedCount to closedCount + 1' . "\n"
            . '      end if' . "\n"
            . '    end try' . "\n"
            . '  end repeat' . "\n"
            . '  return closedCount' . "\n"
            . 'end tell';
    }

    /**
     * Close a Terminal window by id; also SIGTERM the wrapper shell when known.
     */
    public static function closeTrackedWindow(int $windowId, int $shellPid = 0): bool
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        $closed = false;
        if ($windowId > 0
            && \is_file('/usr/bin/osascript')
            && \is_executable('/usr/bin/osascript')
        ) {
            $script = self::buildCloseWindowAppleScript($windowId);
            $command = '/usr/bin/osascript -e ' . \escapeshellarg($script) . ' 2>/dev/null';
            $output = [];
            $exitCode = 1;
            @\exec($command, $output, $exitCode);
            $closed = $exitCode === 0;
        }

        if ($shellPid > 1 && \function_exists('posix_kill')) {
            @\posix_kill($shellPid, 15);
            $closed = true;
        }

        return $closed;
    }

    /**
     * @internal exposed for unit tests
     */
    public static function buildTerminalDoScriptAppleScript(string $wrapperScriptPath, string $windowTitle): string
    {
        $scriptLiteral = self::escapeAppleScriptString($wrapperScriptPath);
        $titleLiteral = self::escapeAppleScriptString($windowTitle);

        return 'tell application "Terminal"' . "\n"
            . '  do script "bash " & quoted form of "' . $scriptLiteral . '"' . "\n"
            . '  set custom title of front window to "' . $titleLiteral . '"' . "\n"
            . '  activate' . "\n"
            . '  return id of front window' . "\n"
            . 'end tell';
    }

    /**
     * @internal exposed for unit tests
     */
    public static function buildCloseWindowAppleScript(int $windowId): string
    {
        $id = \max(0, $windowId);

        return 'tell application "Terminal"' . "\n"
            . '  try' . "\n"
            . '    close (every window whose id is ' . $id . ')' . "\n"
            . '  end try' . "\n"
            . 'end tell';
    }

    /**
     * @internal exposed for unit tests
     */
    public static function escapeAppleScriptString(string $value): string
    {
        return \str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * @internal exposed for unit tests
     */
    public static function buildWrapperScriptBody(string $windowTitle, string $logFile, string $pidFile = ''): string
    {
        $titleExport = \str_replace("'", "'\\''", $windowTitle);
        $logExport = \str_replace("'", "'\\''", $logFile);
        $pidExport = \str_replace("'", "'\\''", $pidFile);

        $body = "#!/bin/bash\n";
        if ($pidFile !== '') {
            $body .= "echo $$ > '" . $pidExport . "'\n";
        }
        $body .= "printf '\\033]0;%s\\007' '" . $titleExport . "'\n"
            . "exec tail -n 200 -F '" . $logExport . "'\n";

        return $body;
    }

    private static function writeAsciiWrapperScript(
        string $windowTitle,
        string $logFile,
        string $pidFile,
        string $digest
    ): string {
        $path = self::asciiTempPath(self::WRAPPER_PREFIX . $digest . '.sh');
        $body = self::buildWrapperScriptBody($windowTitle, $logFile, $pidFile);
        if (@\file_put_contents($path, $body) === false) {
            return '';
        }
        @\chmod($path, 0700);

        return $path;
    }

    private static function asciiTempPath(string $leaf): string
    {
        $tmpDir = \sys_get_temp_dir();
        if ($tmpDir === '' || !\is_dir($tmpDir) || !\is_writable($tmpDir)) {
            $tmpDir = '/tmp';
        }

        return \rtrim($tmpDir, '/') . '/' . $leaf;
    }

    private static function waitForShellPid(string $pidFile, float $timeoutSec): int
    {
        $deadline = \microtime(true) + \max(0.1, $timeoutSec);
        do {
            if (\is_file($pidFile)) {
                $raw = \trim((string)@\file_get_contents($pidFile));
                if ($raw !== '' && \ctype_digit($raw)) {
                    return (int)$raw;
                }
            }
            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        return 0;
    }
}
