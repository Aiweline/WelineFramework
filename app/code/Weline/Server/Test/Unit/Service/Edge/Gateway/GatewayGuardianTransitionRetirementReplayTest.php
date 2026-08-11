<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayGuardianGenerationHead;
use Weline\Server\Service\Edge\Gateway\GatewayGuardianTransitionProtocol;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

final class GatewayGuardianTransitionRetirementReplayTest extends TestCase
{
    private const ZERO_32 = '00000000000000000000000000000000';
    private const ZERO_64 = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';
    private GatewayPaths $paths;
    private GatewayGuardianGenerationHead $head;
    private GatewayGuardianTransitionProtocol $protocol;

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-guardian-retirement-' . \bin2hex(\random_bytes(8));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . DIRECTORY_SEPARATOR . 'host');

        $this->paths = new GatewayPaths();
        $this->paths->ensureDirectories();
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->adminTokenFile(),
            \hash('sha256', 'guardian-retirement-test-administrator'),
            0600,
        );
        $this->head = new GatewayGuardianGenerationHead($this->paths);
        $this->protocol = new GatewayGuardianTransitionProtocol(
            $this->paths,
            $this->head,
        );
        $recovery = $this->generation('initial-recovery');
        $this->head->initializeStable(
            \substr(\hash('sha256', 'guardian-retirement-test-host'), 0, 32),
            $recovery['launcher_sha256'],
            $recovery['ca_sha256'],
            $recovery['runtime_generation'],
            \hash('sha256', 'guardian-retirement-test-boot'),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    /** @return iterable<string,array{int}> */
    public static function retirementCrashWindows(): iterable
    {
        yield 'marker durable before any artifact deletion' => [0];
        yield 'request deletion durable' => [1];
        yield 'acknowledgement deletion durable' => [2];
        yield 'recovery transaction deletion durable' => [3];
    }

    #[DataProvider('retirementCrashWindows')]
    public function testRetirementResumesAfterEveryDeletionCrashWindow(
        int $deletedArtifacts,
    ): void {
        $fixture = $this->terminalRollbackFixture(
            'crash-window-' . $deletedArtifacts,
        );
        $this->publishRetirementMarker($fixture);

        $artifacts = $this->terminalArtifacts();
        for ($index = 0; $index < $deletedArtifacts; ++$index) {
            self::assertTrue(GatewayProjectStateFilesystem::removeRegular(
                $artifacts[$index],
                'simulated crash-window Guardian artifact',
            ));
        }

        $restarted = new GatewayGuardianTransitionProtocol(
            $this->paths,
            new GatewayGuardianGenerationHead($this->paths),
        );
        $restarted->retireHandshake([
            'host_id' => $fixture['host_id'],
            'nonce' => $fixture['nonce'],
        ]);

        $this->assertHandshakeArtifactsAbsent();
    }

    /** @return iterable<string,array{int}> */
    public static function commitRetirementCrashWindows(): iterable
    {
        yield 'commit marker durable before deletion' => [0];
        yield 'commit request deletion durable' => [1];
        yield 'commit acknowledgement deletion durable' => [2];
    }

    #[DataProvider('commitRetirementCrashWindows')]
    public function testCommitRetirementResumesWithoutARecoveryTransaction(
        int $deletedArtifacts,
    ): void {
        $fixture = $this->terminalCommitFixture(
            'commit-crash-window-' . $deletedArtifacts,
        );
        $this->publishRetirementMarker($fixture);
        $artifacts = [
            $this->paths->guardianTransitionRequestFile(),
            $this->paths->guardianTransitionAcknowledgementFile(),
        ];
        for ($index = 0; $index < $deletedArtifacts; ++$index) {
            self::assertTrue(GatewayProjectStateFilesystem::removeRegular(
                $artifacts[$index],
                'simulated commit crash-window Guardian artifact',
            ));
        }

        $restarted = new GatewayGuardianTransitionProtocol(
            $this->paths,
            new GatewayGuardianGenerationHead($this->paths),
        );
        $restarted->retireHandshake([
            'host_id' => $fixture['host_id'],
            'nonce' => $fixture['nonce'],
        ]);

        $this->assertHandshakeArtifactsAbsent();
    }

    public function testCommitRetirementRejectsAnUnexpectedRecoveryTransaction(): void
    {
        $fixture = $this->terminalCommitFixture('commit-unexpected-transaction');
        $request = $fixture['request'];
        $transactionRaw = (string)$this->invoke(
            $this->protocol,
            'recoveryTransactionRawAtSequence',
            [[
                'host_id' => $fixture['host_id'],
                'nonce' => $fixture['nonce'],
                'request_sha256' => \hash('sha256', $fixture['request_raw']),
                'authorization_sha256' => (string)$request['recovery_authorization_sha256'],
                'inventory_sha256' => (string)$request['recovery_inventory_sha256'],
            ], 26],
        );
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianRecoveryTransactionFile(),
            $transactionRaw,
            0600,
        );

        try {
            $this->protocol->retireHandshake([
                'host_id' => $fixture['host_id'],
                'nonce' => $fixture['nonce'],
            ]);
            self::fail('Commit retirement must reject a recovery transaction.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'commit conflicts with a recovery transaction',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($this->paths->guardianTransitionRequestFile());
        self::assertFileExists(
            $this->paths->guardianTransitionAcknowledgementFile(),
        );
        self::assertFileExists($this->paths->guardianRecoveryTransactionFile());
        self::assertFileDoesNotExist(
            $this->paths->guardianTransitionRetirementFile(),
        );
    }

    public function testTwoConsecutiveRollbackHandshakesRetireIndependently(): void
    {
        $first = $this->terminalRollbackFixture('consecutive-first');
        $this->protocol->retireHandshake([
            'host_id' => $first['host_id'],
            'nonce' => $first['nonce'],
        ]);
        $this->assertHandshakeArtifactsAbsent();
        $firstTerminalHead = $this->head->read();
        self::assertNotNull($firstTerminalHead);

        $this->protocol = new GatewayGuardianTransitionProtocol(
            $this->paths,
            new GatewayGuardianGenerationHead($this->paths),
        );
        $this->head = new GatewayGuardianGenerationHead($this->paths);
        $second = $this->terminalRollbackFixture('consecutive-second');
        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertGreaterThan(
            (int)$firstTerminalHead['sequence'],
            (int)$second['terminal_head']['sequence'],
        );

        $this->protocol->retireHandshake([
            'host_id' => $second['host_id'],
            'nonce' => $second['nonce'],
        ]);

        $this->assertHandshakeArtifactsAbsent();
    }

    /** @return iterable<string,array{bool}> */
    public static function orphanedTerminalArtifacts(): iterable
    {
        yield 'acknowledgement and transaction remain' => [true];
        yield 'only transaction remains' => [false];
    }

    #[DataProvider('orphanedTerminalArtifacts')]
    public function testTerminalOrphansWithoutRequestAreRetired(
        bool $keepAcknowledgement,
    ): void {
        $fixture = $this->terminalRollbackFixture(
            $keepAcknowledgement ? 'orphan-ack-transaction' : 'orphan-transaction',
        );
        self::assertTrue(GatewayProjectStateFilesystem::removeRegular(
            $this->paths->guardianTransitionRequestFile(),
            'simulated old Guardian request retirement',
        ));
        if (!$keepAcknowledgement) {
            self::assertTrue(GatewayProjectStateFilesystem::removeRegular(
                $this->paths->guardianTransitionAcknowledgementFile(),
                'simulated old Guardian acknowledgement retirement',
            ));
        }

        $restarted = new GatewayGuardianTransitionProtocol(
            $this->paths,
            new GatewayGuardianGenerationHead($this->paths),
        );
        $restarted->retireHandshake([
            'host_id' => $fixture['host_id'],
            'nonce' => $fixture['nonce'],
        ]);

        $this->assertHandshakeArtifactsAbsent();
    }

    public function testTamperedRetirementMarkerFailsClosed(): void
    {
        $fixture = $this->terminalRollbackFixture('tampered-retirement-marker');
        $this->publishRetirementMarker($fixture);
        $file = $this->paths->guardianTransitionRetirementFile();
        $raw = GatewayProjectStateFilesystem::read(
            $file,
            4096,
            'test Guardian retirement marker',
        );
        $signatureOffset = \strlen($raw) - 2;
        $raw[$signatureOffset] = $raw[$signatureOffset] === '0' ? '1' : '0';
        GatewayProjectStateFilesystem::atomicWrite($file, $raw, 0600);

        try {
            (new GatewayGuardianTransitionProtocol(
                $this->paths,
                new GatewayGuardianGenerationHead($this->paths),
            ))->retireHandshake([
                'host_id' => $fixture['host_id'],
                'nonce' => $fixture['nonce'],
            ]);
            self::fail('A tampered Guardian retirement marker must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'retirement authentication failed',
                $exception->getMessage(),
            );
        }
        foreach ([...$this->terminalArtifacts(), $file] as $artifact) {
            self::assertFileExists($artifact);
        }
    }

    public function testRetirementMarkerRejectsAChangedGenerationHead(): void
    {
        $fixture = $this->terminalRollbackFixture('retirement-head-mismatch');
        $this->publishRetirementMarker($fixture);
        $stableHead = $this->head->read();
        self::assertNotNull($stableHead);
        $recovery = [
            'generation_id' => (string)$stableHead['active_generation_id'],
            'launcher_sha256' => (string)$stableHead['active_launcher_sha256'],
            'ca_sha256' => (string)$stableHead['active_ca_sha256'],
            'runtime_generation' => (string)$stableHead['active_runtime_generation'],
        ];
        $this->head->transition(
            (int)$stableHead['sequence'],
            $this->headRecord(
                (string)$stableHead['host_id'],
                'ROLLBACK_PENDING',
                $this->generation('retirement-head-mismatch-candidate'),
                $recovery,
                \substr(\hash('sha256', 'changed-head-nonce'), 0, 32),
                \hash('sha256', 'changed-head-authorization'),
                (string)$stableHead['host_boot_id'],
            ),
        );

        try {
            (new GatewayGuardianTransitionProtocol(
                $this->paths,
                new GatewayGuardianGenerationHead($this->paths),
            ))->retireHandshake([
                'host_id' => $fixture['host_id'],
                'nonce' => $fixture['nonce'],
            ]);
            self::fail('A Guardian retirement marker must bind the exact head.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'retirement has no exact stable head',
                $exception->getMessage(),
            );
        }
        foreach ([
            ...$this->terminalArtifacts(),
            $this->paths->guardianTransitionRetirementFile(),
        ] as $artifact) {
            self::assertFileExists($artifact);
        }
    }

    /**
     * @return array{
     *   host_id:string,
     *   nonce:string,
     *   request:array<string,mixed>,
     *   request_raw:string,
     *   terminal_head:array<string,mixed>
     * }
     */
    private function terminalRollbackFixture(string $seed): array
    {
        $fixture = $this->transitionRequestFixture($seed);
        $stableHead = $fixture['stable_head'];
        $hostId = $fixture['host_id'];
        $nonce = $fixture['nonce'];
        $candidate = $fixture['candidate'];
        $recovery = $fixture['recovery'];
        $request = $fixture['request'];
        $requestRaw = $fixture['request_raw'];

        $bootId = (string)$stableHead['host_boot_id'];
        $pending = $this->head->transition(
            (int)$stableHead['sequence'],
            $this->headRecord(
                $hostId,
                'ROLLBACK_PENDING',
                $candidate,
                $recovery,
                $nonce,
                (string)$request['recovery_authorization_sha256'],
                $bootId,
            ),
        );
        $observing = $this->head->transition(
            (int)$pending['sequence'],
            $this->headRecord(
                $hostId,
                'ROLLBACK_OBSERVING',
                $recovery,
                $recovery,
                $nonce,
                (string)$request['recovery_authorization_sha256'],
                $bootId,
                1000,
                2000,
            ),
        );
        $terminalHead = $this->head->transition(
            (int)$observing['sequence'],
            $this->headRecord(
                $hostId,
                'STABLE',
                $recovery,
                null,
                self::ZERO_32,
                self::ZERO_64,
                $bootId,
            ),
        );

        $ack = [
            'host_id' => $hostId,
            'nonce' => $nonce,
            'request_sha256' => \hash('sha256', $requestRaw),
            'committed_head_sequence' => (int)$terminalHead['sequence'],
            'committed_head_sha256' => (string)$terminalHead['record_sha256'],
            'purpose' => 'rollback',
            'phase' => 'STABLE',
            'active_generation_id' => $recovery['generation_id'],
        ];
        $ackUnsigned = (string)$this->invoke(
            $this->protocol,
            'encodeAcknowledgementUnsigned',
            [$ack],
        );
        $ackRaw = $ackUnsigned . 'signature=' . $this->invoke(
            $this->protocol,
            'signature',
            [$ackUnsigned],
        ) . "\n";
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianTransitionAcknowledgementFile(),
            $ackRaw,
            0600,
        );

        $transactionRaw = (string)$this->invoke(
            $this->protocol,
            'recoveryTransactionRawAtSequence',
            [[
                'host_id' => $hostId,
                'nonce' => $nonce,
                'request_sha256' => \hash('sha256', $requestRaw),
                'authorization_sha256' => (string)$request['recovery_authorization_sha256'],
                'inventory_sha256' => (string)$request['recovery_inventory_sha256'],
            ], 26],
        );
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianRecoveryTransactionFile(),
            $transactionRaw,
            0600,
        );

        return [
            'host_id' => $hostId,
            'nonce' => $nonce,
            'request' => $request,
            'request_raw' => $requestRaw,
            'terminal_head' => $terminalHead,
        ];
    }

    /**
     * @return array{
     *   host_id:string,
     *   nonce:string,
     *   request:array<string,mixed>,
     *   request_raw:string,
     *   terminal_head:array<string,mixed>
     * }
     */
    private function terminalCommitFixture(string $seed): array
    {
        $fixture = $this->transitionRequestFixture($seed);
        $stableHead = $fixture['stable_head'];
        $hostId = $fixture['host_id'];
        $nonce = $fixture['nonce'];
        $candidate = $fixture['candidate'];
        $recovery = $fixture['recovery'];
        $request = $fixture['request'];
        $requestRaw = $fixture['request_raw'];
        $bootId = (string)$stableHead['host_boot_id'];
        $probation = $this->head->transition(
            (int)$stableHead['sequence'],
            $this->headRecord(
                $hostId,
                'PROBATIONARY_COMMITTED',
                $candidate,
                $recovery,
                $nonce,
                (string)$request['recovery_authorization_sha256'],
                $bootId,
                1000,
                2000,
            ),
        );
        $terminalHead = $this->head->transition(
            (int)$probation['sequence'],
            $this->headRecord(
                $hostId,
                'STABLE',
                $candidate,
                null,
                self::ZERO_32,
                self::ZERO_64,
                $bootId,
            ),
        );
        $ack = [
            'host_id' => $hostId,
            'nonce' => $nonce,
            'request_sha256' => \hash('sha256', $requestRaw),
            'committed_head_sequence' => (int)$terminalHead['sequence'],
            'committed_head_sha256' => (string)$terminalHead['record_sha256'],
            'purpose' => 'commit',
            'phase' => 'STABLE',
            'active_generation_id' => $candidate['generation_id'],
        ];
        $ackUnsigned = (string)$this->invoke(
            $this->protocol,
            'encodeAcknowledgementUnsigned',
            [$ack],
        );
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianTransitionAcknowledgementFile(),
            $ackUnsigned . 'signature=' . $this->invoke(
                $this->protocol,
                'signature',
                [$ackUnsigned],
            ) . "\n",
            0600,
        );
        self::assertFileDoesNotExist(
            $this->paths->guardianRecoveryTransactionFile(),
        );

        return [
            'host_id' => $hostId,
            'nonce' => $nonce,
            'request' => $request,
            'request_raw' => $requestRaw,
            'terminal_head' => $terminalHead,
        ];
    }

    /** @param array<string,mixed> $fixture */
    private function publishRetirementMarker(array $fixture): void
    {
        GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function () use ($fixture): void {
                $retirement = $this->invoke(
                    $this->protocol,
                    'terminalHandshakeRetirement',
                    [$fixture['request_raw'], $fixture['request']],
                );
                $this->invoke(
                    $this->protocol,
                    'publishHandshakeRetirementWhileLocked',
                    [$retirement],
                );
            },
        );
    }

    /**
     * @return array{
     *   stable_head:array<string,mixed>,
     *   host_id:string,
     *   nonce:string,
     *   candidate:array<string,string>,
     *   recovery:array<string,string>,
     *   request:array<string,mixed>,
     *   request_raw:string
     * }
     */
    private function transitionRequestFixture(string $seed): array
    {
        $stableHead = $this->head->read();
        self::assertNotNull($stableHead);
        self::assertSame('STABLE', $stableHead['phase']);
        $hostId = (string)$stableHead['host_id'];
        $nonce = \substr(\hash('sha256', $seed . '-nonce'), 0, 32);
        $candidate = $this->generation($seed . '-candidate');
        $recovery = [
            'generation_id' => (string)$stableHead['active_generation_id'],
            'launcher_sha256' => (string)$stableHead['active_launcher_sha256'],
            'ca_sha256' => (string)$stableHead['active_ca_sha256'],
            'runtime_generation' => (string)$stableHead['active_runtime_generation'],
        ];
        $request = [
            'host_id' => $hostId,
            'nonce' => $nonce,
            'expected_head_sequence' => (int)$stableHead['sequence'],
            'expected_head_sha256' => (string)$stableHead['record_sha256'],
            'journal_sha256' => \hash('sha256', $seed . '-journal'),
            'candidate_generation_id' => $candidate['generation_id'],
            'candidate_launcher_sha256' => $candidate['launcher_sha256'],
            'candidate_launcher_size' => 4096,
            'candidate_launcher_mode' => 0555,
            'candidate_ca_sha256' => $candidate['ca_sha256'],
            'candidate_runtime_generation' => $candidate['runtime_generation'],
            'recovery_generation_id' => $recovery['generation_id'],
            'recovery_launcher_sha256' => $recovery['launcher_sha256'],
            'recovery_launcher_size' => 4096,
            'recovery_launcher_mode' => 0555,
            'recovery_ca_sha256' => $recovery['ca_sha256'],
            'recovery_runtime_generation' => $recovery['runtime_generation'],
            'recovery_active_slot' => 'A',
            'recovery_previous_slot' => 'B',
            'recovery_slot_a_generation' => $recovery['runtime_generation'],
            'recovery_slot_b_generation' => \hash('sha256', $seed . '-slot-b'),
            'derived_manifest_sha256' => \hash('sha256', $seed . '-derived-manifest'),
            'derived_policy_sha256' => \hash('sha256', $seed . '-derived-policy'),
            'platform_kind' => 'test-session',
            'platform_profile' => 'default',
            'platform_definition_sha256' => \hash('sha256', $seed . '-platform-definition'),
            'platform_metadata_sha256' => \hash('sha256', $seed . '-platform-metadata'),
            'trust_rotation' => '0',
            'recovery_inventory_sha256' => \hash('sha256', $seed . '-inventory'),
            'request_binding_sha256' => '',
            'recovery_authorization_sha256' => \hash('sha256', $seed . '-authorization'),
        ];
        $request['request_binding_sha256'] = \hash(
            'sha256',
            (string)$this->invoke(
                $this->protocol,
                'encodeRequestBinding',
                [$request],
            ),
        );
        $requestUnsigned = (string)$this->invoke(
            $this->protocol,
            'encodeRequestUnsigned',
            [$request],
        );
        $request['signature'] = (string)$this->invoke(
            $this->protocol,
            'signature',
            [$requestUnsigned],
        );
        $requestRaw = $requestUnsigned
            . 'signature=' . $request['signature'] . "\n";
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianTransitionRequestFile(),
            $requestRaw,
            0600,
        );
        $request = $this->invoke(
            $this->protocol,
            'decodeRequest',
            [$requestRaw],
        );
        self::assertIsArray($request);

        return [
            'stable_head' => $stableHead,
            'host_id' => $hostId,
            'nonce' => $nonce,
            'candidate' => $candidate,
            'recovery' => $recovery,
            'request' => $request,
            'request_raw' => $requestRaw,
        ];
    }

    /**
     * @param array<string,string> $active
     * @param array<string,string>|null $recovery
     * @return array<string,mixed>
     */
    private function headRecord(
        string $hostId,
        string $phase,
        array $active,
        ?array $recovery,
        string $nonce,
        string $authorizationSha256,
        string $bootId,
        int $observationStarted = 0,
        int $observationDeadline = 0,
    ): array {
        return [
            'host_id' => $hostId,
            'phase' => $phase,
            'active_generation_id' => $active['generation_id'],
            'active_launcher_sha256' => $active['launcher_sha256'],
            'active_ca_sha256' => $active['ca_sha256'],
            'active_runtime_generation' => $active['runtime_generation'],
            'recovery_generation_id' => $recovery['generation_id'] ?? self::ZERO_64,
            'recovery_nonce' => $recovery === null ? self::ZERO_32 : $nonce,
            'recovery_authorization_sha256' => $recovery === null
                ? self::ZERO_64
                : $authorizationSha256,
            'host_boot_id' => $bootId,
            'probation_started_monotonic_ms' => $observationStarted,
            'probation_deadline_monotonic_ms' => $observationDeadline,
        ];
    }

    /** @return array<string,string> */
    private function generation(string $seed): array
    {
        $launcherSha256 = \hash('sha256', $seed . '-launcher');
        $caSha256 = \hash('sha256', $seed . '-ca');
        $runtimeGeneration = \hash('sha256', $seed . '-runtime');
        return [
            'generation_id' => GatewayGuardianGenerationHead::generationId(
                $launcherSha256,
                $caSha256,
                $runtimeGeneration,
            ),
            'launcher_sha256' => $launcherSha256,
            'ca_sha256' => $caSha256,
            'runtime_generation' => $runtimeGeneration,
        ];
    }

    /** @return list<string> */
    private function terminalArtifacts(): array
    {
        return [
            $this->paths->guardianTransitionRequestFile(),
            $this->paths->guardianTransitionAcknowledgementFile(),
            $this->paths->guardianRecoveryTransactionFile(),
        ];
    }

    private function assertHandshakeArtifactsAbsent(): void
    {
        foreach ([
            ...$this->terminalArtifacts(),
            $this->paths->guardianTransitionRetirementFile(),
        ] as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    /** @param list<mixed> $arguments */
    private function invoke(
        object $object,
        string $method,
        array $arguments = [],
    ): mixed {
        return (new \ReflectionMethod($object, $method))->invokeArgs(
            $object,
            $arguments,
        );
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $pathname = $entry->getPathname();
            $entry->isDir() && !$entry->isLink()
                ? @\rmdir($pathname)
                : @\unlink($pathname);
        }
        @\rmdir($path);
    }
}
