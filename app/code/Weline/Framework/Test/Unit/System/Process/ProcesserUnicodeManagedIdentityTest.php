<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;

final class ProcesserUnicodeManagedIdentityTest extends TestCase
{
    public function testBareUnicodeQueueTaskNameKeepsRealNameInsteadOfCmdHash(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildManagedIdentity');
        $method->setAccessible(true);

        $unicodeTask = 'queue-i18n-ai翻译-en_us-40797';
        $identity = (string)$method->invoke(null, $unicodeTask);

        self::assertSame('--name=' . $unicodeTask, $identity);
        self::assertSame($unicodeTask, Processer::getTaskName($identity));
        self::assertStringNotContainsString('weline-cmd-', $identity);
    }

    public function testRemoveManagedProcessLeaseRecordAcceptsBareUnicodeTaskName(): void
    {
        $taskName = 'queue-i18n-ai翻译-unit-' . \bin2hex(\random_bytes(4));
        $launchId = \bin2hex(\random_bytes(32));
        $pid = 700000 + (\getmypid() % 100000);
        $dir = Env::VAR_DIR . 'process' . \DIRECTORY_SEPARATOR . 'pid' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            self::assertTrue(@\mkdir($dir, 0777, true));
        }

        $pname = '--name=' . $taskName . ' --launch-id=' . $launchId;
        $jsonPath = $dir . $taskName . '-' . $pid . '-pid.json';
        $record = [
            'pid' => $pid,
            'time' => \time(),
            'date' => \date('Y-m-d H:i:s'),
            'pname' => $pname,
            'task_name' => $taskName,
            'record_version' => 2,
            'pname_key' => '--name=' . $taskName,
            'process_name' => $taskName,
            'launch_id' => $launchId,
        ];
        self::assertNotFalse(@\file_put_contents(
            $jsonPath,
            (string)\json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        ));

        $pidIndexPath = $dir . 'pid_index.json';
        $nameIndexPath = $dir . 'name_index.json';
        $previousPidIndex = \is_file($pidIndexPath)
            ? \json_decode((string)\file_get_contents($pidIndexPath), true)
            : [];
        $previousNameIndex = \is_file($nameIndexPath)
            ? \json_decode((string)\file_get_contents($nameIndexPath), true)
            : [];
        if (!\is_array($previousPidIndex)) {
            $previousPidIndex = [];
        }
        if (!\is_array($previousNameIndex)) {
            $previousNameIndex = [];
        }

        $pidIndex = $previousPidIndex;
        $pidIndex[(string)$pid] = [
            'pname' => $pname,
            'jsonPath' => $jsonPath,
        ];
        $nameIndex = $previousNameIndex;
        $nameIndex[$pname] = [[
            'pid' => $pid,
            'jsonPath' => $jsonPath,
        ]];
        self::assertNotFalse(@\file_put_contents(
            $pidIndexPath,
            (string)\json_encode($pidIndex, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        ));
        self::assertNotFalse(@\file_put_contents(
            $nameIndexPath,
            (string)\json_encode($nameIndex, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        ));

        try {
            self::assertTrue(
                Processer::removeManagedProcessLeaseRecord($pid, $taskName, $launchId),
                'Bare Unicode queue task names must remove the exact managed lease.'
            );
            self::assertFileDoesNotExist($jsonPath);
        } finally {
            @\unlink($jsonPath);
            @\file_put_contents(
                $pidIndexPath,
                (string)\json_encode($previousPidIndex, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            );
            @\file_put_contents(
                $nameIndexPath,
                (string)\json_encode($previousNameIndex, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            );
        }
    }

    public function testCleanupDeadPidJsonOrphansFastRemovesDeadLeaseFiles(): void
    {
        $dir = Env::VAR_DIR . 'process' . \DIRECTORY_SEPARATOR . 'pid' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            self::assertTrue(@\mkdir($dir, 0777, true));
        }

        $taskName = 'queue-orphan-fast-' . \bin2hex(\random_bytes(4));
        $pid = 800000 + (\getmypid() % 100000);
        $path = $dir . $taskName . '-' . $pid . '-pid.json';
        self::assertNotFalse(@\file_put_contents($path, (string)\json_encode([
            'pid' => $pid,
            'pname' => '--name=' . $taskName,
            'task_name' => $taskName,
            'process_name' => $taskName,
            'record_version' => 2,
            'launch_id' => \bin2hex(\random_bytes(32)),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));

        try {
            $removed = Processer::cleanupDeadPidJsonOrphansFast();
            self::assertGreaterThanOrEqual(1, $removed);
            self::assertFileDoesNotExist($path);
        } finally {
            @\unlink($path);
        }
    }
}
