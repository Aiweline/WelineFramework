<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserSetPidTest extends TestCase
{
    public function testBuildProcessIdentityRecordUsesProvidedPnameForCurrentProcess(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildProcessIdentityRecord');
        $method->setAccessible(true);

        $record = $method->invoke(null, '--name=weline-master-default --launch-id=launch-123 --epoch=7', \getmypid(), 'weline-master-default');

        self::assertSame('weline-master-default', (string) ($record['process_name'] ?? ''));
        self::assertSame('launch-123', (string) ($record['launch_id'] ?? ''));
        self::assertSame(7, (int) ($record['epoch'] ?? 0));
    }

    public function testSetPidWritesPayloadIntoPidFilePath(): void
    {
        $pname = '--name=weline-test-setpid-' . \bin2hex(\random_bytes(4));
        $pid = 654321;
        $pidFile = Processer::getPidFile($pname, $pid);

        try {
            Processer::setPid($pname, $pid);
            $data = Processer::getData($pname);

            self::assertFileExists($pidFile);
            self::assertIsArray($data);
            self::assertSame($pid, (int) ($data['pid'] ?? 0));
            self::assertSame($pname, (string) ($data['pname'] ?? ''));
            self::assertGreaterThan(0, (int) \filesize($pidFile));
        } finally {
            Processer::removePidFile($pname);
        }
    }

    public function testPosixDetachedPhpArgvPublishesExactManagedLeaseBeforeReturning(): void
    {
        if (IS_WIN) {
            self::markTestSkipped('POSIX managed lease publication is not used on Windows.');
        }
        foreach ([
            'pcntl_fork',
            'pcntl_exec',
            'posix_setsid',
            'posix_kill',
            'stream_socket_pair',
        ] as $function) {
            if (!\function_exists($function)) {
                self::markTestSkipped('Required POSIX function is unavailable: ' . $function);
            }
        }

        $name = 'weline-test-detached-' . \bin2hex(\random_bytes(4));
        $launchId = \bin2hex(\random_bytes(16));
        $runStart = '1785442000.000001';
        $identity = '--name=' . $name . ' --launch-id=' . $launchId;
        $releasePath = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline-test-detached-release-' . \bin2hex(\random_bytes(4));
        $pid = 0;

        try {
            $pid = Processer::createDetachedPhpArgv([
                PHP_BINARY,
                '-r',
                'while (!is_file(' . \var_export($releasePath, true)
                    . ')) { usleep(10000); }',
                '--',
                '--name=' . $name,
                '--launch-id=' . $launchId,
                '--cron-run-start=' . $runStart,
            ], BP, $identity, false);

            self::assertGreaterThan(0, $pid);
            $lease = Processer::getManagedProcessLeaseRecord($pid, $identity);
            self::assertNotSame([], $lease);
            self::assertSame($pid, (int)($lease['pid'] ?? 0));
            self::assertSame($name, (string)($lease['process_name'] ?? ''));
            self::assertSame($launchId, (string)($lease['launch_id'] ?? ''));
            $trustedCache = new \ReflectionProperty(Processer::class, 'trustedPidCache');
            self::assertArrayNotHasKey(
                $pid,
                $trustedCache->getValue(),
                'A pre-exec lease must not authorize the parent generic PID trust fast path.'
            );

            $requiredArguments = [
                'name' => $name,
                'launch-id' => $launchId,
                'cron-run-start' => $runStart,
            ];
            $deadline = \microtime(true) + 2.0;
            do {
                $commandProbe = Processer::probeManagedProcessIdentity(
                    $pid,
                    $name,
                    $launchId,
                    $identity,
                    true,
                    $requiredArguments,
                );
                if (($commandProbe['state'] ?? '') === Processer::PROCESS_STATE_RUNNING) {
                    break;
                }
                \usleep(10_000);
            } while (\microtime(true) < $deadline);
            self::assertSame(Processer::PROCESS_STATE_RUNNING, $commandProbe['state'] ?? '');
            self::assertSame('identity_match', $commandProbe['reason'] ?? '');

            $liveCommand = Processer::getProcessCommandLine($pid, true);
            self::assertNotSame('', $liveCommand);
            $strictProbe = Processer::probeManagedProcessIdentity(
                $pid,
                $liveCommand,
                $launchId,
                $identity,
                true,
            );
            self::assertSame(Processer::PROCESS_STATE_RUNNING, $strictProbe['state'] ?? '');
            self::assertSame('identity_match', $strictProbe['reason'] ?? '');

            $mismatchProbe = Processer::probeManagedProcessIdentity(
                $pid,
                $name,
                $launchId,
                $identity,
                true,
                $requiredArguments + ['dispatch-token' => \str_repeat('a', 64)],
            );
            self::assertSame(Processer::PROCESS_STATE_UNKNOWN, $mismatchProbe['state'] ?? '');
            self::assertSame('live_required_argument_missing', $mismatchProbe['reason'] ?? '');

            $matchedTermination = Processer::terminateManagedProcessLease(
                $pid,
                $name,
                $launchId,
                $identity,
                false,
                $requiredArguments,
            );
            self::assertSame(
                Processer::PROCESS_STATE_UNKNOWN,
                $matchedTermination['state'] ?? ''
            );
            self::assertSame(
                'termination_unavailable_without_stable_handle',
                $matchedTermination['reason'] ?? ''
            );
            self::assertFalse((bool)($matchedTermination['terminated'] ?? true));
            self::assertFalse((bool)($matchedTermination['released'] ?? true));
            self::assertNotSame(
                [],
                Processer::getManagedProcessLeaseRecord($pid, $identity),
                'Fail-closed POSIX termination must retain the exact lease.'
            );
            self::assertSame(
                Processer::PROCESS_STATE_RUNNING,
                Processer::probeProcessState($pid, true),
                'A matching required argv fence still cannot authorize a raw PID signal.'
            );

            $wrongIdentityTermination = Processer::terminateManagedProcessLease(
                $pid,
                $name . '-wrong',
                $launchId,
                $identity,
                false,
                $requiredArguments,
            );
            self::assertSame(
                Processer::PROCESS_STATE_UNKNOWN,
                $wrongIdentityTermination['state'] ?? ''
            );
            self::assertSame(
                'expected_process_name_conflicts_with_lease',
                $wrongIdentityTermination['reason'] ?? ''
            );
            self::assertFalse((bool)($wrongIdentityTermination['terminated'] ?? true));
            self::assertFalse((bool)($wrongIdentityTermination['released'] ?? true));
            self::assertSame(
                Processer::PROCESS_STATE_RUNNING,
                Processer::probeProcessState($pid, true),
                'A wrong expected identity must never signal the live PID.'
            );
        } finally {
            if ($pid > 0) {
                @\touch($releasePath);
                Processer::waitForExit([$pid], 2.0);
                Processer::removeManagedProcessLeaseRecord($pid, $name, $launchId);
            }
            @\unlink($releasePath);
        }
    }

    public function testRequiredLiveArgumentsRejectConfusableAndDuplicateTokens(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'validateRequiredLiveArguments');
        $collector = new \ReflectionMethod(Processer::class, 'collectExactCommandLineArguments');
        $windowsValidator = new \ReflectionMethod(
            Processer::class,
            'validateRenderedWindowsRequiredArguments'
        );
        $name = 'weline-queue-test';
        $launchId = \bin2hex(\random_bytes(16));
        $dispatchToken = \bin2hex(\random_bytes(32));
        $required = [
            'name' => $name,
            'launch-id' => $launchId,
            'dispatch-token' => $dispatchToken,
        ];
        $identity = [
            '/usr/bin/php',
            '/tmp/worker.php',
            '--name=' . $name,
            '--launch-id=' . $launchId,
        ];

        self::assertSame(
            'live_required_argument_missing',
            $method->invoke(
                null,
                $required,
                $collector->invoke(null, [
                    '/usr/bin/php',
                    '/tmp/--dispatch-token=' . $dispatchToken . '/unrelated.php',
                    '--name=' . $name,
                    '--launch-id=' . $launchId,
                ]),
            ),
            'A required flag embedded in an unrelated script path is not argv.'
        );
        self::assertSame(
            'live_required_argument_missing',
            $method->invoke(
                null,
                $required,
                $collector->invoke(null, [
                    ...$identity,
                    '--note=prefix --dispatch-token=' . $dispatchToken,
                ]),
            ),
            'A required flag hidden inside one real argv value is not argv.'
        );
        self::assertSame(
            'live_required_argument_duplicate',
            $method->invoke(
                null,
                $required,
                $collector->invoke(null, [
                    ...$identity,
                    '--dispatch-token=' . $dispatchToken,
                    '--dispatch-token=' . $dispatchToken,
                ]),
            ),
            'A repeated identity key is ambiguous even when both values match.'
        );
        self::assertSame(
            'live_required_argument_duplicate',
            $method->invoke(
                null,
                $required,
                $collector->invoke(null, [
                    ...$identity,
                    '--NAME=attacker',
                    '--name=' . $name,
                    '--dispatch-token=' . $dispatchToken,
                ]),
            ),
            'Option names are case-insensitive when detecting identity ambiguity.'
        );
        self::assertSame(
            '',
            $method->invoke(
                null,
                $required,
                $collector->invoke(null, [
                    ...$identity,
                    '--dispatch-token',
                    $dispatchToken,
                ]),
            ),
            'The exact two-token long-option form remains supported.'
        );
        self::assertSame(
            '',
            $windowsValidator->invoke(
                null,
                'php.exe worker.php --name=' . $name
                    . ' --launch-id=' . $launchId
                    . ' --dispatch-token=' . $dispatchToken,
                $required,
            ),
            'The existing Windows CIM rendered-command contract remains available.'
        );
    }
}
