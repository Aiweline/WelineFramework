<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayLauncherTest extends TestCase
{
    private string $root = '';
    private string $launcher = '';
    private string $secretKey = '';

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
        $this->removeTree($this->root);
    }

    public function testSignedSlotExecutesButTamperedBrokerIsRejected(): void
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
        self::assertSame(0, $started['code'], $started['output']);
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
            self::assertSame(0, $started['code'], $started['output']);
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
        self::assertSame(0, $rolledBack['code'], $rolledBack['output']);
        self::assertStringContainsString('rolled back', $rolledBack['output']);
        self::assertFileExists($activeMarker);
        self::assertFileExists($candidateMarker);
        self::assertTrue(\unlink($candidateMarker));
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
        );
        self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
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
                $deadline = \microtime(true) + 5.0;
                while (!\is_file($candidateMarker) && \microtime(true) < $deadline) {
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
                @\proc_terminate($process, SIGTERM);
                foreach ($pipes ?? [] as $pipe) {
                    \is_resource($pipe) && @\fclose($pipe);
                }
                self::assertSame(0, \proc_close($process));
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
            $deadline = \microtime(true) + 5.0;
            while (!\is_file($candidateMarker) && \microtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileExists($candidateMarker);
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-healthy',
                "WLS-UPGRADE-HEALTHY/1\n"
                    . "to=B\n"
                    . 'runtime_generation=' . \str_repeat('b', 64) . "\n",
            ));

            $retention = $trust . DIRECTORY_SEPARATOR . 'slot-retention';
            $deadline = \microtime(true) + 5.0;
            while ((! \is_file($retention)
                    || \is_file($trust . DIRECTORY_SEPARATOR . 'upgrade.intent'))
                && \microtime(true) < $deadline
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
                '/\AWLS-SLOT-RETENTION\\/1\\nslot=A\\nretain_until=([0-9]+)\\n\z/D',
                (string)\file_get_contents($retention),
                $matches,
            ));
            self::assertGreaterThan(\time() + 86_000, (int)$matches[1]);
            self::assertTrue((bool)(\proc_get_status($process)['running'] ?? false));
        } finally {
            @\proc_terminate($process, SIGTERM);
            foreach ($pipes ?? [] as $pipe) {
                \is_resource($pipe) && @\fclose($pipe);
            }
            self::assertSame(0, \proc_close($process));
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
            @\proc_terminate($process, SIGTERM);
            foreach ($pipes ?? [] as $pipe) {
                \is_resource($pipe) && @\fclose($pipe);
            }
            self::assertSame(0, \proc_close($process));
        }
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
            $deadline = \microtime(true) + 5.0;
            while (!\is_file($orphanPidFile) && \microtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileExists($orphanPidFile);
            $orphanPid = (int)\trim((string)\file_get_contents($orphanPidFile));
            self::assertGreaterThan(0, $orphanPid);

            $orphanProc = '/proc/' . $orphanPid;
            $deadline = \microtime(true) + 5.0;
            while (\file_exists($orphanProc) && \microtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileDoesNotExist(
                $orphanProc,
                'The launcher must reap orphaned descendants instead of retaining zombies.',
            );
            self::assertTrue((bool)(\proc_get_status($process)['running'] ?? false));
        } finally {
            @\proc_terminate($process, SIGTERM);
            foreach ($pipes ?? [] as $pipe) {
                \is_resource($pipe) && @\fclose($pipe);
            }
            self::assertSame(0, \proc_close($process));
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

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(array $command): array
    {
        $parts = \array_map(static fn (string $part): string => \escapeshellarg($part), $command);
        $output = [];
        $code = 0;
        \exec(\implode(' ', $parts) . ' 2>&1', $output, $code);
        return ['code' => $code, 'output' => \implode("\n", $output)];
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
