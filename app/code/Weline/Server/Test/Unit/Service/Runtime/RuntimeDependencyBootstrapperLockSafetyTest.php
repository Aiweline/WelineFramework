<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\RuntimeDependencyBootstrapper;

final class RuntimeDependencyBootstrapperLockSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupFiles = [];

    /** @var list<string> */
    private array $cleanupDirectories = [];

    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define(
                'BP',
                \rtrim(\dirname(__DIR__, 8), '/\\') . DIRECTORY_SEPARATOR,
            );
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        foreach ([
            'APP_PATH' => BP . 'app' . DS,
            'APP_ETC_PATH' => BP . 'app' . DS . 'etc' . DS,
            'PUB' => BP . 'pub' . DS,
            'VENDOR_PATH' => BP . 'vendor' . DS,
            'APP_CODE_PATH' => BP . 'app' . DS . 'code' . DS,
        ] as $name => $path) {
            if (!\defined($name)) {
                \define($name, $path);
            }
        }
    }

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

    public function testEventConfigurationLockContentionFailsWithinItsMonotonicBudget(): void
    {
        [$root, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $lockPath = $scanDirectory . DIRECTORY_SEPARATOR . '.weline-event.ini.lock';
        $lock = \fopen($lockPath, 'x+b');
        self::assertIsResource($lock);
        self::assertTrue(\flock($lock, LOCK_EX));

        try {
            $script = $this->childBootstrap()
                . '$runtime = ' . \var_export($this->eventRuntime($scanDirectory, $extensionBinary), true) . ';'
                . '$target = ' . \var_export($scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini', true) . ';'
                . '$bootstrapper = new \\Weline\\Server\\Service\\Runtime\\RuntimeDependencyBootstrapper(0.15);'
                . '$result = $bootstrapper->configureEventExtensionForRuntime('
                . '$runtime, static fn (): array => ['
                . "'exit_code' => 0, 'loaded' => true, 'classes' => true, "
                . "'scanned_files' => [\$target], 'output' => '', 'stderr' => ''"
                . ']);'
                . '$emit($result);';

            $execution = $this->runPhp($script, false, 2.0);
            self::assertFalse(
                $execution['timed_out'],
                'Event ini lock acquisition exceeded its monotonic wait budget.',
            );
            self::assertLessThan(1.5, $execution['elapsed']);
            self::assertSame(0, $execution['exit_code'], $this->executionFailure($execution));
            self::assertSame('failed', $execution['result']['status'] ?? null);
            self::assertStringContainsString('超时', (string)($execution['result']['message'] ?? ''));
            self::assertFileDoesNotExist(
                $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini',
            );
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }

        self::assertDirectoryExists($root);
    }

    public function testExplicitInstallLockContentionFailsInsteadOfHanging(): void
    {
        [$isolatedRoot, $isolatedDirectory] = $this->createIsolatedProjectLockDirectory(
            'wls-runtime-install-lock',
        );
        $isolatedLockPath = $isolatedDirectory . DIRECTORY_SEPARATOR . 'runtime_dependency_install.lock';
        $isolatedLock = \fopen($isolatedLockPath, 'x+b');
        self::assertIsResource($isolatedLock);
        self::assertTrue(\flock($isolatedLock, LOCK_EX));
        $this->cleanupFiles[] = $isolatedLockPath;

        try {
            $script = $this->childBootstrap($isolatedRoot)
                . '$bootstrapper = new \\Weline\\Server\\Service\\Runtime\\RuntimeDependencyBootstrapper(0.15);'
                . '$result = $bootstrapper->ensureOptimalRuntime('
                . "['install-deps' => true], "
                . '\\Weline\\Server\\Service\\Runtime\\RequestedTopology::Dispatcher, '
                . '\\Weline\\Server\\Service\\Runtime\\EffectiveTopology::Dispatcher, '
                . 'false, false);'
                . '$emit($result);';

            $execution = $this->runPhp($script, true, 2.0);
            self::assertFalse(
                $execution['timed_out'],
                'Explicit runtime dependency installation exceeded its lock wait budget.',
            );
            self::assertLessThan(1.5, $execution['elapsed']);
            self::assertSame(0, $execution['exit_code'], $this->executionFailure($execution));
            self::assertSame('platform_optimal', $execution['result']['status'] ?? null);
            self::assertStringContainsString('超时', (string)($execution['result']['message'] ?? ''));
        } finally {
            \flock($isolatedLock, LOCK_UN);
            \fclose($isolatedLock);
        }
    }

    public function testEventConfigurationRejectsAHardLinkedLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link identity validation is POSIX-specific.');
        }
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $source = $scanDirectory . DIRECTORY_SEPARATOR . 'foreign.lock';
        $lockPath = $scanDirectory . DIRECTORY_SEPARATOR . '.weline-event.ini.lock';
        self::assertNotFalse(\file_put_contents($source, 'foreign'));
        self::assertTrue(\link($source, $lockPath));
        $this->cleanupFiles[] = $source;

        $result = (new RuntimeDependencyBootstrapper(0.15))
            ->configureEventExtensionForRuntime(
                $this->eventRuntime($scanDirectory, $extensionBinary),
                static fn (string $phpBinary, string $target): array => [
                    'exit_code' => 0,
                    'loaded' => true,
                    'classes' => true,
                    'scanned_files' => [$target],
                    'output' => '',
                    'stderr' => '',
                ],
            );

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('单链接', $result['message']);
        self::assertFileDoesNotExist(
            $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini',
        );
    }

    public function testExplicitInstallRejectsAHardLinkedLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link identity validation is POSIX-specific.');
        }
        [$isolatedRoot, $isolatedDirectory] = $this->createIsolatedProjectLockDirectory(
            'wls-runtime-install-hardlink',
        );
        $foreign = $isolatedDirectory . DIRECTORY_SEPARATOR . 'foreign.lock';
        $isolatedLockPath = $isolatedDirectory . DIRECTORY_SEPARATOR . 'runtime_dependency_install.lock';
        self::assertNotFalse(\file_put_contents($foreign, 'foreign'));
        self::assertTrue(\link($foreign, $isolatedLockPath));
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $isolatedLockPath;

        $script = $this->childBootstrap($isolatedRoot)
            . '$bootstrapper = new \\Weline\\Server\\Service\\Runtime\\RuntimeDependencyBootstrapper(0.15);'
            . '$result = $bootstrapper->ensureOptimalRuntime('
            . "['install-deps' => true], "
            . '\\Weline\\Server\\Service\\Runtime\\RequestedTopology::Dispatcher, '
            . '\\Weline\\Server\\Service\\Runtime\\EffectiveTopology::Dispatcher, '
            . 'false, false);'
            . '$emit($result);';

        $execution = $this->runPhp($script, true, 2.0);
        self::assertFalse(
            $execution['timed_out'],
            'Unsafe explicit-install lock inspection must fail without waiting.',
        );
        self::assertLessThan(1.5, $execution['elapsed']);
        self::assertSame(0, $execution['exit_code'], $this->executionFailure($execution));
        self::assertSame('platform_optimal', $execution['result']['status'] ?? null);
        self::assertStringContainsString('单链接', (string)($execution['result']['message'] ?? ''));
    }

    public function testExplicitInstallRefusesToFollowASymbolicLinkLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link creation is not available on this runtime.');
        }
        [$isolatedRoot, $isolatedDirectory] = $this->createIsolatedProjectLockDirectory(
            'wls-runtime-install-symlink',
        );
        $foreign = $isolatedDirectory . DIRECTORY_SEPARATOR . 'foreign.lock';
        $isolatedLockPath = $isolatedDirectory . DIRECTORY_SEPARATOR . 'runtime_dependency_install.lock';
        self::assertNotFalse(\file_put_contents($foreign, 'foreign'));
        self::assertTrue(\symlink($foreign, $isolatedLockPath));
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $isolatedLockPath;

        $script = $this->childBootstrap($isolatedRoot)
            . '$bootstrapper = new \\Weline\\Server\\Service\\Runtime\\RuntimeDependencyBootstrapper(0.15);'
            . '$result = $bootstrapper->ensureOptimalRuntime('
            . "['install-deps' => true], "
            . '\\Weline\\Server\\Service\\Runtime\\RequestedTopology::Dispatcher, '
            . '\\Weline\\Server\\Service\\Runtime\\EffectiveTopology::Dispatcher, '
            . 'false, false);'
            . '$emit($result);';

        $execution = $this->runPhp($script, true, 2.0);
        self::assertFalse($execution['timed_out']);
        self::assertLessThan(1.5, $execution['elapsed']);
        self::assertSame(0, $execution['exit_code'], $this->executionFailure($execution));
        self::assertSame('platform_optimal', $execution['result']['status'] ?? null);
        self::assertStringContainsString('单链接', (string)($execution['result']['message'] ?? ''));
        self::assertSame('foreign', (string)\file_get_contents($foreign));
    }

    public function testLockWaitBudgetRejectsUnboundedValues(): void
    {
        foreach ([0.0, -1.0, 301.0, INF] as $invalid) {
            try {
                new RuntimeDependencyBootstrapper($invalid);
                self::fail('An invalid runtime dependency lock budget was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('(0, 300]', $exception->getMessage());
            }
        }
    }

    public function testOrdinaryStartDoesNotCreateOrWaitForTheInstallLock(): void
    {
        [$isolatedRoot, $isolatedDirectory] = $this->createIsolatedProjectLockDirectory(
            'wls-runtime-normal-start',
        );
        $script = $this->childBootstrap($isolatedRoot)
            . '$lock = ' . \var_export($isolatedDirectory . DIRECTORY_SEPARATOR . 'runtime_dependency_install.lock', true) . ';'
            . '$bootstrapper = new \\Weline\\Server\\Service\\Runtime\\RuntimeDependencyBootstrapper(0.15);'
            . '$result = $bootstrapper->ensureOptimalRuntime('
            . '[], '
            . '\\Weline\\Server\\Service\\Runtime\\RequestedTopology::Dispatcher, '
            . '\\Weline\\Server\\Service\\Runtime\\EffectiveTopology::Dispatcher, '
            . 'false, false);'
            . '$emit(['
            . "'status' => \$result['status'] ?? null, "
            . "'lock_exists' => file_exists(\$lock) || is_link(\$lock)"
            . ']);';

        $execution = $this->runPhp($script, true, 2.0);
        self::assertFalse($execution['timed_out']);
        self::assertLessThan(1.5, $execution['elapsed']);
        self::assertSame(0, $execution['exit_code'], $this->executionFailure($execution));
        self::assertSame('platform_optimal', $execution['result']['status'] ?? null);
        self::assertFalse($execution['result']['lock_exists'] ?? true);
    }

    public function testEventPublicationCollectsLegacyAndNewHardCrashOrphans(): void
    {
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        $managed = "; Managed by WLS 2.0 explicit --install-deps\nextension=event\n";
        self::assertSame(\strlen($managed), \file_put_contents($target, $managed));
        $artifacts = [
            $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-a1b2c3',
            $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-abcdefghijklmnopqrst',
            $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-' . \str_repeat('a', 24),
            $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-backup-' . \str_repeat('b', 16),
        ];
        foreach ($artifacts as $artifact) {
            $artifactContent = \str_contains($artifact, '-backup-')
                ? $managed
                : 'orphan';
            self::assertSame(
                \strlen($artifactContent),
                \file_put_contents($artifact, $artifactContent),
            );
            $this->cleanupFiles[] = $artifact;
        }

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['changed']);
        self::assertSame($managed, \file_get_contents($target));
        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testEventPublicationRestoresBackupBeforeReplacingAMissingTarget(): void
    {
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        $backup = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-backup-'
            . \str_repeat('c', 16);
        $staging = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('d', 24);
        $previous = "; Managed by WLS 2.0 explicit --install-deps\nextension=event\n; previous\n";
        self::assertSame(\strlen($previous), \file_put_contents($backup, $previous));
        self::assertSame(7, \file_put_contents($staging, 'partial'));
        $this->cleanupFiles[] = $backup;
        $this->cleanupFiles[] = $staging;

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('ready', $result['status']);
        self::assertTrue($result['changed']);
        self::assertSame(
            "; Managed by WLS 2.0 explicit --install-deps\nextension=event\n",
            \file_get_contents($target),
        );
        self::assertFileDoesNotExist($backup);
        self::assertFileDoesNotExist($staging);
    }

    public function testEventPublicationCleansOrphansWhenTheTargetIsMissing(): void
    {
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $legacy = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-'
            . \str_repeat('e', 20);
        self::assertSame(7, \file_put_contents($legacy, 'partial'));
        $this->cleanupFiles[] = $legacy;

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('ready', $result['status']);
        self::assertTrue($result['changed']);
        self::assertFileDoesNotExist($legacy);
    }

    public function testEventPublicationPreservesAllArtifactsOnCaseAliasOrMalformedLeaf(): void
    {
        foreach (['.WLS-EVENT-STAGING-' . \str_repeat('a', 24), '.wls-event-staging-invalid'] as $unsafeLeaf) {
            [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
            $safe = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
                . \str_repeat('1', 24);
            $unsafe = $scanDirectory . DIRECTORY_SEPARATOR . $unsafeLeaf;
            self::assertSame(4, \file_put_contents($safe, 'safe'));
            self::assertSame(6, \file_put_contents($unsafe, 'unsafe'));
            $this->cleanupFiles[] = $safe;
            $this->cleanupFiles[] = $unsafe;

            $result = $this->configureEvent($scanDirectory, $extensionBinary);

            self::assertSame('failed', $result['status']);
            self::assertFileExists($safe);
            self::assertFileExists($unsafe);
        }
    }

    public function testEventPublicationPreservesAllArtifactsOnUnsafeLink(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link recovery validation is POSIX-specific.');
        }
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $safe = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('2', 24);
        $foreign = $scanDirectory . DIRECTORY_SEPARATOR . 'foreign-event-artifact';
        $unsafe = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('3', 24);
        self::assertSame(4, \file_put_contents($safe, 'safe'));
        self::assertSame(7, \file_put_contents($foreign, 'foreign'));
        self::assertTrue(\symlink($foreign, $unsafe));
        $this->cleanupFiles[] = $safe;
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $unsafe;

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('failed', $result['status']);
        self::assertFileExists($safe);
        self::assertTrue(\is_link($unsafe));
        self::assertSame('foreign', \file_get_contents($foreign));
    }

    public function testEventPublicationPreservesAllArtifactsWhenQuotaIsExceeded(): void
    {
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $artifacts = [];
        for ($index = 0; $index < 9; ++$index) {
            $artifact = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
                . \str_pad(\dechex($index), 24, '0', STR_PAD_LEFT);
            self::assertSame(6, \file_put_contents($artifact, 'orphan'));
            $this->cleanupFiles[] = $artifact;
            $artifacts[] = $artifact;
        }

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('failed', $result['status']);
        foreach ($artifacts as $artifact) {
            self::assertFileExists($artifact);
        }
    }

    public function testEventPublicationRejectsAHardLinkedArtifactWithoutPartialCleanup(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link recovery validation is POSIX-specific.');
        }
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $safe = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('4', 24);
        $foreign = $scanDirectory . DIRECTORY_SEPARATOR . 'foreign-event-hardlink';
        $unsafe = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('5', 24);
        self::assertSame(4, \file_put_contents($safe, 'safe'));
        self::assertSame(7, \file_put_contents($foreign, 'foreign'));
        self::assertTrue(\link($foreign, $unsafe));
        $this->cleanupFiles[] = $safe;
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $unsafe;

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('failed', $result['status']);
        self::assertFileExists($safe);
        self::assertFileExists($unsafe);
        self::assertSame('foreign', \file_get_contents($foreign));
    }

    public function testEventPublicationRejectsASpecialArtifactWithoutPartialCleanup(): void
    {
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $safe = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('6', 24);
        $special = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('7', 24);
        self::assertSame(4, \file_put_contents($safe, 'safe'));
        self::assertTrue(\mkdir($special, 0700));
        $this->cleanupFiles[] = $safe;
        $this->cleanupDirectories[] = $special;

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('failed', $result['status']);
        self::assertFileExists($safe);
        self::assertDirectoryExists($special);
    }

    public function testEventPublicationDoesNotDiscardEvidencePairedWithAForeignTarget(): void
    {
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        $artifact = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-staging-'
            . \str_repeat('8', 24);
        self::assertSame(12, \file_put_contents($target, 'foreign=true'));
        self::assertSame(7, \file_put_contents($artifact, 'partial'));
        $this->cleanupFiles[] = $artifact;

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('failed', $result['status']);
        self::assertSame('foreign=true', \file_get_contents($target));
        self::assertFileExists($artifact);
    }

    public function testEventPublicationRejectsAHardLinkedManagedTarget(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link target validation is POSIX-specific.');
        }
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        $alias = $scanDirectory . DIRECTORY_SEPARATOR . 'managed-event-hardlink';
        $managed = "; Managed by WLS 2.0 explicit --install-deps\nextension=event\n";
        self::assertSame(\strlen($managed), \file_put_contents($target, $managed));
        self::assertTrue(\link($target, $alias));
        $this->cleanupFiles[] = $alias;

        $result = $this->configureEvent($scanDirectory, $extensionBinary);

        self::assertSame('failed', $result['status']);
        self::assertSame($managed, \file_get_contents($target));
        self::assertSame($managed, \file_get_contents($alias));
    }

    public function testAlreadyLoadedEventRuntimeStillCollectsAValidTargetOrphan(): void
    {
        [, $scanDirectory, $extensionBinary] = $this->createEventFixture();
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        $legacy = $scanDirectory . DIRECTORY_SEPARATOR . '.wls-event-'
            . \str_repeat('z', 20);
        $managed = "; Managed by WLS 2.0 explicit --install-deps\nextension=event\n";
        self::assertSame(\strlen($managed), \file_put_contents($target, $managed));
        self::assertSame(7, \file_put_contents($legacy, 'partial'));
        $this->cleanupFiles[] = $legacy;
        $runtime = $this->eventRuntime($scanDirectory, $extensionBinary);
        $runtime['event_loaded'] = true;
        $runtime['event_base_available'] = true;
        $runtime['event_buffer_available'] = true;
        $probeCalled = false;

        $result = (new RuntimeDependencyBootstrapper(0.15))
            ->configureEventExtensionForRuntime(
                $runtime,
                static function () use (&$probeCalled): array {
                    $probeCalled = true;
                    return [];
                },
            );

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['changed']);
        self::assertFalse($probeCalled);
        self::assertFileDoesNotExist($legacy);
        self::assertSame($managed, \file_get_contents($target));
    }

    public function testExplicitInstallReconcilesLoadedEventBeforeReadyReturn(): void
    {
        $source = (string)\file_get_contents(
            $this->projectRoot()
                . 'app/code/Weline/Server/Service/Runtime/RuntimeDependencyBootstrapper.php',
        );

        self::assertStringContainsString(
            '$installRequested && $posix && $this->canUseEvent()',
            $source,
        );
        self::assertStringContainsString(
            '$this->configureEventExtensionForRuntime()',
            $source,
        );
    }

    /** @return array{string,string,string} */
    private function createEventFixture(): array
    {
        $root = $this->createDirectory('wls-event-lock');
        $scanDirectory = $root . DIRECTORY_SEPARATOR . 'conf.d';
        $extensionDirectory = $root . DIRECTORY_SEPARATOR . 'extensions';
        self::assertTrue(\mkdir($scanDirectory, 0700));
        self::assertTrue(\mkdir($extensionDirectory, 0700));
        $this->cleanupDirectories[] = $scanDirectory;
        $this->cleanupDirectories[] = $extensionDirectory;
        $extensionBinary = $extensionDirectory . DIRECTORY_SEPARATOR . 'event.so';
        self::assertNotFalse(\file_put_contents($extensionBinary, 'fixture-event'));
        $this->cleanupFiles[] = $extensionBinary;
        $this->cleanupFiles[] = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        $this->cleanupFiles[] = $scanDirectory . DIRECTORY_SEPARATOR . '.weline-event.ini.lock';

        return [$root, $scanDirectory, $extensionBinary];
    }

    /** @return array<string, mixed> */
    private function eventRuntime(string $scanDirectory, string $extensionBinary): array
    {
        return [
            'os_family' => 'Linux',
            'php_binary' => PHP_BINARY,
            'event_loaded' => false,
            'event_base_available' => false,
            'event_buffer_available' => false,
            'loaded_ini' => '/fixture/php.ini',
            'scan_dirs' => [$scanDirectory],
            'extension_dir' => \dirname($extensionBinary),
            'extension_binary' => $extensionBinary,
        ];
    }

    /** @return array<string, mixed> */
    private function configureEvent(string $scanDirectory, string $extensionBinary): array
    {
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        return (new RuntimeDependencyBootstrapper(0.15))
            ->configureEventExtensionForRuntime(
                $this->eventRuntime($scanDirectory, $extensionBinary),
                static fn (): array => [
                    'exit_code' => 0,
                    'loaded' => true,
                    'classes' => true,
                    'scanned_files' => [$target],
                    'output' => '',
                    'stderr' => '',
                ],
            );
    }

    private function createDirectory(string $prefix): string
    {
        $root = (string)\realpath(\sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . $prefix . '-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($root, 0700));
        $this->cleanupDirectories[] = $root;
        return $root;
    }

    /** @return array{string,string} */
    private function createIsolatedProjectLockDirectory(string $prefix): array
    {
        $root = $this->createDirectory($prefix);
        $current = $root;
        foreach (['var', 'server', 'locks'] as $leaf) {
            $current .= DIRECTORY_SEPARATOR . $leaf;
            self::assertTrue(\mkdir($current, 0700));
            $this->cleanupDirectories[] = $current;
        }
        $this->cleanupDirectories[] = $root . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'cache';
        $this->cleanupDirectories[] = $root . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'object';
        return [$root, $current];
    }

    private function projectRoot(): string
    {
        return \rtrim(\dirname(__DIR__, 8), '/\\') . DIRECTORY_SEPARATOR;
    }

    private function childBootstrap(?string $runtimeRoot = null): string
    {
        $sourceRoot = \rtrim($this->projectRoot(), '/\\');
        $runtimeRoot = \rtrim($runtimeRoot ?? $sourceRoot, '/\\') . DIRECTORY_SEPARATOR;
        return 'define(' . \var_export('BP', true) . ', ' . \var_export($runtimeRoot, true) . ');'
            . 'define(' . \var_export('DS', true) . ', DIRECTORY_SEPARATOR);'
            . 'define(' . \var_export('APP_PATH', true) . ', BP . "app" . DS);'
            . 'define(' . \var_export('APP_ETC_PATH', true) . ', APP_PATH . "etc" . DS);'
            . 'define(' . \var_export('PUB', true) . ', BP . "pub" . DS);'
            . 'define(' . \var_export('VENDOR_PATH', true) . ', ' . \var_export($sourceRoot . '/vendor/', true) . ');'
            . 'define(' . \var_export('APP_CODE_PATH', true) . ', ' . \var_export($sourceRoot . '/app/code/', true) . ');'
            . 'require ' . \var_export($sourceRoot . '/vendor/autoload.php', true) . ';'
            . 'function __(string $text, array|string|int $args = ""): string { return $text; }'
            . '$emit = static function (array $payload): void {'
            . "echo '\\nWLS_LOCK_RESULT=' . base64_encode((string)json_encode(\$payload, JSON_UNESCAPED_SLASHES));"
            . '};';
    }

    /**
     * @return array{timed_out:bool,elapsed:float,exit_code:int,stdout:string,stderr:string,result:array<string,mixed>}
     */
    private function runPhp(string $script, bool $withoutIni, float $timeoutSeconds): array
    {
        $command = [PHP_BINARY];
        if ($withoutIni) {
            $command[] = '-n';
        }
        $command[] = '-r';
        $command[] = $script;
        $process = \proc_open(
            $command,
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
        $exitCode = $lastExitCode >= 0
            ? $lastExitCode
            : $closedExit;
        $result = [];
        if (\preg_match('/WLS_LOCK_RESULT=([A-Za-z0-9+\/=]+)/', $stdout, $match) === 1) {
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
