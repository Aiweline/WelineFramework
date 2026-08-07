<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\Console\Server\Gateway\Promote;

final class PromoteOwnershipTest extends TestCase
{
    private string $root = '';
    /** @var list<string> */
    private array $instanceConfigFiles = [];

    protected function setUp(): void
    {
        $temporaryRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR
            . 'wls-promote-ownership-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root . DIRECTORY_SEPARATOR . 'conf', 0700, true));
    }

    protected function tearDown(): void
    {
        foreach ($this->instanceConfigFiles as $file) {
            @\unlink($file);
            @\unlink($file . '.lock');
        }
        if ($this->root === '' || !\is_dir($this->root) || \is_link($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? @\rmdir($item->getPathname())
                : @\unlink($item->getPathname());
        }
        @\rmdir($this->root);
    }

    public function testPromotionJournalsOnlyOwnedFieldsAndRollbackPreservesConcurrentConfig(): void
    {
        if (!\defined('BP')) {
            \define(
                'BP',
                \dirname(__DIR__, 8) . DIRECTORY_SEPARATOR,
            );
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        $instance = 'unit-promote-' . \bin2hex(\random_bytes(6));
        $directory = Env::VAR_DIR . 'server' . DS . 'config' . DS;
        if (!\is_dir($directory)) {
            self::assertTrue(\mkdir($directory, 0755, true));
        }
        $file = $directory . $instance . '.json';
        $this->instanceConfigFiles[] = $file;
        $original = \json_encode([
            'host' => '127.0.0.1',
            'port' => 28080,
            'edge_mode' => 'legacy',
            'edge_adapter' => 'nginx',
            'ssl_enabled' => false,
            'database_password' => 'must-not-enter-host-journal',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        self::assertNotFalse(\file_put_contents($file, $original));
        self::assertTrue(\chmod($file, 0640));

        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $persist = new \ReflectionMethod(Promote::class, 'persistPromotedInstanceEdgeMode');
        $restore = new \ReflectionMethod(Promote::class, 'restoreSavedInstanceEdgeMode');
        $journaledSnapshot = null;
        $snapshot = $persist->invoke(
            $command,
            $instance,
            static function (array $captured) use (&$journaledSnapshot): void {
                $journaledSnapshot = $captured;
            },
        );
        $promoted = \json_decode((string)\file_get_contents($file), true);

        self::assertIsArray($snapshot);
        self::assertSame($snapshot, $journaledSnapshot);
        self::assertArrayNotHasKey('content', $snapshot);
        self::assertStringNotContainsString(
            'must-not-enter-host-journal',
            \json_encode($snapshot, JSON_THROW_ON_ERROR),
        );
        self::assertSame('auto', $promoted['edge_mode'] ?? null);
        self::assertSame('nginx', $promoted['edge_adapter'] ?? null);
        self::assertFalse($promoted['ssl_enabled'] ?? true);
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0640, (int)\fileperms($file) & 0777);
        }

        $promoted['database_password'] = 'rotated-concurrently';
        $promoted['concurrent_setting'] = 'preserved';
        self::assertNotFalse(\file_put_contents(
            $file,
            \json_encode(
                $promoted,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        ));

        $restore->invoke($command, $instance, $snapshot);
        $restored = \json_decode((string)\file_get_contents($file), true);
        self::assertSame('legacy', $restored['edge_mode'] ?? null);
        self::assertSame('nginx', $restored['edge_adapter'] ?? null);
        self::assertFalse($restored['ssl_enabled'] ?? true);
        self::assertArrayNotHasKey('saved_at', $restored);
        self::assertSame('rotated-concurrently', $restored['database_password'] ?? null);
        self::assertSame('preserved', $restored['concurrent_setting'] ?? null);
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0640, (int)\fileperms($file) & 0777);
        }
    }

    public function testRollbackOwnershipRestorationRejectsLinksBeforeChangingTree(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX ownership restoration is not used on Windows.');
        }
        $config = $this->root . DIRECTORY_SEPARATOR . 'conf'
            . DIRECTORY_SEPARATOR . 'nginx.conf';
        self::assertNotFalse(\file_put_contents($config, "events {}\n"));
        $unsafeLink = $this->root . DIRECTORY_SEPARATOR . 'unsafe-link';
        self::assertTrue(\symlink($config, $unsafeLink));
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $restore = new \ReflectionMethod(Promote::class, 'restoreProjectRuntimeOwnership');

        $rejection = null;
        try {
            $restore->invoke(
                $command,
                $this->root,
                (int)\posix_getuid(),
                (int)\posix_getgid(),
            );
        } catch (\RuntimeException $exception) {
            $rejection = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $rejection);
        self::assertStringContainsString($unsafeLink, $rejection->getMessage());
        self::assertSame((int)\posix_getuid(), (int)\stat($config)['uid']);
        self::assertSame((int)\posix_getgid(), (int)\stat($config)['gid']);
    }

    public function testPromotionOwnershipPreflightRejectsUnsafeDescendantsBeforeCutover(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX ownership restoration is not used on Windows.');
        }
        $config = $this->root . DIRECTORY_SEPARATOR . 'conf'
            . DIRECTORY_SEPARATOR . 'nginx.conf';
        self::assertNotFalse(\file_put_contents($config, "events {}\n"));
        $unsafeLink = $this->root . DIRECTORY_SEPARATOR . 'unsafe-link';
        self::assertTrue(\symlink($config, $unsafeLink));
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $capture = new \ReflectionMethod(Promote::class, 'projectRuntimeOwnership');

        $rejection = null;
        try {
            $capture->invoke($command, $this->root);
        } catch (\RuntimeException $exception) {
            $rejection = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $rejection);
        self::assertStringContainsString($unsafeLink, $rejection->getMessage());
    }

    public function testPromotionOwnershipPreflightRejectsLinkedRuntimeAncestor(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX ownership restoration is not used on Windows.');
        }
        $target = $this->root . DIRECTORY_SEPARATOR . 'target';
        self::assertTrue(\mkdir($target . DIRECTORY_SEPARATOR . 'runtime', 0700, true));
        $linkedParent = $this->root . DIRECTORY_SEPARATOR . 'linked-parent';
        self::assertTrue(\symlink($target, $linkedParent));
        $linkedRuntime = $linkedParent . DIRECTORY_SEPARATOR . 'runtime';
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $capture = new \ReflectionMethod(Promote::class, 'projectRuntimeOwnership');

        $rejection = null;
        try {
            $capture->invoke($command, $linkedRuntime);
        } catch (\RuntimeException $exception) {
            $rejection = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $rejection);
        self::assertStringContainsString($linkedRuntime, $rejection->getMessage());
    }

    public function testRollbackOwnershipRejectsAReplacedRuntimeRootIdentity(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX ownership restoration is not used on Windows.');
        }
        $runtime = $this->root . DIRECTORY_SEPARATOR . 'runtime';
        self::assertTrue(\mkdir($runtime, 0700));
        self::assertNotFalse(\file_put_contents(
            $runtime . DIRECTORY_SEPARATOR . 'original.conf',
            "events {}\n",
        ));
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $capture = new \ReflectionMethod(Promote::class, 'projectRuntimeOwnership');
        $preCutoverFence = new \ReflectionMethod(
            Promote::class,
            'assertProjectRuntimeOwnershipUnchanged',
        );
        $restore = new \ReflectionMethod(Promote::class, 'restoreProjectRuntimeOwnership');
        $ownership = $capture->invoke($command, $runtime);
        self::assertIsArray($ownership);

        self::assertTrue(\rename($runtime, $runtime . '-original'));
        self::assertTrue(\mkdir($runtime, 0700));
        $replacement = $runtime . DIRECTORY_SEPARATOR . 'replacement.conf';
        self::assertNotFalse(\file_put_contents($replacement, "events {}\n"));

        $preCutoverRejection = null;
        try {
            $preCutoverFence->invoke($command, $runtime, $ownership);
        } catch (\RuntimeException $exception) {
            $preCutoverRejection = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $preCutoverRejection);
        self::assertStringContainsString(
            'before public cutover',
            \strtolower($preCutoverRejection->getMessage()),
        );

        $rejection = null;
        try {
            $restore->invoke(
                $command,
                $runtime,
                (int)($ownership['uid'] ?? -1),
                (int)($ownership['gid'] ?? -1),
                (string)($ownership['device'] ?? ''),
                (string)($ownership['inode'] ?? ''),
            );
        } catch (\RuntimeException $exception) {
            $rejection = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $rejection);
        self::assertStringContainsString(
            'identity',
            \strtolower($rejection->getMessage()),
        );
        self::assertFileExists($replacement);
    }

    public function testPromotionRequiresAnExactLegacyStopReleaseReceipt(): void
    {
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $released = new \ReflectionMethod(
            Promote::class,
            'legacyStopReleasedPublicOwnership',
        );

        self::assertFalse($released->invoke($command, [
            'ok' => true,
            'message' => 'managed nginx is owned by another WLS instance; left running',
        ]));
        self::assertFalse($released->invoke($command, [
            'ok' => true,
            'stopped' => false,
            'owner_matched' => false,
            'message' => 'managed nginx is owned by another WLS instance; left running',
        ]));
        self::assertFalse($released->invoke($command, [
            'ok' => true,
            'stopped' => true,
            'owner_matched' => false,
            'message' => 'managed nginx was already absent',
        ]));
        self::assertTrue($released->invoke($command, [
            'ok' => true,
            'stopped' => true,
            'owner_matched' => true,
            'message' => 'stopped',
        ]));
    }

    public function testRollbackRejectsAReplacementLegacyRuntimeOwner(): void
    {
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $fence = new \ReflectionMethod(
            Promote::class,
            'assertRollbackLegacyOwnerNotReplaced',
        );

        $fence->invoke($command, 'expected-owner', [
            'ok' => true,
            'running' => false,
            'runtime_owner_active' => false,
            'owner_instance' => '',
        ]);
        $fence->invoke($command, 'expected-owner', [
            'ok' => true,
            'running' => true,
            'runtime_owner_active' => true,
            'owner_instance' => 'expected-owner',
        ]);

        foreach ([
            [
                'ok' => true,
                'running' => true,
                'runtime_owner_active' => true,
                'owner_instance' => 'replacement-owner',
            ],
            [
                'ok' => true,
                'running' => true,
                'runtime_owner_active' => false,
                'owner_instance' => '',
            ],
            [
                'ok' => false,
                'running' => false,
                'runtime_owner_active' => false,
                'owner_instance' => '',
            ],
        ] as $snapshot) {
            $rejection = null;
            try {
                $fence->invoke($command, 'expected-owner', $snapshot);
            } catch (\RuntimeException $exception) {
                $rejection = $exception;
            }
            self::assertInstanceOf(\RuntimeException::class, $rejection);
            self::assertStringContainsString(
                'owner',
                \strtolower($rejection->getMessage()),
            );
        }
    }

    public function testRollbackPublicProbeSelectsOnlyAnExactSafeServerName(): void
    {
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $select = new \ReflectionMethod(Promote::class, 'legacyPublicProbeHost');

        self::assertSame('shop.example.test', $select->invoke($command, [
            '*.example.test',
            "bad.example.test\r\nX-Injected: true",
            'Shop.Example.Test.',
        ]));
        self::assertSame('', $select->invoke($command, [
            '_',
            '*.example.test',
            '[::1]',
        ]));
    }

    public function testRollbackPublicProbeFailsClosedBeforeNetworkOnInvalidPort(): void
    {
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $probe = new \ReflectionMethod(Promote::class, 'probeLegacyPublicResponses');
        $result = $probe->invoke($command, 0, ['shop.example.test']);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame(0, $result['port'] ?? null);
        self::assertSame([], $result['probes'] ?? null);
    }

    public function testPromotionJournalCollectsRetainedBackupOnlyAfterValidatingCurrentTarget(): void
    {
        $previousMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $previousHome = \getenv('WLS_GATEWAY_HOME');
        $gatewayHome = $this->root . DIRECTORY_SEPARATOR . 'gateway-home';
        self::assertTrue(\putenv('WLS_GATEWAY_TEST_MODE=1'));
        self::assertTrue(\putenv('WLS_GATEWAY_HOME=' . $gatewayHome));

        try {
            $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
            $write = new \ReflectionMethod(Promote::class, 'writePromotionJournal');
            $journal = $write->invoke($command, [
                'schema_version' => 1,
                'transaction_id' => \str_repeat('a', 32),
                'sequence' => 1,
                'phase' => 'PREPARED',
            ]);
            self::assertIsArray($journal);

            $journalFile = $gatewayHome . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'promotion-transaction'
                . DIRECTORY_SEPARATOR . 'journal.json';
            $backup = $journalFile . '.wls-backup-' . \str_repeat('b', 16);
            self::assertNotFalse(\file_put_contents($backup, "previous\n"));

            $cleanup = new \ReflectionMethod(
                Promote::class,
                'cleanupPromotionJournalRecoveryBackups',
            );
            $cleanup->invoke($command);

            self::assertFileDoesNotExist($backup);
            self::assertFileExists($journalFile);
        } finally {
            $previousMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $previousMode);
            $previousHome === false
                ? \putenv('WLS_GATEWAY_HOME')
                : \putenv('WLS_GATEWAY_HOME=' . $previousHome);
        }
    }

    public function testPromotionJournalPreservesRetainedBackupWhenCurrentTargetIsMalformed(): void
    {
        $previousMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $previousHome = \getenv('WLS_GATEWAY_HOME');
        $gatewayHome = $this->root . DIRECTORY_SEPARATOR . 'gateway-home-malformed';
        self::assertTrue(\putenv('WLS_GATEWAY_TEST_MODE=1'));
        self::assertTrue(\putenv('WLS_GATEWAY_HOME=' . $gatewayHome));

        try {
            $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
            $directory = $gatewayHome . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'promotion-transaction';
            self::assertTrue(\mkdir($directory, 0700, true));
            $journalFile = $directory . DIRECTORY_SEPARATOR . 'journal.json';
            $backup = $journalFile . '.wls-backup-' . \str_repeat('c', 16);
            self::assertNotFalse(\file_put_contents($journalFile, "{}\n"));
            self::assertNotFalse(\file_put_contents($backup, "previous\n"));

            $cleanup = new \ReflectionMethod(
                Promote::class,
                'cleanupPromotionJournalRecoveryBackups',
            );
            try {
                $cleanup->invoke($command);
                self::fail('Malformed promotion journal must preserve recovery evidence.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $throwable) {
                self::assertStringContainsString(
                    'integrity',
                    \strtolower($throwable->getMessage()),
                );
            }

            self::assertFileExists($backup);
            self::assertSame("{}\n", (string)\file_get_contents($journalFile));
        } finally {
            $previousMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $previousMode);
            $previousHome === false
                ? \putenv('WLS_GATEWAY_HOME')
                : \putenv('WLS_GATEWAY_HOME=' . $previousHome);
        }
    }
}
