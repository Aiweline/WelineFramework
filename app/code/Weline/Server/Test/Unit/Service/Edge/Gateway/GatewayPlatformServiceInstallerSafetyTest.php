<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
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
            'public function installDefinition(string $profile)',
            'public function refreshDefinition(string $profile)',
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
            'public function start(string $kind)',
            'public function secureInstalledRuntimeSlot',
        );
        $restart = $this->sourceBetween(
            $source,
            'public function restart(string $kind)',
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
            'public function refreshDefinition(string $profile)',
            'public function start(string $kind)',
        );
        self::assertStringNotContainsString(
            'configureWindowsServiceDefinition',
            $refresh,
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
            '$this->createDarwinServiceIdentity($account, $group);',
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
