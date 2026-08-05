<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;

final class NativeGatewayLauncherTest extends TestCase
{
    private string $root = '';
    private string $launcher = '';
    private string $secretKey = '';
    private bool $preserveRoot = false;

    protected function setUp(): void
    {
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_INTEGRATION') !== '1') {
            self::markTestSkipped('Set WLS_RUN_NATIVE_GATEWAY_INTEGRATION=1 for native launcher integration.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The POSIX stable launcher integration is not a Windows binary test.');
        }
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('libsodium is required for stable launcher verification.');
        }
        $temporaryRoot = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-ngl-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $keyPair = \sodium_crypto_sign_keypair();
        $publicKey = \sodium_crypto_sign_publickey($keyPair);
        $this->secretKey = \sodium_crypto_sign_secretkey($keyPair);
        $build = $this->root . DIRECTORY_SEPARATOR . 'build';
        $source = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native';
        $configure = $this->runCommand([
            'cmake',
            '-S',
            $source,
            '-B',
            $build,
            '-DWLS_RELEASE_PUBLIC_KEY_HEX=' . \bin2hex($publicKey),
            '-DCMAKE_BUILD_TYPE=Release',
        ]);
        self::assertSame(0, $configure['code'], $configure['output']);
        $compiled = $this->runCommand(['cmake', '--build', $build, '--parallel', '2']);
        self::assertSame(0, $compiled['code'], $compiled['output']);
        $this->launcher = $build . DIRECTORY_SEPARATOR . 'wls-gateway-launcher';
        self::assertTrue(\is_executable($this->launcher));
    }

    protected function tearDown(): void
    {
        if ($this->secretKey !== '') {
            \sodium_memzero($this->secretKey);
        }
        if (!$this->preserveRoot) {
            $this->removeTree($this->root);
        }
    }

    public function testSignedSlotExecutesButUnexpectedCleanExitAndTamperingAreFailures(): void
    {
        $selfTest = $this->runCommand([$this->launcher, '--self-test']);
        self::assertSame(0, $selfTest['code'], $selfTest['output']);
        [$home, $run, $broker, $marker] = $this->createSignedHome();

        $started = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $started['code'], $started['output']);
        self::assertFileExists($marker);
        $arguments = (string)\file_get_contents($marker);
        self::assertStringContainsString('--admin-socket', $arguments);
        self::assertStringContainsString('--controller-user', $arguments);
        self::assertStringContainsString('--runtime-generation', $arguments);

        self::assertTrue(\unlink($marker));
        self::assertNotFalse(\file_put_contents($broker, "#!/bin/sh\nexit 9\n"));
        self::assertTrue(\chmod($broker, 0755));
        $tampered = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertNotSame(0, $tampered['code']);
        self::assertFileDoesNotExist($marker);
    }

    public function testReservedBrokerExitCannotImpersonateLauncherReload(): void
    {
        $marker = $this->root . DIRECTORY_SEPARATOR . 'reserved-exit-started';
        $broker = "#!/bin/sh\nprintf 'started\\n' >> " . \escapeshellarg($marker)
            . "\nexit 254\n";
        [$home, $run] = $this->createSignedHome(false, $broker);

        $started = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $started['code'], $started['output']);
        self::assertSame(['started'], \file($marker, FILE_IGNORE_NEW_LINES));
    }

    public function testSignedAdminStopOwnsNonZeroBrokerExit(): void
    {
        $trust = $this->root . DIRECTORY_SEPARATOR . 'home/trust';
        $intent = $trust . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
        $pending = $trust . DIRECTORY_SEPARATOR . 'admin-stopped.pending';
        $marker = $this->root . DIRECTORY_SEPARATOR . 'admin-stop-broker-started';
        $broker = "#!/bin/sh\nprintf 'started\\n' > " . \escapeshellarg($marker)
            . "\ncp " . \escapeshellarg($pending) . ' ' . \escapeshellarg($intent)
            . "\nexit 7\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $payload = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'epoch=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        self::assertNotFalse(\file_put_contents(
            $pending,
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);

        $stopped = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(0, $stopped['code'], $stopped['output']);
        self::assertFileExists($marker);
        self::assertFileExists($intent);
    }

    public function testSignedAndDamagedAdminStoppedIntentBothBlockAutomaticLaunch(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $state = $home . DIRECTORY_SEPARATOR . 'trust';
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $state . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $payload = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'epoch=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $intentFile = $state . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
        self::assertNotFalse(\file_put_contents(
            $intentFile,
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);

        $stopped = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $stopped['code'], $stopped['output']);
        self::assertStringContainsString('signed ADMIN_STOPPED', $stopped['output']);
        self::assertFileDoesNotExist($marker);

        self::assertNotFalse(\file_put_contents($intentFile, "damaged\n"));
        $damaged = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $damaged['code'], $damaged['output']);
        self::assertStringContainsString('invalid ADMIN_STOPPED', $damaged['output']);
        self::assertFileDoesNotExist($marker);
    }

    public function testThirdCandidateCrashWithinObservationWindowRollsBackWholeSlot(): void
    {
        [$home, $run, , $activeMarker] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR . 'candidate-started';
        $this->createSignedCandidateSlot($home, $candidateMarker);
        $preparedAt = \time();
        $payload = "WLS-UPGRADE/1\n"
            . 'host_id=' . $hostId . "\n"
            . "from=A\n"
            . "to=B\n"
            . 'prepared_at=' . $preparedAt . "\n"
            . 'deadline=' . ($preparedAt + 300) . "\n"
            . 'runtime_generation=' . \str_repeat('b', 64) . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $started = $this->runCommand([
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ]);
            self::assertSame(1, $started['code'], $started['output']);
            self::assertFileExists($candidateMarker);
            self::assertTrue(\unlink($candidateMarker));
            self::assertFileDoesNotExist($activeMarker);
        }

        $rolledBack = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $rolledBack['code'], $rolledBack['output']);
        self::assertStringContainsString(
            'rollback awaits old-slot health proof',
            $rolledBack['output'],
        );
        self::assertFileExists($activeMarker);
        self::assertFileExists($candidateMarker);
        self::assertTrue(\unlink($candidateMarker));
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
        );
        self::assertFileExists($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
        self::assertStringContainsString(
            "phase=ROLLBACK_PENDING\n",
            (string)\file_get_contents($trust . DIRECTORY_SEPARATOR . 'upgrade-state'),
        );
        self::assertSame(
            "B\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'previous-slot'),
        );

        // Model a power loss after active-slot=A became durable but before the
        // inverse previous-slot=B write. The terminal rollback must not become
        // eligible until the launcher repairs and rereads that exact pointer.
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        $recovered = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $recovered['code'], $recovered['output']);
        self::assertSame(
            "B\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'previous-slot'),
        );
        self::assertFileExists($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
        self::assertStringContainsString(
            "phase=ROLLBACK_PENDING\n",
            (string)\file_get_contents($trust . DIRECTORY_SEPARATOR . 'upgrade-state'),
        );
    }

    public function testPosixAndWindowsLaunchersShareTheDurableUpgradeContract(): void
    {
        $native = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native';
        $posix = (string)\file_get_contents(
            $native . DIRECTORY_SEPARATOR . 'posix'
                . DIRECTORY_SEPARATOR . 'wls_gateway_launcher.c',
        );
        $windows = (string)\file_get_contents(
            $native . DIRECTORY_SEPARATOR . 'windows'
                . DIRECTORY_SEPARATOR . 'wls_gateway_launcher.c',
        );

        foreach ([$posix, $windows] as $source) {
            self::assertStringContainsString('WLS-UPGRADE-ROLLED-BACK/3', $source);
            self::assertStringContainsString(
                'from=%c\\nto=%c\\nruntime_generation=%s\\nat=%lld',
                $source,
            );
            self::assertStringContainsString('package-install.lock', $source);
            self::assertStringContainsString('WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS', $source);
            self::assertStringContainsString(
                'wls_delete_optional_durable(rollback_path)',
                $source,
            );
            self::assertStringContainsString('verified_previous != upgrade.to', $source);
            self::assertStringContainsString('WLS_UPGRADE_ACTIVATION_SECONDS', $source);
            self::assertStringContainsString('WLS_UPGRADE_TOTAL_SECONDS', $source);
            self::assertStringContainsString(
                'WLS_UPGRADE_OBSERVATION_MILLISECONDS',
                $source,
            );
            self::assertStringContainsString('WLS_ROLLBACK_HEALTH_MILLISECONDS', $source);
            self::assertStringContainsString('WLS_SLOT_RETENTION_MILLISECONDS', $source);
        }
        self::assertStringContainsString('flock(fd, LOCK_EX | LOCK_NB)', $posix);
        self::assertStringContainsString('LockFileEx(', $windows);
        self::assertStringContainsString('UnlockFileEx(', $windows);
        self::assertStringContainsString('MOVEFILE_WRITE_THROUGH', $windows);
    }

    public function testCleanCandidateRestartsDoNotConsumeCrashBudget(): void
    {
        [$home, $run] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR . 'clean-candidate-started';
        $this->createSignedCandidateSlot($home, $candidateMarker, true);
        $preparedAt = \time();
        $payload = "WLS-UPGRADE/1\n"
            . 'host_id=' . $hostId . "\n"
            . "from=A\n"
            . "to=B\n"
            . 'prepared_at=' . $preparedAt . "\n"
            . 'deadline=' . ($preparedAt + 300) . "\n"
            . 'runtime_generation=' . \str_repeat('b', 64) . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        for ($restart = 1; $restart <= 3; $restart++) {
            $process = \proc_open(
                [
                    $this->launcher,
                    '--service',
                    '--home=' . $home,
                    '--run=' . $run,
                    '--profile=default',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            try {
                $deadline = \hrtime(true) + 5_000_000_000;
                while (!\is_file($candidateMarker) && \hrtime(true) < $deadline) {
                    \usleep(50_000);
                }
                self::assertFileExists($candidateMarker);
                self::assertSame(
                    "B\n",
                    \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
                );
                self::assertFileDoesNotExist(
                    $trust . DIRECTORY_SEPARATOR . 'upgrade-attempts',
                );
            } finally {
                self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
                @\unlink($candidateMarker);
            }
        }

        self::assertFileExists($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
        self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade-rolled-back');
    }

    public function testHealthyCandidateCommitsWhileBrokerRemainsRunning(): void
    {
        [$home, $run] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR . 'persistent-candidate-started';
        $this->createSignedCandidateSlot($home, $candidateMarker, true);
        $preparedAt = \time();
        $payload = "WLS-UPGRADE/1\n"
            . 'host_id=' . $hostId . "\n"
            . "from=A\n"
            . "to=B\n"
            . 'prepared_at=' . $preparedAt . "\n"
            . 'deadline=' . ($preparedAt + 300) . "\n"
            . 'runtime_generation=' . \str_repeat('b', 64) . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        $process = \proc_open(
            [
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            $deadline = \hrtime(true) + 5_000_000_000;
            while (!\is_file($candidateMarker) && \hrtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileExists($candidateMarker);
            $stateFile = $trust . DIRECTORY_SEPARATOR . 'upgrade-state';
            $stateDeadline = \hrtime(true) + 5_000_000_000;
            while (!\is_file($stateFile) && \hrtime(true) < $stateDeadline) {
                \usleep(50_000);
            }
            $state = (string)\file_get_contents($stateFile);
            self::assertSame(1, \preg_match(
                '/\AWLS-UPGRADE-STATE\\/2\\n'
                    . 'intent_sha256=([a-f0-9]{64})\\n'
                    . 'intent_nonce=([a-f0-9]{32})\\n'
                    . 'from=A\\nto=B\\n'
                    . 'runtime_generation=([a-f0-9]{64})\\n'
                    . 'boot_id=([a-f0-9]{64})\\n/s',
                $state,
                $stateMatch,
            ));
            self::assertSame(GatewayHostBootIdentity::current(), $stateMatch[4]);
            $observationStarted = 1;
            $observationDeadline = 300001;
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-observing',
                "WLS-UPGRADE-OBSERVING/2\n"
                    . 'intent_sha256=' . $stateMatch[1] . "\n"
                    . 'intent_nonce=' . $stateMatch[2] . "\n"
                    . "from=A\nto=B\n"
                    . 'runtime_generation=' . $stateMatch[3] . "\n"
                    . 'boot_id=' . $stateMatch[4] . "\n"
                    . 'started_monotonic_ms=' . $observationStarted . "\n"
                    . 'deadline_monotonic_ms=' . $observationDeadline . "\n",
            ));
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-healthy',
                "WLS-UPGRADE-HEALTHY/2\n"
                    . 'intent_sha256=' . $stateMatch[1] . "\n"
                    . 'intent_nonce=' . $stateMatch[2] . "\n"
                    . "from=A\nto=B\n"
                    . 'runtime_generation=' . $stateMatch[3] . "\n"
                    . 'boot_id=' . $stateMatch[4] . "\n"
                    . 'observation_deadline_monotonic_ms=' . $observationDeadline . "\n"
                    . 'healthy_monotonic_ms=' . $observationDeadline . "\n",
            ));

            $retention = $trust . DIRECTORY_SEPARATOR . 'slot-retention';
            $deadline = \hrtime(true) + 5_000_000_000;
            while ((! \is_file($retention)
                    || \is_file($trust . DIRECTORY_SEPARATOR . 'upgrade.intent'))
                && \hrtime(true) < $deadline
            ) {
                \usleep(50_000);
            }
            self::assertFileExists($retention);
            self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
            self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade-healthy');
            self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade-attempts');
            self::assertSame("B\n", \file_get_contents(
                $trust . DIRECTORY_SEPARATOR . 'active-slot',
            ));
            self::assertSame(1, \preg_match(
                '/\AWLS-SLOT-RETENTION\\/3\\n'
                    . 'intent_sha256=([a-f0-9]{64})\\n'
                    . 'intent_nonce=([a-f0-9]{32})\\n'
                    . 'slot=A\\n'
                    . 'boot_id=([a-f0-9]{64})\\n'
                    . 'retained_at=([0-9]+)\\n'
                    . 'retain_until=([0-9]+)\\n'
                    . 'retained_since_monotonic_ms=([0-9]+)\\n'
                    . 'retain_until_monotonic_ms=([0-9]+)\\n\z/D',
                (string)\file_get_contents($retention),
                $matches,
            ));
            self::assertSame($stateMatch[1], $matches[1]);
            self::assertSame($stateMatch[2], $matches[2]);
            self::assertSame($stateMatch[4], $matches[3]);
            self::assertSame(86_400, (int)$matches[5] - (int)$matches[4]);
            self::assertSame(86_400_000, (int)$matches[7] - (int)$matches[6]);
            self::assertGreaterThan(\time() + 86_000, (int)$matches[5]);
            self::assertTrue((bool)(\proc_get_status($process)['running'] ?? false));
        } finally {
            self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
        }
    }

    public function testHupRebuildsBrokerUnderTheSameStableLauncherPid(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome(true);
        $process = \proc_open(
            [$this->launcher, '--service', '--home=' . $home, '--run=' . $run, '--profile=default'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            for ($attempt = 0; $attempt < 50 && !\is_file($marker); $attempt++) {
                \usleep(100000);
            }
            self::assertFileExists($marker);
            $status = \proc_get_status($process);
            $launcherPid = (int)($status['pid'] ?? 0);
            self::assertGreaterThan(0, $launcherPid);
            self::assertTrue(\unlink($marker));
            self::assertTrue(\posix_kill($launcherPid, SIGHUP));
            for ($attempt = 0; $attempt < 50 && !\is_file($marker); $attempt++) {
                \usleep(100000);
            }
            self::assertFileExists($marker);
            $after = \proc_get_status($process);
            self::assertTrue((bool)($after['running'] ?? false));
            self::assertSame($launcherPid, (int)($after['pid'] ?? 0));
        } finally {
            self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
        }
    }

    public function testLinuxSystemdRestartsUnexpectedCleanBrokerExit(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux systemd semantics are required.');
        }
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_SYSTEMD_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set WLS_RUN_NATIVE_GATEWAY_SYSTEMD_INTEGRATION=1 for transient systemd validation.',
            );
        }

        $suffix = \bin2hex(\random_bytes(16));
        $unit = 'ai-test-wls2-clean-exit-' . $suffix;
        $systemRoot = '/var/tmp/weline-wls2-ci-' . $suffix;
        self::assertStringStartsWith('/var/tmp/weline-wls2-ci-', $systemRoot);
        $rootLauncher = $systemRoot . '/wls-gateway-launcher';
        $rootHome = $systemRoot . '/home';
        $rootRun = $systemRoot . '/run';
        $rootBroker = $rootHome . '/slots/A/bin/wls-gateway-broker';
        $marker = $systemRoot . '/systemd-clean-exit-starts';
        $broker = "#!/bin/sh\n"
            . "printf 'started\\n' >> " . \escapeshellarg($marker) . "\n"
            . "exit 0\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $rootCreated = false;
        $submissionAttempted = false;
        $unitOwned = false;
        $unloaded = false;
        $cleanup = ['code' => 1, 'output' => 'root fixture cleanup was not attempted'];
        try {
            $preflight = $this->runCommand([
                'sudo', '-n', 'systemctl', 'show', $unit,
                '--property=LoadState', '--value',
            ]);
            self::assertSame(0, $preflight['code'], $preflight['output']);
            self::assertSame(
                'not-found',
                \trim($preflight['output']),
                'The randomized systemd fixture unit already exists.',
            );
            $created = $this->runCommand([
                'sudo', '-n', '/bin/mkdir', '-m', '0755', $systemRoot,
            ]);
            self::assertSame(0, $created['code'], $created['output']);
            $rootCreated = true;
            foreach ([
                ['sudo', '-n', '/bin/cp', '-R', $home, $rootHome],
                ['sudo', '-n', '/bin/cp', '-R', $run, $rootRun],
                ['sudo', '-n', '/bin/cp', $this->launcher, $rootLauncher],
                ['sudo', '-n', '/bin/chown', '-R', 'root:root', $systemRoot],
                ['sudo', '-n', '/bin/chmod', '0755', $rootLauncher],
            ] as $command) {
                $prepared = $this->runCommand($command);
                self::assertSame(0, $prepared['code'], $prepared['output']);
            }
            $ownership = $this->runCommand([
                'sudo', '-n', '/usr/bin/stat', '-c', '%U:%G:%a', $rootLauncher, $rootBroker,
            ]);
            self::assertSame(0, $ownership['code'], $ownership['output']);
            self::assertSame(
                ['root:root:755', 'root:root:755'],
                \preg_split('/\R/', \trim($ownership['output'])),
            );

            $submissionAttempted = true;
            $started = $this->runCommand([
                'sudo',
                '-n',
                'systemd-run',
                '--unit=' . $unit,
                '--collect',
                '--property=Type=simple',
                '--property=Restart=on-failure',
                '--property=RestartSec=200ms',
                $rootLauncher,
                '--service',
                '--home=' . $rootHome,
                '--run=' . $rootRun,
                '--profile=default',
            ]);
            self::assertSame(0, $started['code'], $started['output']);
            $identity = $this->runCommand([
                'sudo', '-n', 'systemctl', 'show', $unit,
                '--property=ExecStart', '--value',
            ]);
            self::assertSame(0, $identity['code'], $identity['output']);
            self::assertStringContainsString($rootLauncher, $identity['output']);
            $unitOwned = true;

            $restartCount = 0;
            $deadline = \hrtime(true) + 8_000_000_000;
            while ($restartCount < 2 && \hrtime(true) < $deadline) {
                $lines = @\file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $restartCount = \is_array($lines) ? \count($lines) : 0;
                if ($restartCount < 2) {
                    \usleep(50_000);
                }
            }
            self::assertGreaterThanOrEqual(
                2,
                $restartCount,
                'systemd must start the clean-exiting Broker more than once.',
            );

            $status = $this->runCommand([
                'sudo',
                '-n',
                'systemctl',
                'show',
                $unit,
                '--property=Restart',
                '--property=NRestarts',
            ]);
            self::assertSame(0, $status['code'], $status['output']);
            self::assertStringContainsString('Restart=on-failure', $status['output']);
            self::assertSame(1, \preg_match('/^NRestarts=([0-9]+)$/m', $status['output'], $matches));
            self::assertGreaterThanOrEqual(1, (int)$matches[1]);
        } finally {
            if ($submissionAttempted) {
                if (!$unitOwned) {
                    $identity = $this->runCommand([
                        'sudo', '-n', 'systemctl', 'show', $unit,
                        '--property=LoadState', '--property=ExecStart',
                    ]);
                    $unloaded = $identity['code'] === 0
                        && \str_contains($identity['output'], 'LoadState=not-found');
                    $unitOwned = !$unloaded
                        && $identity['code'] === 0
                        && \str_contains($identity['output'], $rootLauncher);
                }
                if ($unitOwned) {
                    $this->runCommand([
                        'sudo', '-n', 'systemctl', 'stop', $unit,
                    ]);
                    $this->runCommand([
                        'sudo', '-n', 'systemctl', 'reset-failed', $unit,
                    ]);
                    $deadline = \hrtime(true) + 8_000_000_000;
                    do {
                        $loadState = $this->runCommand([
                            'sudo',
                            '-n',
                            'systemctl',
                            'show',
                            $unit,
                            '--property=LoadState',
                            '--value',
                        ]);
                        $unloaded = $loadState['code'] === 0
                            && \trim($loadState['output']) === 'not-found';
                        if (!$unloaded) {
                            \usleep(100_000);
                        }
                    } while (!$unloaded && \hrtime(true) < $deadline);
                } elseif (!$unloaded) {
                    $cleanup['output'] = 'systemd unit ownership became indeterminate; fixture retained';
                }
            }
            if ($rootCreated && (!$submissionAttempted || $unloaded)) {
                $cleanup = $this->runCommand([
                    'sudo', '-n', '/bin/rm', '-rf', $systemRoot,
                ]);
            }
        }
        self::assertTrue($unloaded, 'The transient systemd unit remained loaded.');
        self::assertSame(0, $cleanup['code'], $cleanup['output']);
        self::assertDirectoryDoesNotExist($systemRoot);
    }

    public function testMacOsLaunchdRestartsUnexpectedCleanBrokerExit(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('macOS launchd semantics are required.');
        }
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_LAUNCHD_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set WLS_RUN_NATIVE_GATEWAY_LAUNCHD_INTEGRATION=1 for transient launchd validation.',
            );
        }
        if (!\function_exists('posix_geteuid')) {
            self::markTestSkipped('The POSIX extension is required to select the launchd user domain.');
        }

        $label = 'com.weline.ai-test.wls2-clean-exit.' . \bin2hex(\random_bytes(16));
        $domain = 'gui/' . \posix_geteuid();
        $service = $domain . '/' . $label;
        $marker = $this->root . DIRECTORY_SEPARATOR . 'launchd-clean-exit-starts';
        self::assertNotFalse(\file_put_contents($marker, ''));
        $broker = "#!/bin/sh\n"
            . "printf 'started\\n' >> " . \escapeshellarg($marker) . "\n"
            . "exit 0\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $plist = $this->root . DIRECTORY_SEPARATOR . $label . '.plist';
        $this->writeLaunchdPlist(
            $plist,
            $label,
            $this->launcher,
            $home,
            $run,
            $this->root . DIRECTORY_SEPARATOR . 'launchd.log',
        );

        $lint = $this->runCommand(['/usr/bin/plutil', '-lint', $plist]);
        self::assertSame(0, $lint['code'], $lint['output']);
        $bootstrapAttempted = false;
        $bootstrapped = false;
        $serviceOwned = false;
        $unloaded = false;
        $bootout = ['code' => 1, 'output' => 'transient launchd service was not removed'];
        try {
            $preflight = $this->runCommand(['/bin/launchctl', 'print', $service]);
            self::assertNotSame(
                0,
                $preflight['code'],
                'The randomized launchd fixture service already exists: '
                    . $preflight['output'],
            );
            $bootstrapAttempted = true;
            $started = $this->runCommand([
                '/bin/launchctl',
                'bootstrap',
                $domain,
                $plist,
            ]);
            self::assertSame(0, $started['code'], $started['output']);
            $bootstrapped = true;
            $identity = $this->runCommand(['/bin/launchctl', 'print', $service]);
            self::assertSame(0, $identity['code'], $identity['output']);
            self::assertStringContainsString($this->launcher, $identity['output']);
            self::assertStringContainsString($home, $identity['output']);
            $serviceOwned = true;

            $restartCount = 0;
            $deadline = \hrtime(true) + 12_000_000_000;
            while ($restartCount < 2 && \hrtime(true) < $deadline) {
                $lines = @\file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $restartCount = \is_array($lines) ? \count($lines) : 0;
                if ($restartCount < 2) {
                    \usleep(50_000);
                }
            }
            self::assertGreaterThanOrEqual(
                2,
                $restartCount,
                'launchd must start the clean-exiting Broker more than once.',
            );

            $status = $this->runCommand(['/bin/launchctl', 'print', $service]);
            self::assertSame(0, $status['code'], $status['output']);
            self::assertStringContainsString('runs =', $status['output']);
            self::assertStringContainsString('last exit code = 1', $status['output']);
        } finally {
            if ($bootstrapAttempted) {
                if (!$serviceOwned) {
                    $identity = $this->runCommand(['/bin/launchctl', 'print', $service]);
                    $unloaded = $identity['code'] !== 0;
                    $serviceOwned = !$unloaded
                        && \str_contains($identity['output'], $this->launcher)
                        && \str_contains($identity['output'], $home);
                }
                if ($serviceOwned) {
                    $bootout = $this->runCommand(['/bin/launchctl', 'bootout', $service]);
                    $deadline = \hrtime(true) + 8_000_000_000;
                    do {
                        $active = $this->runCommand(['/bin/launchctl', 'print', $service]);
                        $unloaded = $active['code'] !== 0;
                        if (!$unloaded) {
                            \usleep(100_000);
                        }
                    } while (!$unloaded && \hrtime(true) < $deadline);
                    if (!$unloaded) {
                        $bootout = $this->runCommand([
                            '/bin/launchctl', 'bootout', $domain, $plist,
                        ]);
                        $active = $this->runCommand(['/bin/launchctl', 'print', $service]);
                        $unloaded = $active['code'] !== 0;
                    }
                }
            }
            $this->preserveRoot = !$unloaded;
        }
        self::assertTrue(
            $unloaded,
            'The transient launchd service remained loaded; its fixture root was retained. '
                . $bootout['output'],
        );
        if ($bootstrapped) {
            self::assertFalse($this->preserveRoot);
        }
        $active = $this->runCommand(['/bin/launchctl', 'print', $service]);
        self::assertNotSame(0, $active['code'], $active['output']);
    }

    public function testMacOsSystemLaunchDaemonRestartsUnexpectedCleanBrokerExit(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('macOS system launchd semantics are required.');
        }
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_SYSTEM_LAUNCHD_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set WLS_RUN_NATIVE_GATEWAY_SYSTEM_LAUNCHD_INTEGRATION=1 explicitly.',
            );
        }
        $sudo = $this->runCommand(['sudo', '-n', 'true']);
        self::assertSame(0, $sudo['code'], $sudo['output']);

        $suffix = \bin2hex(\random_bytes(16));
        $label = 'com.weline.ai-test.wls2-system-clean-exit.' . $suffix;
        $service = 'system/' . $label;
        $systemRoot = '/private/var/tmp/weline-wls2-ci-' . $suffix;
        self::assertStringStartsWith('/private/var/tmp/weline-wls2-ci-', $systemRoot);
        $marker = $systemRoot . '/system-launchd-clean-exit-starts';
        $broker = "#!/bin/sh\n"
            . "printf 'started\\n' >> " . \escapeshellarg($marker) . "\n"
            . "exit 0\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $stagedPlist = $this->root . DIRECTORY_SEPARATOR . $label . '.plist';
        $rootLauncher = $systemRoot . '/wls-gateway-launcher';
        $rootHome = $systemRoot . '/home';
        $rootRun = $systemRoot . '/run';
        $rootBroker = $rootHome . '/slots/A/bin/wls-gateway-broker';
        $rootPlist = $systemRoot . '/' . $label . '.plist';
        $this->writeLaunchdPlist(
            $stagedPlist,
            $label,
            $rootLauncher,
            $rootHome,
            $rootRun,
            $systemRoot . '/launchd.log',
        );
        $lint = $this->runCommand(['/usr/bin/plutil', '-lint', $stagedPlist]);
        self::assertSame(0, $lint['code'], $lint['output']);

        $rootCreated = false;
        $bootstrapAttempted = false;
        $bootstrapped = false;
        $serviceOwned = false;
        $unloaded = false;
        $bootout = null;
        $cleanup = ['code' => 1, 'output' => 'root fixture cleanup was not attempted'];
        try {
            $preflight = $this->runCommand([
                'sudo', '-n', '/bin/launchctl', 'print', $service,
            ]);
            self::assertNotSame(
                0,
                $preflight['code'],
                'The randomized system LaunchDaemon already exists: '
                    . $preflight['output'],
            );
            $created = $this->runCommand([
                'sudo', '-n', '/bin/mkdir', '-m', '0755', $systemRoot,
            ]);
            self::assertSame(0, $created['code'], $created['output']);
            $rootCreated = true;
            foreach ([
                ['sudo', '-n', '/bin/cp', '-R', $home, $rootHome],
                ['sudo', '-n', '/bin/cp', '-R', $run, $rootRun],
                ['sudo', '-n', '/bin/cp', $this->launcher, $rootLauncher],
                ['sudo', '-n', '/usr/sbin/chown', '-R', 'root:wheel', $systemRoot],
                ['sudo', '-n', '/bin/chmod', '0755', $rootLauncher],
                [
                    'sudo',
                    '-n',
                    '/usr/bin/install',
                    '-o',
                    'root',
                    '-g',
                    'wheel',
                    '-m',
                    '0644',
                    $stagedPlist,
                    $rootPlist,
                ],
            ] as $command) {
                $prepared = $this->runCommand($command);
                self::assertSame(0, $prepared['code'], $prepared['output']);
            }
            $ownership = $this->runCommand([
                'sudo',
                '-n',
                '/usr/bin/stat',
                '-f',
                '%Su:%Sg:%Lp',
                $rootPlist,
                $rootLauncher,
                $rootBroker,
            ]);
            self::assertSame(0, $ownership['code'], $ownership['output']);
            self::assertSame(
                [
                    'root:wheel:644',
                    'root:wheel:755',
                    'root:wheel:755',
                ],
                \preg_split('/\R/', \trim($ownership['output'])),
            );

            $bootstrapAttempted = true;
            $started = $this->runCommand([
                'sudo',
                '-n',
                '/bin/launchctl',
                'bootstrap',
                'system',
                $rootPlist,
            ]);
            self::assertSame(0, $started['code'], $started['output']);
            $bootstrapped = true;
            $identity = $this->runCommand([
                'sudo', '-n', '/bin/launchctl', 'print', $service,
            ]);
            self::assertSame(0, $identity['code'], $identity['output']);
            self::assertStringContainsString($rootLauncher, $identity['output']);
            self::assertStringContainsString($rootHome, $identity['output']);
            $serviceOwned = true;
            $restartCount = 0;
            $deadline = \hrtime(true) + 12_000_000_000;
            while ($restartCount < 2 && \hrtime(true) < $deadline) {
                $lines = @\file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $restartCount = \is_array($lines) ? \count($lines) : 0;
                if ($restartCount < 2) {
                    \usleep(50_000);
                }
            }
            self::assertGreaterThanOrEqual(
                2,
                $restartCount,
                'The system LaunchDaemon must restart an unexpectedly clean Broker exit.',
            );
            $status = $this->runCommand([
                'sudo',
                '-n',
                '/bin/launchctl',
                'print',
                $service,
            ]);
            self::assertSame(0, $status['code'], $status['output']);
            self::assertStringContainsString('runs =', $status['output']);
            self::assertStringContainsString('last exit code = 1', $status['output']);
        } finally {
            if ($bootstrapAttempted) {
                if (!$serviceOwned) {
                    $identity = $this->runCommand([
                        'sudo', '-n', '/bin/launchctl', 'print', $service,
                    ]);
                    $unloaded = $identity['code'] !== 0;
                    $serviceOwned = !$unloaded
                        && \str_contains($identity['output'], $rootLauncher)
                        && \str_contains($identity['output'], $rootHome);
                }
                if ($serviceOwned) {
                    $bootout = $this->runCommand([
                        'sudo',
                        '-n',
                        '/bin/launchctl',
                        'bootout',
                        $service,
                    ]);
                    $deadline = \hrtime(true) + 8_000_000_000;
                    do {
                        $active = $this->runCommand([
                            'sudo', '-n', '/bin/launchctl', 'print', $service,
                        ]);
                        $unloaded = $active['code'] !== 0;
                        if (!$unloaded) {
                            \usleep(100_000);
                        }
                    } while (!$unloaded && \hrtime(true) < $deadline);
                } elseif (!$unloaded) {
                    $cleanup['output'] = 'LaunchDaemon ownership became indeterminate; fixture retained';
                }
            }
            if ($rootCreated && (!$bootstrapAttempted || $unloaded)) {
                $cleanup = $this->runCommand([
                    'sudo',
                    '-n',
                    '/bin/rm',
                    '-rf',
                    $systemRoot,
                ]);
            }
        }
        self::assertTrue(
            $unloaded,
            'The system LaunchDaemon remained loaded; its root fixture was retained. '
                . (string)($bootout['output'] ?? ''),
        );
        self::assertTrue(!$bootstrapped || $bootout !== null);
        self::assertSame(0, $cleanup['code'], $cleanup['output']);
        self::assertDirectoryDoesNotExist($systemRoot);
        $active = $this->runCommand([
            'sudo',
            '-n',
            '/bin/launchctl',
            'print',
            $service,
        ]);
        self::assertNotSame(0, $active['code'], $active['output']);
    }

    public function testLinuxLauncherReapsOrphanedGrandchildren(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux child-subreaper semantics are required.');
        }
        $orphanPidFile = $this->root . DIRECTORY_SEPARATOR . 'orphan-pid';
        $broker = "#!/bin/sh\n"
            . "printf '%s\\n' \"\$*\" > "
            . \escapeshellarg($this->root . DIRECTORY_SEPARATOR . 'broker-started')
            . "\n(\n"
            . "  sleep 1 &\n"
            . "  printf '%s\\n' \"\$!\" > " . \escapeshellarg($orphanPidFile) . "\n"
            . "  exit 0\n"
            . ") &\n"
            . "trap 'exit 0' TERM INT HUP\n"
            . "while :; do sleep 1; done\n";
        [$home, $run] = $this->createSignedHome(true, $broker);
        $process = \proc_open(
            [$this->launcher, '--service', '--home=' . $home, '--run=' . $run, '--profile=default'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            $deadline = \hrtime(true) + 5_000_000_000;
            while (!\is_file($orphanPidFile) && \hrtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileExists($orphanPidFile);
            $orphanPid = (int)\trim((string)\file_get_contents($orphanPidFile));
            self::assertGreaterThan(0, $orphanPid);

            $orphanProc = '/proc/' . $orphanPid;
            $deadline = \hrtime(true) + 5_000_000_000;
            while (\file_exists($orphanProc) && \hrtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileDoesNotExist(
                $orphanProc,
                'The launcher must reap orphaned descendants instead of retaining zombies.',
            );
            self::assertTrue((bool)(\proc_get_status($process)['running'] ?? false));
        } finally {
            self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
        }
    }

    private function createSignedCandidateSlot(
        string $home,
        string $marker,
        bool $persistent = false,
    ): void
    {
        $source = $home . DIRECTORY_SEPARATOR . 'slots/A';
        $slot = $home . DIRECTORY_SEPARATOR . 'slots/B';
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'app', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'release', 0700, true));
        $broker = "#!/bin/sh\nprintf '%s\\n' \"\$*\" > "
            . \escapeshellarg($marker) . "\n";
        $broker .= $persistent
            ? "trap 'exit 0' TERM INT HUP\nwhile :; do sleep 1; done\n"
            : "exit 0\n";
        $files = [
            'bin/wls-gateway-broker' => [
                $broker,
                0755,
            ],
            'bin/php' => [(string)\file_get_contents($source . '/bin/php'), 0755],
            'bin/nginx' => [(string)\file_get_contents($source . '/bin/nginx'), 0755],
            'app/controller.php' => [(string)\file_get_contents($source . '/app/controller.php'), 0440],
        ];
        $components = [];
        foreach ($files as $relative => [$contents, $mode]) {
            $file = $slot . DIRECTORY_SEPARATOR . $relative;
            self::assertNotFalse(\file_put_contents($file, $contents));
            self::assertTrue(\chmod($file, $mode));
            $components[$relative] = [
                'sha256' => \hash_file('sha256', $file),
                'size' => \filesize($file),
                'mode' => $mode,
            ];
        }
        $manifest = \json_encode([
            'schema_version' => 2,
            'version' => '2.0.0-test-candidate',
            'components' => $components,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents($slot . '/release/manifest.json', $manifest));
        self::assertNotFalse(\file_put_contents(
            $slot . '/release/manifest.sig',
            \base64_encode(\sodium_crypto_sign_detached($manifest, $this->secretKey)) . PHP_EOL,
        ));
        self::assertNotFalse(\file_put_contents(
            $slot . '/manifest.json',
            \json_encode([
                'schema_version' => 1,
                'role' => 'host_gateway',
                'runtime_generation' => \str_repeat('b', 64),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
    }

    /** @return array{string,string,string,string} */
    private function createSignedHome(
        bool $persistent = false,
        ?string $brokerOverride = null,
    ): array
    {
        $home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $run = $this->root . DIRECTORY_SEPARATOR . 'run';
        $slot = $home . DIRECTORY_SEPARATOR . 'slots' . DIRECTORY_SEPARATOR . 'A';
        $release = $slot . DIRECTORY_SEPARATOR . 'release';
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'app', 0700, true));
        self::assertTrue(\mkdir($release, 0700, true));
        self::assertTrue(\mkdir($home . DIRECTORY_SEPARATOR . 'state', 0700, true));
        self::assertTrue(\mkdir($home . DIRECTORY_SEPARATOR . 'trust', 0700, true));
        self::assertTrue(\mkdir($run, 0700, true));
        $marker = $this->root . DIRECTORY_SEPARATOR . 'broker-started';
        $broker = $brokerOverride
            ?? ("#!/bin/sh\nprintf '%s\\n' \"\$*\" > " . \escapeshellarg($marker) . "\n"
                . ($persistent
                    ? "trap 'exit 0' TERM INT HUP\nwhile :; do sleep 1; done\n"
                    : "exit 0\n"));
        $files = [
            'bin/wls-gateway-broker' => [
                $broker,
                0755,
            ],
            'bin/php' => ["#!/bin/sh\nexit 0\n", 0755],
            'bin/nginx' => ["#!/bin/sh\nexit 0\n", 0755],
            'app/controller.php' => ["<?php\n", 0440],
        ];
        $components = [];
        foreach ($files as $relative => [$contents, $mode]) {
            $file = $slot . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            self::assertNotFalse(\file_put_contents($file, $contents));
            self::assertTrue(\chmod($file, $mode));
            $components[$relative] = [
                'sha256' => \hash_file('sha256', $file),
                'size' => \filesize($file),
                'mode' => $mode,
            ];
        }
        $manifest = \json_encode([
            'schema_version' => 2,
            'version' => '2.0.0-test-signed',
            'components' => $components,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents(
            $release . DIRECTORY_SEPARATOR . 'manifest.json',
            $manifest,
        ));
        $signature = \sodium_crypto_sign_detached($manifest, $this->secretKey);
        self::assertNotFalse(\file_put_contents(
            $release . DIRECTORY_SEPARATOR . 'manifest.sig',
            \base64_encode($signature) . PHP_EOL,
        ));
        self::assertNotFalse(\file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode([
                'schema_version' => 1,
                'role' => 'host_gateway',
                'runtime_generation' => \str_repeat('a', 64),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        self::assertNotFalse(\file_put_contents(
            $home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'active-slot',
            "A\n",
        ));
        return [
            $home,
            $run,
            $slot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wls-gateway-broker',
            $marker,
        ];
    }

    private function writeLaunchdPlist(
        string $plist,
        string $label,
        string $launcher,
        string $home,
        string $run,
        string $log,
    ): void
    {
        $xml = static fn (string $value): string => \htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_XML1,
            'UTF-8',
        );
        self::assertNotFalse(\file_put_contents(
            $plist,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" "
                . "\"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n"
                . "<plist version=\"1.0\"><dict>\n"
                . "<key>Label</key><string>" . $xml($label) . "</string>\n"
                . "<key>ProgramArguments</key><array>\n"
                . "<string>" . $xml($launcher) . "</string>\n"
                . "<string>--service</string>\n"
                . "<string>--home=" . $xml($home) . "</string>\n"
                . "<string>--run=" . $xml($run) . "</string>\n"
                . "<string>--profile=default</string>\n"
                . "</array>\n"
                . "<key>RunAtLoad</key><true/>\n"
                . "<key>KeepAlive</key><dict>"
                . "<key>SuccessfulExit</key><false/>"
                . "</dict>\n"
                . "<key>ThrottleInterval</key><integer>1</integer>\n"
                . "<key>ProcessType</key><string>Background</string>\n"
                . "<key>StandardOutPath</key><string>" . $xml($log) . "</string>\n"
                . "<key>StandardErrorPath</key><string>" . $xml($log) . "</string>\n"
                . "</dict></plist>\n",
        ));
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(array $command, float $timeoutSeconds = 60.0): array
    {
        $process = \proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return ['code' => 127, 'output' => 'Unable to start: ' . \implode(' ', $command)];
        }
        foreach ($pipes as $pipe) {
            \stream_set_blocking($pipe, false);
        }
        $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
        $output = '';
        $exitCode = -1;
        for (;;) {
            $status = \proc_get_status($process);
            foreach ($pipes as $pipe) {
                $chunk = \stream_get_contents($pipe);
                if (\is_string($chunk)) {
                    $output .= $chunk;
                }
            }
            if (!(bool)($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (\hrtime(true) >= $deadline) {
                $this->stopProcess($process, $pipes);
                return [
                    'code' => 124,
                    'output' => \trim(
                        $output . "\nCommand timed out: " . \implode(' ', $command),
                    ),
                ];
            }
            \usleep(25_000);
        }
        foreach ($pipes as $pipe) {
            $chunk = \stream_get_contents($pipe);
            if (\is_string($chunk)) {
                $output .= $chunk;
            }
            @\fclose($pipe);
        }
        $closed = \proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closed;
        }
        return ['code' => $exitCode, 'output' => \trim($output)];
    }

    /**
     * @param resource $process
     * @param array<int,resource> $pipes
     */
    private function stopProcess($process, array $pipes, float $timeoutSeconds = 5.0): int
    {
        $status = \proc_get_status($process);
        $exitCode = !(bool)($status['running'] ?? false)
            ? (int)($status['exitcode'] ?? -1)
            : -1;
        if ((bool)($status['running'] ?? false)) {
            @\proc_terminate($process, SIGTERM);
        }
        $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
        while ((bool)($status['running'] ?? false) && \hrtime(true) < $deadline) {
            $status = \proc_get_status($process);
            foreach ($pipes as $pipe) {
                \is_resource($pipe) && \stream_get_contents($pipe);
            }
            if (!(bool)($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            \usleep(25_000);
        }
        if ((bool)($status['running'] ?? false)) {
            @\proc_terminate($process, SIGKILL);
            $killDeadline = \hrtime(true) + 2_000_000_000;
            do {
                $status = \proc_get_status($process);
                if (!(bool)($status['running'] ?? false)) {
                    $exitCode = (int)($status['exitcode'] ?? -1);
                    break;
                }
                \usleep(25_000);
            } while (\hrtime(true) < $killDeadline);
        }
        foreach ($pipes as $pipe) {
            \is_resource($pipe) && @\fclose($pipe);
        }
        if ((bool)($status['running'] ?? false)) {
            return 124;
        }
        $closed = \proc_close($process);
        return $exitCode >= 0 ? $exitCode : $closed;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
