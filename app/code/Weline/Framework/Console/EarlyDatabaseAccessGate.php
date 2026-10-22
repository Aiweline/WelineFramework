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

        $announcedTerm = false;
        $announcedKill = false;
        $seenNotices = [];
        $first = self::terminateCronLockHolders(false);
        self::announceFreshNotices($first['notices'], $seenNotices);
        if ($first['signaled'] > 0) {
            $announcedTerm = true;
            self::writeSignalCount(
                '已向 %{1} 个占用门禁的计划任务进程发送终止信号。',
                $first['signaled'],
            );
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
            }
            $again = self::terminateCronLockHolders($killed);
            self::announceFreshNotices($again['notices'], $seenNotices);
            if ($again['signaled'] > 0 && $killed && !$announcedKill) {
                $announcedKill = true;
                self::writeSignalCount(
                    '计划任务未及时释放门禁，已强制结束 %{1} 个进程。',
                    $again['signaled'],
                );
            } elseif ($again['signaled'] > 0 && !$killed && !$announcedTerm) {
                $announcedTerm = true;
                self::writeSignalCount(
                    '已向 %{1} 个占用门禁的计划任务进程发送终止信号。',
                    $again['signaled'],
                );
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
     * Empty string means this command line may be signaled.
     * Otherwise: unread_command, not_cron, or other_project.
     */
    public static function lockHolderSkipReason(string $commandLine, string $projectRoot): string
    {
        $command = trim($commandLine);
        if ($command === '') {
            return 'unread_command';
        }
        if (!self::isSameProjectGatePeer($command)) {
            return 'not_cron';
        }
        $root = rtrim($projectRoot, '/\\');
        if ($root !== '' && !str_contains($command, $root)) {
            return 'other_project';
        }

        return '';
    }

    /**
     * Cron and setup both hold this flock. A later -f may stop either one
     * when it belongs to this application root. Other commands stay untouched.
     */
    private static function isSameProjectGatePeer(string $command): bool
    {
        if (str_contains($command, 'cron:task:run') || str_contains($command, 'setup:upgrade')) {
            return true;
        }

        return preg_match('/(?:^|\s)s:up(?:\s|$)/', $command) === 1;
    }

    /**
     * Signal cron processes that currently hold this project's setup DB access lock.
     *
     * @return array{signaled:int, notices:list<string>}
     */
    private static function terminateCronLockHolders(bool $forceKill): array
    {
        $lockPath = SetupDatabaseAccessLock::path();
        $listed = self::listLockHolderPids($lockPath);
        if ($listed['pids'] === []) {
            if ($listed['failure'] === '') {
                return ['signaled' => 0, 'notices' => []];
            }

            return [
                'signaled' => 0,
                'notices' => [self::noticeForListingFailure($listed['failure'], $lockPath)],
            ];
        }

        $self = (int)(getmypid() ?: 0);
        $projectRoot = rtrim((string)\BP, DIRECTORY_SEPARATOR);
        $signaled = 0;
        $notices = [];
        foreach ($listed['pids'] as $pid) {
            if ($pid < 1) {
                continue;
            }
            if ($pid === $self) {
                $notices[] = self::noticeForSkip($pid, 'self', '');
                continue;
            }
            $command = self::processCommandLine($pid);
            $reason = self::lockHolderSkipReason($command, $projectRoot);
            if ($reason !== '') {
                $notices[] = self::noticeForSkip($pid, $reason, $command);
                continue;
            }
            $outcome = self::signalProcess($pid, $forceKill ? 9 : 15);
            if ($outcome === '') {
                $signaled++;
                continue;
            }
            if ($outcome !== 'gone') {
                $notices[] = $outcome;
            }
        }

        return ['signaled' => $signaled, 'notices' => $notices];
    }

    /**
     * @return array{pids:list<int>, failure:string}
     */
    private static function listLockHolderPids(string $lockPath): array
    {
        if (!is_file($lockPath)) {
            return ['pids' => [], 'failure' => ''];
        }
        if (!\function_exists('exec')) {
            return ['pids' => [], 'failure' => 'lsof_unavailable'];
        }
        $output = [];
        $code = 0;
        @exec('lsof -t ' . escapeshellarg($lockPath) . ' 2>&1', $output, $code);
        $joined = strtolower(implode("\n", $output));
        $pids = [];
        foreach ($output as $line) {
            $line = trim((string)$line);
            if (preg_match('/^[1-9][0-9]*$/D', $line) === 1) {
                $pids[] = (int)$line;
            }
        }
        $pids = array_values(array_unique($pids));
        if ($pids !== []) {
            return ['pids' => $pids, 'failure' => ''];
        }
        if ($code === 127
            || str_contains($joined, 'not found')
            || str_contains($joined, 'not recognized')
        ) {
            return ['pids' => [], 'failure' => 'lsof_unavailable'];
        }

        return ['pids' => [], 'failure' => 'holders_invisible'];
    }

    /**
     * @param list<string> $notices
     * @param array<string, true> $seen
     */
    private static function announceFreshNotices(array $notices, array &$seen): void
    {
        foreach ($notices as $notice) {
            if ($notice === '' || isset($seen[$notice])) {
                continue;
            }
            $seen[$notice] = true;
            self::writeMessage($notice);
        }
    }

    private static function writeSignalCount(string $source, int $count): void
    {
        self::writeMessage(str_replace(
            '%{1}',
            (string)$count,
            DatabaseFreeTranslator::translate($source, 'Weline_Framework'),
        ));
    }

    private static function noticeForListingFailure(string $failure, string $lockPath): string
    {
        if ($failure === 'lsof_unavailable') {
            return DatabaseFreeTranslator::translate(
                '无法执行 lsof，因此列不出占用数据库访问门禁的进程。',
                'Weline_Framework',
            );
        }

        return str_replace(
            '%{1}',
            $lockPath,
            DatabaseFreeTranslator::translate(
                '数据库访问门禁仍被占用，但当前用户列不出持有进程。请用该进程所属用户或 root 执行：lsof %{1}',
                'Weline_Framework',
            ),
        );
    }

    private static function noticeForSkip(int $pid, string $reason, string $command): string
    {
        $template = match ($reason) {
            'self' => DatabaseFreeTranslator::translate(
                '进程 %{1} 是当前进程并且占用门禁，不能向自己发信号。',
                'Weline_Framework',
            ),
            'unread_command' => DatabaseFreeTranslator::translate(
                '有进程占用门禁，但读不到命令行，已跳过，不会结束无法确认身份的进程。',
                'Weline_Framework',
            ),
            'other_project' => DatabaseFreeTranslator::translate(
                '进程 %{1} 是计划任务，但不属于当前应用根，已跳过。',
                'Weline_Framework',
            ),
            default => DatabaseFreeTranslator::translate(
                '进程 %{1} 占用门禁，但不是本站计划任务或系统升级，已跳过。命令：%{2}',
                'Weline_Framework',
            ),
        };

        return str_replace(
            ['%{1}', '%{2}'],
            [(string)$pid, self::commandSnippet($command)],
            $template,
        );
    }

    /**
     * Empty string means the signal was delivered. "gone" means the process
     * had already exited. Any other string is an operator-facing notice.
     */
    private static function signalProcess(int $pid, int $signal): string
    {
        if (\function_exists('posix_kill')) {
            if (posix_kill($pid, $signal)) {
                return '';
            }
            $error = \function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
            if ($error === 3) {
                return 'gone';
            }

            return self::signalDeniedNotice($pid);
        }
        if (!\function_exists('exec')) {
            return self::signalDeniedNotice($pid);
        }
        $escaped = escapeshellarg((string)$pid);
        $command = $signal === 9 ? 'kill -9 ' . $escaped : 'kill -TERM ' . $escaped;
        $output = [];
        $code = 1;
        @exec($command . ' 2>&1', $output, $code);
        if ($code === 0) {
            return '';
        }
        $joined = strtolower(implode(' ', $output));
        if (str_contains($joined, 'no such process')) {
            return 'gone';
        }

        return self::signalDeniedNotice($pid);
    }

    private static function signalDeniedNotice(int $pid): string
    {
        return str_replace(
            '%{1}',
            (string)$pid,
            DatabaseFreeTranslator::translate(
                '无法向进程 %{1} 发送信号。当前用户可能没有权限结束该进程。',
                'Weline_Framework',
            ),
        );
    }

    private static function commandSnippet(string $command): string
    {
        $snippet = trim((string)preg_replace('/\s+/', ' ', $command));
        if ($snippet === '') {
            return '-';
        }
        if (\function_exists('mb_substr')) {
            return mb_substr($snippet, 0, 160);
        }

        return substr($snippet, 0, 160);
    }

    private static function processCommandLine(int $pid): string
    {
        if ($pid < 1) {
            return '';
        }
        $proc = '/proc/' . $pid . '/cmdline';
        if (is_readable($proc)) {
            $raw = @file_get_contents($proc);
            if (is_string($raw) && $raw !== '') {
                return trim(str_replace("\0", ' ', $raw));
            }
        }
        $wide = [];
        @exec('ps -ww -p ' . $pid . ' -o command= 2>/dev/null', $wide);
        $command = trim(implode(' ', $wide));
        if ($command !== '') {
            return $command;
        }
        $output = [];
        @exec('ps -p ' . $pid . ' -o command= 2>/dev/null', $output);

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
