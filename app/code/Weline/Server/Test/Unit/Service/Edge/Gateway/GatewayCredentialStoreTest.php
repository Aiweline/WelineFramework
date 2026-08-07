<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class GatewayCredentialStoreTest extends TestCase
{
    private string $root = '';
    private string $home = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-credential-'
            . \bin2hex(\random_bytes(8));
        $this->home = $this->root . DIRECTORY_SEPARATOR . 'gateway';
        $this->project = $this->root . DIRECTORY_SEPARATOR . 'project';
        self::assertTrue(\mkdir($this->home . DIRECTORY_SEPARATOR . 'state', 0700, true));
        self::assertTrue(\mkdir($this->home . DIRECTORY_SEPARATOR . 'trust', 0700, true));
        self::assertTrue(\mkdir($this->project, 0700, true));
        $canonicalRoot = \realpath($this->root);
        self::assertIsString($canonicalRoot);
        $this->root = $canonicalRoot;
        $this->home = $this->root . DIRECTORY_SEPARATOR . 'gateway';
        $this->project = $this->root . DIRECTORY_SEPARATOR . 'project';
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->home);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=28080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=28443');
    }

    protected function tearDown(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE');
        \putenv('WLS_GATEWAY_HOME');
        \putenv('WLS_GATEWAY_LISTEN_HTTP');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS');
        $this->removeTree($this->root);
    }

    public function testCredentialIsHostBoundAndStoredOutsideProjectConfiguration(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        $projectUuid = '123e4567-e89b-42d3-a456-426614174010';
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        $file = $store->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \bin2hex(\random_bytes(16)),
            'secret' => \bin2hex(\random_bytes(32)),
        ], $projectUuid);
        self::assertStringContainsString(
            DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'wls'
                . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR,
            $file,
        );
        self::assertStringNotContainsString(
            DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc',
            $file,
        );
        self::assertSame($hostId, $store->load($projectUuid)['host_id']);
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($file) & 0777);
            self::assertSame(
                (\stat($this->project))['uid'],
                (\stat($file))['uid'],
            );
            self::assertSame(
                (\stat($this->project))['gid'],
                (\stat($file))['gid'],
            );
        }

        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            \bin2hex(\random_bytes(16)),
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not enrolled');
        $store->load($projectUuid);
    }

    public function testCredentialRejectsProjectMismatchAndLinkedTarget(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        try {
            $store->install([
                'host_id' => $hostId,
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174011',
                'credential_id' => \bin2hex(\random_bytes(16)),
                'secret' => \bin2hex(\random_bytes(32)),
            ], '123e4567-e89b-42d3-a456-426614174012');
            self::fail('Cross-project credential was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid', \strtolower($exception->getMessage()));
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $directory = $this->project . DIRECTORY_SEPARATOR . 'var/wls/gateway';
        self::assertTrue(\mkdir($directory, 0700, true));
        self::assertTrue(\symlink('/tmp', $directory . DIRECTORY_SEPARATOR . $hostId . '.cred'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-linked file');
        $store->install([
            'host_id' => $hostId,
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174011',
            'credential_id' => \bin2hex(\random_bytes(16)),
            'secret' => \bin2hex(\random_bytes(32)),
        ], '123e4567-e89b-42d3-a456-426614174011');
    }

    public function testPendingRotationCredentialDoesNotReplaceActiveUntilCommit(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $oldUuid = '123e4567-e89b-42d3-a456-426614174021';
        $newUuid = '123e4567-e89b-42d3-a456-426614174022';
        $oldId = \bin2hex(\random_bytes(16));
        $newId = \bin2hex(\random_bytes(16));
        $rotationId = \bin2hex(\random_bytes(16));
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        $store->install([
            'host_id' => $hostId,
            'project_uuid' => $oldUuid,
            'credential_id' => $oldId,
            'secret' => \bin2hex(\random_bytes(32)),
        ], $oldUuid);
        $store->installPending([
            'host_id' => $hostId,
            'project_uuid' => $newUuid,
            'credential_id' => $newId,
            'secret' => \bin2hex(\random_bytes(32)),
        ], $newUuid, $rotationId);

        self::assertSame($oldId, $store->load($oldUuid)['credential_id']);
        self::assertSame(
            $newId,
            $store->loadPending($rotationId, $newUuid)['credential_id'],
        );
        self::assertSame(
            $newId,
            $store->commitPending($rotationId, $newUuid)['credential_id'],
        );
        self::assertSame($newId, $store->load($newUuid)['credential_id']);
    }

    public function testFreshClonePurgeRemovesEveryCopiedCredentialOnlyInsideClone(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $oldUuid = '123e4567-e89b-42d3-a456-426614174023';
        $newUuid = '123e4567-e89b-42d3-a456-426614174024';
        $rotationId = $this->rotationId(1);
        $store->install(
            $this->credential($hostId, $oldUuid, 'clone-active'),
            $oldUuid,
        );
        $store->installPending(
            $this->credential($hostId, $newUuid, 'clone-pending'),
            $newUuid,
            $rotationId,
        );
        $otherHostId = \str_repeat('e', 32);
        $this->writeCredentialFile(
            $this->activeCredentialPath($otherHostId),
            $this->credential($otherHostId, $oldUuid, 'other-host-active'),
        );
        $this->writeCredentialFile(
            $this->pendingCredentialPath($otherHostId, $this->rotationId(2)),
            $this->credential($otherHostId, $newUuid, 'other-host-pending'),
        );
        $sourceCredential = $this->root . DIRECTORY_SEPARATOR . 'source-project'
            . DIRECTORY_SEPARATOR . 'var/wls/gateway/' . $hostId . '.cred';
        self::assertTrue(\mkdir(\dirname($sourceCredential), 0700, true));
        self::assertNotFalse(\file_put_contents($sourceCredential, 'source-secret'));
        self::assertCount(4, $this->semanticCredentialFiles());

        $store->purgeForFreshCloneIdentity();

        self::assertSame([], $this->semanticCredentialFiles());
        self::assertFileExists($sourceCredential);
        self::assertSame('source-secret', \file_get_contents($sourceCredential));
    }

    public function testFreshClonePurgeRetryPreservesOnlyDurableNewIdentityCredential(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $oldUuid = '123e4567-e89b-42d3-a456-426614174025';
        $newUuid = '123e4567-e89b-42d3-a456-426614174026';
        $store->install(
            $this->credential($hostId, $newUuid, 'fresh-active-after-image'),
            $newUuid,
        );
        $store->installPending(
            $this->credential($hostId, $oldUuid, 'copied-pending-retry'),
            $oldUuid,
            $this->rotationId(3),
        );
        $otherHostId = \str_repeat('d', 32);
        $this->writeCredentialFile(
            $this->activeCredentialPath($otherHostId),
            $this->credential($otherHostId, $oldUuid, 'other-host-retry'),
        );
        self::assertCount(3, $this->semanticCredentialFiles());

        self::assertSame(2, $store->purgeForFreshCloneIdentity($newUuid));

        self::assertSame(
            [$hostId . '.cred'],
            $this->semanticCredentialFiles(),
        );
        self::assertSame(
            $newUuid,
            $store->load($newUuid)['project_uuid'],
        );
    }

    public function testCredentialOperationCollectsBoundedOrphanAtomicStagingFile(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174027';
        $active = $this->credential($hostId, $projectUuid, 'staging-active');
        $store->install($active, $projectUuid);
        $staging = $this->activeCredentialPath($hostId)
            . '.tmp-' . \str_repeat('a', 24);
        $this->writeRawCredentialArtifact($staging, '{"interrupted":');

        $replacement = $this->credential($hostId, $projectUuid, 'staging-replacement');
        $store->install($replacement, $projectUuid);

        self::assertFileDoesNotExist($staging);
        self::assertSame(
            $replacement['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );
    }

    public function testCredentialOperationCollectsBackupOnlyAfterValidActiveTargetProof(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $identity = new ProjectIdentityStore(
            $this->project,
            $this->root . DIRECTORY_SEPARATOR . 'identity-host-state',
            $this->root . DIRECTORY_SEPARATOR . 'missing-legacy.json',
        );
        $projectUuid = $identity->projectUuid();
        $active = $this->credential($hostId, $projectUuid, 'backup-active');
        $store->install($active, $projectUuid);
        $backup = $this->activeCredentialPath($hostId)
            . '.wls-backup-' . \str_repeat('b', 16);
        $this->writeCredentialFile(
            $backup,
            $this->credential($hostId, $projectUuid, 'backup-previous'),
        );

        $replacement = $this->credential($hostId, $projectUuid, 'backup-replacement');
        $store->install($replacement, $projectUuid);

        self::assertFileDoesNotExist($backup);
        self::assertSame(
            $replacement['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );
    }

    public function testCredentialOperationCollectsBackupForFactBoundPendingTarget(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $identity = new ProjectIdentityStore(
            $this->project,
            $this->root . DIRECTORY_SEPARATOR . 'pending-identity-host-state',
            $this->root . DIRECTORY_SEPARATOR . 'missing-pending-legacy.json',
        );
        $identity->projectUuid();
        $rotation = $identity->prepareRotation();
        $rotationId = (string)$rotation['rotation_id'];
        $newProjectUuid = (string)$rotation['new_project_uuid'];
        $pending = $this->pendingCredentialPath($hostId, $rotationId);
        $store->installPending(
            $this->credential($hostId, $newProjectUuid, 'backup-pending'),
            $newProjectUuid,
            $rotationId,
        );
        $backup = $pending . '.wls-backup-' . \str_repeat('c', 16);
        $this->writeCredentialFile(
            $backup,
            $this->credential($hostId, $newProjectUuid, 'backup-pending-previous'),
        );

        $replacement = $this->credential(
            $hostId,
            $newProjectUuid,
            'backup-pending-replacement',
        );
        $store->installPending($replacement, $newProjectUuid, $rotationId);

        self::assertFileDoesNotExist($backup);
        self::assertSame(
            $replacement['credential_id'],
            $store->loadPending($rotationId, $newProjectUuid)['credential_id'],
        );
    }

    public function testCredentialBackupWithoutPairedTargetIsPreservedAsRecoveryEvidence(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $identity = new ProjectIdentityStore(
            $this->project,
            $this->root . DIRECTORY_SEPARATOR . 'missing-target-host-state',
            $this->root . DIRECTORY_SEPARATOR . 'missing-target-legacy.json',
        );
        $projectUuid = $identity->projectUuid();
        $active = $this->credential($hostId, $projectUuid, 'missing-target');
        $store->install($active, $projectUuid);
        $backup = $this->activeCredentialPath($hostId)
            . '.wls-backup-' . \str_repeat('d', 16);
        self::assertTrue(\rename($this->activeCredentialPath($hostId), $backup));

        try {
            $store->install(
                $this->credential($hostId, $projectUuid, 'must-not-publish'),
                $projectUuid,
            );
            self::fail('A missing backup target must fail closed before publication.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'paired credential target is missing',
                \strtolower($exception->getMessage()),
            );
        }
        self::assertFileExists($backup);
        self::assertFileDoesNotExist($this->activeCredentialPath($hostId));
    }

    public function testCredentialBackupWithCorruptTargetIsPreservedAsRecoveryEvidence(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $identity = new ProjectIdentityStore(
            $this->project,
            $this->root . DIRECTORY_SEPARATOR . 'corrupt-target-host-state',
            $this->root . DIRECTORY_SEPARATOR . 'corrupt-target-legacy.json',
        );
        $projectUuid = $identity->projectUuid();
        $activePath = $this->activeCredentialPath($hostId);
        $store->install(
            $this->credential($hostId, $projectUuid, 'corrupt-target-active'),
            $projectUuid,
        );
        $backup = $activePath . '.wls-backup-' . \str_repeat('e', 16);
        $this->writeCredentialFile(
            $backup,
            $this->credential($hostId, $projectUuid, 'corrupt-target-backup'),
        );
        $this->writeRawCredentialArtifact($activePath, '{"corrupt":');

        try {
            $store->install(
                $this->credential($hostId, $projectUuid, 'must-not-replace-corrupt'),
                $projectUuid,
            );
            self::fail('A corrupt backup target must retain recovery evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'paired credential target is invalid',
                \strtolower($exception->getMessage()),
            );
        }
        self::assertFileExists($backup);
        self::assertSame('{"corrupt":', \file_get_contents($activePath));
    }

    public function testCredentialBackupTargetMustMatchDurableProjectAndHostFacts(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $identity = new ProjectIdentityStore(
            $this->project,
            $this->root . DIRECTORY_SEPARATOR . 'fact-fence-host-state',
            $this->root . DIRECTORY_SEPARATOR . 'fact-fence-legacy.json',
        );
        $projectUuid = $identity->projectUuid();
        $activePath = $this->activeCredentialPath($hostId);
        $store->install(
            $this->credential($hostId, $projectUuid, 'fact-fence-active'),
            $projectUuid,
        );
        $backup = $activePath . '.wls-backup-' . \str_repeat('f', 16);
        $this->writeCredentialFile(
            $backup,
            $this->credential($hostId, $projectUuid, 'fact-fence-backup'),
        );
        $cases = [
            'foreign project UUID' => $this->credential(
                $hostId,
                '123e4567-e89b-42d3-a456-426614174029',
                'fact-fence-foreign-project',
            ),
            'foreign host ID' => $this->credential(
                \str_repeat('9', 32),
                $projectUuid,
                'fact-fence-foreign-host',
            ),
        ];

        foreach ($cases as $case => $invalidTarget) {
            $this->writeCredentialFile($activePath, $invalidTarget);
            try {
                $store->install(
                    $this->credential($hostId, $projectUuid, 'blocked-' . $case),
                    $projectUuid,
                );
                self::fail($case . ' must not authorize backup deletion.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'paired credential target is invalid',
                    \strtolower($exception->getMessage()),
                    $case,
                );
            }
            self::assertFileExists($backup, $case);
        }
    }

    public function testFullCapacityRejectsNewActiveAndPendingPathsBeforePublishingFileSixtyFive(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174041';
        $this->fillPendingCredentials($store, $hostId, $projectUuid, 64);
        self::assertCount(64, $this->semanticCredentialFiles());

        $active = $this->credential($hostId, $projectUuid, 'active-overflow');
        try {
            $store->install($active, $projectUuid);
            self::fail('A new active path was published as credential file 65.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('capacity', \strtolower($exception->getMessage()));
            self::assertStringNotContainsString($active['secret'], $exception->getMessage());
            self::assertStringNotContainsString($active['credential_id'], $exception->getMessage());
        }
        self::assertCount(64, $this->semanticCredentialFiles());
        self::assertFileDoesNotExist($this->activeCredentialPath($hostId));

        $pending = $this->credential($hostId, $projectUuid, 'pending-overflow');
        $rotationId = $this->rotationId(65);
        try {
            $store->installPending($pending, $projectUuid, $rotationId);
            self::fail('A new pending path was published as credential file 65.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('capacity', \strtolower($exception->getMessage()));
            self::assertStringNotContainsString($pending['secret'], $exception->getMessage());
            self::assertStringNotContainsString($pending['credential_id'], $exception->getMessage());
        }
        self::assertCount(64, $this->semanticCredentialFiles());
        self::assertFileDoesNotExist($this->pendingCredentialPath($hostId, $rotationId));
    }

    public function testFullCapacityCommitWithoutActivePathRetainsPendingAndNeverPublishesFileSixtyFive(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174042';
        $this->fillPendingCredentials($store, $hostId, $projectUuid, 64);
        $rotationId = $this->rotationId(1);
        $pending = $store->loadPending($rotationId, $projectUuid);

        try {
            $store->commitPending($rotationId, $projectUuid);
            self::fail('Commit published a new active path as credential file 65.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('capacity', \strtolower($exception->getMessage()));
            self::assertStringNotContainsString($pending['secret'], $exception->getMessage());
            self::assertStringNotContainsString($pending['credential_id'], $exception->getMessage());
        }
        self::assertCount(64, $this->semanticCredentialFiles());
        self::assertFileExists($this->pendingCredentialPath($hostId, $rotationId));
        self::assertFileDoesNotExist($this->activeCredentialPath($hostId));
    }

    public function testFullCapacityTreatsExistingActiveAndPendingPathsAsReplacements(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174043';
        $active = $this->credential($hostId, $projectUuid, 'active-original');
        $store->install($active, $projectUuid);
        $this->fillPendingCredentials($store, $hostId, $projectUuid, 63);
        self::assertCount(64, $this->semanticCredentialFiles());

        $activeReplacement = $this->credential($hostId, $projectUuid, 'active-replacement');
        $store->install($activeReplacement, $projectUuid);
        self::assertCount(64, $this->semanticCredentialFiles());
        self::assertSame(
            $activeReplacement['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );

        $rotationId = $this->rotationId(1);
        $pendingReplacement = $this->credential(
            $hostId,
            $projectUuid,
            'pending-replacement',
        );
        $store->installPending($pendingReplacement, $projectUuid, $rotationId);
        self::assertCount(64, $this->semanticCredentialFiles());
        self::assertSame(
            $pendingReplacement['credential_id'],
            $store->loadPending($rotationId, $projectUuid)['credential_id'],
        );
    }

    public function testExactPendingOverflowAfterImageRetriesAndCommitConvergesToCapacity(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174044';
        $store->install(
            $this->credential($hostId, $projectUuid, 'old-active'),
            $projectUuid,
        );
        $this->fillPendingCredentials($store, $hostId, $projectUuid, 63);
        $rotationId = $this->rotationId(64);
        $afterImage = $this->credential($hostId, $projectUuid, 'rotation-after-image');
        $this->writeCredentialFile(
            $this->pendingCredentialPath($hostId, $rotationId),
            $afterImage,
        );
        self::assertCount(65, $this->semanticCredentialFiles());

        self::assertSame(
            (string)\realpath($this->pendingCredentialPath($hostId, $rotationId)),
            $store->installPending($afterImage, $projectUuid, $rotationId),
        );
        self::assertSame(
            $afterImage['credential_id'],
            $store->commitPending($rotationId, $projectUuid)['credential_id'],
        );
        self::assertCount(64, $this->semanticCredentialFiles());
        self::assertFileDoesNotExist($this->pendingCredentialPath($hostId, $rotationId));
        self::assertSame(
            $afterImage['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );
    }

    public function testCommittedActiveAndPendingCrashAfterImageRetriesWithoutFalseFailure(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174048';
        $committed = $this->credential($hostId, $projectUuid, 'committed-after-image');
        $store->install($committed, $projectUuid);
        $this->fillPendingCredentials($store, $hostId, $projectUuid, 63);
        $rotationId = $this->rotationId(64);
        $this->writeCredentialFile(
            $this->pendingCredentialPath($hostId, $rotationId),
            $committed,
        );
        self::assertCount(65, $this->semanticCredentialFiles());

        self::assertSame(
            $committed['credential_id'],
            $store->commitPending($rotationId, $projectUuid)['credential_id'],
        );
        self::assertCount(64, $this->semanticCredentialFiles());
        self::assertFileDoesNotExist($this->pendingCredentialPath($hostId, $rotationId));
        self::assertSame(
            $committed['secret'],
            $store->load($projectUuid)['secret'],
        );
    }

    public function testExactActiveOverflowAfterImageIsRecoverableButArbitraryOverflowIsRejected(): void
    {
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174045';
        $this->fillPendingCredentials($store, $hostId, $projectUuid, 64);
        $activeAfterImage = $this->credential($hostId, $projectUuid, 'active-after-image');
        $this->writeCredentialFile($this->activeCredentialPath($hostId), $activeAfterImage);
        self::assertCount(65, $this->semanticCredentialFiles());

        self::assertSame(
            (string)\realpath($this->activeCredentialPath($hostId)),
            $store->install($activeAfterImage, $projectUuid),
        );
        self::assertSame(
            $activeAfterImage['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );
        self::assertTrue($store->remove());
        self::assertCount(64, $this->semanticCredentialFiles());

        $rotationId = $this->rotationId(65);
        $persisted = $this->credential($hostId, $projectUuid, 'persisted-overflow');
        $submitted = $this->credential($hostId, $projectUuid, 'different-overflow');
        $this->writeCredentialFile(
            $this->pendingCredentialPath($hostId, $rotationId),
            $persisted,
        );
        self::assertCount(65, $this->semanticCredentialFiles());
        try {
            $store->installPending($submitted, $projectUuid, $rotationId);
            self::fail('An arbitrary overflow was accepted as a recoverable after-image.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'exact recoverable after-image',
                \strtolower($exception->getMessage()),
            );
            self::assertStringNotContainsString($persisted['secret'], $exception->getMessage());
            self::assertStringNotContainsString($submitted['secret'], $exception->getMessage());
        }
        self::assertCount(65, $this->semanticCredentialFiles());
    }

    public function testExactOverflowAfterImageStillRequiresPrivateProjectOwnedTarget(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX mode ownership is enforced through the Windows ACL path.');
        }
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174047';
        $this->fillPendingCredentials($store, $hostId, $projectUuid, 64);
        $afterImage = $this->credential($hostId, $projectUuid, 'unsafe-after-image');
        $active = $this->activeCredentialPath($hostId);
        $this->writeCredentialFile($active, $afterImage);
        self::assertTrue(\chmod($active, 0644));

        try {
            $store->install($afterImage, $projectUuid);
            self::fail('An exact but non-private after-image was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unsafe', \strtolower($exception->getMessage()));
            self::assertStringNotContainsString($afterImage['secret'], $exception->getMessage());
        }
        self::assertSame(0644, \fileperms($active) & 0777);
        self::assertCount(65, $this->semanticCredentialFiles());
    }

    public function testPostPublicationCleanupFailureCannotReportTheActiveCredentialAsUncommitted(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The unsafe cleanup-race fixture uses a POSIX symbolic link.');
        }
        [$store, $hostId] = $this->credentialStore();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174046';
        $active = $this->credential($hostId, $projectUuid, 'durably-active');
        $activePath = $store->install($active, $projectUuid);
        $unsafeStale = $this->credentialDirectory() . DIRECTORY_SEPARATOR
            . \str_repeat('f', 32) . '.cred';
        self::assertTrue(\symlink('/tmp', $unsafeStale));

        $cleanup = new \ReflectionMethod($store, 'cleanupPublishedCredentialFiles');
        $cleanup->invoke($store, [$unsafeStale], $activePath);

        self::assertSame(
            $active['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );
        self::assertTrue(\is_link($unsafeStale));
    }

    public function testCredentialStoreRejectsTheFilesystemRootAsProjectRoot(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $projectRoot = DIRECTORY_SEPARATOR === '/'
            ? '/'
            : \substr((string)\realpath(\sys_get_temp_dir()), 0, 3);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174031';
        $store = new GatewayCredentialStore(new GatewayPaths(), $projectRoot);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('safe project root');
        $store->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \bin2hex(\random_bytes(16)),
            'secret' => \bin2hex(\random_bytes(32)),
        ], $projectUuid);
    }

    public function testCredentialStoreRecognizesExtendedWindowsFilesystemRoots(): void
    {
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        $method = new \ReflectionMethod($store, 'isFilesystemRoot');
        foreach ([
            '/',
            '///',
            'C:\\',
            '\\\\server\\',
            '\\\\server\\share\\',
            '\\\\?\\C:\\',
            '\\\\?\\UNC\\server\\share\\',
            '\\\\?\\UNC\\server\\',
            '\\\\.\\C:\\',
            '\\\\?\\Volume{01234567-89ab-cdef-0123-456789abcdef}\\',
        ] as $path) {
            self::assertTrue($method->invoke($store, $path), $path);
        }
        self::assertFalse($method->invoke(
            $store,
            '\\\\?\\UNC\\server\\share\\project',
        ));
    }

    /** @return array{GatewayCredentialStore,string} */
    private function credentialStore(): array
    {
        $hostId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        return [
            new GatewayCredentialStore(new GatewayPaths(), $this->project),
            $hostId,
        ];
    }

    /** @return array<string,mixed> */
    private function credential(string $hostId, string $projectUuid, string $seed): array
    {
        return [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \substr(\hash('sha256', 'id:' . $seed), 0, 32),
            'credential_generation' => 1,
            'secret' => \hash('sha256', 'secret:' . $seed),
            'issued_at' => '2026-08-06T00:00:00+00:00',
        ];
    }

    private function fillPendingCredentials(
        GatewayCredentialStore $store,
        string $hostId,
        string $projectUuid,
        int $count,
    ): void {
        self::assertGreaterThan(0, $count);
        $firstRotation = $this->rotationId(1);
        $store->installPending(
            $this->credential($hostId, $projectUuid, 'pending-1'),
            $projectUuid,
            $firstRotation,
        );
        for ($index = 2; $index <= $count; ++$index) {
            $this->writeCredentialFile(
                $this->pendingCredentialPath($hostId, $this->rotationId($index)),
                $this->credential($hostId, $projectUuid, 'pending-' . $index),
            );
        }
    }

    private function rotationId(int $index): string
    {
        return \str_pad(\dechex($index), 32, '0', STR_PAD_LEFT);
    }

    private function credentialDirectory(): string
    {
        return $this->project . DIRECTORY_SEPARATOR . 'var/wls/gateway';
    }

    private function activeCredentialPath(string $hostId): string
    {
        return $this->credentialDirectory() . DIRECTORY_SEPARATOR . $hostId . '.cred';
    }

    private function pendingCredentialPath(string $hostId, string $rotationId): string
    {
        return $this->credentialDirectory() . DIRECTORY_SEPARATOR . $hostId
            . '.rotate-' . $rotationId . '.pending';
    }

    /** @param array<string,mixed> $credential */
    private function writeCredentialFile(string $path, array $credential): void
    {
        self::assertNotFalse(\file_put_contents(
            $path,
            \json_encode(
                $credential,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($path, 0600));
        }
    }

    private function writeRawCredentialArtifact(string $path, string $contents): void
    {
        self::assertNotFalse(\file_put_contents($path, $contents));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($path, 0600));
        }
    }

    /** @return list<string> */
    private function semanticCredentialFiles(): array
    {
        $directory = $this->credentialDirectory();
        $entries = \is_dir($directory) ? \scandir($directory) : false;
        self::assertIsArray($entries);
        return \array_values(\array_filter(
            $entries,
            static fn (string $leaf): bool =>
                \preg_match('/\A[a-f0-9]{32}\.cred\z/D', $leaf) === 1
                || \preg_match(
                    '/\A[a-f0-9]{32}\.rotate-[a-f0-9]{32}\.pending\z/D',
                    $leaf,
                ) === 1,
        ));
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
