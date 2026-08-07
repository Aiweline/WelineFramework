<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Server\Service\HostsFileManager;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;

final class HostsFileManagerTransactionTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $lockPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-hosts-transaction-test-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
        foreach (\array_unique($this->lockPaths) as $lockPath) {
            @\unlink($lockPath);
        }
        parent::tearDown();
    }

    public function testConcurrentManagedMutationsRebaseAfterTakingOneIdentityScopedLock(): void
    {
        self::assertTrue(\method_exists(HostsFileManager::class, 'mutateHostsFile'));
        self::assertTrue(\method_exists(HostsFileManager::class, 'hostMutationLockPath'));
        $hostsPath = $this->directory . DIRECTORY_SEPARATOR . 'hosts';
        $initial = "127.0.0.1 localhost\n"
            . "# Weline WLS Auto-Config Start\n"
            . "127.0.0.1 remove.weline.test\n"
            . "192.0.2.10 rewrite.weline.test\n"
            . "# Weline WLS Auto-Config End\n";
        self::assertSame(\strlen($initial), \file_put_contents($hostsPath, $initial));

        $lockPath = $this->lockPath($hostsPath);
        $parentLock = VerifiedPersistentFileLock::acquire(
            $lockPath,
            1.0,
            static fn(): array => ['test' => 'parent-barrier'],
        );
        self::assertIsResource($parentLock);

        $autoload = (string)\realpath(BP . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
        self::assertNotSame('', $autoload);
        $scriptPath = $this->directory . DIRECTORY_SEPARATOR . 'mutate.php';
        $script = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$method = new ReflectionMethod(Weline\Server\Service\HostsFileManager::class, 'mutateHostsFile');
$method->setAccessible(true);
$result = $method->invoke(null, $argv[2], $argv[3], $argv[4], $argv[5]);
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
exit(($result['success'] ?? false) === true ? 0 : 1);
PHP;
        self::assertSame(\strlen($script), \file_put_contents($scriptPath, $script));

        $children = [];
        try {
            $children[] = $this->startMutationChild(
                $scriptPath,
                $autoload,
                $hostsPath,
                'upsert',
                'added.weline.test',
                '127.0.0.1',
            );
            $children[] = $this->startMutationChild(
                $scriptPath,
                $autoload,
                $hostsPath,
                'upsert',
                'rewrite.weline.test',
                '127.0.0.1',
            );
            $children[] = $this->startMutationChild(
                $scriptPath,
                $autoload,
                $hostsPath,
                'remove',
                'remove.weline.test',
                '127.0.0.1',
            );

            \usleep(200_000);
            self::assertSame($initial, \file_get_contents($hostsPath));
            foreach ($children as $child) {
                $status = \proc_get_status($child['process']);
                self::assertIsArray($status);
                self::assertTrue((bool)($status['running'] ?? false));
            }
        } finally {
            @\flock($parentLock, LOCK_UN);
            @\fclose($parentLock);
        }

        foreach ($children as $child) {
            $result = $this->finishMutationChild($child);
            self::assertSame(0, $result['code'], $result['stderr'] . $result['stdout']);
        }
        $published = (string)\file_get_contents($hostsPath);
        self::assertStringContainsString("127.0.0.1 localhost\n", $published);
        self::assertStringContainsString("127.0.0.1 added.weline.test\n", $published);
        self::assertStringContainsString("127.0.0.1 rewrite.weline.test\n", $published);
        self::assertStringNotContainsString('192.0.2.10 rewrite.weline.test', $published);
        self::assertStringNotContainsString('remove.weline.test', $published);
    }

    public function testExternalExactEntrySatisfiesAddWithoutTakingOwnership(): void
    {
        $hostsPath = $this->writeHosts("127.0.0.1 external.weline.test # user owned\r\n");
        $before = (string)\file_get_contents($hostsPath);

        $result = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'external.weline.test',
            '127.0.0.1',
        );

        self::assertTrue($result['success'] ?? false);
        self::assertSame('external_satisfied', $result['status'] ?? null);
        self::assertSame($before, \file_get_contents($hostsPath));
        self::assertStringNotContainsString('Weline WLS Auto-Config', (string)\file_get_contents($hostsPath));
    }

    public function testExternalEntryWithAttachedCommentStillRetainsOwnership(): void
    {
        $hostsPath = $this->writeHosts("127.0.0.1 attached.weline.test#user-owned\n");
        $before = (string)\file_get_contents($hostsPath);

        $result = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'attached.weline.test',
            '127.0.0.1',
        );

        self::assertTrue($result['success'] ?? false);
        self::assertSame('external_satisfied', $result['status'] ?? null);
        self::assertSame($before, \file_get_contents($hostsPath));
    }

    public function testExternalConflictingEntryFailsWithoutChangingOneByte(): void
    {
        $hostsPath = $this->writeHosts("192.0.2.40 conflict.weline.test # user owned\n");
        $before = (string)\file_get_contents($hostsPath);

        $result = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'conflict.weline.test',
            '127.0.0.1',
        );

        self::assertFalse($result['success'] ?? true);
        self::assertSame('EXTERNAL_DOMAIN_CONFLICT', $result['error_code'] ?? null);
        self::assertSame($before, \file_get_contents($hostsPath));
    }

    public function testAmbiguousExternalDomainTokenFailsClosedWithoutChangingOneByte(): void
    {
        $hostsPath = $this->writeHosts("ambiguous.weline.test\n");
        $before = (string)\file_get_contents($hostsPath);

        $result = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'ambiguous.weline.test',
            '127.0.0.1',
        );

        self::assertFalse($result['success'] ?? true);
        self::assertSame('EXTERNAL_DOMAIN_AMBIGUOUS', $result['error_code'] ?? null);
        self::assertSame($before, \file_get_contents($hostsPath));
    }

    public function testRemoveNeverDeletesAnExternalEntry(): void
    {
        $hostsPath = $this->writeHosts("192.0.2.41 external.weline.test # user owned\n");
        $before = (string)\file_get_contents($hostsPath);

        $result = $this->invokeMutation(
            $hostsPath,
            'remove',
            'external.weline.test',
            '127.0.0.1',
        );

        self::assertTrue($result['success'] ?? false);
        self::assertSame('external_preserved', $result['status'] ?? null);
        self::assertSame($before, \file_get_contents($hostsPath));
    }

    public function testManagedAndExternalOwnershipForSameDomainFailsClosed(): void
    {
        $content = "127.0.0.1 mixed.weline.test # external\n"
            . "# Weline WLS Auto-Config Start\n"
            . "127.0.0.1 mixed.weline.test\n"
            . "# Weline WLS Auto-Config End\n";
        $hostsPath = $this->writeHosts($content);

        $result = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'mixed.weline.test',
            '127.0.0.1',
        );

        self::assertFalse($result['success'] ?? true);
        self::assertSame('MANAGED_EXTERNAL_DOMAIN_CONFLICT', $result['error_code'] ?? null);
        self::assertSame($content, \file_get_contents($hostsPath));
    }

    public function testMalformedDuplicateNestedAndUnpairedMarkersFailClosed(): void
    {
        $fixtures = [
            "# Weline WLS Auto-Config Start\n127.0.0.1 a.weline.test\n",
            "# Weline WLS Auto-Config End\n",
            "# Weline WLS Auto-Config Start\n# Weline WLS Auto-Config Start\n"
                . "# Weline WLS Auto-Config End\n# Weline WLS Auto-Config End\n",
            "# Weline WLS Auto-Config Start\n# Weline WLS Auto-Config End\n"
                . "# Weline WLS Auto-Config Start\n# Weline WLS Auto-Config End\n",
            "# Weline WLS Auto-Config Start\n# external syntax is forbidden here\n"
                . "# Weline WLS Auto-Config End\n",
            "# Weline WLS Auto-Config Start\n127.0.0.1 a.weline.test alias.weline.test\n"
                . "# Weline WLS Auto-Config End\n",
            "# Weline WLS Auto-Config Start\nnot-an-ip a.weline.test\n"
                . "# Weline WLS Auto-Config End\n",
        ];
        foreach ($fixtures as $index => $content) {
            $hostsPath = $this->directory . DIRECTORY_SEPARATOR . 'hosts-malformed-' . $index;
            self::assertSame(\strlen($content), \file_put_contents($hostsPath, $content));
            $result = $this->invokeMutation(
                $hostsPath,
                'upsert',
                'new.weline.test',
                '127.0.0.1',
            );
            self::assertFalse($result['success'] ?? true);
            self::assertSame('MANAGED_BLOCK_INVALID', $result['error_code'] ?? null);
            self::assertSame($content, \file_get_contents($hostsPath));
        }
    }

    public function testDuplicateManagedDomainFailsClosed(): void
    {
        $content = "# Weline WLS Auto-Config Start\n"
            . "127.0.0.1 duplicate.weline.test\n"
            . "127.0.0.1 duplicate.weline.test\n"
            . "# Weline WLS Auto-Config End\n";
        $hostsPath = $this->writeHosts($content);

        $result = $this->invokeMutation(
            $hostsPath,
            'remove',
            'duplicate.weline.test',
            '127.0.0.1',
        );

        self::assertFalse($result['success'] ?? true);
        self::assertSame('MANAGED_DOMAIN_DUPLICATE', $result['error_code'] ?? null);
        self::assertSame($content, \file_get_contents($hostsPath));
    }

    public function testManagedRewritePreservesEveryExternalByteAndCrLf(): void
    {
        $content = "# external header\r\n"
            . "127.0.0.1\tlocalhost localhost.localdomain # keep spacing\r\n"
            . "# Weline WLS Auto-Config Start\r\n"
            . "192.0.2.20 target.weline.test\r\n"
            . "# Weline WLS Auto-Config End\r\n";
        $hostsPath = $this->writeHosts($content);
        self::assertTrue(\chmod($hostsPath, 0640));

        $result = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'target.weline.test',
            '127.0.0.1',
        );

        self::assertTrue($result['success'] ?? false, (string)($result['message'] ?? ''));
        self::assertSame('repaired', $result['status'] ?? null);
        $published = (string)\file_get_contents($hostsPath);
        self::assertStringStartsWith(
            "# external header\r\n127.0.0.1\tlocalhost localhost.localdomain # keep spacing\r\n",
            $published,
        );
        self::assertStringContainsString("127.0.0.1 target.weline.test\r\n", $published);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $published);
    }

    public function testPublicationAtomicallyReplacesTheTargetAndPreservesMetadata(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Direct Windows hosts replacement is intentionally unsupported.');
        }
        $original = "127.0.0.1 localhost\n";
        $hostsPath = $this->writeHosts($original);
        self::assertTrue(\chmod($hostsPath, 0640));
        $before = \lstat($hostsPath);
        self::assertIsArray($before);
        $oldHandle = \fopen($hostsPath, 'rb');
        self::assertIsResource($oldHandle);

        try {
            $result = $this->invokeMutation(
                $hostsPath,
                'upsert',
                'atomic.weline.test',
                '127.0.0.1',
            );
            self::assertTrue($result['success'] ?? false, (string)($result['message'] ?? ''));
            $after = \lstat($hostsPath);
            self::assertIsArray($after);
            self::assertSame((int)$before['mode'], (int)$after['mode']);
            self::assertSame((int)$before['uid'], (int)$after['uid']);
            self::assertSame((int)$before['gid'], (int)$after['gid']);
            self::assertNotSame((int)$before['ino'], (int)$after['ino']);
            self::assertSame(0, \fseek($oldHandle, 0, SEEK_SET));
            self::assertSame($original, \stream_get_contents($oldHandle));
            self::assertStringContainsString(
                '127.0.0.1 atomic.weline.test',
                (string)\file_get_contents($hostsPath),
            );
            self::assertSame([], \glob($hostsPath . '.wls-hosts-txn-*') ?: []);
        } finally {
            \fclose($oldHandle);
        }
    }

    public function testPublicationPreservesDarwinAccessControlList(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Darwin ACL preservation is platform-specific.');
        }
        $hostsPath = $this->writeHosts("127.0.0.1 localhost\n");
        $acl = $this->runLocalCommand([
            '/bin/chmod',
            '+a',
            'everyone allow read',
            $hostsPath,
        ]);
        if ($acl['code'] !== 0) {
            self::markTestSkipped('The local filesystem does not support a test ACL.');
        }

        try {
            $before = $this->runLocalCommand(['/bin/ls', '-lde', $hostsPath]);
            self::assertSame(0, $before['code'], $before['stderr']);
            self::assertStringContainsString('everyone allow read', $before['stdout']);

            $result = $this->invokeMutation(
                $hostsPath,
                'upsert',
                'acl.weline.test',
                '127.0.0.1',
            );
            self::assertTrue($result['success'] ?? false, (string)($result['message'] ?? ''));

            $after = $this->runLocalCommand(['/bin/ls', '-lde', $hostsPath]);
            self::assertSame(0, $after['code'], $after['stderr']);
            self::assertStringContainsString('everyone allow read', $after['stdout']);
        } finally {
            $this->runLocalCommand(['/bin/chmod', '-N', $hostsPath]);
        }
    }

    public function testPublicationFailsWithoutTruncatingWhenSameDirectoryStagingIsUnavailable(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || (\function_exists('posix_geteuid') && \posix_geteuid() === 0)
        ) {
            self::markTestSkipped('Requires a non-root POSIX account.');
        }
        $nested = $this->directory . DIRECTORY_SEPARATOR . 'read-only-parent';
        self::assertTrue(\mkdir($nested, 0700));
        $hostsPath = $nested . DIRECTORY_SEPARATOR . 'hosts';
        $original = "127.0.0.1 localhost\n";
        self::assertSame(\strlen($original), \file_put_contents($hostsPath, $original));
        self::assertTrue(\chmod($hostsPath, 0600));
        self::assertTrue(\chmod($nested, 0500));
        try {
            $result = $this->invokeMutation(
                $hostsPath,
                'upsert',
                'no-stage.weline.test',
                '127.0.0.1',
            );
            self::assertFalse($result['success'] ?? true);
            self::assertSame('ATOMIC_REPLACE_UNAVAILABLE', $result['error_code'] ?? null);
            self::assertSame($original, \file_get_contents($hostsPath));
        } finally {
            @\chmod($nested, 0700);
        }
    }

    public function testMutationRejectsLinkedTargetsWithoutChangingPeers(): void
    {
        $peer = $this->directory . DIRECTORY_SEPARATOR . 'peer';
        $original = "127.0.0.1 localhost\n";
        self::assertSame(\strlen($original), \file_put_contents($peer, $original));
        $hardLink = $this->directory . DIRECTORY_SEPARATOR . 'hosts-hardlink';
        self::assertTrue(\link($peer, $hardLink));
        $result = $this->invokeMutation(
            $hardLink,
            'upsert',
            'hardlink.weline.test',
            '127.0.0.1',
        );
        self::assertFalse($result['success'] ?? true);
        self::assertSame($original, \file_get_contents($peer));

        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\unlink($hardLink));
            $symbolic = $this->directory . DIRECTORY_SEPARATOR . 'hosts-symlink';
            self::assertTrue(\symlink($peer, $symbolic));
            $result = $this->invokeMutation(
                $symbolic,
                'upsert',
                'symlink.weline.test',
                '127.0.0.1',
            );
            self::assertFalse($result['success'] ?? true);
            self::assertSame($original, \file_get_contents($peer));
        }
    }

    public function testStableLockInodeSurvivesRejectedAndSuccessfulMutations(): void
    {
        $hostsPath = $this->writeHosts("127.0.0.1 localhost\n");
        $first = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'one.weline.test',
            '127.0.0.1',
        );
        self::assertTrue($first['success'] ?? false);
        $lockPath = $this->lockPath($hostsPath);
        $before = \lstat($lockPath);
        self::assertIsArray($before);

        $oversized = \str_repeat('#', 1_048_577);
        self::assertSame(\strlen($oversized), \file_put_contents($hostsPath, $oversized));
        $rejected = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'two.weline.test',
            '127.0.0.1',
        );
        self::assertFalse($rejected['success'] ?? true);
        $afterFailure = \lstat($lockPath);
        self::assertIsArray($afterFailure);
        self::assertSame((int)$before['ino'], (int)$afterFailure['ino']);

        self::assertSame(20, \file_put_contents($hostsPath, "127.0.0.1 localhost\n"));
        $second = $this->invokeMutation(
            $hostsPath,
            'upsert',
            'two.weline.test',
            '127.0.0.1',
        );
        self::assertTrue($second['success'] ?? false);
        $afterSuccess = \lstat($lockPath);
        self::assertIsArray($afterSuccess);
        self::assertSame((int)$before['ino'], (int)$afterSuccess['ino']);
    }

    public function testDirectWriterIdentityPolicyRejectsWindowsRootAndForeignOwners(): void
    {
        self::assertTrue(\method_exists(HostsFileManager::class, 'directWriterIdentityAllowed'));
        $method = new ReflectionMethod(HostsFileManager::class, 'directWriterIdentityAllowed');
        $method->setAccessible(true);

        self::assertFalse($method->invoke(null, ['uid' => 501], 'Windows', 501));
        self::assertFalse($method->invoke(null, ['uid' => 0], 'Linux', 0));
        self::assertFalse($method->invoke(null, ['uid' => 0], 'Linux', 501));
        self::assertTrue($method->invoke(null, ['uid' => 501], 'Linux', 501));
    }

    public function testAutomaticElevationAndProjectCodeAdminSurfaceIsAbsent(): void
    {
        $class = new ReflectionClass(HostsFileManager::class);
        foreach ([
            'tryAddDomainWithElevation',
            'tryAddDomainWithMacOsAuthorization',
            'tryAddDomainWithMacOsAuthOpen',
            'tryAddDomainWithSudo',
            'tryAddDomainWithWindowsElevation',
            'applyElevatedMutation',
            'createPrivilegedMutationHelper',
            'runBoundedCommand',
            'getAdminCommand',
            'getAdminCommandForOs',
        ] as $method) {
            self::assertFalse($class->hasMethod($method), $method . ' must not exist.');
        }
        $path = $class->getFileName();
        self::assertIsString($path);
        $source = (string)\file_get_contents($path);
        self::assertStringNotContainsString('GatewayBoundedCommandRunner', $source);
        self::assertStringNotContainsString('Start-Process', $source);
        self::assertStringNotContainsString('authopen', $source);
        self::assertStringNotContainsString('osascript', $source);
        self::assertStringNotContainsString("'sudo'", $source);
        self::assertStringNotContainsString('vendor/autoload.php', $source);
        self::assertStringNotContainsString('PHP_BINARY', $source);
    }

    /** @return array<string,mixed> */
    private function invokeMutation(
        string $path,
        string $operation,
        string $domain,
        string $ip,
    ): array {
        $this->lockPaths[] = $this->lockPath($path);
        $method = new ReflectionMethod(HostsFileManager::class, 'mutateHostsFile');
        $method->setAccessible(true);
        $result = $method->invoke(null, $path, $operation, $domain, $ip);
        self::assertIsArray($result);
        return $result;
    }

    private function lockPath(string $hostsPath): string
    {
        $method = new ReflectionMethod(HostsFileManager::class, 'hostMutationLockPath');
        $method->setAccessible(true);
        $path = $method->invoke(null, $hostsPath);
        self::assertIsString($path);
        self::assertNotSame('', $path);
        $this->lockPaths[] = $path;
        return $path;
    }

    private function writeHosts(string $content): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'hosts-' . \bin2hex(\random_bytes(5));
        self::assertSame(\strlen($content), \file_put_contents($path, $content));
        return $path;
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startMutationChild(
        string $scriptPath,
        string $autoload,
        string $hostsPath,
        string $operation,
        string $domain,
        string $ip,
    ): array {
        $alternateTemporaryDirectory = $this->directory . DIRECTORY_SEPARATOR . 'child-tmp';
        if (!\is_dir($alternateTemporaryDirectory)) {
            self::assertTrue(\mkdir($alternateTemporaryDirectory, 0700, true));
        }
        $environment = \getenv();
        self::assertIsArray($environment);
        $environment['TMPDIR'] = $alternateTemporaryDirectory;
        $environment['TMP'] = $alternateTemporaryDirectory;
        $environment['TEMP'] = $alternateTemporaryDirectory;
        $pipes = [];
        $process = \proc_open(
            [PHP_BINARY, $scriptPath, $autoload, $hostsPath, $operation, $domain, $ip],
            [
                0 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->directory,
            $environment,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param array{process:resource,pipes:array<int,resource>} $child
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function finishMutationChild(array $child): array
    {
        $deadline = \hrtime(true) + 5_000_000_000;
        do {
            $status = \proc_get_status($child['process']);
            self::assertIsArray($status);
            if (!(bool)($status['running'] ?? false)) {
                break;
            }
            \usleep(20_000);
        } while (\hrtime(true) < $deadline);

        $status = \proc_get_status($child['process']);
        self::assertIsArray($status);
        if ((bool)($status['running'] ?? false)) {
            @\proc_terminate($child['process'], 9);
            self::fail('Hosts mutation child exceeded its test deadline.');
        }
        $stdout = \stream_get_contents($child['pipes'][1]);
        $stderr = \stream_get_contents($child['pipes'][2]);
        @\fclose($child['pipes'][1]);
        @\fclose($child['pipes'][2]);
        $observedCode = (int)($status['exitcode'] ?? -1);
        $closedCode = \proc_close($child['process']);
        return [
            'code' => $observedCode >= 0 ? $observedCode : $closedCode,
            'stdout' => \is_string($stdout) ? $stdout : '',
            'stderr' => \is_string($stderr) ? $stderr : '',
        ];
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runLocalCommand(array $command): array
    {
        $pipes = [];
        $process = \proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->directory,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $deadline = \hrtime(true) + 2_000_000_000;
        do {
            $status = \proc_get_status($process);
            self::assertIsArray($status);
            if (!(bool)($status['running'] ?? false)) {
                break;
            }
            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        $status = \proc_get_status($process);
        self::assertIsArray($status);
        if ((bool)($status['running'] ?? false)) {
            @\proc_terminate($process, 9);
            self::fail('Local ACL test command exceeded its deadline.');
        }
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        $observedCode = (int)($status['exitcode'] ?? -1);
        $closedCode = \proc_close($process);

        return [
            'code' => $observedCode >= 0 ? $observedCode : $closedCode,
            'stdout' => \is_string($stdout) ? $stdout : '',
            'stderr' => \is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        $entries = \scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($path);
    }
}
