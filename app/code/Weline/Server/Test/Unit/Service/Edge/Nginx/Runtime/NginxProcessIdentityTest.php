<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxProcessIdentity;

final class NginxProcessIdentityTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-identity-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAdoptionBindsPidDigestGenerationAndExactArgvPaths(): void
    {
        [$identity, $command, $generation, $processManifest] = $this->fixture();

        $adopted = $identity->inspect(32123, $command, true);
        self::assertTrue($adopted['ok']);
        self::assertTrue($adopted['adopted']);
        self::assertSame($generation, $adopted['runtime_generation']);
        self::assertSame(32123, $identity->recordedPid());

        $verified = $identity->inspect(32123, $command, false);
        self::assertTrue($verified['ok']);
        self::assertFalse($verified['adopted']);
        self::assertFileExists($processManifest);
    }

    public function testAdoptionAcceptsExactManifestAfterPostRenameDirectorySyncFailure(): void
    {
        [$identity, $command, $generation, $processManifest] = $this->fixture(
            'post-rename-manifest',
        );

        $result = $this->withPostRenameSyncFailure(
            $processManifest,
            static fn(): array => $identity->inspect(32123, $command, true),
        );

        self::assertTrue($result['ok'], $result['reason'] ?? 'missing reason');
        self::assertTrue($result['adopted']);
        self::assertSame($generation, $result['runtime_generation']);
        self::assertSame(32123, $identity->recordedPid());
        self::assertFileExists($processManifest);
    }

    public function testDifferentPidCannotReuseExistingProcessGeneration(): void
    {
        [$identity, $command] = $this->fixture();
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);

        $reused = $identity->inspect(32124, $command, true);

        self::assertFalse($reused['ok']);
        self::assertTrue(
            \str_contains($reused['reason'], 'generation does not match')
            || \str_contains($reused['reason'], 'process_start_identity'),
            $reused['reason'],
        );
        self::assertSame(32123, $identity->recordedPid());
    }

    public function testBinaryMutationAndArgvMismatchFailClosed(): void
    {
        [$identity, $command, , , $binary] = $this->fixture();
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);
        self::assertSame(7, \file_put_contents($binary, 'changed'));

        $mutated = $identity->inspect(32123, $command, false);
        self::assertFalse($mutated['ok']);
        self::assertStringContainsString('binary digest', $mutated['reason']);

        [$fresh, , , , $freshBinary] = $this->fixture('second');
        $wrongCommand = $this->quote($freshBinary) . ' -p ' . $this->quote($this->root)
            . ' -c ' . $this->quote($this->root . DIRECTORY_SEPARATOR . 'wrong.conf');
        $wrong = $fresh->inspect(65432, $wrongCommand, true);
        self::assertFalse($wrong['ok']);
        self::assertStringContainsString('argv', $wrong['reason']);
    }

    public function testClearRequiresMatchingPid(): void
    {
        [$identity, $command] = $this->fixture();
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);

        try {
            $identity->clear(99999);
            self::fail('A different PID must not clear the process identity.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('another generation', $exception->getMessage());
        }

        $identity->clear(32123);
        self::assertNull($identity->recordedPid());
    }

    public function testIdentityLockCollectsValidatedRetainedBackupBeforeClear(): void
    {
        [$identity, $command, , $processManifest] = $this->fixture('backup-cleanup');
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);
        $backup = $processManifest . '.wls-backup-' . \str_repeat('a', 16);
        self::assertSame(17, \file_put_contents($backup, '{"previous":true}'));

        self::assertSame(32123, $identity->recordedPid());
        self::assertFileDoesNotExist($backup);

        self::assertSame(17, \file_put_contents($backup, '{"previous":true}'));
        $identity->clear(32123);
        self::assertFileDoesNotExist($processManifest);
        self::assertFileDoesNotExist($backup);
    }

    public function testIdentityLockPreservesBackupWhenPairedManifestIsInvalidOrMissing(): void
    {
        [$identity, $command, , $processManifest] = $this->fixture('backup-preserve');
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);
        $backup = $processManifest . '.wls-backup-' . \str_repeat('b', 16);
        self::assertSame(17, \file_put_contents($backup, '{"previous":true}'));
        self::assertSame(8, \file_put_contents($processManifest, '{broken:'));

        try {
            $identity->recordedPid();
            self::fail('An invalid paired process manifest must fail closed.');
        } catch (\RuntimeException) {
            self::assertFileExists($processManifest);
            self::assertFileExists($backup);
        }

        self::assertTrue(\unlink($processManifest));
        try {
            $identity->recordedPid();
            self::fail('A missing paired process manifest must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('missing or unsafe', $exception->getMessage());
        }
        self::assertFileDoesNotExist($processManifest);
        self::assertFileExists($backup);
    }

    public function testIdentityLockCompetitionHonorsAbsoluteLifecycleDeadline(): void
    {
        [$identity, $command, , $processManifest] = $this->fixture(
            'deadline-competition',
        );
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);
        $lockFile = $processManifest . '.lock';
        $lock = \fopen($lockFile, 'r+b');
        self::assertIsResource($lock);
        self::assertTrue(\flock($lock, LOCK_EX | LOCK_NB));
        $started = \hrtime(true) / 1_000_000_000;
        try {
            $identity->recordedPid($started + 0.05);
            self::fail('A competing identity lock must honor the lifecycle deadline.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'lock timed out before the lifecycle deadline',
                $exception->getMessage(),
            );
        } finally {
            self::assertTrue(\flock($lock, LOCK_UN));
            self::assertTrue(\fclose($lock));
        }
        self::assertLessThan(
            0.75,
            (\hrtime(true) / 1_000_000_000) - $started,
        );
        self::assertSame(32123, $identity->recordedPid());
    }

    public function testExpiredLifecycleDeadlineCannotClearProcessIdentity(): void
    {
        [$identity, $command] = $this->fixture('deadline-exhausted');
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);

        try {
            $identity->clear(
                32123,
                (\hrtime(true) / 1_000_000_000) - 0.001,
            );
            self::fail('An expired deadline must not mutate process identity.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'deadline was exhausted',
                $exception->getMessage(),
            );
        }
        self::assertSame(32123, $identity->recordedPid());
    }

    public function testNormalizeProcessStartTimeRecoversBleedingNumericColumns(): void
    {
        [$identity] = $this->fixture('start-time');
        $method = new \ReflectionMethod(NginxProcessIdentity::class, 'normalizeProcessStartTime');
        $method->setAccessible(true);

        self::assertSame(
            'Tue Aug 4 18:59:13 2026',
            $method->invoke($identity, '0.0 0.0 Tue Aug  4 18:59:13 2026'),
        );
        self::assertSame(
            'Tue Aug 4 18:59:13 2026',
            $method->invoke($identity, 'Tue Aug  4 18:59:13 2026'),
        );
        self::assertNull($method->invoke($identity, '0.0 0.0'));
        self::assertNull($method->invoke($identity, ''));
    }

    public function testRepeatedInspectStaysStableWithDarwinIdentityResolver(): void
    {
        $calls = 0;
        [$identity, $command] = $this->fixture(
            'repeat-inspect',
            static function (int $pid) use (&$calls): string {
                ++$calls;

                return 'darwin-start-timeval:1785841153:80586';
            },
        );

        for ($i = 0; $i < 8; ++$i) {
            $result = $identity->inspect(32123, $command, $i === 0);
            self::assertTrue($result['ok'], $result['reason'] ?? 'missing reason');
        }
        self::assertGreaterThanOrEqual(8, $calls);
    }

    public function testLegacyManifestIsAttestedOnceAndThenFenced(): void
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'legacy';
        self::assertTrue(\mkdir($directory, 0700, true));
        $binary = $directory . DIRECTORY_SEPARATOR . 'nginx';
        $prefix = $directory . DIRECTORY_SEPARATOR . 'runtime';
        $config = $prefix . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'nginx.conf';
        $manifest = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $processManifest = $prefix . DIRECTORY_SEPARATOR . 'run'
            . DIRECTORY_SEPARATOR . 'nginx.process-identity.json';
        self::assertTrue(\mkdir(\dirname($config), 0700, true));
        self::assertTrue(\mkdir(\dirname($processManifest), 0700, true));
        self::assertSame(6, \file_put_contents($binary, 'legacy'));
        self::assertSame(8, \file_put_contents($config, 'events{}'));
        $payloadWithoutGeneration = [
            'schema_version' => 2,
            'role' => 'legacy-project-nginx',
            'implementation_level' => 'nginx-runtime-v2',
            'version' => '1.26.3',
            'binary' => $binary,
            'binary_sha256' => \hash_file('sha256', $binary),
        ];
        $generation = \hash(
            'sha256',
            \json_encode($this->canonicalize($payloadWithoutGeneration), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        self::assertNotFalse(\file_put_contents($manifest, \json_encode(
            $payloadWithoutGeneration + ['runtime_generation' => $generation],
            JSON_THROW_ON_ERROR,
        )));
        $identity = new NginxProcessIdentity(
            role: 'legacy-project-nginx',
            binary: $binary,
            prefix: $prefix,
            config: $config,
            installManifest: $manifest,
            processManifest: $processManifest,
            processStartIdentityResolver: static fn (int $pid): string => 'legacy-start:' . $pid,
        );
        $command = $this->quote($binary) . ' -p ' . $this->quote($prefix)
            . ' -c ' . $this->quote($config);

        $adopted = $identity->inspect(76543, $command, true);
        self::assertTrue($adopted['ok']);
        self::assertTrue($adopted['adopted']);
        self::assertSame(7, \file_put_contents($binary, 'changed'));

        $changed = $identity->inspect(76543, $command, false);
        self::assertFalse($changed['ok']);
        self::assertStringContainsString('binary digest', $changed['reason']);
    }

    /**
     * @return array{NginxProcessIdentity,string,string,string,string}
     */
    private function fixture(string $name = 'first', ?\Closure $processStartIdentityResolver = null): array
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $binary = $directory . DIRECTORY_SEPARATOR . 'nginx';
        $prefix = $directory . DIRECTORY_SEPARATOR . 'runtime';
        $config = $prefix . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'nginx.conf';
        $manifest = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $processManifest = $prefix . DIRECTORY_SEPARATOR . 'run'
            . DIRECTORY_SEPARATOR . 'nginx.process-identity.json';
        self::assertTrue(\mkdir(\dirname($config), 0700, true));
        self::assertTrue(\mkdir(\dirname($processManifest), 0700, true));
        self::assertSame(6, \file_put_contents($binary, 'binary'));
        self::assertSame(8, \file_put_contents($config, 'events{}'));
        $payloadWithoutGeneration = [
            'schema_version' => 2,
            'role' => 'test-nginx',
            'implementation_level' => 'nginx-runtime-v2',
            'version' => '1.30.4',
            'binary' => $binary,
            'binary_sha256' => \hash_file('sha256', $binary),
        ];
        $generation = \hash(
            'sha256',
            \json_encode($this->canonicalize($payloadWithoutGeneration), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $payload = $payloadWithoutGeneration + ['runtime_generation' => $generation];
        self::assertNotFalse(\file_put_contents(
            $manifest,
            \json_encode($payload, JSON_THROW_ON_ERROR),
        ));
        $identity = new NginxProcessIdentity(
            role: 'test-nginx',
            binary: $binary,
            prefix: $prefix,
            config: $config,
            installManifest: $manifest,
            processManifest: $processManifest,
            processStartIdentityResolver: $processStartIdentityResolver
                ?? static fn (int $pid): string => 'test-start-identity:stable',
        );
        $command = $this->quote($binary) . ' -p ' . $this->quote($prefix)
            . ' -c ' . $this->quote($config);

        return [$identity, $command, $generation, $processManifest, $binary];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        \ksort($value, SORT_STRING);

        return $value;
    }

    private function quote(string $value): string
    {
        return '"' . $value . '"';
    }

    private function withPostRenameSyncFailure(string $target, callable $operation): mixed
    {
        $previousMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $previousFailure = \getenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE');
        $previousTarget = \getenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256',
        );
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE=directory_fsync_after_rename_failed',
        );
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256=' . \hash('sha256', $target),
        );
        try {
            return $operation();
        } finally {
            $previousMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $previousMode);
            $previousFailure === false
                ? \putenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE=' . $previousFailure);
            $previousTarget === false
                ? \putenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256=' . $previousTarget,
                );
        }
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
