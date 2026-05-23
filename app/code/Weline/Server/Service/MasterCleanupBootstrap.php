<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Log\WlsLogger;

class MasterCleanupBootstrap
{
    public static function preBoot(string $instanceName, int $controlPort, int $maxRetries = 3): bool
    {
        WlsLogger::info_("[Master-Cleanup] pre-boot check, control_port={$controlPort}");

        $occupant = self::checkPortOccupant($controlPort);
        if ($occupant === null) {
            WlsLogger::info_('[Master-Cleanup] control port is available');

            return true;
        }

        WlsLogger::warning_(
            "[Master-Cleanup] control port occupied: pid={$occupant['pid']}, process={$occupant['name']}"
        );

        if (!self::isProcessRunning($occupant['pid'])) {
            WlsLogger::warning_('[Master-Cleanup] occupant process is gone, waiting for port release');

            return self::forceCleanPort($controlPort);
        }

        for ($i = 1; $i <= $maxRetries; $i++) {
            WlsLogger::info_("[Master-Cleanup] cleanup attempt {$i}/{$maxRetries}");
            if (PHP_OS_FAMILY === 'Windows') {
                self::killProcessWindows($occupant['pid']);
            } elseif (function_exists('posix_kill')) {
                @posix_kill($occupant['pid'], SIGTERM);
            }

            usleep(1_000_000);
            if (!self::isProcessRunning($occupant['pid'])) {
                usleep(500_000);

                return self::isPortAvailable($controlPort);
            }
        }

        WlsLogger::error_("[Master-Cleanup] failed to release control port, pid={$occupant['pid']}");

        return false;
    }

    /**
     * @return array{pid:int,name:string}|null
     */
    private static function checkPortOccupant(int $port): ?array
    {
        return PHP_OS_FAMILY === 'Windows'
            ? self::checkPortOccupantWindows($port)
            : self::checkPortOccupantUnix($port);
    }

    /**
     * @return array{pid:int,name:string}|null
     */
    private static function checkPortOccupantWindows(int $port): ?array
    {
        $pid = Processer::getProcessIdByPort($port);
        if ($pid <= 0) {
            return null;
        }

        $info = Processer::getProcessInfo($pid);
        $name = (string) ($info['name'] ?? '');

        return ['pid' => $pid, 'name' => $name !== '' ? $name : 'unknown.exe'];
    }

    /**
     * @return array{pid:int,name:string}|null
     */
    private static function checkPortOccupantUnix(int $port): ?array
    {
        $output = @shell_exec("lsof -i :{$port} -sTCP:LISTEN 2>/dev/null | tail -1") ?: '';
        if ($output === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($output));
        if (count($parts) < 2) {
            return null;
        }

        $pid = (int) ($parts[1] ?? 0);
        if ($pid <= 0) {
            return null;
        }

        return ['pid' => $pid, 'name' => (string) $parts[0]];
    }

    private static function isProcessRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return Processer::processExists($pid);
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0) !== false;
    }

    private static function isPortAvailable(int $port): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::checkPortOccupantWindows($port) === null;
        }

        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);

            return false;
        }

        return true;
    }

    private static function forceCleanPort(int $port): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            for ($i = 0; $i < 10; $i++) {
                usleep(500_000);
                if (self::isPortAvailable($port)) {
                    return true;
                }
            }

            return false;
        }

        $pids = @shell_exec("lsof -ti :{$port}") ?: '';
        if ($pids === '') {
            return true;
        }

        foreach (explode("\n", $pids) as $pid) {
            $pid = trim($pid);
            if ($pid !== '' && is_numeric($pid) && function_exists('posix_kill')) {
                @posix_kill((int) $pid, SIGKILL);
            }
        }
        usleep(500_000);

        return self::isPortAvailable($port);
    }

    private static function killProcessWindows(int $pid): void
    {
        Processer::killProcessTreeByPid($pid, true);
    }

    public static function cleanupLockFiles(string $instanceName): void
    {
        $basePath = defined('BP') ? BP : '';
        if ($basePath === '') {
            return;
        }

        $lockDir = $basePath . 'var/locks/';
        if (!is_dir($lockDir)) {
            return;
        }

        foreach (glob($lockDir . '*' . $instanceName . '*.lock') ?: [] as $lockFile) {
            $mtime = @filemtime($lockFile) ?: 0;
            if ((time() - $mtime) <= 300) {
                continue;
            }

            @unlink($lockFile);
            WlsLogger::debug_("[Master-Cleanup] removed stale lock file: {$lockFile}");
        }
    }
}
