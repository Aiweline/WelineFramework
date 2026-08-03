<?php
declare(strict_types=1);

namespace Weline\Cron\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;

class Process
{
    /** @var array<string,array{pid:int,identity:string,launch_id:string,output:string,error:string}> */
    private static array $managedShutdownLeases = [];
    private static bool $managedShutdownRegistered = false;

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

    /**
     * Launch one Cron child through the framework's argv-safe detached
     * transport. The stable --name controls indexes/logs; launch-id fences one
     * concrete generation without creating a new log identity every minute.
     *
     * @param list<string> $argv
     */
    public static function createDetachedPhpArgv(
        array $argv,
        string $executeName,
        string $launchId,
    ): int {
        $processLogPath = self::getLogProcessFilePath($executeName);
        if (is_file($processLogPath) && (int)filesize($processLogPath) > 0) {
            self::moveCurrentLogToHistory($executeName);
        }
        self::mergeManagedErrorLog($executeName);
        self::moveManagedLogToHistory($executeName);

        $processIdentity = self::managedLaunchIdentity($executeName, $launchId);
        $logArgv = array_map(static function (string $argument): string {
            foreach (['--setup-gate-handoff=', '--launch-id='] as $sensitivePrefix) {
                if (str_starts_with($argument, $sensitivePrefix)) {
                    return $sensitivePrefix . '[redacted]';
                }
            }

            return $argument;
        }, $argv);
        Processer::setOutput(
            self::stableProcessIdentity($executeName),
            'Processer::createDetachedPhpArgv '
            . json_encode($logArgv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . PHP_EOL,
        );
        try {
            $outputPath = self::getManagedLogProcessFilePath($executeName);
            $errorPath = IS_WIN ? $outputPath . '.stderr.log' : $outputPath;
            $pid = Processer::createDetachedPhpArgv(
                $argv,
                BP,
                $processIdentity,
                true,
                $outputPath,
                $errorPath,
            );
        } catch (\Throwable $throwable) {
            Processer::setOutput(
                self::stableProcessIdentity($executeName),
                'spawn_error=' . $throwable->getMessage() . PHP_EOL,
            );
            throw $throwable;
        }
        Processer::setOutput(self::stableProcessIdentity($executeName), 'pid=' . $pid . PHP_EOL);

        return $pid;
    }

    public static function stableProcessIdentity(string $executeName): string
    {
        $normalized = Processer::normalizeName('weline-cron-' . self::initTaskName($executeName));
        $normalized = trim($normalized, '-');
        if ($normalized === '') {
            $normalized = 'weline-cron-task';
        }

        return '--name=' . substr($normalized, 0, 80)
            . '-' . substr(hash('sha256', $executeName), 0, 12);
    }

    public static function managedLaunchIdentity(string $executeName, string $launchId): string
    {
        return self::stableProcessIdentity($executeName) . ' --launch-id=' . $launchId;
    }

    public static function getManagedLogProcessFilePath(string $executeName): string
    {
        return Processer::getLogFile(self::stableProcessIdentity($executeName));
    }

    /**
     * @return array{running:bool,released:bool,unknown:bool,reason:string,launch_id:string}
     */
    public static function probeManagedCronProcess(
        int $pid,
        string $executeName,
        string $expectedLaunchId,
        string $expectedRunStart,
    ): array
    {
        $expectedLaunchId = strtolower(trim($expectedLaunchId));
        $expectedRunStart = trim($expectedRunStart);
        if (preg_match('/^[0-9a-f]{32}$/D', $expectedLaunchId) !== 1
            || preg_match('/^[0-9]{10,12}\.[0-9]{6}$/D', $expectedRunStart) !== 1
        ) {
            return [
                'running' => false,
                'released' => false,
                'unknown' => true,
                'reason' => 'expected_generation_missing',
                'launch_id' => '',
            ];
        }

        $stableIdentity = self::stableProcessIdentity($executeName);
        $expectedIdentity = self::managedLaunchIdentity($executeName, $expectedLaunchId);
        $probe = Processer::probeManagedProcessIdentity(
            $pid,
            substr($stableIdentity, strlen('--name=')),
            $expectedLaunchId,
            $expectedIdentity,
            true,
            self::managedRequiredLiveArguments(
                $stableIdentity,
                $expectedLaunchId,
                $expectedRunStart,
            ),
        );
        $probeState = (string)($probe['state'] ?? Processer::PROCESS_STATE_UNKNOWN);
        if ($probeState === Processer::PROCESS_STATE_EXITED
            || $probeState === Processer::PROCESS_STATE_IDENTITY_MISMATCH
        ) {
            self::releaseManagedLeaseRecord($pid, $executeName, $expectedLaunchId);
            return [
                'running' => false,
                'released' => true,
                'unknown' => false,
                'reason' => (string)($probe['reason'] ?? 'process_released'),
                'launch_id' => '',
            ];
        }
        if ($probeState !== Processer::PROCESS_STATE_RUNNING) {
            return [
                'running' => false,
                'released' => false,
                'unknown' => true,
                'reason' => (string)($probe['reason'] ?? 'process_probe_unknown'),
                'launch_id' => '',
            ];
        }
        return [
            'running' => true,
            'released' => false,
            'unknown' => false,
            'reason' => 'identity_match',
            'launch_id' => $expectedLaunchId,
        ];
    }

    /**
     * Reap an exited POSIX child launched by the current Cron dispatcher.
     *
     * A zombie remains visible to signal 0 and macOS may deny ps inspection
     * to a system Cron process. waitpid is authoritative for the dispatcher's
     * own child and prevents a serialized SQLite Scheduler from waiting on a
     * dead managed generation forever.
     */
    public static function reapManagedCronChildIfExited(
        int $pid,
        string $executeName,
        string $expectedLaunchId,
        string $expectedRunStart,
    ): bool {
        $expectedLaunchId = \strtolower(\trim($expectedLaunchId));
        $expectedRunStart = \trim($expectedRunStart);
        if (IS_WIN
            || $pid < 1
            || \preg_match('/^[0-9a-f]{32}$/D', $expectedLaunchId) !== 1
            || \preg_match('/^[0-9]{10,12}\.[0-9]{6}$/D', $expectedRunStart) !== 1
            || !\function_exists('pcntl_waitpid')
            || !\defined('WNOHANG')
        ) {
            return false;
        }

        $status = 0;
        $reapedPid = @\pcntl_waitpid($pid, $status, \WNOHANG);
        if ($reapedPid !== $pid) {
            return false;
        }

        self::releaseManagedLeaseRecord($pid, $executeName, $expectedLaunchId);
        self::mergeManagedErrorLog($executeName);
        return true;
    }

    /** @return array{released:bool,terminated:bool,state:string,reason:string,pid?:int} */
    public static function terminateTrackedCronProcess(
        int $pid,
        string $executeName,
        string $expectedLaunchId,
        string $expectedRunStart,
    ): array {
        $probe = self::probeManagedCronProcess(
            $pid,
            $executeName,
            $expectedLaunchId,
            $expectedRunStart,
        );
        if ($probe['released']) {
            return [
                'released' => true,
                'terminated' => false,
                'state' => Processer::PROCESS_STATE_IDENTITY_MISMATCH,
                'reason' => $probe['reason'],
                'pid' => $pid,
            ];
        }
        if (!$probe['running'] || $probe['launch_id'] === '') {
            return [
                'released' => false,
                'terminated' => false,
                'state' => Processer::PROCESS_STATE_UNKNOWN,
                'reason' => $probe['reason'],
                'pid' => $pid,
            ];
        }

        return self::terminateManagedLaunch(
            $pid,
            $executeName,
            $probe['launch_id'],
            $expectedRunStart,
        );
    }

    public static function registerManagedLaunchShutdown(
        int $pid,
        string $executeName,
        string $stableName,
        string $launchId,
    ): void {
        $stableName = trim($stableName);
        $expectedStableName = substr(
            self::stableProcessIdentity($executeName),
            strlen('--name='),
        );
        if ($pid < 1
            || $expectedStableName === ''
            || !hash_equals($expectedStableName, $stableName)
            || preg_match('/^[0-9a-f]{32}$/D', $launchId) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid managed Cron shutdown lease.');
        }

        $identity = '--name=' . $stableName . ' --launch-id=' . $launchId;
        $outputPath = Processer::getLogFile('--name=' . $stableName);
        self::$managedShutdownLeases[$pid . ':' . $launchId] = [
            'pid' => $pid,
            'identity' => $identity,
            'launch_id' => $launchId,
            'output' => $outputPath,
            'error' => $outputPath . '.stderr.log',
        ];
        if (!self::$managedShutdownRegistered) {
            self::$managedShutdownRegistered = true;
            register_shutdown_function([self::class, 'releaseManagedShutdownLeases']);
        }
    }

    public static function releaseManagedShutdownLeases(): void
    {
        $leases = self::$managedShutdownLeases;
        self::$managedShutdownLeases = [];
        foreach ($leases as $lease) {
            try {
                self::mergeLogPaths((string)$lease['output'], (string)$lease['error']);
                Processer::removeManagedProcessLeaseRecord(
                    (int)$lease['pid'],
                    (string)$lease['identity'],
                    (string)$lease['launch_id'],
                );
            } catch (\Throwable) {
                // Process exit must not be converted into a fatal error.
            }
        }
    }

    /**
     * Identity-safe, bounded termination for exactly one detached launch.
     *
     * @return array{released:bool,terminated:bool,state:string,reason:string,pid?:int}
     */
    public static function terminateManagedLaunch(
        int $pid,
        string $executeName,
        string $launchId,
        string $expectedRunStart,
        int $timeoutMilliseconds = 1_000,
    ): array {
        $launchId = strtolower(trim($launchId));
        $expectedRunStart = trim($expectedRunStart);
        if (preg_match('/^[0-9a-f]{32}$/D', $launchId) !== 1
            || preg_match('/^[0-9]{10,12}\.[0-9]{6}$/D', $expectedRunStart) !== 1
        ) {
            return [
                'released' => false,
                'terminated' => false,
                'state' => Processer::PROCESS_STATE_UNKNOWN,
                'reason' => 'expected_generation_missing',
                'pid' => $pid,
            ];
        }

        $stableIdentity = self::stableProcessIdentity($executeName);
        $expectedIdentity = self::managedLaunchIdentity($executeName, $launchId);
        $requiredLiveArguments = self::managedRequiredLiveArguments(
            $stableIdentity,
            $launchId,
            $expectedRunStart,
        );
        $last = Processer::terminateManagedProcessLease(
            $pid,
            substr($stableIdentity, strlen('--name=')),
            $launchId,
            $expectedIdentity,
            true,
            $requiredLiveArguments,
        );
        if (!empty($last['released'])) {
            self::releaseManagedLeaseRecord($pid, $executeName, $launchId);
            self::mergeManagedErrorLog($executeName);
            return $last;
        }
        if (empty($last['terminated'])) {
            return $last;
        }

        $deadline = microtime(true) + (max(1, $timeoutMilliseconds) / 1000);
        do {
            $probe = Processer::probeManagedProcessIdentity(
                $pid,
                substr($stableIdentity, strlen('--name=')),
                $launchId,
                $expectedIdentity,
                true,
                $requiredLiveArguments,
            );
            $state = (string)($probe['state'] ?? Processer::PROCESS_STATE_UNKNOWN);
            if ($state === Processer::PROCESS_STATE_EXITED
                || $state === Processer::PROCESS_STATE_IDENTITY_MISMATCH
            ) {
                self::releaseManagedLeaseRecord($pid, $executeName, $launchId);
                self::mergeManagedErrorLog($executeName);
                $probe['terminated'] = true;
                $probe['released'] = true;
                return $probe;
            }
            if ($state === Processer::PROCESS_STATE_UNKNOWN || microtime(true) >= $deadline) {
                break;
            }
            SchedulerSystem::usleep(50_000);
        } while (true);

        $last['released'] = false;
        $last['reason'] = 'termination_result_unverified';
        return $last;
    }

    /** @return array{name:string,launch-id:string,cron-run-start:string} */
    private static function managedRequiredLiveArguments(
        string $stableIdentity,
        string $launchId,
        string $expectedRunStart,
    ): array {
        return [
            'name' => substr($stableIdentity, strlen('--name=')),
            'launch-id' => $launchId,
            'cron-run-start' => $expectedRunStart,
        ];
    }

    private static function releaseManagedLeaseRecord(
        int $pid,
        string $executeName,
        string $launchId,
    ): void {
        if ($pid < 1 || preg_match('/^[0-9a-f]{32}$/D', $launchId) !== 1) {
            return;
        }
        try {
            Processer::removeManagedProcessLeaseRecord(
                $pid,
                self::managedLaunchIdentity($executeName, $launchId),
                $launchId,
            );
        } catch (\Throwable) {
            // A stale lease cleanup failure must not turn an exited/mismatched PID
            // into authority to signal the current OS process.
        }
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
        $removedCronLog = is_file($path) && unlink($path);
        $removedManagedLog = Processer::removeLogFile(self::stableProcessIdentity($pname));

        return $removedCronLog || (bool)$removedManagedLog;
    }

    private static function moveCurrentLogToHistory(string $pname): void
    {
        $path = self::getLogProcessFilePath($pname);
        if (!is_file($path) || (int) filesize($path) === 0) {
            return;
        }

        $historyDir = self::historyDirectoryForExecuteName($pname);
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0777, true);
        }

        $historyPath = $historyDir . DS . self::newHistoryBasename('legacy');
        if (!@rename($path, $historyPath)) {
            return;
        }
        touch($path);
        self::pruneHistoryDir($historyDir, 20);
    }

    private static function moveManagedLogToHistory(string $executeName): void
    {
        $path = self::getManagedLogProcessFilePath($executeName);
        if (!is_file($path) || (int)filesize($path) === 0) {
            return;
        }

        $historyDir = self::historyDirectoryForExecuteName($executeName);
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0777, true);
        }
        $historyPath = $historyDir . DS . self::newHistoryBasename('managed');
        if (!@rename($path, $historyPath)) {
            return;
        }
        self::pruneHistoryDir($historyDir, 20);
    }

    private static function mergeManagedErrorLog(string $executeName): void
    {
        $outputPath = self::getManagedLogProcessFilePath($executeName);
        self::mergeLogPaths($outputPath, $outputPath . '.stderr.log');
    }

    private static function mergeLogPaths(string $outputPath, string $errorPath): void
    {
        if ($outputPath === '' || $errorPath === '' || $outputPath === $errorPath
            || !is_file($errorPath) || (int)filesize($errorPath) === 0
        ) {
            return;
        }
        $source = @fopen($errorPath, 'rb');
        $target = @fopen($outputPath, 'ab');
        if (!is_resource($source) || !is_resource($target)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            return;
        }

        $complete = false;
        try {
            if (!@flock($target, LOCK_EX)
                || !self::writeStreamFully($target, PHP_EOL . '[stderr]' . PHP_EOL)
            ) {
                return;
            }
            while (!feof($source)) {
                $chunk = @fread($source, 65_536);
                if (!is_string($chunk) || ($chunk === '' && !feof($source))) {
                    return;
                }
                if ($chunk !== '' && !self::writeStreamFully($target, $chunk)) {
                    return;
                }
            }
            $complete = @fflush($target);
        } finally {
            @flock($target, LOCK_UN);
            fclose($source);
            fclose($target);
        }
        if ($complete) {
            @unlink($errorPath);
        }
    }

    /** @param resource $stream */
    private static function writeStreamFully($stream, string $data): bool
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset));
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
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

    public static function historyDirectoryForExecuteName(string $executeName): string
    {
        return Env::VAR_DIR . 'log' . DS . 'cron' . DS . 'history' . DS
            . substr(hash('sha256', $executeName), 0, 24);
    }

    private static function newHistoryBasename(string $kind): string
    {
        $kind = $kind === 'legacy' ? 'legacy' : 'managed';
        $now = microtime(true);
        $microseconds = (int)(($now - floor($now)) * 1_000_000);

        return date('Ymd-His', (int)$now)
            . '-' . str_pad((string)$microseconds, 6, '0', STR_PAD_LEFT)
            . '-' . $kind . '.log';
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
        $cronOutput = @file_get_contents(self::getLogProcessFilePath($pname));
        $managedOutput = @file_get_contents(Processer::getLogFile(self::stableProcessIdentity($pname)));
        $parts = array_values(array_filter([
            is_string($cronOutput) ? trim($cronOutput) : '',
            is_string($managedOutput) ? trim($managedOutput) : '',
        ], static fn(string $part): bool => $part !== ''));

        return $parts === [] ? false : implode(PHP_EOL, $parts) . PHP_EOL;
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
