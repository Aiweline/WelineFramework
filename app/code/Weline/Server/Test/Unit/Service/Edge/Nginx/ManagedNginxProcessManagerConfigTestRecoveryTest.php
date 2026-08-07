<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxProcessManager;

final class ManagedNginxProcessManagerConfigTestRecoveryTest extends TestCase
{
    private string $root = '';
    private ManagedNginxPaths $paths;
    private ManagedNginxProcessManager $manager;
    private string|false $previousLocalAppData = false;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-nginx-config-test-recovery-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonicalRoot = \realpath($this->root);
        self::assertIsString($canonicalRoot);
        $this->root = $canonicalRoot;
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->previousLocalAppData = \getenv('LOCALAPPDATA');
            self::assertTrue(\putenv('LOCALAPPDATA=' . $this->root));
        }
        $this->paths = new ManagedNginxPaths($this->root, [
            'install_root' => 'nginx-install',
            'runtime_root' => 'nginx-runtime',
        ]);
        $this->paths->ensureRuntimeDirectories();
        $this->manager = new ManagedNginxProcessManager($this->paths);
    }

    protected function tearDown(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(\putenv($this->previousLocalAppData === false
                ? 'LOCALAPPDATA'
                : 'LOCALAPPDATA=' . $this->previousLocalAppData));
        }
        $this->removeTree($this->root);
    }

    public function testNextConfigTestCollectsCanonicalLegacyAndAtomicCrashArtifacts(): void
    {
        $canonicalToken = \str_repeat('a', 32);
        $canonicalConfig = $this->canonicalConfig($canonicalToken);
        $canonicalTemporary = $canonicalConfig . '.tmp-' . \str_repeat('b', 24);
        $canonicalPid = $this->canonicalPid($canonicalToken);
        $legacyToken = \str_repeat('c', 32);
        $legacyConfig = $this->paths->confDir() . DIRECTORY_SEPARATOR
            . 'nginx.conf.candidate.123.' . \str_repeat('d', 8)
            . '.test.' . $legacyToken;
        $legacyTemporary = $legacyConfig . '.tmp-' . \str_repeat('e', 24);
        $legacyPid = $this->legacyPid($legacyToken);
        $this->write($canonicalConfig, $this->isolatedConfig(
            \basename($canonicalPid),
        ));
        $this->write($canonicalTemporary, '');
        $this->write($canonicalPid, "2147483647\n");
        $this->write($legacyConfig, $this->isolatedConfig(
            \basename($legacyPid),
        ));
        $this->write($legacyTemporary, 'partial');
        $this->write($legacyPid, "2147483647\n");

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertStringNotContainsString('recovery failed', \strtolower($result['output']));
        foreach ([
            $canonicalConfig,
            $canonicalTemporary,
            $canonicalPid,
            $legacyConfig,
            $legacyTemporary,
            $legacyPid,
        ] as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testLiveConfigTestPidPreservesTheCompleteRecoverySet(): void
    {
        $liveToken = \str_repeat('1', 32);
        $liveConfig = $this->canonicalConfig($liveToken);
        $livePid = $this->canonicalPid($liveToken);
        $safeToken = \str_repeat('2', 32);
        $safeConfig = $this->canonicalConfig($safeToken);
        $this->write($liveConfig, $this->isolatedConfig(\basename($livePid)));
        $this->write($livePid, (string)\getmypid() . "\n");
        $this->write($safeConfig, $this->isolatedConfig(
            \basename($this->canonicalPid($safeToken)),
        ));

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('live config-test pid', \strtolower($result['output']));
        self::assertFileExists($liveConfig);
        self::assertFileExists($livePid);
        self::assertFileExists($safeConfig);
    }

    public function testMalformedConfigTestPidPreservesTheCompleteRecoverySet(): void
    {
        $token = \str_repeat('3', 32);
        $config = $this->canonicalConfig($token);
        $pid = $this->canonicalPid($token);
        $safe = $this->canonicalConfig(\str_repeat('4', 32));
        $this->write($config, $this->isolatedConfig(\basename($pid)));
        $this->write($pid, "not-a-pid\n");
        $this->write($safe, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('4', 32))),
        ));

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('pid', \strtolower($result['output']));
        self::assertFileExists($config);
        self::assertFileExists($pid);
        self::assertFileExists($safe);
    }

    public function testCanonicalCaseAliasPreservesTheCompleteRecoverySet(): void
    {
        $safe = $this->canonicalConfig(\str_repeat('5', 32));
        $alias = $this->paths->confDir() . DIRECTORY_SEPARATOR
            . 'WLS-nginx-config-test-' . \str_repeat('6', 32) . '.conf';
        $this->write($safe, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('5', 32))),
        ));
        $this->write($alias, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('6', 32))),
        ));

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('case alias', \strtolower($result['output']));
        self::assertFileExists($safe);
        self::assertFileExists($alias);
    }

    public function testMalformedCanonicalLeafPreservesTheCompleteRecoverySet(): void
    {
        $safe = $this->canonicalConfig(\str_repeat('7', 32));
        $malformed = $this->paths->confDir() . DIRECTORY_SEPARATOR
            . 'wls-nginx-config-test-not-a-token.conf';
        $this->write($safe, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('7', 32))),
        ));
        $this->write($malformed, 'preserve-malformed');

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('malformed reserved leaf', \strtolower($result['output']));
        self::assertFileExists($safe);
        self::assertFileExists($malformed);
    }

    public function testMalformedLegacyReservedLeafPreservesTheCompleteRecoverySet(): void
    {
        $safe = $this->canonicalConfig(\str_repeat('0', 32));
        $malformed = $this->paths->confDir() . DIRECTORY_SEPARATOR
            . 'nginx.conf.candidate.not-a-pid.deadbeef.test.'
            . \str_repeat('1', 32);
        $this->write($safe, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('0', 32))),
        ));
        $this->write($malformed, 'preserve-malformed-legacy');

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('malformed reserved leaf', \strtolower($result['output']));
        self::assertFileExists($safe);
        self::assertSame('preserve-malformed-legacy', \file_get_contents($malformed));
    }

    public function testUnrelatedDotTestFileIsOutsideTheLegacyRecoveryNamespace(): void
    {
        $unrelated = $this->paths->confDir() . DIRECTORY_SEPARATOR
            . 'operator-notes.test.' . \str_repeat('2', 32);
        $this->write($unrelated, 'preserve-unrelated');

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertStringNotContainsString('recovery failed', \strtolower($result['output']));
        self::assertSame('preserve-unrelated', \file_get_contents($unrelated));
    }

    public function testSymlinkArtifactPreservesTheCompleteRecoverySetAndTarget(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symbolic-link fixture; Windows reparse rejection is native-gated.');
        }
        $safe = $this->canonicalConfig(\str_repeat('8', 32));
        $linked = $this->canonicalConfig(\str_repeat('9', 32));
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside-config';
        $this->write($safe, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('8', 32))),
        ));
        $this->write($outside, 'preserve-outside');
        self::assertTrue(\symlink($outside, $linked));

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('regular non-linked', \strtolower($result['output']));
        self::assertFileExists($safe);
        self::assertTrue(\is_link($linked));
        self::assertSame('preserve-outside', \file_get_contents($outside));
    }

    public function testHardLinkArtifactPreservesTheCompleteRecoverySetAndTarget(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX hard-link fixture; Windows hard-link rejection is native-gated.');
        }
        $safe = $this->canonicalConfig(\str_repeat('a', 32));
        $linked = $this->canonicalConfig(\str_repeat('b', 32));
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside-hard-link';
        $this->write($safe, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('a', 32))),
        ));
        $this->write($outside, 'preserve-outside');
        self::assertTrue(\link($outside, $linked));

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('regular non-linked', \strtolower($result['output']));
        self::assertFileExists($safe);
        self::assertFileExists($linked);
        self::assertSame('preserve-outside', \file_get_contents($outside));
    }

    public function testArtifactQuotaFailurePreservesEverySelectedArtifact(): void
    {
        $artifacts = [];
        for ($i = 1; $i <= 33; ++$i) {
            $token = \sprintf('%032x', $i);
            $artifact = $this->canonicalConfig($token);
            $this->write($artifact, $this->isolatedConfig(
                \basename($this->canonicalPid($token)),
            ));
            $artifacts[] = $artifact;
        }

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('quota', \strtolower($result['output']));
        foreach ($artifacts as $artifact) {
            self::assertFileExists($artifact);
        }
    }

    public function testConfigTestLockSymlinkIsRejectedWithoutTouchingItsTarget(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symbolic-link lock fixture.');
        }
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside-lock';
        $lock = $this->paths->runDir() . DIRECTORY_SEPARATOR
            . 'managed-nginx.config-test.lock';
        $this->write($outside, 'preserve-lock-target');
        self::assertTrue(\symlink($outside, $lock));

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('lock', \strtolower($result['output']));
        self::assertTrue(\is_link($lock));
        self::assertSame('preserve-lock-target', \file_get_contents($outside));
    }

    public function testConfigTestLockCaseAliasIsRejectedBeforeArtifactMutation(): void
    {
        $safe = $this->canonicalConfig(\str_repeat('d', 32));
        $alias = $this->paths->runDir() . DIRECTORY_SEPARATOR
            . 'MANAGED-NGINX.CONFIG-TEST.LOCK';
        $this->write($safe, $this->isolatedConfig(
            \basename($this->canonicalPid(\str_repeat('d', 32))),
        ));
        $this->write($alias, 'alias-lock');

        $result = $this->manager->testConfig($this->candidateConfig());

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('case alias', \strtolower($result['output']));
        self::assertFileExists($safe);
        self::assertSame('alias-lock', \file_get_contents($alias));
    }

    public function testConcurrentConfigTestWaitsForTheNamespaceLock(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('POSIX fork fixture for observable lock serialization.');
        }
        $candidate = $this->candidateConfig();
        $lock = $this->paths->runDir() . DIRECTORY_SEPARATOR
            . 'managed-nginx.config-test.lock';
        $ready = $this->root . DIRECTORY_SEPARATOR . 'config-test-child.ready';
        $resultFile = $this->root . DIRECTORY_SEPARATOR . 'config-test-child.result';
        $this->write($lock, '');
        $handle = \fopen($lock, 'r+b');
        self::assertIsResource($handle);
        self::assertTrue(\flock($handle, LOCK_EX | LOCK_NB));

        $child = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $child);
        if ($child === 0) {
            \fclose($handle);
            \file_put_contents($ready, 'ready');
            $result = $this->manager->testConfig($candidate);
            \file_put_contents($resultFile, \json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        }

        try {
            $deadline = (\hrtime(true) / 1_000_000_000) + 2.0;
            while (!\is_file($ready) && (\hrtime(true) / 1_000_000_000) < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists($ready);
            \usleep(100_000);
            self::assertFileDoesNotExist($resultFile);
        } finally {
            self::assertTrue(\flock($handle, LOCK_UN));
            self::assertTrue(\fclose($handle));
        }
        self::assertSame($child, \pcntl_waitpid($child, $status));
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
        self::assertFileExists($resultFile);
        $result = \json_decode((string)\file_get_contents($resultFile), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertArrayHasKey('code', $result);
    }

    private function candidateConfig(): string
    {
        $path = $this->paths->confDir() . DIRECTORY_SEPARATOR . 'next-candidate.conf';
        $this->write($path, $this->isolatedConfig('nginx.pid'));
        return $path;
    }

    private function canonicalConfig(string $token): string
    {
        return $this->paths->confDir() . DIRECTORY_SEPARATOR
            . 'wls-nginx-config-test-' . $token . '.conf';
    }

    private function canonicalPid(string $token): string
    {
        return $this->paths->runDir() . DIRECTORY_SEPARATOR
            . 'wls-nginx-config-test-' . $token . '.pid';
    }

    private function legacyPid(string $token): string
    {
        return $this->paths->runDir() . DIRECTORY_SEPARATOR
            . 'nginx-config-test-' . $token . '.pid';
    }

    private function isolatedConfig(string $pidLeaf): string
    {
        return "worker_processes 1;\npid run/{$pidLeaf};\nevents {}\nhttp {}\n";
    }

    private function write(string $path, string $contents): void
    {
        self::assertSame(\strlen($contents), \file_put_contents($path, $contents));
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
