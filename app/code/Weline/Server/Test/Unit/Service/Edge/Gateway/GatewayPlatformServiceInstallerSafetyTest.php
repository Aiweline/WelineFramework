<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPlatformServiceInstaller;

final class GatewayPlatformServiceInstallerSafetyTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';
    private GatewayPaths $paths;

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-gateway-platform-safety-' . \bin2hex(\random_bytes(8));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . DIRECTORY_SEPARATOR . 'host');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=22080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=22443');
        $this->paths = new GatewayPaths();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testPlatformLockContentionCannotOutliveLifecycleDeadline(): void
    {
        $this->paths->ensureDirectories();
        $lockFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'package-install.lock';
        $handle = \fopen($lockFile, 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(\flock($handle, LOCK_EX | LOCK_NB));
        $started = \hrtime(true) / 1_000_000_000;
        try {
            (new GatewayPlatformServiceInstaller($this->paths))
                ->installDefinition('default', $started + 0.15);
            self::fail('Contended platform lock must honor the outer deadline.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Timed out acquiring the WLS state lock',
                $exception->getMessage(),
            );
            self::assertLessThan(
                1.0,
                \hrtime(true) / 1_000_000_000 - $started,
            );
        } finally {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    public function testInstallRefusesToReplaceAnExistingPlatformArtifact(): void
    {
        $this->paths->ensureDirectories();
        $definition = $this->paths->serviceDefinitionFile();
        self::assertNotFalse(\file_put_contents($definition, "existing-definition\n"));

        try {
            (new GatewayPlatformServiceInstaller($this->paths))
                ->installDefinition('default');
            self::fail('Initial platform installation must not replace an existing artifact.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        self::assertSame("existing-definition\n", \file_get_contents($definition));
        self::assertFileDoesNotExist($this->paths->platformServiceMetadataFile());
    }

    public function testInitialInstallSharesTheHostPackageTransactionLock(): void
    {
        (new GatewayPlatformServiceInstaller($this->paths))
            ->installDefinition('default');

        self::assertFileExists(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'package-install.lock',
        );
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $install = $this->sourceBetween(
            $source,
            'public function installDefinition(',
            'public function refreshDefinition(',
        );
        self::assertStringContainsString(
            'GatewayProjectStateFilesystem::withExclusiveLock(',
            $install,
        );
        self::assertStringContainsString("'package-install.lock'", $install);
    }

    public function testRefreshAndRemovalCollectOnlyValidatedPlatformBackupsUnderPackageLock(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $definition = $this->paths->serviceDefinitionFile();
        $metadata = $this->paths->platformServiceMetadataFile();
        $definitionBackup = $definition . '.wls-backup-' . \str_repeat('a', 16);
        $metadataBackup = $metadata . '.wls-backup-' . \str_repeat('b', 16);
        self::assertTrue(\copy($definition, $definitionBackup));
        self::assertTrue(\copy($metadata, $metadataBackup));

        $installer->refreshDefinition('default');

        self::assertFileDoesNotExist($definitionBackup);
        self::assertFileDoesNotExist($metadataBackup);
        $pending = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'platform-removal.pending';
        self::assertNotFalse(\file_put_contents(
            $pending,
            "WLS-PLATFORM-REMOVAL/1\nkind=test-session\nat=1\nnonce="
                . \str_repeat('c', 32) . "\n",
        ));
        $pendingBackup = $pending . '.wls-backup-' . \str_repeat('d', 16);
        self::assertTrue(\copy($pending, $pendingBackup));

        $installer->removeDefinition('test-session');

        self::assertFileDoesNotExist($pendingBackup);
        self::assertFileDoesNotExist($definition);
        self::assertFileDoesNotExist($metadata);
    }

    public function testRefreshCollectsPlatformAtomicWriteTemporaries(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $definitionTemporary = $this->paths->serviceDefinitionFile()
            . '.tmp-' . \str_repeat('5', 24);
        $metadataTemporary = $this->paths->platformServiceMetadataFile()
            . '.tmp-' . \str_repeat('6', 24);
        self::assertNotFalse(\file_put_contents($definitionTemporary, 'partial'));
        self::assertNotFalse(\file_put_contents($metadataTemporary, 'partial'));
        self::assertTrue(\chmod($definitionTemporary, 0600));
        self::assertTrue(\chmod($metadataTemporary, 0600));

        $installer->refreshDefinition('default');

        self::assertFileDoesNotExist($definitionTemporary);
        self::assertFileDoesNotExist($metadataTemporary);
    }

    public function testMissingPlatformTemporaryTargetPreservesTheCompleteRecoverySet(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $definitionTemporary = $this->paths->serviceDefinitionFile()
            . '.tmp-' . \str_repeat('7', 24);
        $metadataTemporary = $this->paths->platformServiceMetadataFile()
            . '.tmp-' . \str_repeat('8', 24);
        self::assertNotFalse(\file_put_contents($definitionTemporary, 'partial'));
        self::assertNotFalse(\file_put_contents($metadataTemporary, 'partial'));
        self::assertTrue(\chmod($definitionTemporary, 0600));
        self::assertTrue(\chmod($metadataTemporary, 0600));
        self::assertTrue(\unlink($this->paths->platformServiceMetadataFile()));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('default'),
        );
        self::assertStringContainsString(
            'paired target',
            \strtolower($exception->getMessage()),
        );
        self::assertFileExists($definitionTemporary);
        self::assertFileExists($metadataTemporary);
    }

    public function testMalformedPlatformTemporaryLeafBlocksEveryCleanup(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $valid = $this->paths->serviceDefinitionFile()
            . '.tmp-' . \str_repeat('9', 24);
        $malformed = $this->paths->platformServiceMetadataFile()
            . '.tmp-' . \str_repeat('Z', 24);
        self::assertNotFalse(\file_put_contents($valid, 'partial'));
        self::assertNotFalse(\file_put_contents($malformed, 'partial'));
        self::assertTrue(\chmod($valid, 0600));
        self::assertTrue(\chmod($malformed, 0600));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('default'),
        );
        self::assertStringContainsString(
            'malformed reserved leaf',
            \strtolower($exception->getMessage()),
        );
        self::assertFileExists($valid);
        self::assertFileExists($malformed);
    }

    public function testPlatformTemporaryQuotaFailsBeforeCleanup(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $temporaries = [];
        for ($index = 0; $index < 9; ++$index) {
            $temporary = $this->paths->platformServiceMetadataFile() . '.tmp-'
                . \str_pad(\dechex($index + 1), 24, '0', STR_PAD_LEFT);
            self::assertNotFalse(\file_put_contents($temporary, 'partial'));
            self::assertTrue(\chmod($temporary, 0600));
            $temporaries[] = $temporary;
        }

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('default'),
        );
        self::assertStringContainsString(
            'recovery artifact quota',
            \strtolower($exception->getMessage()),
        );
        foreach ($temporaries as $temporary) {
            self::assertFileExists($temporary);
        }
    }

    public function testRefreshKeepsDefinitionAndMetadataProfileBoundForFutureRecovery(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');

        $installer->refreshDefinition('ipv4-only');

        $definition = $this->paths->serviceDefinitionFile();
        $metadata = $this->paths->platformServiceMetadataFile();
        $decoded = \json_decode(
            (string)\file_get_contents($metadata),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('ipv4-only', $decoded['profile'] ?? null);
        $definitionBackup = $definition . '.wls-backup-' . \str_repeat('1', 16);
        $metadataBackup = $metadata . '.wls-backup-' . \str_repeat('2', 16);
        self::assertTrue(\copy($definition, $definitionBackup));
        self::assertTrue(\copy($metadata, $metadataBackup));

        $installer->refreshDefinition('ipv4-only');

        self::assertFileDoesNotExist($definitionBackup);
        self::assertFileDoesNotExist($metadataBackup);
    }

    public function testMissingPlatformMetadataTargetRetainsEveryRecoveryBackup(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $definition = $this->paths->serviceDefinitionFile();
        $metadata = $this->paths->platformServiceMetadataFile();
        $definitionBackup = $definition . '.wls-backup-' . \str_repeat('e', 16);
        $metadataBackup = $metadata . '.wls-backup-' . \str_repeat('f', 16);
        self::assertTrue(\copy($definition, $definitionBackup));
        self::assertTrue(\rename($metadata, $metadataBackup));

        try {
            $installer->refreshDefinition('default');
            self::fail('A missing paired platform metadata target must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'paired target',
                \strtolower($exception->getMessage()),
            );
        }
        self::assertFileExists($definitionBackup);
        self::assertFileExists($metadataBackup);
        self::assertFileDoesNotExist($metadata);
    }

    public function testRecoveryRejectsARemovalFenceForAnotherPlatform(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $pending = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'platform-removal.pending';
        self::assertNotFalse(\file_put_contents(
            $pending,
            "WLS-PLATFORM-REMOVAL/1\nkind=systemd-system\nat=1\nnonce="
                . \str_repeat('3', 32) . "\n",
        ));
        $backup = $pending . '.wls-backup-' . \str_repeat('4', 16);
        self::assertTrue(\copy($pending, $backup));

        try {
            $installer->refreshDefinition('default');
            self::fail('A foreign-platform removal fence must not authorize recovery cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'malformed or unsupported',
                \strtolower($exception->getMessage()),
            );
        }
        self::assertFileExists($backup);
    }

    public function testFailedMetadataPublicationCannotLeaveAPartialDefinition(): void
    {
        $this->paths->ensureDirectories();
        $metadata = $this->paths->platformServiceMetadataFile();
        self::assertTrue(\mkdir($metadata, 0700));

        try {
            (new GatewayPlatformServiceInstaller($this->paths))
                ->installDefinition('default');
            self::fail('An unsafe metadata target must reject platform installation.');
        } catch (\RuntimeException) {
        }

        self::assertFileDoesNotExist($this->paths->serviceDefinitionFile());
        self::assertDirectoryExists($metadata);
    }

    public function testInterruptedInitialInstallCompletesFromItsPersistentTransaction(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $this->paths->ensureDirectories();
        $definition = $installer->renderDefinition('default');
        $metadata = $this->initialMetadata('default');
        $journal = $this->writeDefinitionTransaction(
            'install',
            'prepared',
            null,
            'default',
            null,
            null,
            $definition,
            $metadata,
        );
        self::assertNotFalse(\file_put_contents(
            $this->paths->serviceDefinitionFile(),
            $definition,
        ));

        self::assertSame(
            [
                'kind' => 'test-session',
                'path' => $this->paths->serviceDefinitionFile(),
                'test_mode' => true,
            ],
            $installer->installDefinition('default'),
        );

        self::assertSame(
            $metadata,
            \file_get_contents($this->paths->platformServiceMetadataFile()),
        );
        self::assertFileDoesNotExist($journal);
    }

    public function testInterruptedRefreshCompletesNewDefinitionWithOldMetadata(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $newDefinition = $installer->renderDefinition('ipv4-only');
        $newMetadata = $this->metadataWithProfile($oldMetadata, 'ipv4-only');
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            $newDefinition,
            $newMetadata,
        );
        self::assertNotFalse(\file_put_contents(
            $this->paths->serviceDefinitionFile(),
            $newDefinition,
        ));

        $installer->refreshDefinition('ipv4-only');

        self::assertSame(
            $newMetadata,
            \file_get_contents($this->paths->platformServiceMetadataFile()),
        );
        self::assertFileDoesNotExist($journal);
    }

    public function testInterruptedRefreshCompletesOldDefinitionWithNewMetadata(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $newDefinition = $installer->renderDefinition('ipv4-only');
        $newMetadata = $this->metadataWithProfile($oldMetadata, 'ipv4-only');
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'definition_published',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            $newDefinition,
            $newMetadata,
        );
        self::assertNotFalse(\file_put_contents(
            $this->paths->platformServiceMetadataFile(),
            $newMetadata,
        ));

        $installer->refreshDefinition('ipv4-only');

        self::assertSame(
            $newDefinition,
            \file_get_contents($this->paths->serviceDefinitionFile()),
        );
        self::assertFileDoesNotExist($journal);
    }

    public function testMissingJournalRecoversOnlyOneValidatedStagingAfterImage(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $newDefinition = $installer->renderDefinition('ipv4-only');
        $newMetadata = $this->metadataWithProfile($oldMetadata, 'ipv4-only');
        $journal = $this->definitionTransactionPath();
        $raw = $this->definitionTransactionRaw(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            $newDefinition,
            $newMetadata,
        );
        $staging = $journal . '.tmp-' . \str_repeat('a', 24);
        self::assertNotFalse(\file_put_contents($staging, $raw));
        self::assertTrue(\chmod($staging, 0600));

        $installer->refreshDefinition('ipv4-only');

        self::assertFileDoesNotExist($staging);
        self::assertFileDoesNotExist($journal);
        self::assertSame(
            $newDefinition,
            \file_get_contents($this->paths->serviceDefinitionFile()),
        );
        self::assertSame(
            $newMetadata,
            \file_get_contents($this->paths->platformServiceMetadataFile()),
        );
    }

    public function testUnknownTransactionTargetStateFailsClosedWithoutDeletingEvidence(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            $installer->renderDefinition('ipv4-only'),
            $this->metadataWithProfile($oldMetadata, 'ipv4-only'),
        );
        self::assertNotFalse(\file_put_contents(
            $this->paths->serviceDefinitionFile(),
            "unknown-definition\n",
        ));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('ipv4-only'),
        );

        self::assertStringContainsString(
            'transaction target',
            \strtolower($exception->getMessage()),
        );
        self::assertSame(
            "unknown-definition\n",
            \file_get_contents($this->paths->serviceDefinitionFile()),
        );
        self::assertFileExists($journal);
    }

    public function testUnknownTargetPreservesJournalBackupBeforeFirstCleanup(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            $installer->renderDefinition('ipv4-only'),
            $this->metadataWithProfile($oldMetadata, 'ipv4-only'),
        );
        $backup = $journal . '.wls-backup-' . \str_repeat('f', 16);
        self::assertTrue(\copy($journal, $backup));
        self::assertNotFalse(\file_put_contents(
            $this->paths->serviceDefinitionFile(),
            "unknown-definition\n",
        ));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('ipv4-only'),
        );

        self::assertStringContainsString(
            'transaction target',
            \strtolower($exception->getMessage()),
        );
        self::assertFileExists($journal);
        self::assertFileExists($backup);
    }

    public function testRefreshTransactionMissingOldTargetFailsClosed(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $definition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $metadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $definition,
            $metadata,
            $installer->renderDefinition('ipv4-only'),
            $this->metadataWithProfile($metadata, 'ipv4-only'),
        );
        self::assertTrue(\unlink($this->paths->platformServiceMetadataFile()));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('ipv4-only'),
        );

        self::assertStringContainsString('missing', \strtolower($exception->getMessage()));
        self::assertFileExists($journal);
        self::assertSame(
            $definition,
            \file_get_contents($this->paths->serviceDefinitionFile()),
        );
        self::assertFileDoesNotExist($this->paths->platformServiceMetadataFile());
    }

    public function testHardLinkedJournalArtifactBlocksRecoveryBeforeAnyDeletion(): void
    {
        if (!\function_exists('link')) {
            self::markTestSkipped('Hard-link creation is unavailable.');
        }
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            $installer->renderDefinition('ipv4-only'),
            $this->metadataWithProfile($oldMetadata, 'ipv4-only'),
        );
        $staging = $journal . '.tmp-' . \str_repeat('b', 24);
        if (!@\link($journal, $staging)) {
            self::markTestSkipped('Filesystem does not permit a hard-link fixture.');
        }

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('ipv4-only'),
        );

        self::assertStringContainsString('linked', \strtolower($exception->getMessage()));
        self::assertFileExists($journal);
        self::assertFileExists($staging);
    }

    public function testJournalArtifactQuotaFailsClosedBeforeAnyDeletion(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            $installer->renderDefinition('ipv4-only'),
            $this->metadataWithProfile($oldMetadata, 'ipv4-only'),
        );
        $artifacts = [];
        for ($index = 0; $index < 9; ++$index) {
            $artifact = $journal . '.tmp-'
                . \str_pad(\dechex($index + 1), 24, '0', STR_PAD_LEFT);
            self::assertNotFalse(\file_put_contents($artifact, 'partial'));
            self::assertTrue(\chmod($artifact, 0600));
            $artifacts[] = $artifact;
        }

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('ipv4-only'),
        );

        self::assertStringContainsString('quota', \strtolower($exception->getMessage()));
        self::assertFileExists($journal);
        foreach ($artifacts as $artifact) {
            self::assertFileExists($artifact);
        }
    }

    public function testJournalCaseAliasFailsBeforeDeletingOtherRecoveryEvidence(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $definition = $this->paths->serviceDefinitionFile();
        $definitionBackup = $definition . '.wls-backup-'
            . \str_repeat('c', 16);
        self::assertTrue(\copy($definition, $definitionBackup));
        $alias = $this->definitionTransactionPath()
            . '.TMP-' . \str_repeat('d', 24);
        self::assertNotFalse(\file_put_contents($alias, 'partial'));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('default'),
        );

        self::assertStringContainsString(
            'case alias',
            \strtolower($exception->getMessage()),
        );
        self::assertFileExists($alias);
        self::assertFileExists($definitionBackup);
    }

    public function testJournalSymlinkArtifactFailsClosed(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside-journal';
        self::assertNotFalse(\file_put_contents($outside, 'partial'));
        $artifact = $this->definitionTransactionPath()
            . '.tmp-' . \str_repeat('e', 24);
        if (!@\symlink($outside, $artifact)) {
            self::markTestSkipped('Filesystem does not permit a symlink fixture.');
        }

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('default'),
        );

        self::assertStringContainsString('linked', \strtolower($exception->getMessage()));
        self::assertTrue(\is_link($artifact));
        self::assertSame('partial', \file_get_contents($outside));
    }

    public function testMalformedJournalTargetIsRetainedWithoutChangingInstalledFiles(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $definition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $metadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $journal = $this->definitionTransactionPath();
        self::assertNotFalse(\file_put_contents($journal, "{}\n"));
        self::assertTrue(\chmod($journal, 0600));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('ipv4-only'),
        );

        self::assertStringContainsString(
            'journal is malformed',
            \strtolower($exception->getMessage()),
        );
        self::assertSame("{}\n", \file_get_contents($journal));
        self::assertSame(
            $definition,
            \file_get_contents($this->paths->serviceDefinitionFile()),
        );
        self::assertSame(
            $metadata,
            \file_get_contents($this->paths->platformServiceMetadataFile()),
        );
    }

    public function testDigestBoundButNonCanonicalDefinitionAfterImageFailsBeforeMutation(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $oldDefinition = (string)\file_get_contents(
            $this->paths->serviceDefinitionFile(),
        );
        $oldMetadata = (string)\file_get_contents(
            $this->paths->platformServiceMetadataFile(),
        );
        $journal = $this->writeDefinitionTransaction(
            'refresh',
            'prepared',
            'default',
            'ipv4-only',
            $oldDefinition,
            $oldMetadata,
            "# digest-bound but not rendered by WLS\n",
            $this->metadataWithProfile($oldMetadata, 'ipv4-only'),
        );

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->refreshDefinition('ipv4-only'),
        );

        self::assertStringContainsString(
            'after-image',
            \strtolower($exception->getMessage()),
        );
        self::assertSame(
            $oldDefinition,
            \file_get_contents($this->paths->serviceDefinitionFile()),
        );
        self::assertSame(
            $oldMetadata,
            \file_get_contents($this->paths->platformServiceMetadataFile()),
        );
        self::assertFileExists($journal);
    }

    public function testMissingInstalledMetadataUsesTheStableAbsenceContract(): void
    {
        $this->paths->ensureDirectories();
        try {
            (new GatewayPlatformServiceInstaller($this->paths))->installedDefinition();
            self::fail('Missing platform metadata must not look installed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'platform service metadata is unavailable',
                $exception->getMessage(),
            );
        }
    }

    public function testCorruptInstalledMetadataIsNotReportedAsAnEmptyHost(): void
    {
        $this->paths->ensureDirectories();
        self::assertNotFalse(\file_put_contents(
            $this->paths->platformServiceMetadataFile(),
            "{}\n",
        ));
        try {
            (new GatewayPlatformServiceInstaller($this->paths))->installedDefinition();
            self::fail('Corrupt platform metadata must not look installed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'platform service metadata is invalid',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'metadata is unavailable',
                $exception->getMessage(),
            );
        }
    }

    public function testInstalledDefinitionRejectsAPlatformIdentityMismatch(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        $metadata = $this->paths->platformServiceMetadataFile();
        $decoded = \json_decode(
            (string)\file_get_contents($metadata),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        $decoded['kind'] = 'systemd-system';
        self::assertNotFalse(\file_put_contents(
            $metadata,
            \json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->installedDefinition(),
        );
        self::assertStringContainsString(
            'metadata',
            \strtolower($exception->getMessage()),
        );
    }

    public function testInstalledDefinitionRejectsDefinitionDrift(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->installDefinition('default');
        self::assertNotFalse(\file_put_contents(
            $this->paths->serviceDefinitionFile(),
            "tampered-definition\n",
        ));

        $exception = $this->captureRuntimeException(
            static fn (): array => $installer->installedDefinition(),
        );
        self::assertStringContainsString(
            'definition',
            \strtolower($exception->getMessage()),
        );
    }

    public function testSystemdStoppedStateRequiresAllAuthoritativeFields(): void
    {
        $parse = new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'systemdServiceStateFromShow',
        );

        self::assertSame(
            ['active' => 'inactive', 'sub' => 'dead', 'main_pid' => 0],
            $parse->invoke(
                null,
                "SubState=dead\nMainPID=0\nActiveState=inactive\n",
            ),
        );
        self::assertSame(
            ['active' => 'failed', 'sub' => 'failed', 'main_pid' => 0],
            $parse->invoke(
                null,
                "ActiveState=failed\nSubState=failed\nMainPID=0\n",
            ),
        );
        self::assertNull($parse->invoke(
            null,
            "ActiveState=inactive\nMainPID=0\n",
        ));
        self::assertNull($parse->invoke(
            null,
            "ActiveState=inactive\nSubState=dead\nMainPID=0\nMainPID=9\n",
        ));
    }

    public function testLinuxSystemdActivationUsesTheDedicatedAbsoluteUnitTarget(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $start = $this->sourceBetween(
            $source,
            'private function startWithinDeadline(',
            'public function secureInstalledRuntimeSlot(',
        );
        self::assertStringContainsString(
            "['/bin/systemctl', 'enable', '--now', \$this->paths->serviceDefinitionFile()]",
            $start,
        );
        self::assertStringContainsString(
            'assertCurrentDefinitionAndFixedLink(',
            $start,
        );
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/env/gateway/systemd.service.template',
        );
        self::assertStringContainsString(
            'ReadWritePaths="{{HOME}}" "{{RUN_DIR}}" "{{SYSTEMD_DEFINITION_DIR}}"',
            $template,
        );
        self::assertStringNotContainsString(
            'ReadWritePaths="/etc/systemd/system"',
            $template,
        );
    }

    public function testWindowsPollTimeoutUsesRemainingTransitionBudget(): void
    {
        $timeout = new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'windowsPollCommandTimeoutSeconds',
        );

        self::assertSame(5.0, $timeout->invoke(null, 110.0, 100.0));
        self::assertSame(0.5, $timeout->invoke(null, 100.5, 100.0));
        self::assertNull($timeout->invoke(null, 100.09, 100.0));
        self::assertNull($timeout->invoke(null, 99.0, 100.0));

        $deletion = new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'waitForWindowsServiceDeletion',
        );
        self::assertSame(1, $deletion->getNumberOfParameters());
        self::assertSame(0, $deletion->getNumberOfRequiredParameters());
    }

    public function testWindowsServiceRemainsDisabledUntilAclSealingCompletes(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $configure = $this->sourceBetween(
            $source,
            'private function configureWindowsServiceDefinition',
            'private function enableWindowsServiceDefinition',
        );
        self::assertStringContainsString("'disabled'", $configure);
        self::assertStringNotContainsString("'auto'", $configure);
        $enableDefinition = $this->sourceBetween(
            $source,
            'private function enableWindowsServiceDefinition',
            'private function queryWindowsService',
        );
        self::assertStringContainsString("'auto'", $enableDefinition);

        $start = $this->sourceBetween(
            $source,
            'public function start(',
            'public function secureInstalledRuntimeSlot',
        );
        $restart = $this->sourceBetween(
            $source,
            'public function restart(',
            'public function restartControlPlane',
        );
        foreach ([$start, $restart] as $lifecycle) {
            $acl = \strpos($lifecycle, '$this->ensureServiceIdentityAndPermissions();');
            $enable = \strpos($lifecycle, '$this->enableWindowsServiceDefinition();');
            $startCommand = \strpos($lifecycle, "'sc.exe'), 'start'");
            self::assertIsInt($acl);
            self::assertIsInt($enable);
            self::assertIsInt($startCommand);
            self::assertLessThan($enable, $acl);
            self::assertLessThan($startCommand, $enable);
        }

        $refresh = $this->sourceBetween(
            $source,
            'public function refreshDefinition(',
            'public function start(',
        );
        self::assertStringNotContainsString(
            'configureWindowsServiceDefinition',
            $refresh,
        );
    }

    public function testWindowsServiceDefinitionSealsPreshutdownAndScmObjectDacl(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $configure = $this->sourceBetween(
            $source,
            'private function configureWindowsServiceDefinition(',
            'private function enableWindowsServiceDefinition(',
        );

        self::assertStringContainsString(
            '$this->configureWindowsServicePreshutdownTimeout();',
            $configure,
        );
        self::assertStringContainsString(
            '$this->configureWindowsServiceObjectDacl();',
            $configure,
        );
        self::assertLessThan(
            \strpos($configure, '$this->configureWindowsServiceObjectDacl();'),
            \strpos($configure, '$this->configureWindowsServicePreshutdownTimeout();'),
        );
        self::assertStringContainsString(
            'SERVICE_CONFIG_PRESHUTDOWN_INFO = 7',
            $source,
        );
        self::assertStringContainsString(
            'PRESHUTDOWN_TIMEOUT_MILLISECONDS = 330000',
            $source,
        );
        self::assertStringContainsString('ChangeServiceConfig2W', $source);
        self::assertStringContainsString('QueryServiceConfig2W', $source);
        self::assertStringContainsString(
            'SERVICE_CHANGE_CONFIG | SERVICE_QUERY_CONFIG',
            $source,
        );
        self::assertStringContainsString('SE_SERVICE = 5', $source);
        self::assertStringContainsString(
            'PROTECTED_DACL_SECURITY_INFORMATION = 0x80000000',
            $source,
        );
        self::assertStringContainsString(
            'WRITE_DAC | READ_CONTROL',
            $source,
        );
        self::assertStringContainsString('SetSecurityInfo(', $source);
        self::assertStringContainsString(
            'QueryServiceObjectSecurity(',
            $source,
        );
        self::assertStringContainsString('RawSecurityDescriptor', $source);
        self::assertStringContainsString(
            'DiscretionaryAclProtected',
            $source,
        );
        self::assertStringContainsString(
            'D:P(A;;0xF01FF;;;SY)(A;;0xF01FF;;;BA)',
            $source,
        );
    }

    public function testPosixServiceIdentityMustBeDedicatedAndNonLogin(): void
    {
        $validate = new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'posixServiceIdentityIsValid',
        );
        $darwinUser = [
            'name' => '_welinegateway',
            'uid' => 399,
            'gid' => 399,
            'dir' => '/var/empty',
            'shell' => '/usr/bin/false',
        ];
        $darwinGroup = ['name' => '_welinegateway', 'gid' => 399];
        self::assertTrue($validate->invoke(
            null,
            $darwinUser,
            $darwinGroup,
            'Darwin',
        ));
        self::assertFalse($validate->invoke(
            null,
            \array_replace($darwinUser, ['shell' => '/bin/zsh']),
            $darwinGroup,
            'Darwin',
        ));
        self::assertFalse($validate->invoke(
            null,
            $darwinUser,
            ['name' => '_welinegateway', 'gid' => 398],
            'Darwin',
        ));

        self::assertTrue($validate->invoke(
            null,
            [
                'name' => 'weline-gateway',
                'uid' => 998,
                'gid' => 998,
                'dir' => '/nonexistent',
                'shell' => '/usr/sbin/nologin',
            ],
            ['name' => 'weline-gateway', 'gid' => 998],
            'Linux',
        ));
        self::assertTrue($validate->invoke(
            null,
            [
                'name' => '_welinegateway_nginx',
                'uid' => 398,
                'gid' => 398,
                'dir' => '/var/empty',
                'shell' => '/usr/bin/false',
            ],
            ['name' => '_welinegateway_nginx', 'gid' => 398],
            'Darwin',
            'data-plane',
        ));
        self::assertTrue($validate->invoke(
            null,
            [
                'name' => 'weline-gateway-nginx',
                'uid' => 997,
                'gid' => 997,
                'dir' => '/nonexistent',
                'shell' => '/usr/sbin/nologin',
            ],
            ['name' => 'weline-gateway-nginx', 'gid' => 997],
            'Linux',
            'data-plane',
        ));
        self::assertFalse($validate->invoke(
            null,
            $darwinUser,
            $darwinGroup,
            'Darwin',
            'data-plane',
        ));
    }

    public function testDarwinSystemIdSelectionReservesUserAndGroupNamespaces(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $selection = $this->sourceBetween(
            $source,
            'private function availableDarwinSystemId',
            'private function secureRuntimeTree',
        );
        self::assertStringContainsString("['/Users', 'UniqueID']", $selection);
        self::assertStringContainsString(
            "['/Groups', 'PrimaryGroupID']",
            $selection,
        );
    }

    public function testDarwinIdentityCreationHasCompensatingRollback(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        self::assertStringContainsString(
            '$this->createDarwinServiceIdentity(',
            $source,
        );
        $creation = $this->sourceBetween(
            $source,
            'private function createDarwinServiceIdentity',
            'private function rollbackDarwinServiceIdentityCreation',
        );
        self::assertStringContainsString('catch (\Throwable $throwable)', $creation);
        self::assertStringContainsString(
            '$this->rollbackDarwinServiceIdentityCreation(',
            $creation,
        );
        $rollback = $this->sourceBetween(
            $source,
            'private function rollbackDarwinServiceIdentityCreation',
            'private function availableDarwinSystemId',
        );
        self::assertSame(2, \substr_count($rollback, "'-delete'"));
    }

    public function testPosixRebootstrapBackupSecurityProofIsNoOp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission proof is not used on Windows.');
        }
        $nonce = \str_repeat('a', 32);
        $backup = $this->createRebootstrapBackupFixture(
            $nonce,
            true,
            true,
        );
        $launcher = $backup . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'launcher';
        $state = $backup . DIRECTORY_SEPARATOR . 'derived'
            . DIRECTORY_SEPARATOR . 'state'
            . DIRECTORY_SEPARATOR . 'route.json';
        $before = [
            'root' => \fileperms($backup) & 0777,
            'launcher' => \fileperms($launcher) & 0777,
            'state' => \fileperms($state) & 0777,
            'contents' => (string)\file_get_contents($state),
        ];

        (new GatewayPlatformServiceInstaller($this->paths))
            ->secureRebootstrapBackup(
                $nonce,
                \hrtime(true) / 1_000_000_000 + 5.0,
            );

        self::assertSame($before, [
            'root' => \fileperms($backup) & 0777,
            'launcher' => \fileperms($launcher) & 0777,
            'state' => \fileperms($state) & 0777,
            'contents' => (string)\file_get_contents($state),
        ]);
    }

    public function testRebootstrapBackupSecurityProofRejectsSymlink(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $nonce = \str_repeat('b', 32);
        $backup = $this->createRebootstrapBackupFixture($nonce, true);
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside-acl-target';
        self::assertNotFalse(\file_put_contents($outside, "outside\n"));
        $link = $backup . DIRECTORY_SEPARATOR . 'derived'
            . DIRECTORY_SEPARATOR . 'state'
            . DIRECTORY_SEPARATOR . 'linked.json';
        if (!@\symlink($outside, $link)) {
            self::markTestSkipped('Filesystem does not permit a symlink fixture.');
        }

        $exception = $this->captureRuntimeException(
            function () use ($nonce): void {
                (new GatewayPlatformServiceInstaller($this->paths))
                    ->secureRebootstrapBackup($nonce);
            },
        );

        self::assertStringContainsString(
            'link or special',
            \strtolower($exception->getMessage()),
        );
        self::assertTrue(\is_link($link));
        self::assertSame("outside\n", \file_get_contents($outside));
    }

    public function testPosixRebootstrapBackupSecurityProofRejectsWritableLeaf(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission proof is not used on Windows.');
        }
        $nonce = \str_repeat('c', 32);
        $backup = $this->createRebootstrapBackupFixture($nonce, true);
        $state = $backup . DIRECTORY_SEPARATOR . 'derived'
            . DIRECTORY_SEPARATOR . 'state'
            . DIRECTORY_SEPARATOR . 'route.json';
        self::assertTrue(\chmod($state, 0620));

        $exception = $this->captureRuntimeException(
            function () use ($nonce): void {
                (new GatewayPlatformServiceInstaller($this->paths))
                    ->secureRebootstrapBackup($nonce);
            },
        );

        self::assertStringContainsString(
            'group/other writable',
            \strtolower($exception->getMessage()),
        );
        self::assertSame(0620, \fileperms($state) & 0777);
    }

    public function testRebootstrapBackupSecurityProofRejectsUnexpectedRoot(): void
    {
        $nonce = \str_repeat('d', 32);
        $backup = $this->createRebootstrapBackupFixture($nonce);
        $unexpected = $backup . DIRECTORY_SEPARATOR . 'project-state';
        self::assertTrue(\mkdir($unexpected, 0700));

        $exception = $this->captureRuntimeException(
            function () use ($nonce): void {
                (new GatewayPlatformServiceInstaller($this->paths))
                    ->secureRebootstrapBackup($nonce);
            },
        );

        self::assertStringContainsString(
            'unexpected acl root',
            \strtolower($exception->getMessage()),
        );
        self::assertDirectoryExists($unexpected);
    }

    public function testRebootstrapBackupSecurityProofHonorsExpiredDeadline(): void
    {
        $exception = $this->captureRuntimeException(
            function (): void {
                (new GatewayPlatformServiceInstaller($this->paths))
                    ->secureRebootstrapBackup(
                    \str_repeat('e', 32),
                    \hrtime(true) / 1_000_000_000 - 1.0,
                    );
            },
        );

        self::assertStringContainsString(
            'deadline was exhausted',
            \strtolower($exception->getMessage()),
        );
    }

    public function testWindowsRebootstrapBackupAclContractIsExactAndIdentityBound(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $entry = $this->sourceBetween(
            $source,
            'public function secureRebootstrapBackup(',
            'public function persistentStoppedProof(',
        );
        self::assertStringContainsString(
            'withWindowsRebootstrapBackupIdentityHandles',
            $entry,
        );
        self::assertStringContainsString("'NONE'", $entry);
        self::assertStringContainsString('recursive: false', $entry);
        self::assertStringContainsString('inheritChildren: false', $entry);
        self::assertStringContainsString(
            'REBOOTSTRAP_BACKUP_ACL_TOTAL_ENTRY_QUOTA = 65_536',
            $source,
        );
        $segmented = $this->sourceBetween(
            $source,
            'private function rebootstrapBackupAclTreeClosure(',
            'private function assertSameRebootstrapBackupAclTreeClosure(',
        );
        self::assertStringContainsString(
            '$segments = [\'.\' => [$root]]',
            $segmented,
        );
        self::assertStringContainsString(
            'GatewayBoundedTreeWalker::collect(',
            $segmented,
        );
        self::assertStringContainsString('$child,', $segmented);
        foreach ([
            "'derived'",
            "'new-derived'",
            "'slots'",
            "'bin'",
            "'trust'",
            "'platform'",
        ] as $root) {
            self::assertStringContainsString($root, $source);
        }
        foreach ([
            'CreateFileW',
            'GetFileInformationByHandle',
            '0x00000003',
            '0x02200000',
            'nFileIndexHigh',
            'nFileIndexLow',
            'GatewayBoundedTreeWalker::revalidate',
            'assertTraversalDeadline',
        ] as $identityFence) {
            self::assertStringContainsString($identityFence, $source);
        }
        $acl = $this->sourceBetween(
            $source,
            'private function applyWindowsAcl(',
            'private function windowsPowerShell()',
        );
        self::assertStringContainsString("'S-1-5-18'", $acl);
        self::assertStringContainsString("'S-1-5-32-544'", $acl);
        self::assertStringContainsString(
            '$acl.SetAccessRuleProtection($true, $false)',
            $acl,
        );
        self::assertStringContainsString(
            '$actualRules.Count -ne $expectedRules.Count',
            $acl,
        );
        self::assertStringContainsString(
            '$inheritChildren = $false',
            \str_replace(
                '__WLS_INHERIT_CHILDREN__',
                '$false',
                $acl,
            ),
        );
    }

    public function testWindowsDerivedRootAuthorityBindsTheGatewayServiceSid(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $rootMethods = $this->sourceBetween(
            $source,
            'public function captureRebootstrapDerivedRootAuthority(',
            'public function captureRebootstrapDerivedDescendantAuthority(',
        );
        foreach ([
            'GatewayWindowsHostRootAuthority::captureExactPathSddl(',
            'GatewayWindowsHostRootAuthority::canonicalizeSddl(',
            'GatewayWindowsHostRootAuthority::applyExactPathSddl(',
            '$expectedIdentity,',
        ] as $required) {
            self::assertStringContainsString($required, $rootMethods);
        }
        foreach ([
            'runCommand(',
            'windowsPowerShell(',
            'Set-Acl',
            'Get-Acl',
            'withRestorePrivilege(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $rootMethods);
        }

        $contracts = $this->sourceBetween(
            $source,
            'private function windowsDerivedCanonicalAuthorityContracts(',
            'public function assertRebootstrapDerivedDescendantPosixAclFree(',
        );
        foreach ([
            'S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626',
            "'0x120089'",
            "'0x1200a9'",
            "'0x1301bf'",
            "'GX'",
            "'GA'",
            "'GR'",
            "'GRGX'",
            "'D:P(A;;FA;;;SY)(A;;FA;;;BA)'",
            'GatewayWindowsHostRootAuthority::canonicalizeSddl(',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        self::assertStringContainsString(
            "self::WINDOWS_CONTROLLER_SERVICE_SID\n                        => ['RX', 'M', 'GA']",
            $contracts,
        );
        self::assertStringNotContainsString(
            'NT SERVICE\\weline-wls-gateway-v2',
            $contracts,
        );
    }

    public function testWindowsDerivedParentAuthorityUsesTheExactDaclContract(): void
    {
        $platformSource = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $allowList = $this->sourceBetween(
            $platformSource,
            'private function assertRebootstrapDerivedRootPath(',
            'private function securePackageTransactionTrustWithinDeadline(',
        );
        self::assertStringContainsString('$this->paths->home(),', $allowList);
        self::assertStringContainsString(
            '$this->paths->runtimeDir(),',
            $allowList,
        );

        $rootMethods = $this->sourceBetween(
            $platformSource,
            'public function captureRebootstrapDerivedRootAuthority(',
            'public function captureRebootstrapDerivedDescendantAuthority(',
        );
        foreach ([
            'GatewayWindowsHostRootAuthority::captureExactPathSddl(',
            'GatewayWindowsHostRootAuthority::canonicalizeSddl(',
            'GatewayWindowsHostRootAuthority::applyExactPathSddl(',
            '$expectedIdentity,',
            'not its exact canonical profile',
        ] as $required) {
            self::assertStringContainsString($required, $rootMethods);
        }
        foreach ([
            'Set-Acl',
            'Get-Acl',
            'runCommand(',
            'withRestorePrivilege(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $rootMethods);
        }

        $rightsMap = $this->sourceBetween(
            $platformSource,
            'private function rebootstrapDerivedRootWindowsServiceRights(',
            'private function securePackageTransactionTrustWithinDeadline(',
        );
        self::assertStringContainsString('DERIVED_AUTHORITY_HOME', $rightsMap);
        self::assertStringContainsString(
            'DERIVED_AUTHORITY_SNAPSHOTS_V2',
            $rightsMap,
        );
        self::assertStringContainsString("=> 'RX'", $rightsMap);
        self::assertStringContainsString("default => 'M'", $rightsMap);

        $posixRoot = $this->sourceBetween(
            $platformSource,
            'public function assertRebootstrapDerivedRootPosixAuthority(',
            'private function rebootstrapDerivedRootWindowsServiceRights(',
        );
        foreach ([
            '$expectedDevice',
            '$expectedInode',
            '$expectedType',
            '$expectedNlink',
            "'/proc/self/fd/'",
            'posixAclDescriptorStatus(',
            'struct wls_darwin_stat',
            'int fstat64(int fd,',
            '@\\lstat($root)',
            'path identity changed after its descriptor closed',
            '$verificationFailure',
            '$closeFailure',
            '$verificationFailure,',
        ] as $required) {
            self::assertStringContainsString($required, $posixRoot);
        }

        $managerSource = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $capture = $this->sourceBetween(
            $managerSource,
            'private function captureRebootstrapDerivedRootProof(',
            'private function assertRebootstrapDerivedRootProofContract(',
        );
        self::assertStringContainsString(
            '->captureRebootstrapDerivedRootAuthority(',
            $capture,
        );
        self::assertStringContainsString('$parentIdentity,', $capture);
        self::assertStringContainsString('$identity,', $capture);
        self::assertStringContainsString("'parent_authority_sha256'", $capture);
        self::assertStringContainsString("'parent_authority_policy'", $capture);
        self::assertStringContainsString("'parent_windows_sddl_b64'", $capture);

        $recreate = $this->sourceBetween(
            $managerSource,
            'private function createAndSealRebootstrapDerivedRoot(',
            'private function stashOldRebootstrapGeneration(',
        );
        self::assertStringContainsString(
            '$currentIdentity = GatewayBoundedTreeWalker::identity($root);',
            $recreate,
        );
        self::assertStringContainsString('$currentIdentity,', $recreate);
    }

    public function testWindowsDerivedDescendantsUseExactCanonicalAclVariants(): void
    {
        $platformSource = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $descendants = $this->sourceBetween(
            $platformSource,
            'public function captureRebootstrapDerivedDescendantAuthority(',
            'private function assertRebootstrapDerivedRootPath(',
        );
        foreach ([
            'captureRebootstrapDerivedDescendantAuthority',
            'restoreRebootstrapDerivedDescendantAuthority',
            'assertRebootstrapDerivedBackupDescendantAuthority',
            'withWindowsRebootstrapBackupIdentityHandles',
            'GatewayWindowsHostRootAuthority::captureExactPathSddl(',
            'GatewayWindowsHostRootAuthority::applyExactPathSddl(',
            'GatewayWindowsHostRootAuthority::canonicalizeSddl(',
            'windowsDerivedCanonicalAuthorityContracts',
            '$expectedIdentity,',
            "'GRGX'",
            "'GR'",
            "'GA'",
            "'D:P(A;;FA;;;SY)(A;;FA;;;BA)'",
        ] as $required) {
            self::assertStringContainsString($required, $descendants);
        }
        foreach ([
            'Set-Acl',
            'Get-Acl',
            'withRestorePrivilege(',
            'runCommand(',
            'windowsPowerShell(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $descendants);
        }
        self::assertStringContainsString(
            "'service_sid' => ''",
            $descendants,
        );

        $handles = $this->sourceBetween(
            $platformSource,
            'private function withWindowsRebootstrapBackupIdentityHandles(',
            'private function openWindowsRebootstrapBackupObject(',
        );
        self::assertStringContainsString(
            'for ($index = \\count($opened) - 1; $index >= 0; --$index)',
            $handles,
        );
        self::assertStringContainsString('catch (\\Throwable $closeFailure)', $handles);
        self::assertStringContainsString('$failure,', $handles);

        $openAndClose = $this->sourceBetween(
            $platformSource,
            'private function openWindowsRebootstrapBackupObject(',
            'private function windowsRebootstrapBackupKernel32(',
        );
        self::assertStringContainsString(
            '$this->closeWindowsRebootstrapBackupObject([',
            $openAndClose,
        );
        self::assertStringContainsString(
            '$closed = (int)$object[\'ffi\']->CloseHandle(',
            $openAndClose,
        );
        self::assertStringContainsString('$closed === 0', $openAndClose);
        self::assertStringNotContainsString(
            'catch (\\Throwable) {\n        }',
            $openAndClose,
        );

        $managerSource = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $capture = $this->sourceBetween(
            $managerSource,
            'private function captureRebootstrapDerivedClosure(',
            'private function assertRebootstrapDerivedClosureAt(',
        );
        self::assertStringContainsString(
            '->captureRebootstrapDerivedDescendantAuthority(',
            $capture,
        );
        self::assertStringContainsString("'acl_profile'", $capture);
        self::assertStringContainsString("'owner_sid'", $capture);
        self::assertStringContainsString("'sddl_b64'", $capture);
        self::assertStringContainsString("'acl_sha256'", $capture);
    }

    public function testWindowsDerivedSddlMasksRoundTripInPowerShell(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows SDDL runtime regression.');
        }
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $powerShell = new \ReflectionMethod($installer, 'windowsPowerShell');
        $encode = new \ReflectionMethod(
            $installer,
            'encodeWindowsPowerShell',
        );
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$service = 'S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626'
foreach ($mask in @('0x120089', '0x1200a9', '0x1301bf', 'GX', 'GA', 'GR', 'GRGX')) {
    $sddl = "O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;$mask;;;$service)"
    $first = [Security.AccessControl.DirectorySecurity]::new()
    $first.SetSecurityDescriptorSddlForm($sddl)
    $canonical = $first.GetSecurityDescriptorSddlForm(
        [Security.AccessControl.AccessControlSections]::Access -bor
        [Security.AccessControl.AccessControlSections]::Owner
    )
    $second = [Security.AccessControl.DirectorySecurity]::new()
    $second.SetSecurityDescriptorSddlForm($canonical)
    if ($canonical -ne $second.GetSecurityDescriptorSddlForm(
            [Security.AccessControl.AccessControlSections]::Access -bor
            [Security.AccessControl.AccessControlSections]::Owner
        )) {
        throw "SDDL mask did not round-trip: $mask"
    }
}
[Console]::Out.Write('ok')
POWERSHELL;
        $result = GatewayBoundedCommandRunner::run([
            (string)$powerShell->invoke($installer),
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-EncodedCommand',
            (string)$encode->invoke($installer, $script),
        ], 30.0);
        self::assertSame(0, $result['code'], $result['output']);
        self::assertSame('ok', $result['output']);
    }

    public function testPosixDerivedDescendantAclFreeProofUsesNoFollowFd(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\class_exists(\FFI::class)) {
            self::markTestSkipped('POSIX FFI ACL regression.');
        }
        $this->paths->ensureDirectories();
        $directory = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'derived-acl-proof';
        self::assertTrue(\mkdir($directory, 0700));
        $file = $directory . DIRECTORY_SEPARATOR . 'state.json';
        self::assertNotFalse(\file_put_contents($file, "{}\n"));
        self::assertTrue(\chmod($file, 0600));
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->assertRebootstrapDerivedDescendantPosixAclFree(
            $directory,
            true,
        );
        $installer->assertRebootstrapDerivedDescendantPosixAclFree(
            $file,
            false,
        );

        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $proof = $this->sourceBetween(
            $source,
            'public function assertRebootstrapDerivedDescendantPosixAclFree(',
            'private function assertRebootstrapDerivedRootPath(',
        );
        self::assertStringContainsString('system.posix_acl_access', $proof);
        self::assertStringContainsString('system.posix_acl_default', $proof);
        self::assertStringContainsString('acl_get_fd_np', $proof);
        self::assertStringContainsString('0x20000', $proof);
        self::assertStringContainsString('0x100', $proof);
        self::assertStringContainsString("'/proc/self/fd/'", $proof);
        self::assertStringContainsString('posixAclDescriptorStatus(', $proof);
        self::assertStringContainsString('struct wls_darwin_stat', $proof);
        self::assertStringContainsString('int fstat64(int fd,', $proof);
        self::assertStringContainsString('$verificationFailure', $proof);
        self::assertStringContainsString('$closeFailure', $proof);
        self::assertStringContainsString(
            '$verificationFailure,',
            $proof,
        );
        self::assertStringContainsString(
            "(string)\$expected['device']",
            $proof,
        );
        self::assertStringContainsString(
            "(string)\$expected['inode']",
            $proof,
        );

        $managerSource = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $capture = $this->sourceBetween(
            $managerSource,
            'private function captureRebootstrapDerivedClosure(',
            'private function assertRebootstrapDerivedClosureAt(',
        );
        self::assertStringContainsString(
            '->assertRebootstrapDerivedDescendantPosixAclFree(',
            $capture,
        );
    }

    public function testPosixDerivedRootAuthorityRejectsAnUntrustedOwner(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX authority regression.');
        }
        $this->paths->ensureDirectories();
        $home = \lstat($this->paths->home());
        self::assertIsArray($home);
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installer->assertRebootstrapDerivedRootPosixAuthority(
            $this->paths->home(),
            (int)$home['uid'],
            (int)$home['gid'],
            (string)$home['dev'],
            (string)$home['ino'],
            ((int)$home['mode']) & 0170000,
            (int)$home['nlink'],
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escaped its isolated owner');
        $installer->assertRebootstrapDerivedRootPosixAuthority(
            $this->paths->home(),
            (int)$home['uid'] + 1,
            (int)$home['gid'],
            (string)$home['dev'],
            (string)$home['ino'],
            ((int)$home['mode']) & 0170000,
            (int)$home['nlink'],
        );
    }

    public function testDerivedSnapshotNamespacesHaveDistinctFixedAuthorityProfiles(): void
    {
        $this->paths->ensureDirectories();
        $installer = new GatewayPlatformServiceInstaller($this->paths);

        self::assertSame(
            GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_SNAPSHOTS_V2,
            $installer->rebootstrapDerivedRootAuthorityProfile(
                $this->paths->sealedSnapshotsDir(),
            ),
        );
        self::assertSame(
            GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2,
            $installer->rebootstrapDerivedRootAuthorityProfile(
                $this->paths->snapshotCandidatesDir(),
            ),
        );

        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        self::assertStringContainsString(
            "'S-1-5-80-3611316956-1833621424-61377994-3153356469-2496947245'",
            $source,
        );
        self::assertStringContainsString("'rights' => 'GX'", $source);
        self::assertStringContainsString("'rights' => 'GA'", $source);
        $permissions = $this->sourceBetween(
            $source,
            'private function ensureServiceIdentityAndPermissions()',
            'private function assertPosixServiceTreeSafe(',
        );
        self::assertStringContainsString(
            'GatewayWindowsHostRootAuthority::ensureBootstrapDirectories([',
            $permissions,
        );
        self::assertStringContainsString(
            '$this->paths->sealedSnapshotsDir(),',
            $permissions,
        );
        self::assertStringContainsString(
            '$this->paths->snapshotCandidatesDir(),',
            $permissions,
        );
    }

    public function testWindowsCollectingBackupAclRequiresAuthenticatedTerminalReceipt(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $inventory = $this->sourceBetween(
            $source,
            'private function windowsRebootstrapWorkspaceInventory()',
            'private function windowsRebootstrapNamespaceInventory(',
        );
        self::assertStringContainsString('.collecting-', $inventory);
        self::assertStringContainsString('candidate_locks', $inventory);
        self::assertStringContainsString('.install\\.lock', $inventory);
        self::assertStringContainsString(
            'assertWindowsCollectingRebootstrapBackupReceipt',
            $inventory,
        );
        $binding = $this->sourceBetween(
            $source,
            'private function assertWindowsCollectingRebootstrapBackupReceipt(',
            'private function windowsRebootstrapNamespaceInventory(',
        );
        foreach ([
            'authenticatedTerminalRebootstrapReceiptForPlatformSeal',
            'backup_collection_nonce',
            'backup_collection_device',
            'backup_collection_inode',
            'assertRebootstrapBackupObjectUnchanged',
        ] as $required) {
            self::assertStringContainsString($required, $binding);
        }
        $candidateLocks = $this->sourceBetween(
            $source,
            'private function windowsRebootstrapCandidateWorkspaceInventory(',
            'private function windowsRebootstrapNamespaceInventory(',
        );
        foreach ([
            '.install\\.lock',
            'candidate_locks',
            'assertWindowsRebootstrapCandidateLockRecord',
            "'NONE'",
            'inheritChildren: false',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        self::assertStringContainsString("['size'] ?? -1) !== 0", $candidateLocks);
        self::assertStringContainsString("['nlink'] ?? 0) !== 1", $candidateLocks);
        $manager = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $receiptVerifier = $this->sourceBetween(
            $manager,
            'public function authenticatedTerminalRebootstrapReceiptForPlatformSeal(',
            "/**\n     * @return array{",
        );
        self::assertStringContainsString("'COMMITTED'", $receiptVerifier);
        self::assertStringContainsString("'ROLLED_BACK'", $receiptVerifier);
        self::assertStringContainsString("'RETAINED'", $receiptVerifier);
        self::assertStringContainsString("'COLLECTED'", $receiptVerifier);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startOffset = \strpos($source, $start);
        self::assertIsInt($startOffset);
        $endOffset = \strpos($source, $end, $startOffset + \strlen($start));
        self::assertIsInt($endOffset);
        return \substr($source, $startOffset, $endOffset - $startOffset);
    }

    private function captureRuntimeException(\Closure $operation): \RuntimeException
    {
        $exception = null;
        try {
            $operation();
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }
        self::assertInstanceOf(\RuntimeException::class, $exception);
        return $exception;
    }

    private function createRebootstrapBackupFixture(
        string $nonce,
        bool $withDerived = false,
        bool $withNewDerived = false,
    ): string {
        $this->paths->ensureDirectories();
        $backup = $this->paths->rebootstrapBackupDir($nonce);
        self::assertTrue(\mkdir($backup, 0700));
        foreach (['bin', 'platform', 'slots', 'trust'] as $leaf) {
            self::assertTrue(\mkdir(
                $backup . DIRECTORY_SEPARATOR . $leaf,
                0700,
            ));
        }
        $launcher = $backup . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'launcher';
        self::assertNotFalse(\file_put_contents($launcher, "launcher\n"));
        self::assertTrue(\chmod($launcher, 0600));
        $active = $backup . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'active-slot';
        self::assertNotFalse(\file_put_contents($active, "A\n"));
        self::assertTrue(\chmod($active, 0600));
        if ($withDerived) {
            $derived = $backup . DIRECTORY_SEPARATOR . 'derived';
            self::assertTrue(\mkdir($derived, 0700));
            self::assertTrue(\mkdir(
                $derived . DIRECTORY_SEPARATOR . 'state',
                0700,
            ));
            $state = $derived . DIRECTORY_SEPARATOR . 'state'
                . DIRECTORY_SEPARATOR . 'route.json';
            self::assertNotFalse(\file_put_contents($state, "{}\n"));
            self::assertTrue(\chmod($state, 0600));
            $manifest = $backup . DIRECTORY_SEPARATOR
                . 'derived-state.manifest.json';
            self::assertNotFalse(\file_put_contents($manifest, "{}\n"));
            self::assertTrue(\chmod($manifest, 0600));
        }
        if ($withNewDerived) {
            $newDerived = $backup . DIRECTORY_SEPARATOR . 'new-derived';
            self::assertTrue(\mkdir($newDerived, 0700));
            $quarantine = $newDerived . DIRECTORY_SEPARATOR . 'state.json';
            self::assertNotFalse(\file_put_contents($quarantine, "{}\n"));
            self::assertTrue(\chmod($quarantine, 0600));
        }
        return $backup;
    }

    private function definitionTransactionPath(): string
    {
        return $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'platform-definition.transaction';
    }

    private function initialMetadata(string $profile): string
    {
        return \json_encode([
            'schema_version' => 1,
            'kind' => 'test-session',
            'definition' => $this->paths->serviceDefinitionFile(),
            'profile' => $profile,
            'test_mode' => true,
            'installed_at' => '2026-08-06T00:00:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
    }

    private function metadataWithProfile(string $metadata, string $profile): string
    {
        $decoded = \json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['profile'] = $profile;
        return \json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    private function writeDefinitionTransaction(
        string $operation,
        string $phase,
        ?string $fromProfile,
        string $toProfile,
        ?string $oldDefinition,
        ?string $oldMetadata,
        string $newDefinition,
        string $newMetadata,
    ): string {
        $path = $this->definitionTransactionPath();
        self::assertNotFalse(\file_put_contents(
            $path,
            $this->definitionTransactionRaw(
                $operation,
                $phase,
                $fromProfile,
                $toProfile,
                $oldDefinition,
                $oldMetadata,
                $newDefinition,
                $newMetadata,
            ),
        ));
        self::assertTrue(\chmod($path, 0600));
        return $path;
    }

    private function definitionTransactionRaw(
        string $operation,
        string $phase,
        ?string $fromProfile,
        string $toProfile,
        ?string $oldDefinition,
        ?string $oldMetadata,
        string $newDefinition,
        string $newMetadata,
    ): string {
        return \json_encode([
            'schema_version' => 1,
            'operation' => $operation,
            'phase' => $phase,
            'nonce' => \str_repeat('1', 32),
            'from_profile' => $fromProfile,
            'to_profile' => $toProfile,
            'old_definition_sha256' => $oldDefinition === null
                ? null
                : \hash('sha256', $oldDefinition),
            'old_metadata_sha256' => $oldMetadata === null
                ? null
                : \hash('sha256', $oldMetadata),
            'new_definition_sha256' => \hash('sha256', $newDefinition),
            'new_metadata_sha256' => \hash('sha256', $newMetadata),
            'new_definition_base64' => \base64_encode($newDefinition),
            'new_metadata_base64' => \base64_encode($newMetadata),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
    }

    private function removeTree(string $directory): void
    {
        if ($directory === '' || (!\file_exists($directory) && !\is_link($directory))) {
            return;
        }
        if (\is_link($directory) || !\is_dir($directory)) {
            @\unlink($directory);
            return;
        }
        $entries = \scandir($directory);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($directory);
    }
}
