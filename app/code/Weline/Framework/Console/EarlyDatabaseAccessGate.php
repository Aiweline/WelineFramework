<?php

declare(strict_types=1);

namespace Weline\Framework\Console;

use Weline\Framework\Phrase\DatabaseFreeTranslator;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;

/**
 * Database gate acquired by bin/w and bin/m before application bootstrap.
 *
 * This class deliberately depends only on two file-only framework classes so
 * a lock-contention path can finish without autoloading the application,
 * dispatching observers, building models, or consulting database-backed i18n.
 */
final class EarlyDatabaseAccessGate
{
    /**
     * @param list<string> $argv
     * @return int|null Exit code when the invocation is rejected; null otherwise.
     */
    public static function prepare(array $argv): ?int
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
        $acquired = ($isCron || $readOnlySetup)
            ? $lease->acquireShared()
            : $lease->acquireExclusive();
        if (!$acquired) {
            if ($isCron) {
                self::writeMessage(DatabaseFreeTranslator::translate(
                    '系统升级正在执行，本次计划任务已跳过且未访问数据库。',
                    'Weline_Cron',
                ));

                return self::hasAnyOption($argv, ['p', 'process', 'f', 'force']) ? 75 : 0;
            }

            self::writeMessage(DatabaseFreeTranslator::translate(
                '系统升级文件门禁正被其他升级或计划任务进程占用，本次升级未启动、未访问数据库；当前数据库驱动配置未被切换，请稍后再试。',
                'Weline_Framework',
            ));

            return 75;
        }

        SetupDatabaseAccessLock::retainCliBootstrapLease($lease);
        return null;
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
