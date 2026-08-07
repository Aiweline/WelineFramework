<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Policy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Server\Service\Policy\RuntimePolicyStore;

final class RuntimePolicyStoreLockSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupFiles = [];

    /** @var list<string> */
    private array $cleanupDirectories = [];

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->cleanupFiles) as $file) {
            if (\is_link($file) || \is_file($file)) {
                @\unlink($file);
            }
        }
        foreach (\array_reverse($this->cleanupDirectories) as $directory) {
            @\rmdir($directory);
        }
    }

    public function testWriteLockContentionFailsWithinTheMonotonicBudget(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-lock-contention');
        $lockPath = $instanceDirectory . DIRECTORY_SEPARATOR . '.lock';
        $lock = \fopen($lockPath, 'x+b');
        self::assertIsResource($lock);
        self::assertTrue(\flock($lock, LOCK_EX));
        $this->cleanupFiles[] = $lockPath;

        try {
            $sourceRoot = $this->projectRoot();
            $script = 'define("DS", DIRECTORY_SEPARATOR);'
                . 'require ' . \var_export($sourceRoot . 'vendor/autoload.php', true) . ';'
                . '$store = new \\Weline\\Server\\Service\\Policy\\RuntimePolicyStore('
                . \var_export($base, true) . ', 0.05);'
                . '$bundle = \\Weline\\Framework\\Runtime\\Policy\\RuntimePolicyBundle::fromDescriptors([]);'
                . 'try {'
                . '$store->save("ai-test-policy", $bundle);'
                . '$result = ["saved" => true, "error" => ""];'
                . '} catch (\\Throwable $throwable) {'
                . '$result = ["saved" => false, "error" => $throwable->getMessage()];'
                . '}'
                . 'echo "WLS_POLICY_RESULT=" . base64_encode((string)json_encode($result));';

            $execution = $this->runPhp($script, 1.0);

            self::assertFalse(
                $execution['timed_out'],
                'Runtime policy lock contention exceeded its monotonic wait budget.',
            );
            self::assertLessThan(0.75, $execution['elapsed']);
            self::assertSame(0, $execution['exit_code'], $this->executionFailure($execution));
            self::assertFalse($execution['result']['saved'] ?? true);
            self::assertStringContainsString(
                'runtime policy store lock',
                \strtolower((string)($execution['result']['error'] ?? '')),
            );
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
    }

    public function testWriteLockRejectsAHardLinkedInode(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link identity validation is POSIX-specific.');
        }
        [$base, $instanceDirectory] = $this->createStore('wls-policy-lock-hardlink');
        $foreign = $instanceDirectory . DIRECTORY_SEPARATOR . 'foreign.lock';
        $lockPath = $instanceDirectory . DIRECTORY_SEPARATOR . '.lock';
        self::assertNotFalse(\file_put_contents($foreign, 'foreign'));
        self::assertTrue(\chmod($foreign, 0640));
        $foreignMode = ((int)\fileperms($foreign)) & 0777;
        self::assertTrue(\link($foreign, $lockPath));
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $lockPath;

        $store = new RuntimePolicyStore($base, 0.05);
        $bundle = RuntimePolicyBundle::fromDescriptors([]);

        try {
            $store->save('ai-test-policy', $bundle);
            self::fail('A hard-linked runtime policy lock was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'runtime policy store lock',
                \strtolower($exception->getMessage()),
            );
        }

        self::assertSame('foreign', (string)\file_get_contents($foreign));
        self::assertSame(
            $foreignMode,
            ((int)\fileperms($foreign)) & 0777,
            'Rejecting a hard-linked lock must not chmod the foreign inode.',
        );
    }

    public function testWriteLockRefusesToFollowASymbolicLink(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link creation is not available on this runtime.');
        }
        [$base, $instanceDirectory] = $this->createStore('wls-policy-lock-symlink');
        $foreign = $instanceDirectory . DIRECTORY_SEPARATOR . 'foreign.lock';
        $lockPath = $instanceDirectory . DIRECTORY_SEPARATOR . '.lock';
        self::assertNotFalse(\file_put_contents($foreign, 'foreign'));
        self::assertTrue(\symlink($foreign, $lockPath));
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $lockPath;

        $store = new RuntimePolicyStore($base, 0.05);

        $this->expectException(\RuntimeException::class);
        $store->save('ai-test-policy', RuntimePolicyBundle::fromDescriptors([]));
    }

    public function testVerifiedWriteLockPublishesAndReleasesForTheNextTransaction(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-lock-success');
        $store = new RuntimePolicyStore($base, 0.2);
        $bundle = RuntimePolicyBundle::fromDescriptors([]);

        $saved = $store->save('ai-test-policy', $bundle);
        $staged = $store->stage('ai-test-policy', $bundle);
        $active = $store->activate('ai-test-policy', $bundle->digest);

        $this->cleanupFiles[] = $instanceDirectory . DIRECTORY_SEPARATOR . '.lock';
        $this->cleanupFiles[] = $instanceDirectory . DIRECTORY_SEPARATOR . 'state.php';
        $this->cleanupFiles[] = $saved;
        self::assertFileExists($saved);
        self::assertSame($bundle->digest, $staged['staged_digest']);
        self::assertSame($bundle->digest, $active['active_digest']);
        self::assertSame('', $active['staged_digest']);
        self::assertSame($bundle->digest, $store->active('ai-test-policy')?->digest);
    }

    public function testValidCommittedBundleCollectsExactLegacyAndNativeCrashResidues(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-publication-recovery');
        $store = new RuntimePolicyStore($base, 0.2);
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $target = $store->save('ai-test-policy', $bundle);
        $unknown = $instanceDirectory . DIRECTORY_SEPARATOR . 'unrelated.tmp-'
            . \str_repeat('d', 24);
        $residues = [
            $target . '.tmp.12345.deadbeef',
            $target . '.tmp-' . \str_repeat('a', 24),
            $target . '.wls-backup-' . \str_repeat('b', 16),
        ];
        foreach ([...$residues, $unknown] as $path) {
            self::assertNotFalse(\file_put_contents($path, 'crash-evidence'));
            $this->cleanupFiles[] = $path;
        }
        $this->trackStoreFiles($instanceDirectory, $target);

        self::assertSame($target, $store->save('ai-test-policy', $bundle));

        foreach ($residues as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertFileExists($unknown, 'Unrelated namespaces must not be collected.');
        self::assertSame($bundle->digest, $store->load('ai-test-policy', $bundle->digest)->digest);
    }

    public function testValidCommittedStateCollectsCrashResidueBeforeTheNextActivation(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-state-recovery');
        $store = new RuntimePolicyStore($base, 0.2);
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $target = $store->save('ai-test-policy', $bundle);
        $store->stage('ai-test-policy', $bundle);
        $state = $instanceDirectory . DIRECTORY_SEPARATOR . 'state.php';
        $residue = $state . '.tmp-' . \str_repeat('a', 24);
        self::assertNotFalse(\file_put_contents($residue, 'interrupted-state'));
        $this->cleanupFiles[] = $residue;
        $this->trackStoreFiles($instanceDirectory, $target);

        $active = $store->activate('ai-test-policy', $bundle->digest);

        self::assertFileDoesNotExist($residue);
        self::assertSame($bundle->digest, $active['active_digest']);
        self::assertSame('', $active['staged_digest']);
    }

    public function testMissingCommittedBundlePreservesItsCrashResidue(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-publication-missing');
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $target = $instanceDirectory . DIRECTORY_SEPARATOR . $bundle->digest . '.php';
        $residue = $target . '.tmp-' . \str_repeat('a', 24);
        self::assertNotFalse(\file_put_contents($residue, $this->bundlePayload($bundle)));
        $this->cleanupFiles[] = $residue;
        $this->trackStoreFiles($instanceDirectory, $target);

        try {
            (new RuntimePolicyStore($base, 0.2))->save('ai-test-policy', $bundle);
            self::fail('A new bundle was layered over unresolved first-publication evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('missing', \strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($target);
        self::assertFileExists($residue);
    }

    public function testCorruptCommittedBundlePreservesEveryCrashResidue(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-publication-corrupt');
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $target = $instanceDirectory . DIRECTORY_SEPARATOR . $bundle->digest . '.php';
        $residues = [
            $target . '.tmp.9.deadbeef',
            $target . '.wls-backup-' . \str_repeat('b', 16),
        ];
        self::assertNotFalse(\file_put_contents($target, "<?php return 'corrupt';\n"));
        foreach ($residues as $path) {
            self::assertNotFalse(\file_put_contents($path, $this->bundlePayload($bundle)));
            $this->cleanupFiles[] = $path;
        }
        $this->trackStoreFiles($instanceDirectory, $target);

        $this->expectException(\RuntimeException::class);
        try {
            (new RuntimePolicyStore($base, 0.2))->save('ai-test-policy', $bundle);
        } finally {
            foreach ($residues as $path) {
                self::assertFileExists($path);
            }
        }
    }

    public function testRecoveryRejectsCaseAliasesBeforeDeletingCanonicalEvidence(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-publication-alias');
        $store = new RuntimePolicyStore($base, 0.2);
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $target = $store->save('ai-test-policy', $bundle);
        $canonical = $target . '.tmp.7.deadbeef';
        $alias = $target . '.TMP-' . \str_repeat('a', 24);
        foreach ([$canonical, $alias] as $path) {
            self::assertNotFalse(\file_put_contents($path, 'evidence'));
            $this->cleanupFiles[] = $path;
        }
        $this->trackStoreFiles($instanceDirectory, $target);

        $exception = null;
        try {
            $store->save('ai-test-policy', $bundle);
        } catch (\RuntimeException $throwable) {
            $exception = $throwable;
        }
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertStringContainsString('case', \strtolower($exception->getMessage()));
        self::assertFileExists($canonical);
        self::assertFileExists($alias);
    }

    public function testRecoveryRejectsLinkedEvidenceBeforeDeletingCanonicalEvidence(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link creation is not available on this runtime.');
        }
        [$base, $instanceDirectory] = $this->createStore('wls-policy-publication-link');
        $store = new RuntimePolicyStore($base, 0.2);
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $target = $store->save('ai-test-policy', $bundle);
        $canonical = $target . '.tmp.7.deadbeef';
        $foreign = $instanceDirectory . DIRECTORY_SEPARATOR . 'foreign-evidence';
        $linked = $target . '.tmp-' . \str_repeat('a', 24);
        self::assertNotFalse(\file_put_contents($canonical, 'canonical'));
        self::assertNotFalse(\file_put_contents($foreign, 'foreign'));
        self::assertTrue(\symlink($foreign, $linked));
        foreach ([$canonical, $foreign, $linked] as $path) {
            $this->cleanupFiles[] = $path;
        }
        $this->trackStoreFiles($instanceDirectory, $target);

        $this->expectException(\RuntimeException::class);
        try {
            $store->save('ai-test-policy', $bundle);
        } finally {
            self::assertFileExists($canonical);
            self::assertSame('foreign', (string)\file_get_contents($foreign));
        }
    }

    public function testRecoveryQuotaFailsBeforeDeletingAnyEvidence(): void
    {
        [$base, $instanceDirectory] = $this->createStore('wls-policy-publication-quota');
        $store = new RuntimePolicyStore($base, 0.2);
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $target = $store->save('ai-test-policy', $bundle);
        $residues = [];
        for ($index = 0; $index < 9; ++$index) {
            $path = $target . '.tmp-' . \str_pad(\dechex($index), 24, '0', STR_PAD_LEFT);
            self::assertNotFalse(\file_put_contents($path, 'evidence'));
            $this->cleanupFiles[] = $path;
            $residues[] = $path;
        }
        $this->trackStoreFiles($instanceDirectory, $target);

        $this->expectException(\RuntimeException::class);
        try {
            $store->save('ai-test-policy', $bundle);
        } finally {
            foreach ($residues as $path) {
                self::assertFileExists($path);
            }
        }
    }

    public function testEveryPolicyArtifactRejectsHardLinksBeforeChmod(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link identity validation is POSIX-specific.');
        }
        [$base, $instanceDirectory] = $this->createStore('wls-policy-artifact-hardlink');
        $bundle = RuntimePolicyBundle::fromDescriptors([]);
        $foreign = $instanceDirectory . DIRECTORY_SEPARATOR . 'foreign-policy.php';
        $target = $instanceDirectory . DIRECTORY_SEPARATOR . $bundle->digest . '.php';
        self::assertNotFalse(\file_put_contents($foreign, $this->bundlePayload($bundle)));
        self::assertTrue(\chmod($foreign, 0640));
        $mode = ((int)\fileperms($foreign)) & 0777;
        self::assertTrue(\link($foreign, $target));
        foreach ([$foreign, $target, $instanceDirectory . DIRECTORY_SEPARATOR . '.lock'] as $path) {
            $this->cleanupFiles[] = $path;
        }

        $this->expectException(\RuntimeException::class);
        try {
            (new RuntimePolicyStore($base, 0.2))->save('ai-test-policy', $bundle);
        } finally {
            self::assertSame($mode, ((int)\fileperms($foreign)) & 0777);
        }
    }

    public function testPolicyPublicationUsesTheCrossPlatformAtomicPrimitiveWithoutPreUnlink(): void
    {
        $source = $this->storeSource();

        self::assertStringContainsString('GatewayProjectStateFilesystem::atomicWrite(', $source);
        self::assertStringNotContainsString(". '.tmp.' . \\getmypid()", $source);
        self::assertStringNotContainsString('\\file_put_contents($temporary', $source);
        self::assertStringNotContainsString('@\\unlink($target)', $source);
        self::assertStringNotContainsString("\\PHP_OS_FAMILY === 'Windows' && \\is_file(\$target)", $source);
        self::assertStringContainsString('wls-backup-', $source);
        self::assertStringContainsString('tmp-', $source);
        self::assertStringContainsString('tmp\\.', $source);
    }

    public function testLockBudgetRejectsUnboundedValues(): void
    {
        foreach ([0.0, -1.0, 301.0, INF] as $invalid) {
            try {
                new RuntimePolicyStore(null, $invalid);
                self::fail('An invalid runtime policy lock budget was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('(0, 300]', $exception->getMessage());
            }
        }
    }

    public function testStoreDelegatesLockIdentityAndDeadlineChecksToTheVerifiedLock(): void
    {
        $source = $this->storeSource();
        self::assertStringContainsString('VerifiedPersistentFileLock::acquire(', $source);
        self::assertStringNotContainsString('\\flock($lock, \\LOCK_EX)', $source);
    }

    /** @return array{string,string} */
    private function createStore(string $prefix): array
    {
        $base = (string)\realpath(\sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . $prefix . '-' . \bin2hex(\random_bytes(8));
        $instanceDirectory = $base . DIRECTORY_SEPARATOR . 'ai-test-policy';
        self::assertTrue(\mkdir($base, 0700));
        self::assertTrue(\mkdir($instanceDirectory, 0700));
        $this->cleanupDirectories[] = $base;
        $this->cleanupDirectories[] = $instanceDirectory;

        return [$base, $instanceDirectory];
    }

    private function trackStoreFiles(string $instanceDirectory, string $target): void
    {
        $this->cleanupFiles[] = $instanceDirectory . DIRECTORY_SEPARATOR . '.lock';
        $this->cleanupFiles[] = $instanceDirectory . DIRECTORY_SEPARATOR . 'state.php';
        $this->cleanupFiles[] = $target;
    }

    private function bundlePayload(RuntimePolicyBundle $bundle): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . \var_export($bundle->toArray(), true) . ";\n";
    }

    private function storeSource(): string
    {
        $path = (new \ReflectionClass(RuntimePolicyStore::class))->getFileName();
        self::assertIsString($path);
        $source = \file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    private function projectRoot(): string
    {
        return \rtrim(\dirname(__DIR__, 8), '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * @return array{timed_out:bool,elapsed:float,exit_code:int,stdout:string,stderr:string,result:array<string,mixed>}
     */
    private function runPhp(string $script, float $timeoutSeconds): array
    {
        $process = \proc_open(
            [PHP_BINARY, '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectRoot(),
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $lastExitCode = -1;
        $startedAt = \hrtime(true) / 1_000_000_000;
        $deadline = $startedAt + $timeoutSeconds;
        do {
            $stdout .= (string)\stream_get_contents($pipes[1]);
            $stderr .= (string)\stream_get_contents($pipes[2]);
            $status = \proc_get_status($process);
            $lastExitCode = (int)$status['exitcode'];
            if (!$status['running']) {
                break;
            }
            if (\hrtime(true) / 1_000_000_000 >= $deadline) {
                $timedOut = true;
                \proc_terminate($process, 9);
                break;
            }
            \usleep(10_000);
        } while (true);
        $stdout .= (string)\stream_get_contents($pipes[1]);
        $stderr .= (string)\stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $closedExit = \proc_close($process);
        $elapsed = \hrtime(true) / 1_000_000_000 - $startedAt;
        $exitCode = $lastExitCode >= 0 ? $lastExitCode : $closedExit;
        $result = [];
        if (\preg_match('/WLS_POLICY_RESULT=([A-Za-z0-9+\/=]+)/', $stdout, $match) === 1) {
            $decoded = \json_decode((string)\base64_decode($match[1], true), true);
            if (\is_array($decoded)) {
                $result = $decoded;
            }
        }

        return [
            'timed_out' => $timedOut,
            'elapsed' => $elapsed,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'result' => $result,
        ];
    }

    /** @param array{stdout:string,stderr:string} $execution */
    private function executionFailure(array $execution): string
    {
        $output = \trim($execution['stderr'] . "\n" . $execution['stdout']);
        return $output !== ''
            ? $output
            : (string)\json_encode($execution, JSON_UNESCAPED_SLASHES);
    }
}
