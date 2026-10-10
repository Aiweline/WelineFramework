<?php

declare(strict_types=1);

namespace Weline\Framework\Console;

use Weline\Framework\Phrase\DatabaseFreeTranslator;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;

/**
 * Database gate acquired by bin/w and bin/m before application bootstrap.
 *
 * This class deliberately depends only on file-only framework classes so
 * a lock-contention path can finish without autoloading the application,
 * dispatching observers, building models, or consulting database-backed i18n.
 */
final class EarlyDatabaseAccessGate
{
    private const EXCLUSIVE_POLL_US = 200_000;
    private const HEARTBEAT_EVERY_MS = 5_000;
    /** -f/--force: after SIGTERM, briefly poll for EX instead of the long drain wait. */
    private const FORCE_EXCLUSIVE_WAIT_MS = 5_000;
    private const FORCE_KILL_AFTER_MS = 2_000;

    /**
     * @param list<string> $argv
     * @return int|null Exit code when the invocation is rejected; null otherwise.
     */
    public static function prepare(array $argv, ?int $exclusiveWaitMilliseconds = null): ?int
    {
        $command = strtolower(trim((string)($argv[1] ?? '')));
        $isSetup = self::matchesSegmentedCommand($command, 'setup:upgrade')
            || self::matchesSegmentedCommand($command, 's:up');
        $isCron = self::matchesSegmentedCommand($command, 'cron:task:run');
        if (!$isSetup && !$isCron) {
            return null;
        }

        $lease = new SetupDatabaseAccessLock();
        $readOnlySetup = $isSetup && self::hasAnyOption($argv, ['hot', 'h', 'help']);
        if ($isCron || $readOnlySetup) {
            $acquired = $lease->acquireShared();
            if (!$acquired) {
                self::writeMessage(DatabaseFreeTranslator::translate(
                    '系统升级正在执行，本次计划任务已跳过且未访问数据库。',
                    'Weline_Cron',
                ));

                return self::hasAnyOption($argv, ['p', 'process', 'f', 'force']) ? 75 : 0;
            }
            SetupDatabaseAccessLock::retainCliBootstrapLease($lease);

            return null;
        }

        $forceExclusive = self::hasAnyOption($argv, ['f', 'force']);

        return self::acquireExclusiveForSetup($lease, $exclusiveWaitMilliseconds, $forceExclusive);
    }

    private static function acquireExclusiveForSetup(
        SetupDatabaseAccessLock $lease,
        ?int $exclusiveWaitMilliseconds,
        bool $forceExclusive,
    ): ?int {
        $published = SetupUpgradeIntent::publish('setup:upgrade');
        $ownsIntent = $published || SetupUpgradeIntent::isOwnedByCurrentProcess();
        if ($ownsIntent) {
            register_shutdown_function(static function (): void {
                SetupUpgradeIntent::clearIfOwner();
            });
        }

        if ($forceExclusive) {
            return self::acquireExclusiveForced($lease, $ownsIntent);
        }

        $waitMs = $exclusiveWaitMilliseconds ?? SetupUpgradeIntent::exclusiveWaitMilliseconds();
        if ($waitMs < 1) {
            $waitMs = 1;
        }
        $deadline = hrtime(true) + ($waitMs * 1_000_000);
        $nextHeartbeat = hrtime(true);
        $waitingAnnounced = false;

        while (true) {
            if (!$ownsIntent && !SetupUpgradeIntent::isActive()) {
                if (SetupUpgradeIntent::publish('setup:upgrade')) {
                    $ownsIntent = true;
                    register_shutdown_function(static function (): void {
                        SetupUpgradeIntent::clearIfOwner();
                    });
                }
            }

            if ($lease->acquireExclusive()) {
                SetupDatabaseAccessLock::retainCliBootstrapLease($lease);
                return null;
            }

            $now = hrtime(true);
            if ($now >= $deadline) {
                if ($ownsIntent) {
                    SetupUpgradeIntent::clearIfOwner();
                }
                self::writeMessage(DatabaseFreeTranslator::translate(
                    '系统升级文件门禁等待计划任务释放超时，本次升级未启动、未访问数据库；当前数据库驱动配置未被切换，请稍后再试。',
                    'Weline_Framework',
                ));

                return 75;
            }

            if (!$waitingAnnounced || $now >= $nextHeartbeat) {
                $waitingAnnounced = true;
                $nextHeartbeat = $now + (self::HEARTBEAT_EVERY_MS * 1_000_000);
                $remainingSeconds = (string)(int)ceil(max(0, (int)(($deadline - $now) / 1_000_000)) / 1000);
                $template = DatabaseFreeTranslator::translate(
                    '系统升级意图已发布，正在等待计划任务释放数据库访问门禁（剩余约 %{1} 秒）…',
                    'Weline_Framework',
                );
                self::writeMessage(str_replace('%{1}', $remainingSeconds, $template));
            }

            usleep(self::EXCLUSIVE_POLL_US);
        }
    }

    private static function acquireExclusiveForced(SetupDatabaseAccessLock $lease, bool $ownsIntent): ?int
    {
        self::writeMessage(DatabaseFreeTranslator::translate(
            '系统升级 -f/--force：不等待计划任务自然结束，正在强制收回数据库访问门禁…',
            'Weline_Framework',
        ));

        $signaled = self::terminateCronLockHolders(false);
        if ($signaled > 0) {
            self::writeMessage(str_replace(
                '%{1}',
                (string)$signaled,
                DatabaseFreeTranslator::translate(
                    '已向 %{1} 个占用门禁的计划任务进程发送终止信号。',
                    'Weline_Framework',
                ),
            ));
        }

        $deadline = hrtime(true) + (self::FORCE_EXCLUSIVE_WAIT_MS * 1_000_000);
        $killDeadline = hrtime(true) + (self::FORCE_KILL_AFTER_MS * 1_000_000);
        $killed = false;

        while (true) {
            if ($lease->acquireExclusive()) {
                SetupDatabaseAccessLock::retainCliBootstrapLease($lease);
                return null;
            }

            $now = hrtime(true);
            if (!$killed && $now >= $killDeadline) {
                $killed = true;
                $killCount = self::terminateCronLockHolders(true);
                if ($killCount > 0) {
                    self::writeMessage(str_replace(
                        '%{1}',
                        (string)$killCount,
                        DatabaseFreeTranslator::translate(
                            '计划任务未及时释放门禁，已强制结束 %{1} 个进程。',
                            'Weline_Framework',
                        ),
                    ));
                }
            }

            if ($now >= $deadline) {
                if ($ownsIntent) {
                    SetupUpgradeIntent::clearIfOwner();
                }
                self::writeMessage(DatabaseFreeTranslator::translate(
                    '系统升级 -f/--force 仍无法取得数据库访问门禁，本次升级未启动、未访问数据库；当前数据库驱动配置未被切换，请稍后再试。',
                    'Weline_Framework',
                ));

                return 75;
            }

            usleep(self::EXCLUSIVE_POLL_US);
        }
    }

    /**
     * Signal cron processes that currently hold this project's setup DB access lock.
     *
     * @return int Number of processes signaled
     */
    private static function terminateCronLockHolders(bool $forceKill): int
    {
        $lockPath = SetupDatabaseAccessLock::path();
        $pids = self::listLockHolderPids($lockPath);
        if ($pids === []) {
            return 0;
        }

        $self = (int)(getmypid() ?: 0);
        $bpNeedle = rtrim((string)\BP, DIRECTORY_SEPARATOR);
        $signaled = 0;
        foreach ($pids as $pid) {
            if ($pid < 1 || $pid === $self) {
                continue;
            }
            $command = self::processCommandLine($pid);
            if ($command === '' || !str_contains($command, 'cron:task:run')) {
                continue;
            }
            if ($bpNeedle !== '' && !str_contains($command, $bpNeedle)) {
                continue;
            }
            $signal = $forceKill ? 9 : 15;
            if (\function_exists('posix_kill')) {
                if (@posix_kill($pid, $signal)) {
                    $signaled++;
                }
                continue;
            }
            // Best-effort non-posix fallback.
            $escaped = escapeshellarg((string)$pid);
            $cmd = $forceKill
                ? 'kill -9 ' . $escaped
                : 'kill -TERM ' . $escaped;
            @exec($cmd . ' 2>/dev/null', $out, $code);
            if ($code === 0) {
                $signaled++;
            }
        }

        return $signaled;
    }

    /** @return list<int> */
    private static function listLockHolderPids(string $lockPath): array
    {
        if (!is_file($lockPath)) {
            return [];
        }
        $escaped = escapeshellarg($lockPath);
        $output = [];
        @exec('lsof -t ' . $escaped . ' 2>/dev/null', $output);
        $pids = [];
        foreach ($output as $line) {
            $pid = (int)trim((string)$line);
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }

        return array_values(array_unique($pids));
    }

    private static function processCommandLine(int $pid): string
    {
        $output = [];
        @exec('ps -p ' . (int)$pid . ' -o command= 2>/dev/null', $output);
        return trim(implode(' ', $output));
    }

    /** @param list<string> $argv @param list<string> $names */
    private static function hasAnyOption(array $argv, array $names): bool
    {
        foreach (array_slice($argv, 2) as $argument) {
            $argument = trim((string)$argument);
            if ($argument === '--') {
                break;
            }
            if (!str_starts_with($argument, '-') || $argument === '-') {
                continue;
            }
            $argument = ltrim($argument, '-');
            $name = strtolower((string)(explode('=', $argument, 2)[0] ?? ''));
            if (\in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesSegmentedCommand(string $input, string $candidate): bool
    {
        $inputSegments = explode(':', $input);
        $candidateSegments = explode(':', $candidate);
        if (count($inputSegments) !== count($candidateSegments)) {
            return false;
        }
        foreach ($inputSegments as $index => $segment) {
            if ($segment === '' || !str_starts_with($candidateSegments[$index], $segment)) {
                return false;
            }
        }

        return true;
    }

    private static function writeMessage(string $message): void
    {
        $stream = \defined('STDOUT') ? \STDOUT : null;
        if (\is_resource($stream)) {
            @fwrite($stream, $message . PHP_EOL);
            return;
        }

        echo $message . PHP_EOL;
    }

    private function __construct()
    {
    }
}
