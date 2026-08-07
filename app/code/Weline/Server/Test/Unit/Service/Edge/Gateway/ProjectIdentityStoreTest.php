<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectIdentityRotator;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class ProjectIdentityStoreTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->temporaryRoots) as $root) {
            $this->removeTree($root);
        }
        $this->temporaryRoots = [];
    }

    public function testIdentityMovesWithProjectAndGenerationIsMonotonic(): void
    {
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'project-a');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $store = new ProjectIdentityStore($project, $hostState, $legacy);

        $uuid = $store->projectUuid();
        $digestA = \hash('sha256', 'desired-a');
        $digestB = \hash('sha256', 'desired-b');
        self::assertSame(1, $store->advanceDesiredState($digestA)['generation']);
        self::assertSame(1, $store->advanceDesiredState($digestA)['generation']);
        self::assertSame(2, $store->advanceDesiredState($digestB)['generation']);

        $moved = $sandbox . DIRECTORY_SEPARATOR . 'project-moved';
        self::assertTrue(\rename($project, $moved));
        $movedStore = new ProjectIdentityStore($moved, $hostState, $legacy);

        self::assertSame($uuid, $movedStore->projectUuid());
        self::assertSame(2, $movedStore->advanceDesiredState($digestB)['generation']);
    }

    public function testValidatedIdentityAndHostClaimBackupsAreCollectedUnderTheirLocks(): void
    {
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'project');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $store = new ProjectIdentityStore($project, $hostState, $legacy);
        $uuid = $store->projectUuid();
        $identity = $project . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json';
        $claim = $hostState . DIRECTORY_SEPARATOR . 'project-identities'
            . DIRECTORY_SEPARATOR . $uuid . '.json';
        $identityBackup = $identity . '.wls-backup-' . \str_repeat('a', 16);
        $claimBackup = $claim . '.wls-backup-' . \str_repeat('b', 16);
        self::assertTrue(\copy($identity, $identityBackup));
        self::assertTrue(\copy($claim, $claimBackup));

        self::assertSame($uuid, $store->projectUuid());

        self::assertFileDoesNotExist($identityBackup);
        self::assertFileDoesNotExist($claimBackup);
    }

    public function testMissingIdentityOrClaimTargetRetainsRecoveryEvidenceAndFailsClosed(): void
    {
        foreach (['identity', 'claim'] as $case) {
            $sandbox = $this->makeSandbox();
            $project = $this->makeProject(
                $sandbox . DIRECTORY_SEPARATOR . 'project-' . $case,
            );
            $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
            $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
            $store = new ProjectIdentityStore($project, $hostState, $legacy);
            $uuid = $store->projectUuid();
            $target = $case === 'identity'
                ? $project . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
                    . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json'
                : $hostState . DIRECTORY_SEPARATOR . 'project-identities'
                    . DIRECTORY_SEPARATOR . $uuid . '.json';
            $backup = $target . '.wls-backup-' . \str_repeat(
                $case === 'identity' ? 'c' : 'd',
                16,
            );
            self::assertTrue(\rename($target, $backup));

            try {
                $store->projectUuid();
                self::fail('A missing paired identity target must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'paired target',
                    \strtolower($exception->getMessage()),
                );
            }
            self::assertFileExists($backup);
            self::assertFileDoesNotExist($target);
        }
    }

    public function testLiveSameHostCloneRequiresTransactionalRotation(): void
    {
        $sandbox = $this->makeSandbox();
        $first = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'first');
        $second = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'second');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $firstStore = new ProjectIdentityStore($first, $hostState, $legacy);
        $firstUuid = $firstStore->projectUuid();
        $identity = 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json';
        self::assertTrue(\copy(
            $first . DIRECTORY_SEPARATOR . $identity,
            $second . DIRECTORY_SEPARATOR . $identity,
        ));
        $clone = new ProjectIdentityStore($second, $hostState, $legacy);

        try {
            $clone->projectUuid();
            self::fail('A live same-host clone must not share a project UUID.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('explicit project identity rotation', $exception->getMessage());
        }

        try {
            $clone->rotate();
            self::fail('Direct identity replacement must not bypass host enrollment.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'transactional gateway enrollment',
                $exception->getMessage(),
            );
        }
        self::assertSame($firstUuid, $firstStore->projectUuid());
    }

    public function testCloneRotationCreatesFreshIdentityWithoutCallingHostTransfer(): void
    {
        $sandbox = $this->makeSandbox();
        $first = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'first');
        $second = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'second');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $firstStore = new ProjectIdentityStore($first, $hostState, $legacy);
        $firstUuid = $firstStore->projectUuid();
        $firstStore->advanceDesiredState(\hash('sha256', 'copied desired'));
        $firstStore->advanceCertificateState(\hash('sha256', 'copied certificate'));
        $firstStore->advanceInstanceGeneration('default');
        $firstStore->prepareRotation();
        $identity = 'app' . DIRECTORY_SEPARATOR . 'etc'
            . DIRECTORY_SEPARATOR . 'wls-project.json';
        self::assertTrue(\copy(
            $first . DIRECTORY_SEPARATOR . $identity,
            $second . DIRECTORY_SEPARATOR . $identity,
        ));
        $cloneStore = new ProjectIdentityStore($second, $hostState, $legacy);
        $hostCalls = [];
        $retiredCredentials = [];
        $rotator = new GatewayProjectIdentityRotator(
            identities: $cloneStore,
            projectRequestResolver: static function (
                string $operation,
                array $payload,
            ) use (&$hostCalls): array {
                $hostCalls[] = [$operation, $payload];
                throw new \RuntimeException(
                    'A cloned root must never authenticate a host identity transfer.',
                );
            },
            credentialRetirer: static function (string $oldProjectUuid) use (
                &$retiredCredentials,
            ): void {
                $retiredCredentials[] = $oldProjectUuid;
            },
        );

        $result = $rotator->rotate();

        self::assertSame('FRESH_ENROLLMENT_REQUIRED', $result['state']);
        self::assertSame($firstUuid, $result['previous_uuid']);
        self::assertNotSame($firstUuid, $result['project_uuid']);
        self::assertSame([], $hostCalls);
        self::assertSame([$firstUuid], $retiredCredentials);
        $sourceClaim = \json_decode((string)\file_get_contents(
            $hostState . DIRECTORY_SEPARATOR . 'project-identities'
                . DIRECTORY_SEPARATOR . $firstUuid . '.json',
        ), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($first, $sourceClaim['project_root'] ?? null);
        self::assertSame($firstUuid, $sourceClaim['project_uuid'] ?? null);
        $cloneState = $cloneStore->ensure();
        self::assertSame($result['project_uuid'], $cloneState['project_uuid']);
        self::assertSame(0, $cloneState['desired']['generation']);
        self::assertSame(0, $cloneState['certificate']['generation']);
        self::assertSame([], $cloneState['instances']);
        self::assertArrayNotHasKey('rotation', $cloneState);
        self::assertSame(
            $result['project_uuid'],
            $cloneStore->freshEnrollmentState()['project_uuid'] ?? null,
        );

        // A crash before enrollment keeps the exact new identity journaled.
        // Retrying may remove only the clone-local copied credential again,
        // but it must neither mint another UUID nor contact host transfer.
        $retry = $rotator->rotate();
        self::assertSame($result['project_uuid'], $retry['project_uuid']);
        self::assertSame([], $hostCalls);
        self::assertSame([$firstUuid, $firstUuid], $retiredCredentials);

        $completed = $cloneStore->completeFreshEnrollment(
            (string)$result['project_uuid'],
        );
        self::assertSame($firstUuid, $completed['previous_project_uuid']);
        self::assertSame([], $cloneStore->freshEnrollmentState());
        self::assertSame($firstUuid, $firstStore->projectUuid());

        $afterLostCompletionAck = $rotator->rotate();
        self::assertSame(
            'FRESH_ENROLLMENT_ALREADY_COMMITTED',
            $afterLostCompletionAck['state'],
        );
        self::assertSame($result['project_uuid'], $afterLostCompletionAck['project_uuid']);
        self::assertSame([], $hostCalls);
        self::assertSame([$firstUuid, $firstUuid], $retiredCredentials);

        try {
            $rotator->rotate(true);
            self::fail('Explicit same-root transfer must reach the protocol boundary.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'A cloned root must never authenticate a host identity transfer.',
                $exception->getMessage(),
            );
        }
        self::assertCount(1, $hostCalls);
        self::assertSame('rotate-prepare', $hostCalls[0][0]);
        self::assertSame($result['project_uuid'], $hostCalls[0][1]['project_uuid']);
        self::assertSame(
            $result['project_uuid'],
            $cloneStore->lastFreshEnrollmentState()['project_uuid'] ?? null,
        );
        $sameRootRotation = $cloneStore->rotationState();
        self::assertSame('LOCAL_PREPARED', $sameRootRotation['phase'] ?? null);
        $cloneStore->recordRotationPrepared(
            (string)$sameRootRotation['rotation_id'],
            \hash('sha256', 'same-root-request'),
            \substr(\hash('sha256', 'same-root-idempotency'), 0, 40),
            \str_repeat('c', 32),
            \hash('sha256', 'same-root-prepare-receipt'),
        );
        $cloneStore->markRotationHostCommitted(
            (string)$sameRootRotation['rotation_id'],
            ['test_host_commit' => true],
        );
        $identityCommitted = $cloneStore->commitRotationIdentity(
            (string)$sameRootRotation['rotation_id'],
        );
        self::assertSame('IDENTITY_COMMITTED', $identityCommitted['phase']);
        self::assertSame(
            $sameRootRotation['new_project_uuid'],
            $cloneStore->ensure()['project_uuid'],
        );
        self::assertSame(
            $sameRootRotation['new_project_uuid'],
            $cloneStore->lastFreshEnrollmentState()['project_uuid'] ?? null,
        );
    }

    public function testSameRootRotationStillBeginsOldCredentialDualProofTransfer(): void
    {
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'project');
        $store = new ProjectIdentityStore(
            $project,
            $sandbox . DIRECTORY_SEPARATOR . 'host-state',
            $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json',
        );
        $oldUuid = $store->projectUuid();
        $calls = [];
        $rotator = new GatewayProjectIdentityRotator(
            identities: $store,
            projectRequestResolver: static function (
                string $operation,
                array $payload,
            ) use (&$calls): array {
                $calls[] = [$operation, $payload];
                throw new \RuntimeException('stop after observing transfer branch');
            },
            credentialRetirer: static function (string $oldProjectUuid): void {
                throw new \RuntimeException(
                    'Same-root transfer must retain the active old credential.',
                );
            },
        );

        try {
            $rotator->rotate();
            self::fail('The fake protocol boundary must stop the rotation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('stop after observing transfer branch', $exception->getMessage());
        }

        self::assertCount(1, $calls);
        self::assertSame('rotate-prepare', $calls[0][0]);
        self::assertSame($oldUuid, $calls[0][1]['project_uuid']);
        self::assertSame($oldUuid, $calls[0][1]['old_project_uuid']);
        self::assertSame($project, $calls[0][1]['project_root']);
        self::assertSame([], $store->freshEnrollmentState());
        self::assertSame($oldUuid, $store->ensure()['project_uuid']);
    }

    public function testEnrollmentCommandSerializesCloneResetThroughFreshCredentialCommit(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 5) . '/Console/Server/Gateway/Enroll.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString(
            'withEnrollmentTransitionLock(',
            $source,
        );
        self::assertStringContainsString(
            "'.wls-enrollment-transition.lock'",
            $identitySource = (string)\file_get_contents(
                \dirname(__DIR__, 5) . '/Service/Edge/Gateway/ProjectIdentityStore.php',
            ),
            'Root-owned enrollment must allow the transition lock ownership seal.',
        );
        self::assertStringContainsString(
            'hash_equals($enrollmentTransitionLockFile, $file)',
            $identitySource,
        );
        self::assertStringContainsString(
            "'FRESH_ENROLLMENT_REQUIRED'",
            $source,
        );
        self::assertStringContainsString(
            'completeFreshEnrollment(',
            $source,
        );
        self::assertLessThan(
            \strpos($source, 'completeFreshEnrollment('),
            \strpos($source, '->install($credential, $projectUuid)'),
            'The fresh marker may clear only after the new credential is durable.',
        );
    }

    public function testDurableIdentityWritesAreNotReportedAsDeadlineFailures(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 5) . '/Service/Edge/Gateway/ProjectIdentityStore.php',
        );
        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression(
            '/publishJson\([\s\S]{0,512}?identityDeadlineRemaining\(\$deadlineMonotonic\)/',
            $source,
            'A deadline may fence a not-yet-started write, but it must not turn a durable identity commit into a reported failure.',
        );
    }

    public function testInstanceGenerationSurvivesEndpointLossAndIgnoresWallClock(): void
    {
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'instance-counter');
        $store = new ProjectIdentityStore(
            $project,
            $sandbox . DIRECTORY_SEPARATOR . 'host-state',
            $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json',
        );

        $first = $store->advanceInstanceGeneration('default');
        self::assertSame(1, $first['generation']);
        $untrustedFloor = $store->advanceInstanceGeneration('default', PHP_INT_MAX);
        self::assertSame(2, $untrustedFloor['generation']);

        $afterRestart = new ProjectIdentityStore(
            $project,
            $sandbox . DIRECTORY_SEPARATOR . 'host-state',
            $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json',
        );
        self::assertSame(
            3,
            $afterRestart->advanceInstanceGeneration('default')['generation'],
        );
        self::assertSame(
            1,
            $afterRestart->advanceInstanceGeneration(
                'another-instance',
                PHP_INT_MAX,
            )['generation'],
        );
    }

    public function testMissingIdentityInReadOnlyProjectFailsWithoutTemporaryUuid(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory permission test.');
        }
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'readonly');
        $etc = $project . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc';
        self::assertTrue(\chmod($etc, 0555));
        try {
            $store = new ProjectIdentityStore(
                $project,
                $sandbox . DIRECTORY_SEPARATOR . 'host-state',
                $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json',
            );
            $this->expectException(\RuntimeException::class);
            $store->projectUuid();
        } finally {
            \chmod($etc, 0755);
        }
    }

    public function testUnreadableIdentityIsNotMisreportedAsInvalidJson(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || (\function_exists('posix_geteuid') && \posix_geteuid() === 0)
        ) {
            self::markTestSkipped('POSIX non-root file permission test.');
        }
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'unreadable');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $store = new ProjectIdentityStore($project, $hostState, $legacy);
        $store->projectUuid();
        $identity = $project . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json';
        self::assertTrue(\chmod($identity, 0000));

        try {
            (new ProjectIdentityStore($project, $hostState, $legacy))->projectUuid();
            self::fail('An unreadable project fact must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Unable to open', $exception->getMessage());
            self::assertStringNotContainsString('invalid JSON', $exception->getMessage());
        } finally {
            \chmod($identity, 0600);
        }
    }

    public function testFilesystemRootsAreRejectedBeforeStatePathsAreDerived(): void
    {
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'safe-project');
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        foreach ([
            'C:\\',
            "\\\\server\\",
            "\\\\server\\share\\",
            "\\\\?\\C:\\",
            "\\\\?\\UNC\\server\\share\\",
            "\\\\?\\UNC\\server\\",
            "\\\\.\\C:\\",
            "\\\\?\\Volume{01234567-89ab-cdef-0123-456789abcdef}\\",
        ] as $hostRoot) {
            try {
                new ProjectIdentityStore($project, $hostRoot, $legacy);
                self::fail('A drive or UNC filesystem root must not become host state.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('must be absolute', $exception->getMessage());
            }
        }

        $projectFilesystemRoot = DIRECTORY_SEPARATOR === '/'
            ? '/'
            : \substr((string)\realpath(\sys_get_temp_dir()), 0, 3);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('safe WLS project root');
        new ProjectIdentityStore(
            $projectFilesystemRoot,
            $sandbox . DIRECTORY_SEPARATOR . 'host-state',
            $legacy,
        );
    }

    private function makeSandbox(): string
    {
        $root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-project-identity-test-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($root, 0700, true));
        $canonical = \realpath($root);
        self::assertIsString($canonical);
        $this->temporaryRoots[] = $canonical;
        return $canonical;
    }

    private function makeProject(string $root): string
    {
        self::assertTrue(\mkdir(
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc',
            0755,
            true,
        ));
        $real = \realpath($root);
        self::assertIsString($real);
        return $real;
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
            if ($item->isDir() && !$item->isLink()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($root);
    }
}
