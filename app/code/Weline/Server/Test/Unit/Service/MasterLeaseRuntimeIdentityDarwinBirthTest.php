<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

final class MasterLeaseRuntimeIdentityDarwinBirthTest extends TestCase
{
    protected function tearDown(): void
    {
        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        parent::tearDown();
    }

    public function testCaptureProcessIdentityUsesDarwinLibprocBirthOnCurrentPid(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Darwin libproc birth capture is macOS-only.');
        }
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            self::markTestSkipped('FFI extension is required for Darwin process birth.');
        }

        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        $identity = new MasterLeaseRuntimeIdentity();
        $first = $identity->captureProcessIdentity((int)\getmypid());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $first['birth']);
        self::assertSame('', $first['pid_namespace_id']);

        // Clearing the success cache must not permanently disable later loads.
        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        $second = $identity->captureProcessIdentity((int)\getmypid());
        self::assertSame($first['birth'], $second['birth']);
    }

    public function testCaptureProcessIdentityFailsFastWhenPidIsDefinitelyMissing(): void
    {
        $identity = new MasterLeaseRuntimeIdentity(
            processInfoResolver: static fn (int $pid): array => ['exists' => false],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WLS process is not running.');
        $identity->captureProcessIdentity(9_999_991);
    }

    public function testInspectProcessTreatsMissingPidAsAbsentWithoutSlowPsProbe(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Darwin missing-PID fast path is macOS-only.');
        }

        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        $identity = new MasterLeaseRuntimeIdentity();
        $missingPid = 9_999_992;
        while (@\posix_kill($missingPid, 0)) {
            ++$missingPid;
        }

        $startedAt = \hrtime(true);
        $info = $identity->inspectProcess($missingPid);
        $elapsedMs = (\hrtime(true) - $startedAt) / 1_000_000;

        self::assertSame(['exists' => false], $info);
        self::assertLessThan(
            250.0,
            $elapsedMs,
            'Missing PID inspection must not fall back to the multi-second ps probe.',
        );
    }

    public function testNativeManagedMetadataPreservesArgumentsTitleAndResolverPriority(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || !\extension_loaded('FFI')) {
            self::markTestSkipped('Darwin native argument inspection requires FFI.');
        }
        $identity = new MasterLeaseRuntimeIdentity();
        $birth = new \ReflectionMethod($identity, 'darwinProcessBirth');
        if ($birth->invoke($identity, (int)\getmypid()) === null) {
            self::markTestSkipped('Darwin native process evidence is unavailable.');
        }
        self::assertTrue(\method_exists($identity, 'inspectDarwinManagedProcess'), 'Managed name/argv observation must support the native path.');
        $native = new \ReflectionMethod($identity, 'inspectDarwinManagedProcess');
        $status = new \ReflectionMethod($identity, 'managedProcessStatus');
        $instance = 'native-metadata-test';
        $expected = \Weline\Server\Service\MasterProcess::getMasterProcessName($instance);
        $script = \tempnam(\sys_get_temp_dir(), 'wls-native-argv-');
        self::assertIsString($script);
        \file_put_contents($script, '<?php echo "ready\\n"; flush(); while (($line = fgets(STDIN)) !== false) { $title = json_decode($line, true); if (!is_string($title)) break; cli_set_process_title($title); echo "titled\\n"; flush(); }');
        try {
            foreach ([['', "tab\there", "line\nthere", 'slash\\there'], ['中文'], [\str_repeat('a', 17_000)]] as $extra) {
                $process = \proc_open(
                    \array_merge([\PHP_BINARY, $script, '--name=' . $expected], $extra),
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'NATIVE_ARGV_ENV' => 'ENV_MUST_NOT_BECOME_ARGV'],
                );
                self::assertIsResource($process);
                try {
                    \stream_set_timeout($pipes[1], 3);
                    self::assertSame('ready', \trim((string)\fgets($pipes[1])));
                    $pid = (int)\proc_get_status($process)['pid'];
                    $actual = $native->invoke($identity, $pid);
                    $legacy = $identity->inspectProcess($pid);
                    if ($extra[0] !== '') {
                        self::assertNull($actual, 'Uncertain encoding or oversized command must use the unchanged full probe.');
                        self::assertSame(
                            $extra[0] === '中文' ? MasterLeaseRuntimeIdentity::OWNER_MATCH : MasterLeaseRuntimeIdentity::OWNER_MISMATCH,
                            $status->invoke($identity, $pid, $instance),
                        );
                        continue;
                    }
                    self::assertIsArray($actual);
                    self::assertSame($legacy['command'], $actual['command']);
                    self::assertSame($legacy['name'], $actual['name']);
                    self::assertStringNotContainsString('ENV_MUST_NOT_BECOME_ARGV', $actual['command']);
                    self::assertSame(MasterLeaseRuntimeIdentity::OWNER_MATCH, $status->invoke($identity, $pid, $instance));
                    foreach ([$expected => MasterLeaseRuntimeIdentity::OWNER_MATCH, 'unmanaged-native-title' => MasterLeaseRuntimeIdentity::OWNER_MISMATCH] as $title => $ownerStatus) {
                        \fwrite($pipes[0], \json_encode($title) . "\n");
                        \fflush($pipes[0]);
                        self::assertSame('titled', \trim((string)\fgets($pipes[1])));
                        $actual = $native->invoke($identity, $pid);
                        $legacy = $identity->inspectProcess($pid);
                        self::assertSame($title, $actual['command']);
                        self::assertSame($legacy['command'], $actual['command']);
                        self::assertSame($legacy['name'], $actual['name']);
                        self::assertSame($ownerStatus, $status->invoke($identity, $pid, $instance));
                    }
                    $resolverCalls = 0;
                    $injected = new MasterLeaseRuntimeIdentity(processInfoResolver: static function (int $observedPid) use (&$resolverCalls, $pid, $expected): array {
                        ++$resolverCalls;
                        self::assertSame($pid, $observedPid);
                        return ['exists' => true, 'name' => $expected, 'command' => $expected];
                    });
                    self::assertSame(MasterLeaseRuntimeIdentity::OWNER_MATCH, $status->invoke($injected, $pid, $instance));
                    self::assertSame(1, $resolverCalls, 'Injected evidence must keep priority over the host PID.');
                } finally {
                    \fwrite($pipes[0], "null\n");
                    foreach ($pipes as $pipe) { \fclose($pipe); }
                    \proc_close($process);
                }
            }
        } finally {
            \unlink($script);
        }
    }

    public function testDarwinArgumentParserPreservesEmptyArgumentsAndStopsBeforeEnvironment(): void
    {
        $identity = new MasterLeaseRuntimeIdentity();
        self::assertTrue(\method_exists($identity, 'parseDarwinProcessArguments'));
        $parse = new \ReflectionMethod($identity, 'parseDarwinProcessArguments');
        $prefix = \pack('i', 3) . "/bin/php\0\0\0";
        self::assertSame('php  --name=master', $parse->invoke($identity, $prefix . "php\0\0--name=master\0ENV_MUST_NOT_BECOME_ARGV=value\0"));
        self::assertSame('changed-title  ', $parse->invoke($identity, $prefix . "changed-title\0\0\0ENV_MUST_NOT_BECOME_ARGV=value\0"));
        foreach ([
            '', \pack('i', 0) . "/bin/php\0php\0", \pack('i', -1) . "/bin/php\0php\0",
            \pack('i', 1) . '/missing-exec-terminator',
            $prefix . "php\0\0missing-last-terminator",
            \pack('i', 1) . "/bin/php\0" . \str_repeat('a', 16_385) . "\0",
            \pack('i', 1) . "/bin/php\0中文\0",
        ] as $raw) {
            self::assertNull($parse->invoke($identity, $raw));
        }
    }
}
