<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxInstaller;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPortAllocator;
use Weline\Server\Service\Edge\Nginx\ManagedNginxProcessManager;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class ManagedNginxServiceAtomicRecoveryTest extends TestCase
{
    private string $root = '';
    private ManagedNginxPaths $paths;
    private ManagedNginxService $service;
    private string|false $previousLocalAppData = false;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-managed-nginx-atomic-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonicalRoot = \realpath($this->root);
        self::assertIsString($canonicalRoot);
        $this->root = $canonicalRoot;
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->previousLocalAppData = \getenv('LOCALAPPDATA');
            self::assertTrue(\putenv('LOCALAPPDATA=' . $this->root));
        }
        $this->paths = new ManagedNginxPaths($this->root, [
            'managed' => true,
            'install_root' => 'nginx-install',
            'runtime_root' => 'nginx-runtime',
        ]);
        $this->paths->ensureRuntimeDirectories();
        $this->service = new ManagedNginxService(
            $this->paths,
            new ManagedNginxInstaller($this->paths),
            new ManagedNginxConfigWriter($this->paths),
            new ManagedNginxProcessManager($this->paths),
            new ManagedNginxPortAllocator($this->paths),
        );
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

    public function testLifecycleLockCollectsValidatedOwnerBackupBeforeOwnerRemoval(): void
    {
        $owner = $this->minimalOwner();
        $ownerFile = $this->paths->ownerFile();
        self::assertNotFalse(\file_put_contents($ownerFile, \json_encode(
            $owner,
            JSON_THROW_ON_ERROR,
        )));
        $backup = $ownerFile . '.wls-backup-' . \str_repeat('d', 16);
        self::assertNotFalse(\file_put_contents($backup, '{"previous":true}'));

        $result = $this->service->stop();

        self::assertTrue($result['ok'], $result['message']);
        self::assertFileDoesNotExist($backup);
        self::assertFileDoesNotExist($ownerFile);
    }

    public function testLifecycleLockRejectsSymbolicLinkInsteadOfLockingItsTarget(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symbolic-link lock boundary.');
        }
        $target = $this->root . DIRECTORY_SEPARATOR . 'external-lock-target';
        self::assertSame(14, \file_put_contents($target, 'outside-target'));
        self::assertTrue(\symlink($target, $this->paths->lifecycleLockFile()));

        $result = $this->service->stop();

        self::assertFalse($result['ok']);
        self::assertStringContainsString(
            'regular non-linked file',
            $result['message'],
        );
        self::assertSame('outside-target', \file_get_contents($target));
    }

    public function testLifecycleLockRejectsHardLinkInsteadOfSharingAnotherInode(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX hard-link lock boundary.');
        }
        $target = $this->root . DIRECTORY_SEPARATOR . 'external-hard-link-target';
        self::assertSame(14, \file_put_contents($target, 'outside-target'));
        self::assertTrue(\link($target, $this->paths->lifecycleLockFile()));

        $result = $this->service->stop();

        self::assertFalse($result['ok']);
        self::assertStringContainsString(
            'regular non-linked file',
            $result['message'],
        );
        self::assertSame('outside-target', \file_get_contents($target));
    }

    public function testLifecycleLockContentionHonorsTheAbsoluteDeadline(): void
    {
        $lockFile = $this->paths->lifecycleLockFile();
        self::assertSame(0, \file_put_contents($lockFile, ''));
        $holder = \fopen($lockFile, 'r+b');
        self::assertIsResource($holder);
        self::assertTrue(\flock($holder, LOCK_EX | LOCK_NB));
        try {
            $started = \hrtime(true) / 1_000_000_000;
            $result = $this->service->stop($started + 0.05);
            $elapsed = (\hrtime(true) / 1_000_000_000) - $started;
        } finally {
            self::assertTrue(\flock($holder, LOCK_UN));
            self::assertTrue(\fclose($holder));
        }

        self::assertSame([
            'ok' => false,
            'message' => 'managed nginx lifecycle lock timed out',
        ], $result);
        self::assertLessThan(1.0, $elapsed);
    }

    public function testProcessIdentityLockSharesTheOuterLifecycleDeadline(): void
    {
        $lockFile = $this->paths->runDir() . DIRECTORY_SEPARATOR
            . 'nginx.process-identity.json.lock';
        self::assertSame(0, \file_put_contents($lockFile, ''));
        $holder = \fopen($lockFile, 'r+b');
        self::assertIsResource($holder);
        self::assertTrue(\flock($holder, LOCK_EX | LOCK_NB));
        try {
            $started = \hrtime(true) / 1_000_000_000;
            $result = $this->service->stop($started + 0.08);
            $elapsed = (\hrtime(true) / 1_000_000_000) - $started;
        } finally {
            self::assertTrue(\flock($holder, LOCK_UN));
            self::assertTrue(\fclose($holder));
        }

        self::assertFalse($result['ok']);
        self::assertStringContainsString(
            'process identity',
            \strtolower((string)$result['message']),
        );
        self::assertStringContainsString(
            'lifecycle deadline',
            \strtolower((string)$result['message']),
        );
        self::assertLessThan(1.0, $elapsed);
    }

    public function testLifecycleLockPreservesOwnerBackupWhenPairedTargetIsInvalidOrMissing(): void
    {
        $ownerFile = $this->paths->ownerFile();
        $backup = $ownerFile . '.wls-backup-' . \str_repeat('e', 16);
        self::assertSame(8, \file_put_contents($ownerFile, '{broken:'));
        self::assertSame(15, \file_put_contents($backup, '{"owner":"old"}'));

        $invalid = $this->service->stop();

        self::assertFalse($invalid['ok']);
        self::assertFileExists($ownerFile);
        self::assertFileExists($backup);

        self::assertTrue(\unlink($ownerFile));
        $missing = $this->service->stop();
        self::assertFalse($missing['ok']);
        self::assertFileDoesNotExist($ownerFile);
        self::assertFileExists($backup);
    }

    public function testInvalidOwnerIntentPreservesEarlierOwnerBackupEvidence(): void
    {
        $ownerFile = $this->paths->ownerFile();
        $intentFile = $this->paths->ownerIntentFile();
        self::assertNotFalse(\file_put_contents(
            $ownerFile,
            \json_encode($this->minimalOwner(), JSON_THROW_ON_ERROR),
        ));
        self::assertSame(8, \file_put_contents($intentFile, '{broken:'));
        $ownerBackup = $ownerFile . '.wls-backup-' . \str_repeat('1', 16);
        $intentBackup = $intentFile . '.wls-backup-' . \str_repeat('2', 16);
        self::assertSame(15, \file_put_contents($ownerBackup, '{"owner":"old"}'));
        self::assertSame(16, \file_put_contents($intentBackup, '{"intent":"old"}'));

        $result = $this->service->stop();

        self::assertFalse($result['ok']);
        self::assertFileExists($ownerBackup);
        self::assertFileExists($intentBackup);
    }

    public function testOwnerIntentFinalizationCollectsSameTransactionBackup(): void
    {
        $config = 'events {} http {}';
        $intent = [
            ...$this->minimalOwner(),
            'transaction_id' => \str_repeat('1', 32),
            'config_sha256' => \hash('sha256', $config),
        ];
        $intentFile = $this->paths->ownerIntentFile();
        self::assertSame(\strlen($config), \file_put_contents(
            $this->paths->confFile(),
            $config,
        ));
        $this->writeOwnerFixture($this->paths->ownerFile(), $intent);
        self::assertNotFalse(\file_put_contents($intentFile, \json_encode(
            $intent,
            JSON_THROW_ON_ERROR,
        )));
        $backup = $intentFile . '.wls-backup-' . \str_repeat('f', 16);
        self::assertNotFalse(\file_put_contents($backup, '{"previous":true}'));

        $finalize = new \ReflectionMethod($this->service, 'finalizeOwnerIntent');
        $finalize->invoke($this->service, $intent);

        self::assertFileDoesNotExist($intentFile);
        self::assertFileDoesNotExist($backup);
    }

    public function testOwnerCommitAcceptsExactAfterImageWhenDirectorySyncReportsFailure(): void
    {
        $config = 'events {} http { server { listen 19506; } }';
        self::assertSame(\strlen($config), \file_put_contents($this->paths->confFile(), $config));
        $intent = [
            ...$this->minimalOwner(),
            'transaction_id' => \str_repeat('a', 32),
            'config_generation' => \str_repeat('b', 32),
            'config_sha256' => \hash('sha256', $config),
            'config_rollback_expected' => false,
            'previous_config_sha256' => '',
            'owner_rollback_expected' => false,
            'previous_owner_sha256' => '',
        ];
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $intent);
        $readOwnerFile = new \ReflectionMethod($this->service, 'readOwnerFile');
        $normalized = $readOwnerFile->invoke($this->service, $this->paths->ownerIntentFile());
        self::assertIsArray($normalized);

        $this->withPostRenameSyncFailure(
            $this->paths->ownerFile(),
            function () use ($normalized): void {
                $commit = new \ReflectionMethod($this->service, 'commitOwnerIntent');
                $commit->invoke($this->service, $normalized);
            },
        );

        self::assertFileExists($this->paths->ownerFile());
        self::assertSame(
            $intent['config_sha256'],
            (string)(\json_decode(
                (string)\file_get_contents($this->paths->ownerFile()),
                true,
                64,
                JSON_THROW_ON_ERROR,
            )['config_sha256'] ?? ''),
        );
    }

    public function testInvalidNginxConfigKeepsActiveBackupUnderLifecycleLock(): void
    {
        $config = $this->paths->confFile();
        self::assertSame(16, \file_put_contents($config, 'not nginx config'));
        $backup = $config . '.wls-backup-' . \str_repeat('a', 16);
        self::assertSame(18, \file_put_contents($backup, 'previous config {}'));

        $result = $this->service->stop();

        self::assertFalse($result['ok']);
        self::assertFileExists($config);
        self::assertFileExists($backup);
    }

    public function testOwnerRecoveryCollectsExactAtomicTemporariesAfterCompleteValidation(): void
    {
        $owner = $this->minimalOwner();
        $intent = [
            ...$owner,
            'transaction_id' => \str_repeat('1', 32),
            'config_sha256' => \str_repeat('2', 64),
        ];
        $this->writeOwnerFixture($this->paths->ownerFile(), $owner);
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $intent);
        $ownerTemporary = $this->paths->ownerFile()
            . '.tmp-' . \str_repeat('a', 24);
        $intentTemporary = $this->paths->ownerIntentFile()
            . '.tmp-' . \str_repeat('b', 24);
        $foreign = \dirname($this->paths->ownerFile())
            . DIRECTORY_SEPARATOR . 'foreign-owner.json.tmp-' . \str_repeat('c', 24);
        self::assertSame(0, \file_put_contents($ownerTemporary, ''));
        self::assertSame(0, \file_put_contents($intentTemporary, ''));
        self::assertSame(7, \file_put_contents($foreign, 'foreign'));

        $this->cleanupOwnerAtomicWriteRecoveryArtifacts();

        self::assertFileDoesNotExist($ownerTemporary);
        self::assertFileDoesNotExist($intentTemporary);
        self::assertSame('foreign', \file_get_contents($foreign));
    }

    public function testInvalidPairedOwnerTargetPreservesEveryAtomicTemporary(): void
    {
        $this->writeOwnerFixture($this->paths->ownerFile(), $this->minimalOwner());
        self::assertSame(8, \file_put_contents($this->paths->ownerIntentFile(), '{broken:'));
        $ownerTemporary = $this->paths->ownerFile()
            . '.tmp-' . \str_repeat('d', 24);
        $intentTemporary = $this->paths->ownerIntentFile()
            . '.tmp-' . \str_repeat('e', 24);
        self::assertSame(0, \file_put_contents($ownerTemporary, ''));
        self::assertSame(0, \file_put_contents($intentTemporary, ''));

        try {
            $this->cleanupOwnerAtomicWriteRecoveryArtifacts();
            self::fail('Every atomic temporary must survive an invalid paired owner target.');
        } catch (\Throwable) {
        }

        self::assertFileExists($ownerTemporary);
        self::assertFileExists($intentTemporary);
        self::assertSame('{broken:', \file_get_contents($this->paths->ownerIntentFile()));
    }

    public function testMalformedOwnerAtomicTemporaryPreservesTheCompleteRecoverySet(): void
    {
        $this->writeOwnerFixture($this->paths->ownerFile(), $this->minimalOwner());
        $valid = $this->paths->ownerFile()
            . '.tmp-' . \str_repeat('f', 24);
        $malformed = $this->paths->ownerFile() . '.tmp-malformed';
        self::assertSame(0, \file_put_contents($valid, ''));
        self::assertSame(9, \file_put_contents($malformed, 'malformed'));

        try {
            $this->cleanupOwnerAtomicWriteRecoveryArtifacts();
            self::fail('A malformed reserved owner temporary must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('reserved leaf is malformed', $exception->getMessage());
        }

        self::assertFileExists($valid);
        self::assertFileExists($malformed);
    }

    public function testCommittedFirstPublicationFinalizesWithoutRollbackArtifact(): void
    {
        $config = 'events {} http { server { listen 19503; } }';
        self::assertSame(\strlen($config), \file_put_contents($this->paths->confFile(), $config));
        $transactionId = \str_repeat('3', 32);
        $owner = [
            ...$this->minimalOwner(),
            'transaction_id' => $transactionId,
            'config_generation' => \str_repeat('4', 32),
            'config_sha256' => \hash('sha256', $config),
            'config_rollback_expected' => false,
        ];
        $this->writeOwnerFixture($this->paths->ownerFile(), $owner);
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $owner);

        $this->recoverOwnerPublication();

        self::assertFileDoesNotExist($this->paths->ownerIntentFile());
        self::assertSame($config, \file_get_contents($this->paths->confFile()));
        self::assertFileExists($this->paths->ownerFile());
    }

    public function testCommittedPublicationCollectsResolvedRollbackTemporary(): void
    {
        $config = 'events {} http { server { listen 19504; } }';
        self::assertSame(\strlen($config), \file_put_contents($this->paths->confFile(), $config));
        $transactionId = \str_repeat('5', 32);
        $owner = [
            ...$this->minimalOwner(),
            'transaction_id' => $transactionId,
            'config_generation' => \str_repeat('6', 32),
            'config_sha256' => \hash('sha256', $config),
            'config_rollback_expected' => true,
        ];
        $this->writeOwnerFixture($this->paths->ownerFile(), $owner);
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $owner);
        $rollback = (new ManagedNginxConfigWriter($this->paths))
            ->rollbackPathForTransaction($transactionId);
        $temporary = $rollback . '.tmp-' . \str_repeat('f', 24);
        self::assertSame(0, \file_put_contents($temporary, ''));

        $this->recoverOwnerPublication();

        self::assertFileDoesNotExist($temporary);
        self::assertFileDoesNotExist($this->paths->ownerIntentFile());
        self::assertSame($config, \file_get_contents($this->paths->confFile()));
    }

    public function testMissingRollbackPreservesIntentWhenCommittedConfigCannotBeProven(): void
    {
        $active = 'events {} http { server { listen 19505; } }';
        self::assertSame(\strlen($active), \file_put_contents($this->paths->confFile(), $active));
        $committed = [
            ...$this->minimalOwner(),
            'transaction_id' => \str_repeat('7', 32),
            'config_sha256' => \hash('sha256', 'different committed config'),
        ];
        $intent = [
            ...$this->minimalOwner(),
            'transaction_id' => \str_repeat('8', 32),
            'config_generation' => \str_repeat('9', 32),
            'config_sha256' => \hash('sha256', 'uncommitted config'),
            'config_rollback_expected' => true,
        ];
        $this->writeOwnerFixture($this->paths->ownerFile(), $committed);
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $intent);
        $rollback = (new ManagedNginxConfigWriter($this->paths))
            ->rollbackPathForTransaction((string)$intent['transaction_id']);
        $temporary = $rollback . '.tmp-' . \str_repeat('a', 24);
        self::assertSame(0, \file_put_contents($temporary, ''));

        try {
            $this->recoverOwnerPublication();
            self::fail('Recovery must retain evidence when the committed disk config is unproven.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'committed config cannot be proven',
                $exception->getMessage(),
            );
        }

        self::assertFileExists($this->paths->ownerIntentFile());
        self::assertFileExists($temporary);
        self::assertSame($active, \file_get_contents($this->paths->confFile()));
    }

    public function testStrictRecoveryWithRollbackRestoresPairedOwnerAndConfigBeforeImages(): void
    {
        $oldConfig = 'events {} http { server { listen 19507; } }';
        $newConfig = 'events {} http { server { listen 19508; } }';
        $previousOwner = [
            ...$this->minimalOwner(),
            'config_generation' => \str_repeat('c', 32),
            'config_sha256' => \hash('sha256', $oldConfig),
        ];
        $previousOwnerJson = \json_encode($previousOwner, JSON_THROW_ON_ERROR);
        $intent = $this->strictOwnerIntent(
            \str_repeat('d', 32),
            $newConfig,
            $oldConfig,
            $previousOwnerJson,
        );
        self::assertSame(\strlen($newConfig), \file_put_contents($this->paths->confFile(), $newConfig));
        $this->writeOwnerFixture($this->paths->ownerFile(), $intent);
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $intent);
        $configRollback = (new ManagedNginxConfigWriter($this->paths))
            ->rollbackPathForTransaction((string)$intent['transaction_id']);
        self::assertSame(\strlen($oldConfig), \file_put_contents($configRollback, $oldConfig));
        $ownerRollback = $this->paths->ownerFile() . '.rollback.' . $intent['transaction_id'];
        self::assertSame(\strlen($previousOwnerJson), \file_put_contents(
            $ownerRollback,
            $previousOwnerJson,
        ));

        $this->recoverOwnerPublication();

        self::assertSame($oldConfig, \file_get_contents($this->paths->confFile()));
        self::assertSame($previousOwnerJson, \file_get_contents($this->paths->ownerFile()));
        self::assertFileDoesNotExist($configRollback);
        self::assertFileDoesNotExist($ownerRollback);
        self::assertFileDoesNotExist($this->paths->ownerIntentFile());
    }

    public function testStrictRecoveryWithoutRollbackAcceptsOnlyExactCommittedClosure(): void
    {
        $oldConfig = 'events {} http { server { listen 19509; } }';
        $newConfig = 'events {} http { server { listen 19510; } }';
        $previousOwner = [
            ...$this->minimalOwner(),
            'config_generation' => \str_repeat('e', 32),
            'config_sha256' => \hash('sha256', $oldConfig),
        ];
        $previousOwnerJson = \json_encode($previousOwner, JSON_THROW_ON_ERROR);
        $intent = $this->strictOwnerIntent(
            \str_repeat('f', 32),
            $newConfig,
            $oldConfig,
            $previousOwnerJson,
        );
        self::assertSame(\strlen($newConfig), \file_put_contents($this->paths->confFile(), $newConfig));
        self::assertSame(\strlen($oldConfig), \file_put_contents(
            $this->paths->confFile() . '.last-good',
            $oldConfig,
        ));
        $this->writeOwnerFixture($this->paths->ownerFile(), $intent);
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $intent);
        $ownerRollback = $this->paths->ownerFile() . '.rollback.' . $intent['transaction_id'];
        self::assertSame(\strlen($previousOwnerJson), \file_put_contents(
            $ownerRollback,
            $previousOwnerJson,
        ));

        $this->recoverOwnerPublication();

        self::assertSame($newConfig, \file_get_contents($this->paths->confFile()));
        self::assertFileExists($this->paths->ownerFile());
        self::assertFileDoesNotExist($ownerRollback);
        self::assertFileDoesNotExist($this->paths->ownerIntentFile());
    }

    public function testStrictRecoveryRetainsEvidenceForAmbiguousOwnerConfigPair(): void
    {
        $oldConfig = 'events {} http { server { listen 19511; } }';
        $newConfig = 'events {} http { server { listen 19512; } }';
        $damagedConfig = 'events {} http { server { listen 19513; } }';
        $previousOwner = [
            ...$this->minimalOwner(),
            'config_generation' => \str_repeat('1', 32),
            'config_sha256' => \hash('sha256', $oldConfig),
        ];
        $previousOwnerJson = \json_encode($previousOwner, JSON_THROW_ON_ERROR);
        $intent = $this->strictOwnerIntent(
            \str_repeat('2', 32),
            $newConfig,
            $oldConfig,
            $previousOwnerJson,
        );
        self::assertSame(\strlen($damagedConfig), \file_put_contents(
            $this->paths->confFile(),
            $damagedConfig,
        ));
        self::assertSame(\strlen($previousOwnerJson), \file_put_contents(
            $this->paths->ownerFile(),
            $previousOwnerJson,
        ));
        $this->writeOwnerFixture($this->paths->ownerIntentFile(), $intent);
        $ownerRollback = $this->paths->ownerFile() . '.rollback.' . $intent['transaction_id'];
        self::assertSame(\strlen($previousOwnerJson), \file_put_contents(
            $ownerRollback,
            $previousOwnerJson,
        ));

        try {
            $this->recoverOwnerPublication();
            self::fail('Ambiguous owner/config state must fail closed and retain evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ambiguous', \strtolower($exception->getMessage()));
        }

        self::assertSame($damagedConfig, \file_get_contents($this->paths->confFile()));
        self::assertSame($previousOwnerJson, \file_get_contents($this->paths->ownerFile()));
        self::assertFileExists($ownerRollback);
        self::assertFileExists($this->paths->ownerIntentFile());
    }

    public function testHttp3DegradationRequiresExactFailedQuicEvidence(): void
    {
        $generation = \str_repeat('3', 32);
        $digest = \str_repeat('4', 64);
        $config = [
            'http3_enabled' => true,
            'config_generation' => $generation,
            'config_sha256' => $digest,
        ];
        $result = [
            'ok' => false,
            'evidence' => [
                'http3_verifier_available' => true,
                'http3_status' => 'failed',
                'http3_config_generation' => $generation,
                'http3_config_sha256' => $digest,
            ],
        ];
        $eligible = new \ReflectionMethod($this->service, 'http3FailureCanDegrade');

        self::assertTrue($eligible->invoke($this->service, $config, $result));
        $result['evidence']['http3_config_sha256'] = \str_repeat('5', 64);
        self::assertFalse($eligible->invoke($this->service, $config, $result));
        $result['evidence']['http3_config_sha256'] = $digest;
        $result['evidence']['http3_verifier_available'] = false;
        self::assertFalse($eligible->invoke($this->service, $config, $result));
    }

    public function testHttp3DegradedCandidateCannotRetainQuicOrAltSvc(): void
    {
        $candidate = $this->paths->confDir() . DIRECTORY_SEPARATOR . 'h2-only.conf';
        self::assertSame(18, \file_put_contents($candidate, "events {}\nhttp {}\n"));
        $certificate = \str_repeat('6', 64);
        $current = [
            'http' => 19514,
            'https' => 19515,
            'server_names' => ['localhost'],
            'upstreams' => ['127.0.0.1:19502'],
            'ssl_certificate_sha256' => $certificate,
            'certificate_generation_managed' => false,
            'http3_enabled' => true,
        ];
        $degraded = [
            ...$current,
            'ssl' => true,
            'http2_enabled' => true,
            'http3_enabled' => false,
            'config_generation' => \str_repeat('7', 32),
            'config_sha256' => \hash('sha256', "events {}\nhttp {}\n"),
        ];
        $assertIdentity = new \ReflectionMethod(
            $this->service,
            'assertHttp3DegradedConfigIdentity',
        );
        $assertIdentity->invoke($this->service, $current, $degraded, $candidate);

        $unsafe = "events {}\nhttp { add_header Alt-Svc h3; }\n";
        self::assertSame(\strlen($unsafe), \file_put_contents($candidate, $unsafe));
        try {
            $assertIdentity->invoke($this->service, $current, $degraded, $candidate);
            self::fail('H2/H1 fallback must not retain Alt-Svc advertisement.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            self::assertStringContainsString('still advertises', $exception->getMessage());
        }
    }

    public function testTlsProbeHostUsesConcreteWildcardNameAndRejectsInvalidCandidates(): void
    {
        $resolve = new \ReflectionMethod($this->service, 'resolveTlsProbeHost');

        self::assertSame(
            'wls-probe.example.test',
            $resolve->invoke($this->service, ['*.Example.Test.']),
        );
        self::assertSame(
            'tenant.example.test',
            $resolve->invoke($this->service, ['_', '*.*.example.test', 'tenant.example.test']),
        );
        self::assertSame(
            'localhost',
            $resolve->invoke($this->service, ['_', '*.*.example.test', '[::1]']),
        );
    }

    /** @return array<string,mixed> */
    private function minimalOwner(): array
    {
        return [
            'instance_name' => 'ai-test-nginx-owner',
            'upstream_host' => '127.0.0.1',
            'upstream_port' => 19502,
            'config_generation' => \str_repeat('0', 32),
            'updated_at' => '2026-08-06T00:00:00+00:00',
        ];
    }

    private function strictOwnerIntent(
        string $transactionId,
        string $newConfig,
        string $oldConfig,
        string $previousOwnerJson,
    ): array {
        return [
            ...$this->minimalOwner(),
            'transaction_id' => $transactionId,
            'config_generation' => \substr(\hash('sha256', $newConfig), 0, 32),
            'config_sha256' => \hash('sha256', $newConfig),
            'config_rollback_expected' => true,
            'previous_config_sha256' => \hash('sha256', $oldConfig),
            'owner_rollback_expected' => true,
            'previous_owner_sha256' => \hash('sha256', $previousOwnerJson),
        ];
    }

    private function withPostRenameSyncFailure(string $target, callable $operation): void
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
            $operation();
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

    /** @param array<string,mixed> $owner */
    private function writeOwnerFixture(string $path, array $owner): void
    {
        self::assertNotFalse(\file_put_contents(
            $path,
            \json_encode($owner, JSON_THROW_ON_ERROR),
        ));
    }

    private function recoverOwnerPublication(): void
    {
        $recover = new \ReflectionMethod($this->service, 'recoverOwnerPublication');
        $recover->invoke($this->service);
    }

    private function cleanupOwnerAtomicWriteRecoveryArtifacts(): void
    {
        $cleanup = new \ReflectionMethod(
            $this->service,
            'cleanupOwnerAtomicWriteRecoveryBackups',
        );
        $cleanup->invoke($this->service);
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
