<?php
declare(strict_types=1);

namespace Weline\Cron\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;

class Process
{
    public static function initTaskName(string $pname): string
    {
        foreach ([' ', '\'', '"'] as $special) {
            $pname = str_replace($special, '-', $pname);
        }

        return $pname;
    }

    public static function create(string $process_name): int
    {
        $processLogPath = self::getLogProcessFilePath($process_name);
        if (is_file($processLogPath) && (int) filesize($processLogPath) > 0) {
            self::moveCurrentLogToHistory($process_name);
        }

        if (IS_WIN) {
            self::setProcessOutput($process_name, 'Processer::create ' . $process_name . PHP_EOL);
            $pid = Processer::create($process_name, false, false, true);
            self::setProcessOutput($process_name, 'pid=' . $pid . PHP_EOL);

            return $pid;
        }

        $command = 'nohup ' . $process_name . ' > "' . $processLogPath . '"';
        self::setProcessOutput($process_name, $command . PHP_EOL);

        if (!function_exists('proc_open')) {
            exec($command . ' 2>&1', $output, $exitCode);
            self::setProcessOutput($process_name, implode(PHP_EOL, $output) . PHP_EOL);

            return 0;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes);
        self::setProcessOutput($process_name, json_encode($process) . PHP_EOL);
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            stream_set_blocking($pipes[1], true);
        }

        if (is_resource($process)) {
            $status = proc_get_status($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            return (int) ($status['pid'] ?? 0);
        }

        return 0;
    }

    public static function getPPid(int $pid): int|string
    {
        if (IS_WIN) {
            return 0;
        }

        return exec("ps -p $pid -o ppid=") ?: 0;
    }

    public static function getLogProcessFilePath(string $pname): string
    {
        foreach (['-name', '-process'] as $name) {
            if (str_contains($pname, $name)) {
                $parts = explode($name, trim($pname), 2);
                $tail = trim($parts[1] ?? '');
                $tailParts = explode(' ', $tail);
                $pname = $tailParts[0] ?? $pname;
            }
        }

        $fileName = str_replace(':', '-', $pname);
        $path = Env::VAR_DIR . 'log' . DS . 'cron' . DS . $fileName . '.log';
        if (!is_file($path)) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            touch($path);
        }

        return $path;
    }

    public static function unsetLogProcessFilePath(string $pname): bool
    {
        $path = self::getLogProcessFilePath($pname);

        return is_file($path) && unlink($path);
    }

    private static function moveCurrentLogToHistory(string $pname): void
    {
        $path = self::getLogProcessFilePath($pname);
        if (!is_file($path) || (int) filesize($path) === 0) {
            return;
        }

        $historyDir = dirname($path) . DS . 'history';
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0777, true);
        }

        $base = pathinfo($path, PATHINFO_FILENAME);
        $historyPath = $historyDir . DS . $base . '-' . date('Ymd-His') . '.log';
        @rename($path, $historyPath);
        touch($path);
        self::pruneHistoryDir($historyDir, 20);
    }

    private static function pruneHistoryDir(string $dir, int $maxFiles): void
    {
        $files = glob($dir . DS . '*.log') ?: [];
        if (count($files) <= $maxFiles) {
            return;
        }

        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $maxFiles) as $file) {
            @unlink($file);
        }
    }

    public static function logBasenameForExecuteName(string $executeName): string
    {
        return str_replace(':', '-', self::initTaskName($executeName));
    }

    public static function killPid(int $pid, string $pname): bool
    {
        $logfile = self::getLogProcessFilePath($pname);
        if (!IS_WIN) {
            exec("kill $pid 2>/dev/null", $output, $exitCode);
            file_put_contents($logfile, json_encode($output), FILE_APPEND);

            return $exitCode === 0;
        }

        $result = Processer::killProcessTreeByPid($pid, true);
        file_put_contents($logfile, json_encode(['kill_tree' => $result, 'pid' => $pid]), FILE_APPEND);

        return $result;
    }

    public static function isProcessRunning(int $pid): bool
    {
        if (IS_WIN) {
            return Processer::processExists($pid);
        }

        exec("ps -p $pid", $output);

        return count($output) > 1;
    }

    public static function getProcessOutput(string $pname): string|false
    {
        return file_get_contents(self::getLogProcessFilePath($pname));
    }

    public static function setProcessOutput(string $pname, string $content): false|int
    {
        $path = self::getLogProcessFilePath($pname);
        for ($i = 0; $i < 3; $i++) {
            $result = @file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
            if ($result !== false) {
                return $result;
            }
            if ($i < 2) {
                SchedulerSystem::usleep(100000);
            }
        }

        return false;
    }

    public static function getPidByName(string $pname): int
    {
        if (IS_WIN) {
            $pname = trim(str_replace(PHP_BINARY, '', $pname));

            return Processer::findPhpProcessPid($pname);
        }

        $cmd = 'ps aux 2>/dev/null | grep -F -- ' . escapeshellarg($pname) . ' | grep -v grep | tail -n 1 | awk \'{print $2}\'';
        $lastLine = exec($cmd) ?: '';

        return $lastLine !== '' ? (int) trim($lastLine) : 0;
    }
}
