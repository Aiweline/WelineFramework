<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

final class NativeGatewayLauncherTest extends TestCase
{
    private const DURABLE_STATE_CONTRACT = [
        'schema_version' => 2,
        'security_ledger_read_schema' => 8,
        'security_ledger_write_schema' => 8,
        'snapshot_receipt_read_schema' => 2,
        'snapshot_receipt_write_schema' => 2,
        'snapshot_namespace' => 'snapshots-v2',
        'nonce_wal_schema' => 1,
        'nginx_test_schema' => 1,
    ];

    private const ROLLBACK_TARGET_CAPABILITIES = [
        'stable_launcher_rollback_target_proof' => true,
        'certificate_public_trust_bundle' => true,
    ];

    private string $root = '';
    private string $launcher = '';
    private string $secretKey = '';
    private bool $preserveRoot = false;

    protected function setUp(): void
    {
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_INTEGRATION') !== '1') {
            self::markTestSkipped('Set WLS_RUN_NATIVE_GATEWAY_INTEGRATION=1 for native launcher integration.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The POSIX stable launcher integration is not a Windows binary test.');
        }
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('libsodium is required for stable launcher verification.');
        }
        $temporaryRoot = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-ngl-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $keyPair = \sodium_crypto_sign_keypair();
        $publicKey = \sodium_crypto_sign_publickey($keyPair);
        $this->secretKey = \sodium_crypto_sign_secretkey($keyPair);
        $build = $this->root . DIRECTORY_SEPARATOR . 'build';
        $source = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native';
        $configure = $this->runCommand([
            'cmake',
            '-S',
            $source,
            '-B',
            $build,
            '-DWLS_RELEASE_PUBLIC_KEY_HEX=' . \bin2hex($publicKey),
            '-DCMAKE_BUILD_TYPE=Release',
        ]);
        self::assertSame(0, $configure['code'], $configure['output']);
        $compiled = $this->runCommand(['cmake', '--build', $build, '--parallel', '2']);
        self::assertSame(0, $compiled['code'], $compiled['output']);
        $this->launcher = $build . DIRECTORY_SEPARATOR . 'wls-gateway-launcher';
        self::assertTrue(\is_executable($this->launcher));
    }

    protected function tearDown(): void
    {
        if ($this->secretKey !== '') {
            \sodium_memzero($this->secretKey);
        }
        if (!$this->preserveRoot) {
            $this->removeTree($this->root);
        }
    }

    public function testSignedSlotExecutesButUnexpectedCleanExitAndTamperingAreFailures(): void
    {
        $selfTest = $this->runCommand([$this->launcher, '--self-test']);
        self::assertSame(0, $selfTest['code'], $selfTest['output']);
        $rollbackProofSelfTest = $this->runCommand([
            $this->launcher,
            '--rollback-target-proof-self-test',
        ]);
        self::assertSame(
            0,
            $rollbackProofSelfTest['code'],
            $rollbackProofSelfTest['output'],
        );
        $recoveryLedgerSelfTest = $this->runCommand([
            $this->launcher,
            '--recovery-ledger-self-test',
        ]);
        self::assertSame(
            0,
            $recoveryLedgerSelfTest['code'],
            $recoveryLedgerSelfTest['output'],
        );
        [$home, $run, $broker, $marker] = $this->createSignedHome();

        $started = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $started['code'], $started['output']);
        self::assertFileExists($marker, $started['output']);
        $arguments = (string)\file_get_contents($marker);
        self::assertStringContainsString('--admin-socket', $arguments);
        self::assertStringContainsString('--controller-user', $arguments);
        self::assertStringContainsString('--data-plane-user', $arguments);
        self::assertStringContainsString('--runtime-generation', $arguments);

        self::assertTrue(\unlink($marker));
        self::assertTrue(\chmod($broker, 0755));
        self::assertNotFalse(\file_put_contents($broker, "#!/bin/sh\nexit 9\n"));
        self::assertTrue(\chmod($broker, 0555));
        $tampered = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertNotSame(0, $tampered['code']);
        self::assertFileDoesNotExist($marker);
    }

    public function testPhysicalCapacityReserveIsCrashReplayableAndActuallyAllocated(): void
    {
        $contract = $this->runCommand([
            $this->launcher,
            '--capacity-reserve-contract-self-test',
        ]);
        self::assertSame(0, $contract['code'], $contract['output']);
        self::assertSame([
            'production_inodes' => 65_536,
            'token_fsync_batch' => 1_024,
            'production_token_directory_fsyncs' => 64,
            'test_token_directory_fsyncs' => 1,
        ], \json_decode(
            \trim($contract['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $systemdDefinitionBuffer = $this->runCommand([
            $this->launcher,
            '--guardian-systemd-definition-buffer-self-test',
        ]);
        self::assertSame(
            0,
            $systemdDefinitionBuffer['code'],
            $systemdDefinitionBuffer['output'],
        );
        self::assertSame([
            'systemd_definition_buffer' => 'ok',
        ], \json_decode(
            \trim($systemdDefinitionBuffer['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $home = $this->root . DIRECTORY_SEPARATOR . 'capacity-home';
        $nonce = '0123456789abcdef0123456789abcdef';
        $effectiveGroup = \function_exists('posix_getegid')
            ? \posix_getegid()
            : \getmygid();
        self::assertIsInt($effectiveGroup);
        foreach ([
            $home,
            $home . '/bin',
            $home . '/runtime',
            $home . '/runtime/conf',
            $home . '/runtime/temp',
            $home . '/runtime/shadow',
            $home . '/runtime/run',
            $home . '/trust',
            $home . '/state',
            $home . '/snapshots',
            $home . '/snapshots-v2',
            $home . '/snapshot-candidates-v2',
            $home . '/slots',
            $home . '/rebootstrap',
            $home . '/rebootstrap/candidates',
            $home . '/rebootstrap/backups',
            $home . '/rebootstrap/capacity',
            $home . '/rebootstrap/candidates/' . $nonce,
            $home . '/rebootstrap/candidates/' . $nonce . '/bin',
        ] as $directory) {
            self::assertTrue(\is_dir($directory) || \mkdir($directory, 0700));
            self::assertTrue(\chmod($directory, 0700));
            self::assertTrue(\chgrp($directory, $effectiveGroup));
        }
        $definition = $home . '/state/service-definition.test';
        self::assertNotFalse(\file_put_contents($definition, "test-service\n"));
        self::assertTrue(\chmod($definition, 0600));
        self::assertTrue(\chgrp($definition, $effectiveGroup));
        $candidate = $home . '/rebootstrap/candidates/' . $nonce
            . '/bin/wls-gateway-launcher';
        self::assertTrue(\copy($this->launcher, $candidate));
        self::assertTrue(\chmod($candidate, 0700));
        $common = [
            '--home=' . $home,
            '--nonce=' . $nonce,
            '--bytes=8388608',
            '--inodes=128',
            '--platform-definition=' . $definition,
            '--test-mode=1',
        ];
        $runReserve = function (
            string $launcher,
            array $arguments,
            string $operation,
            array $extra = [],
        ): array {
            return $this->runCommand([
                $launcher,
                '--capacity-reserve=' . $operation,
                ...$arguments,
                ...$extra,
            ]);
        };
        $invoke = function (string $operation, array $extra = []) use (
            $candidate,
            $common,
            $runReserve,
        ): array {
            $result = $runReserve($candidate, $common, $operation, $extra);
            self::assertSame(0, $result['code'], $result['output']);
            $decoded = \json_decode(
                \trim($result['output']),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($decoded);
            return $decoded;
        };
        $createCapacityCandidate = function (string $candidateNonce) use (
            $home,
            $definition,
        ): array {
            $candidateRoot = $home . '/rebootstrap/candidates/'
                . $candidateNonce;
            self::assertTrue(\mkdir($candidateRoot, 0700));
            self::assertTrue(\mkdir($candidateRoot . '/bin', 0700));
            $candidateLauncher = $candidateRoot . '/bin/wls-gateway-launcher';
            self::assertTrue(\copy($this->launcher, $candidateLauncher));
            self::assertTrue(\chmod($candidateLauncher, 0700));
            return [
                $candidateLauncher,
                [
                    '--home=' . $home,
                    '--nonce=' . $candidateNonce,
                    '--bytes=8388608',
                    '--inodes=128',
                    '--platform-definition=' . $definition,
                    '--test-mode=1',
                ],
            ];
        };

        $foreign = $this->root . DIRECTORY_SEPARATOR . 'foreign-capacity-anchor';
        self::assertTrue(\mkdir($foreign, 0700));
        self::assertTrue(\rmdir($home . '/snapshots-v2'));
        self::assertTrue(\symlink($foreign, $home . '/snapshots-v2'));
        $unsafeAnchor = $runReserve($candidate, $common, 'create');
        self::assertNotSame(0, $unsafeAnchor['code'], $unsafeAnchor['output']);
        $unsafeInspect = $runReserve($candidate, $common, 'inspect');
        self::assertSame(
            77,
            $unsafeInspect['code'],
            'inspect must distinguish an unsafe/foreign anchor from a valid state.',
        );
        self::assertDirectoryDoesNotExist(
            $home . '/rebootstrap/capacity/' . $nonce . '.held',
        );
        self::assertTrue(\unlink($home . '/snapshots-v2'));
        self::assertTrue(\mkdir($home . '/snapshots-v2', 0700));

        $none = $runReserve($candidate, $common, 'inspect');
        self::assertSame(0, $none['code'], $none['output']);
        self::assertSame([
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'NONE',
        ], \json_decode(
            \trim($none['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $held = $invoke('create');
        self::assertSame('HELD', $held['state']);
        $heldInspect = $runReserve($candidate, $common, 'inspect');
        self::assertSame(0, $heldInspect['code'], $heldInspect['output']);
        self::assertSame([
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'HELD',
        ], \json_decode(
            \trim($heldInspect['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        self::assertSame(128, $held['inode_count']);
        self::assertGreaterThanOrEqual(8_388_608, $held['physical_bytes']);
        foreach ([0, 1] as $index) {
            $platformReserve = $home . '/state/' . $nonce
                . '.platform.reserve.' . $index;
            $platformReserveStatus = \stat($platformReserve);
            self::assertIsArray($platformReserveStatus);
            self::assertSame(2_097_152, (int)$platformReserveStatus['size']);
            self::assertGreaterThanOrEqual(
                2_097_152,
                (int)$platformReserveStatus['blocks'] * 512,
            );
        }
        $capacityRoot = $home . '/rebootstrap/capacity';
        $manifest = $capacityRoot . '/' . $nonce . '.held.json';
        self::assertNotFalse(\file_put_contents(
            $manifest,
            "{\"nonce\":\"{$nonce}\",\"state\":\"HELD\"}\n",
        ));
        self::assertTrue(\chmod($manifest, 0600));
        $manifestHash = \hash_file('sha256', $manifest);
        self::assertIsString($manifestHash);
        $manifestDigest = '--expected-manifest-sha256=' . $manifestHash;
        $wrongManifest = $runReserve(
            $candidate,
            $common,
            'verify',
            ['--expected-manifest-sha256=' . \str_repeat('a', 64)],
        );
        self::assertNotSame(0, $wrongManifest['code'], $wrongManifest['output']);
        $verified = $invoke('verify', [$manifestDigest]);
        self::assertSame($held, $verified);
        $heldRoot = $capacityRoot . '/' . $nonce . '.held';
        $directCompletion = $runReserve(
            $candidate,
            $common,
            'complete-release',
            ['--release-reason=cancel', $manifestDigest],
        );
        self::assertNotSame(
            0,
            $directCompletion['code'],
            'HELD must pass through the durable begin-release transition.',
        );
        self::assertDirectoryExists($heldRoot);
        $byteStatus = \stat($heldRoot . '/bytes.reserve');
        self::assertIsArray($byteStatus);
        self::assertSame(8_388_608, (int)$byteStatus['size']);
        self::assertGreaterThanOrEqual(
            8_388_608,
            (int)$byteStatus['blocks'] * 512,
        );
        $tokens = \iterator_to_array(new \FilesystemIterator(
            $heldRoot . '/tokens',
            \FilesystemIterator::SKIP_DOTS,
        ));
        self::assertCount(128, $tokens);
        foreach ($tokens as $token) {
            $status = \lstat($token->getPathname());
            self::assertIsArray($status);
            self::assertSame(0, (int)$status['size']);
            self::assertSame(1, (int)$status['nlink']);
            self::assertSame(0600, ((int)$status['mode']) & 0777);
        }
        $controlStatus = \stat($heldRoot . '/control.reserve');
        self::assertIsArray($controlStatus);
        self::assertSame(1_048_576, (int)$controlStatus['size']);
        self::assertGreaterThanOrEqual(
            1_048_576,
            (int)$controlStatus['blocks'] * 512,
        );
        $controlTokens = \iterator_to_array(new \FilesystemIterator(
            $heldRoot . '/control-tokens',
            \FilesystemIterator::SKIP_DOTS,
        ));
        self::assertCount(16, $controlTokens);
        foreach ($controlTokens as $token) {
            $status = \lstat($token->getPathname());
            self::assertIsArray($status);
            self::assertSame(0, (int)$status['size']);
            self::assertSame(1, (int)$status['nlink']);
            self::assertSame(0600, ((int)$status['mode']) & 0777);
        }

        $release = ['--release-reason=forward', $manifestDigest];
        $controlHandle = \fopen($heldRoot . '/control.reserve', 'r+b');
        self::assertIsResource($controlHandle);
        self::assertSame(
            \strlen('WLS-CAPACITY-REL'),
            \fwrite($controlHandle, 'WLS-CAPACITY-REL'),
        );
        self::assertTrue(\fflush($controlHandle));
        self::assertTrue(\fsync($controlHandle));
        self::assertTrue(\fclose($controlHandle));
        self::assertSame($held, $invoke('verify', [$manifestDigest]));
        $controlHandle = \fopen($heldRoot . '/control.reserve', 'r+b');
        self::assertIsResource($controlHandle);
        self::assertSame(
            \strlen("WLS-CAPACITY-RELEASE/1\n"),
            \fwrite($controlHandle, "WLS-CAPACITY-RELEASE/1\n"),
        );
        self::assertTrue(\fflush($controlHandle));
        self::assertTrue(\fsync($controlHandle));
        self::assertTrue(\fclose($controlHandle));
        foreach ($controlTokens as $token) {
            self::assertTrue(\unlink($token->getPathname()));
        }
        self::assertTrue(\rmdir($heldRoot . '/control-tokens'));
        $transitionIsNotHeld = $runReserve(
            $candidate,
            $common,
            'verify',
            [$manifestDigest],
        );
        self::assertNotSame(
            0,
            $transitionIsNotHeld['code'],
            $transitionIsNotHeld['output'],
        );
        $releasing = $invoke('begin-release', $release);
        self::assertSame('RELEASING', $releasing['state']);
        self::assertSame(
            $held['entry_set_sha256'],
            $releasing['entry_set_sha256'],
        );
        $releasingInspect = $runReserve($candidate, $common, 'inspect');
        self::assertSame(
            0,
            $releasingInspect['code'],
            $releasingInspect['output'],
        );
        self::assertSame([
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'RELEASING',
        ], \json_decode(
            \trim($releasingInspect['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $releasingRoot = $home . '/rebootstrap/capacity/'
            . $nonce . '.releasing';
        self::assertDirectoryDoesNotExist($heldRoot);
        self::assertDirectoryExists($releasingRoot);
        self::assertFileDoesNotExist($releasingRoot . '/control.reserve');
        self::assertDirectoryDoesNotExist($releasingRoot . '/control-tokens');
        foreach ([0, 1] as $index) {
            self::assertFileDoesNotExist(
                $home . '/state/' . $nonce . '.platform.reserve.' . $index,
                'begin-release must release same-filesystem definition credits before Guardian writes.',
            );
        }
        // Once release has begun, the strict pre-stop HELD verifier stays
        // closed; only authenticated begin-release may replay the transition.
        $postRenameHeld = $runReserve(
            $candidate,
            $common,
            'verify',
            [$manifestDigest],
        );
        self::assertNotSame(0, $postRenameHeld['code'], $postRenameHeld['output']);
        self::assertSame($releasing, $invoke('begin-release', $release));
        self::assertSame(['state' => 'RELEASED'], $invoke(
            'complete-release',
            $release,
        ));
        self::assertSame(['state' => 'RELEASED'], $invoke(
            'complete-release',
            $release,
        ));
        GatewayProjectStateFilesystem::atomicWrite(
            $definition,
            "test-service-after-platform-credit-release\n",
            0600,
        );
        self::assertSame(
            "test-service-after-platform-credit-release\n",
            \file_get_contents($definition),
            'After begin-release spends the platform credits and complete-release frees the home reserve, the definition directory must support its atomic publication.',
        );
        foreach (['allocating', 'held', 'releasing'] as $state) {
            self::assertFileDoesNotExist(
                $capacityRoot . '/' . $nonce . '.' . $state,
            );
        }

        // inspect must classify a crash-left partial ALLOCATING tree without
        // giving PHP permission to manufacture a replacement reserve.  The
        // cleanup operation then removes only the exact partial tree and its
        // remaining direct platform credit.
        $allocatingNonce = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        [$allocatingCandidate, $allocatingCommon] = $createCapacityCandidate(
            $allocatingNonce,
        );
        $allocatingCreated = $runReserve(
            $allocatingCandidate,
            $allocatingCommon,
            'create',
        );
        self::assertSame(
            0,
            $allocatingCreated['code'],
            $allocatingCreated['output'],
        );
        $allocatingHeld = $capacityRoot . '/' . $allocatingNonce . '.held';
        $allocatingLive = $capacityRoot . '/' . $allocatingNonce
            . '.allocating';
        self::assertTrue(\rename($allocatingHeld, $allocatingLive));
        $allocatingToken = $allocatingLive . '/tokens/00000000.reserve';
        self::assertTrue(\unlink($allocatingToken));
        $allocatingPlatform = $home . '/state/' . $allocatingNonce
            . '.platform.reserve.';
        self::assertTrue(\unlink($allocatingPlatform . '0'));
        $allocatingInspect = $runReserve(
            $allocatingCandidate,
            $allocatingCommon,
            'inspect',
        );
        self::assertSame(
            0,
            $allocatingInspect['code'],
            $allocatingInspect['output'],
        );
        self::assertSame([
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'ALLOCATING',
        ], \json_decode(
            \trim($allocatingInspect['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $allocatingCleanup = $runReserve(
            $allocatingCandidate,
            $allocatingCommon,
            'complete-release',
            ['--release-reason=cancel'],
        );
        self::assertSame(
            0,
            $allocatingCleanup['code'],
            $allocatingCleanup['output'],
        );
        self::assertSame(
            ['state' => 'RELEASED'],
            \json_decode(
                \trim($allocatingCleanup['output']),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertDirectoryDoesNotExist($allocatingLive);
        self::assertFileDoesNotExist($allocatingPlatform . '0');
        self::assertFileDoesNotExist($allocatingPlatform . '1');

        // A contradictory namespace has no state JSON and uses the dedicated
        // conflict code.  A non-directory leaf likewise has no state JSON
        // and uses the unsafe/foreign code.
        $conflictNonce = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        [$conflictCandidate, $conflictCommon] = $createCapacityCandidate(
            $conflictNonce,
        );
        self::assertTrue(\mkdir(
            $capacityRoot . '/' . $conflictNonce . '.allocating',
            0700,
        ));
        self::assertTrue(\mkdir(
            $capacityRoot . '/' . $conflictNonce . '.held',
            0700,
        ));
        $conflictInspect = $runReserve(
            $conflictCandidate,
            $conflictCommon,
            'inspect',
        );
        self::assertSame(
            78,
            $conflictInspect['code'],
            $conflictInspect['output'],
        );

        $foreignNonce = 'cccccccccccccccccccccccccccccccc';
        [$foreignCandidate, $foreignCommon] = $createCapacityCandidate(
            $foreignNonce,
        );
        $foreignLive = $capacityRoot . '/' . $foreignNonce . '.allocating';
        self::assertSame(7, \file_put_contents($foreignLive, 'foreign'));
        self::assertTrue(\chmod($foreignLive, 0600));
        $foreignInspect = $runReserve(
            $foreignCandidate,
            $foreignCommon,
            'inspect',
        );
        self::assertSame(
            77,
            $foreignInspect['code'],
            $foreignInspect['output'],
        );

        // Recreate the only durable artifacts a power loss can leave after
        // begin-release has recorded TRANSITION and unlinked the first of
        // the two exact platform credits. The retry below is the real native
        // helper; it must accept this narrow exact subset, consume the second
        // credit, and never treat an arbitrary partial file as recoverable.
        $partialNonce = '13579bdf2468ace013579bdf2468ace0';
        $partialCandidateRoot = $home . '/rebootstrap/candidates/'
            . $partialNonce;
        self::assertTrue(\mkdir($partialCandidateRoot, 0700));
        self::assertTrue(\mkdir($partialCandidateRoot . '/bin', 0700));
        $partialCandidate = $partialCandidateRoot . '/bin/wls-gateway-launcher';
        self::assertTrue(\copy($this->launcher, $partialCandidate));
        self::assertTrue(\chmod($partialCandidate, 0700));
        $partialCommon = [
            '--home=' . $home,
            '--nonce=' . $partialNonce,
            '--bytes=8388608',
            '--inodes=128',
            '--platform-definition=' . $definition,
            '--test-mode=1',
        ];
        $partialCreated = $runReserve(
            $partialCandidate,
            $partialCommon,
            'create',
        );
        self::assertSame(0, $partialCreated['code'], $partialCreated['output']);
        $partialManifest = $capacityRoot . '/' . $partialNonce . '.held.json';
        self::assertNotFalse(\file_put_contents(
            $partialManifest,
            '{"nonce":"' . $partialNonce . '","state":"HELD"}' . PHP_EOL,
        ));
        self::assertTrue(\chmod($partialManifest, 0600));
        $partialManifestHash = \hash_file('sha256', $partialManifest);
        self::assertIsString($partialManifestHash);
        $partialRelease = [
            '--release-reason=forward',
            '--expected-manifest-sha256=' . $partialManifestHash,
        ];
        $partialHeldRoot = $capacityRoot . '/' . $partialNonce . '.held';
        $partialReleasingRoot = $capacityRoot . '/' . $partialNonce
            . '.releasing';
        $partialControl = \fopen($partialHeldRoot . '/control.reserve', 'r+b');
        self::assertIsResource($partialControl);
        self::assertSame(
            \strlen("WLS-CAPACITY-RELEASE/1\n"),
            \fwrite($partialControl, "WLS-CAPACITY-RELEASE/1\n"),
        );
        self::assertTrue(\fflush($partialControl));
        self::assertTrue(\fsync($partialControl));
        self::assertTrue(\fclose($partialControl));
        foreach (new \FilesystemIterator(
            $partialHeldRoot . '/control-tokens',
            \FilesystemIterator::SKIP_DOTS,
        ) as $token) {
            self::assertTrue(\unlink($token->getPathname()));
        }
        self::assertTrue(\rmdir($partialHeldRoot . '/control-tokens'));
        self::assertTrue(\rename($partialHeldRoot, $partialReleasingRoot));
        $partialPlatformReserve = $home . '/state/' . $partialNonce
            . '.platform.reserve.';
        self::assertTrue(\unlink($partialPlatformReserve . '0'));
        self::assertFileDoesNotExist($partialPlatformReserve . '0');
        self::assertFileExists($partialPlatformReserve . '1');

        $partialReplay = $runReserve(
            $partialCandidate,
            $partialCommon,
            'begin-release',
            $partialRelease,
        );
        self::assertSame(0, $partialReplay['code'], $partialReplay['output']);
        self::assertSame('RELEASING', \json_decode(
            \trim($partialReplay['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['state']);
        self::assertFileDoesNotExist($partialPlatformReserve . '0');
        self::assertFileDoesNotExist($partialPlatformReserve . '1');
        self::assertFileDoesNotExist(
            $partialReleasingRoot . '/control.reserve',
        );
        $partialCompleted = $runReserve(
            $partialCandidate,
            $partialCommon,
            'complete-release',
            $partialRelease,
        );
        self::assertSame(0, $partialCompleted['code'], $partialCompleted['output']);
        self::assertDirectoryDoesNotExist($partialReleasingRoot);

        // Missing or partial control credits without the durable release
        // marker are corruption, not a crash-replayable release transition.
        $tamperNonce = 'fedcba9876543210fedcba9876543210';
        $tamperCandidateRoot = $home . '/rebootstrap/candidates/' . $tamperNonce;
        self::assertTrue(\mkdir($tamperCandidateRoot, 0700));
        self::assertTrue(\mkdir($tamperCandidateRoot . '/bin', 0700));
        $tamperCandidate = $tamperCandidateRoot . '/bin/wls-gateway-launcher';
        self::assertTrue(\copy($this->launcher, $tamperCandidate));
        self::assertTrue(\chmod($tamperCandidate, 0700));
        $tamperCommon = [
            '--home=' . $home,
            '--nonce=' . $tamperNonce,
            '--bytes=8388608',
            '--inodes=128',
            '--platform-definition=' . $definition,
            '--test-mode=1',
        ];
        $tamperCreated = $runReserve(
            $tamperCandidate,
            $tamperCommon,
            'create',
        );
        self::assertSame(0, $tamperCreated['code'], $tamperCreated['output']);
        $tamperManifest = $capacityRoot . '/' . $tamperNonce . '.held.json';
        self::assertNotFalse(\file_put_contents(
            $tamperManifest,
            "{\"nonce\":\"{$tamperNonce}\",\"state\":\"HELD\"}\n",
        ));
        self::assertTrue(\chmod($tamperManifest, 0600));
        $tamperManifestHash = \hash_file('sha256', $tamperManifest);
        self::assertIsString($tamperManifestHash);
        $tamperManifestArgument = '--expected-manifest-sha256='
            . $tamperManifestHash;
        $tamperHeld = $capacityRoot . '/' . $tamperNonce . '.held';
        $missingToken = $tamperHeld . '/control-tokens/00000000.reserve';
        self::assertTrue(\unlink($missingToken));
        $partialControl = $runReserve(
            $tamperCandidate,
            $tamperCommon,
            'verify',
            [$tamperManifestArgument],
        );
        self::assertNotSame(0, $partialControl['code'], $partialControl['output']);
        $partialRelease = $runReserve(
            $tamperCandidate,
            $tamperCommon,
            'begin-release',
            ['--release-reason=cancel', $tamperManifestArgument],
        );
        self::assertNotSame(0, $partialRelease['code'], $partialRelease['output']);
        self::assertSame(0, \file_put_contents($missingToken, ''));
        self::assertTrue(\chmod($missingToken, 0600));
        $restoredControl = $runReserve(
            $tamperCandidate,
            $tamperCommon,
            'verify',
            [$tamperManifestArgument],
        );
        self::assertSame(0, $restoredControl['code'], $restoredControl['output']);
        self::assertTrue(\unlink($tamperHeld . '/control.reserve'));
        $missingControl = $runReserve(
            $tamperCandidate,
            $tamperCommon,
            'verify',
            [$tamperManifestArgument],
        );
        self::assertNotSame(0, $missingControl['code'], $missingControl['output']);
        $missingControlRelease = $runReserve(
            $tamperCandidate,
            $tamperCommon,
            'begin-release',
            ['--release-reason=cancel', $tamperManifestArgument],
        );
        self::assertNotSame(
            0,
            $missingControlRelease['code'],
            $missingControlRelease['output'],
        );
        $cancelled = $runReserve(
            $tamperCandidate,
            $tamperCommon,
            'complete-release',
            ['--release-reason=cancel', $tamperManifestArgument],
        );
        self::assertNotSame(
            0,
            $cancelled['code'],
            'A corrupted HELD reserve must not bypass begin-release cleanup.',
        );
        self::assertDirectoryExists($tamperHeld);
    }

    public function testGuardianPlatformShutdownUsesMainOnlyGracefulTreeHandoff(): void
    {
        $shutdown = $this->runCommand([
            $this->launcher,
            '--guardian-platform-shutdown-self-test',
        ]);

        self::assertSame(0, $shutdown['code'], $shutdown['output']);
        self::assertSame([
            'platform_grace_ms' => 300_000,
            'guardian_budget_ms' => 320_000,
            'crash_grace_ms' => 5_000,
            'main_only_signal' => true,
        ], \json_decode(
            \trim($shutdown['output']),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function testSeparatePlatformFilesystemReserveIsConsumableBeforeCompletion(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux' || !\is_dir('/dev/shm')) {
            self::markTestSkipped('A separate Linux tmpfs is required for the platform-capacity fixture.');
        }
        $home = $this->root . DIRECTORY_SEPARATOR . 'separate-platform-capacity-home';
        $nonce = '11111111111111111111111111111111';
        $effectiveGroup = \function_exists('posix_getegid')
            ? \posix_getegid()
            : \getmygid();
        self::assertIsInt($effectiveGroup);
        foreach ([
            $home,
            $home . '/bin',
            $home . '/runtime',
            $home . '/runtime/conf',
            $home . '/runtime/temp',
            $home . '/runtime/shadow',
            $home . '/runtime/run',
            $home . '/trust',
            $home . '/state',
            $home . '/snapshots',
            $home . '/snapshots-v2',
            $home . '/snapshot-candidates-v2',
            $home . '/slots',
            $home . '/rebootstrap',
            $home . '/rebootstrap/candidates',
            $home . '/rebootstrap/backups',
            $home . '/rebootstrap/capacity',
            $home . '/rebootstrap/candidates/' . $nonce,
            $home . '/rebootstrap/candidates/' . $nonce . '/bin',
        ] as $directory) {
            self::assertTrue(\is_dir($directory) || \mkdir($directory, 0700));
            self::assertTrue(\chmod($directory, 0700));
            self::assertTrue(\chgrp($directory, $effectiveGroup));
        }

        $platformRoot = '/dev/shm/wls-ngl-platform-' . \bin2hex(\random_bytes(8));
        $platformCreated = false;
        try {
            if (!@\mkdir($platformRoot, 0700)) {
                self::markTestSkipped('Unable to create the isolated platform-capacity fixture.');
            }
            $platformCreated = true;
            self::assertTrue(\chmod($platformRoot, 0700));
            self::assertTrue(\chgrp($platformRoot, $effectiveGroup));
            $homeStatus = \stat($home);
            $platformStatus = \stat($platformRoot);
            self::assertIsArray($homeStatus);
            self::assertIsArray($platformStatus);
            if ((int)$homeStatus['dev'] === (int)$platformStatus['dev']) {
                self::markTestSkipped('/dev/shm is not a separate filesystem on this runner.');
            }

            $definition = $platformRoot . '/service-definition.test';
            self::assertNotFalse(\file_put_contents($definition, "test-service\n"));
            self::assertTrue(\chmod($definition, 0600));
            self::assertTrue(\chgrp($definition, $effectiveGroup));
            $candidate = $home . '/rebootstrap/candidates/' . $nonce
                . '/bin/wls-gateway-launcher';
            self::assertTrue(\copy($this->launcher, $candidate));
            self::assertTrue(\chmod($candidate, 0700));
            $common = [
                '--home=' . $home,
                '--nonce=' . $nonce,
                '--bytes=8388608',
                '--inodes=128',
                '--platform-definition=' . $definition,
                '--test-mode=1',
            ];
            $run = function (string $operation, array $extra = []) use (
                $candidate,
                $common,
            ): array {
                $result = $this->runCommand([
                    $candidate,
                    '--capacity-reserve=' . $operation,
                    ...$common,
                    ...$extra,
                ]);
                self::assertSame(0, $result['code'], $result['output']);
                $decoded = \json_decode(
                    \trim($result['output']),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                self::assertIsArray($decoded);
                return $decoded;
            };

            self::assertSame('HELD', $run('create')['state']);
            foreach ([0, 1] as $index) {
                $reserve = $platformRoot . '/' . $nonce
                    . '.platform.reserve.' . $index;
                $status = \stat($reserve);
                self::assertIsArray($status);
                self::assertSame(2_097_152, (int)$status['size']);
                self::assertGreaterThanOrEqual(
                    2_097_152,
                    (int)$status['blocks'] * 512,
                );
            }
            $manifest = $home . '/rebootstrap/capacity/' . $nonce . '.held.json';
            self::assertNotFalse(\file_put_contents(
                $manifest,
                "{\"nonce\":\"{$nonce}\",\"state\":\"HELD\"}\n",
            ));
            self::assertTrue(\chmod($manifest, 0600));
            $digest = \hash_file('sha256', $manifest);
            self::assertIsString($digest);
            $release = [
                '--release-reason=forward',
                '--expected-manifest-sha256=' . $digest,
            ];
            self::assertSame('RELEASING', $run('begin-release', $release)['state']);
            foreach ([0, 1] as $index) {
                self::assertFileDoesNotExist(
                    $platformRoot . '/' . $nonce . '.platform.reserve.' . $index,
                    'begin-release must make each same-directory capacity credit consumable.',
                );
            }
            self::assertSame(['state' => 'RELEASED'], $run('complete-release', $release));
        } finally {
            if ($platformCreated) {
                $this->removeTree($platformRoot);
            }
        }
    }

    public function testActiveSlotRequiresAnExactContractGenerationAndSingleLinkClosure(): void
    {
        [$home, $run, $broker, $marker] = $this->createSignedHome();
        $manifestFile = $home . DIRECTORY_SEPARATOR . 'slots/A/manifest.json';
        $base = \json_decode(
            (string)\file_get_contents($manifestFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($base);
        $variants = [];

        $missing = $base;
        unset($missing['durable_state_contract']);
        $variants['missing contract'] = $this->sealRuntimeGeneration($missing);

        $extra = $base;
        $extra['durable_state_contract']['future_schema'] = 1;
        $variants['extra contract field'] = $this->sealRuntimeGeneration($extra);

        $wrong = $base;
        $wrong['durable_state_contract']['security_ledger_read_schema'] = 6;
        $variants['wrong contract value'] = $this->sealRuntimeGeneration($wrong);

        $wrongType = $base;
        $wrongType['durable_state_contract']['nonce_wal_schema'] = '1';
        $variants['wrong contract type'] = $this->sealRuntimeGeneration($wrongType);

        $capability = $base;
        $capability['capabilities']['stable_launcher_rollback_target_proof'] = false;
        $variants['false proof capability'] = $this->sealRuntimeGeneration($capability);

        $generation = $base;
        $generation['runtime_generation'] = \str_repeat('0', 64);
        $variants['forged runtime generation'] = $generation;

        foreach ($variants as $label => $variant) {
            $this->writeInstalledManifest(
                $manifestFile,
                \json_encode(
                    $variant,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL,
                $label,
            );
            @\unlink($marker);
            $blocked = $this->runCommand([
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ]);
            self::assertSame(1, $blocked['code'], $label . ': ' . $blocked['output']);
            self::assertStringContainsString(
                'active gateway slot lacks the exact WLS 2.0 durable-state contract',
                $blocked['output'],
                $label,
            );
            self::assertFileDoesNotExist($marker, $label);
        }

        $valid = \json_encode(
            $base,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $duplicate = \preg_replace(
            '/\A\{\n    "schema_version": 2,\n/D',
            "{\n    \"schema_version\": 2,\n    \"schema_version\": 2,\n",
            $valid,
            1,
        );
        self::assertIsString($duplicate);
        self::assertNotSame($valid, $duplicate);
        $this->writeInstalledManifest($manifestFile, $duplicate);
        $duplicateBlocked = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $duplicateBlocked['code'], $duplicateBlocked['output']);
        self::assertFileDoesNotExist($marker);

        $this->writeInstalledManifest($manifestFile, $valid);
        $alias = $broker . '.hardlink';
        self::assertTrue(\link($broker, $alias));
        $hardlinkBlocked = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $hardlinkBlocked['code'], $hardlinkBlocked['output']);
        self::assertFileDoesNotExist($marker);
    }

    public function testActiveSlotRejectsReleaseAndInstalledComponentModeDisagreement(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $slot = $home . DIRECTORY_SEPARATOR . 'slots/A';
        $releaseManifestFile = $slot . DIRECTORY_SEPARATOR . 'release/manifest.json';
        $releaseSignatureFile = $slot . DIRECTORY_SEPARATOR . 'release/manifest.sig';
        $release = \json_decode(
            (string)\file_get_contents($releaseManifestFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($release);
        $release['components']['bin/php']['mode'] = 0700;
        $releaseBytes = \json_encode(
            $release,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        self::assertTrue(\chmod($releaseManifestFile, 0644));
        self::assertNotFalse(\file_put_contents($releaseManifestFile, $releaseBytes));
        self::assertTrue(\chmod($releaseManifestFile, 0444));
        self::assertTrue(\chmod($releaseSignatureFile, 0644));
        self::assertNotFalse(\file_put_contents(
            $releaseSignatureFile,
            \base64_encode(\sodium_crypto_sign_detached(
                $releaseBytes,
                $this->secretKey,
            )) . PHP_EOL,
        ));
        self::assertTrue(\chmod($releaseSignatureFile, 0444));

        $installedFile = $slot . DIRECTORY_SEPARATOR . 'manifest.json';
        $installed = \json_decode(
            (string)\file_get_contents($installedFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($installed);
        $installed['components']['release/manifest.json'] =
            $this->componentDefinition($releaseManifestFile, 0444);
        $installed['components']['release/manifest.sig'] =
            $this->componentDefinition($releaseSignatureFile, 0444);
        $installed = $this->sealRuntimeGeneration($installed);
        $this->writeInstalledManifest(
            $installedFile,
            \json_encode(
                $installed,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );

        $blocked = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $blocked['code'], $blocked['output']);
        self::assertStringContainsString(
            'active gateway slot lacks the exact WLS 2.0 durable-state contract',
            $blocked['output'],
        );
        self::assertFileDoesNotExist($marker);
    }

    public function testActiveSlotRejectsNonTraversableProductionDirectories(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $directories = [
            $home => 0751,
            $home . DIRECTORY_SEPARATOR . 'slots' => 0755,
            $home . DIRECTORY_SEPARATOR . 'slots/A' => 0755,
            $home . DIRECTORY_SEPARATOR . 'slots/A/bin' => 0755,
            $home . DIRECTORY_SEPARATOR . 'slots/A/app' => 0755,
            $home . DIRECTORY_SEPARATOR . 'slots/A/release' => 0755,
        ];
        foreach ($directories as $directory => $expectedMode) {
            self::assertTrue(\chmod($directory, 0700), $directory);
            $blocked = $this->runCommand([
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ]);
            self::assertSame(1, $blocked['code'], $directory . ': ' . $blocked['output']);
            self::assertStringContainsString(
                'active gateway slot lacks the exact WLS 2.0 durable-state contract',
                $blocked['output'],
                $directory,
            );
            self::assertFileDoesNotExist($marker, $directory);
            self::assertTrue(\chmod($directory, $expectedMode), $directory);
        }
    }

    public function testActiveSlotRejectsEveryInstalledModeBoundary(): void
    {
        [$home, $run, $broker, $marker] = $this->createSignedHome();
        $slot = $home . DIRECTORY_SEPARATOR . 'slots/A';
        $installedFile = $slot . DIRECTORY_SEPARATOR . 'manifest.json';
        $releaseManifest = $slot . DIRECTORY_SEPARATOR . 'release/manifest.json';
        $releaseSignature = $slot . DIRECTORY_SEPARATOR . 'release/manifest.sig';
        $base = (string)\file_get_contents($installedFile);

        foreach ([
            [$installedFile, 0400, 0444, 'installed manifest actual mode'],
            [$broker, 0500, 0555, 'installed executable actual mode'],
            [$releaseManifest, 0400, 0444, 'release manifest actual mode'],
            [$releaseSignature, 0400, 0444, 'release signature actual mode'],
        ] as [$file, $invalidMode, $validMode, $label]) {
            self::assertTrue(\chmod($file, $invalidMode), $label);
            $this->assertSlotContractRejected($home, $run, $marker, $label);
            self::assertTrue(\chmod($file, $validMode), $label);
        }

        $installed = \json_decode($base, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($installed);
        $installed['components']['bin/php']['mode'] = 0550;
        $installed = $this->sealRuntimeGeneration($installed);
        $this->writeInstalledManifest(
            $installedFile,
            \json_encode(
                $installed,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
        $this->assertSlotContractRejected(
            $home,
            $run,
            $marker,
            'installed component declared mode',
        );
        $this->writeInstalledManifest($installedFile, $base);
    }

    public function testReservedBrokerExitCannotImpersonateLauncherReload(): void
    {
        $marker = $this->root . DIRECTORY_SEPARATOR . 'reserved-exit-started';
        $broker = "#!/bin/sh\nprintf 'started\\n' >> " . \escapeshellarg($marker)
            . "\nexit 254\n";
        [$home, $run] = $this->createSignedHome(false, $broker);

        $started = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $started['code'], $started['output']);
        self::assertSame(['started'], \file($marker, FILE_IGNORE_NEW_LINES));
    }

    public function testSignedAdminStopOwnsNonZeroBrokerExit(): void
    {
        $trust = $this->root . DIRECTORY_SEPARATOR . 'home/trust';
        $intent = $trust . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
        $pending = $trust . DIRECTORY_SEPARATOR . 'admin-stopped.pending';
        $marker = $this->root . DIRECTORY_SEPARATOR . 'admin-stop-broker-started';
        $broker = "#!/bin/sh\nprintf 'started\\n' > " . \escapeshellarg($marker)
            . "\ncp " . \escapeshellarg($pending) . ' ' . \escapeshellarg($intent)
            . "\nexit 7\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $payload = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'epoch=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        self::assertNotFalse(\file_put_contents(
            $pending,
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);

        $stopped = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(0, $stopped['code'], $stopped['output']);
        self::assertFileExists($marker);
        self::assertFileExists($intent);
    }

    public function testSignedAndDamagedAdminStoppedIntentBothBlockAutomaticLaunch(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $state = $home . DIRECTORY_SEPARATOR . 'trust';
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $state . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $payload = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'epoch=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $intentFile = $state . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
        self::assertNotFalse(\file_put_contents(
            $intentFile,
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);

        $stopped = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $stopped['code'], $stopped['output']);
        self::assertStringContainsString('signed ADMIN_STOPPED', $stopped['output']);
        self::assertFileDoesNotExist($marker);

        self::assertNotFalse(\file_put_contents($intentFile, "damaged\n"));
        $damaged = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $damaged['code'], $damaged['output']);
        self::assertStringContainsString('invalid ADMIN_STOPPED', $damaged['output']);
        self::assertFileDoesNotExist($marker);
    }

    public function testStartAuthorizedRebootstrapAllowsTheRealLauncherToSpawnTheNewGeneration(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $journal = $this->writeRebootstrapJournal($home, 'START_AUTHORIZED');
        $transaction = $home . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'rebootstrap.transaction';
        self::assertFileDoesNotExist(
            $home . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'admin-stopped.intent',
            'HostManager consumes the signed stop intent immediately before platform start.',
        );

        $tampered = $journal;
        $tampered['signature'] = ($tampered['signature'][0] === '0' ? '1' : '0')
            . \substr($tampered['signature'], 1);
        self::assertNotFalse(\file_put_contents(
            $transaction,
            GatewayClient::canonicalJson($tampered) . "\n",
        ));
        $blockedTamper = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $blockedTamper['code'], $blockedTamper['output']);
        self::assertFileDoesNotExist($marker);

        $preStart = $journal;
        $preStart['phase'] = 'PLATFORM_REFRESHED';
        $preStart = $this->signRebootstrapJournal($preStart, $home);
        self::assertNotFalse(\file_put_contents(
            $transaction,
            GatewayClient::canonicalJson($preStart) . "\n",
        ));
        $blockedPhase = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $blockedPhase['code'], $blockedPhase['output']);
        self::assertFileDoesNotExist($marker);

        self::assertNotFalse(\file_put_contents(
            $transaction,
            GatewayClient::canonicalJson($journal) . "\n",
        ));

        $started = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $started['code'], $started['output']);
        self::assertFileExists(
            $marker,
            'A signed START_AUTHORIZED journal must not be mistaken for an ordinary maintenance fence.',
        );
    }

    public function testTerminalRebootstrapRollbackRestartsTheRestoredOldGeneration(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $journal = $this->writeRebootstrapJournal(
            $home,
            'ROLLING_BACK',
            true,
        );
        $blocked = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $blocked['code'], $blocked['output']);
        self::assertFileDoesNotExist($marker);

        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $transaction = $trust . DIRECTORY_SEPARATOR
            . 'rebootstrap.transaction';
        $journal['phase'] = 'ROLLBACK_START_AUTHORIZED';
        $journal['updated_at'] = (int)$journal['updated_at'] + 1;
        $journal = $this->signRebootstrapJournal($journal, $home);
        self::assertNotFalse(\file_put_contents(
            $transaction,
            GatewayClient::canonicalJson($journal) . "\n",
        ));
        self::assertTrue(\unlink(
            $trust . DIRECTORY_SEPARATOR . 'admin-stopped.intent',
        ));
        $this->writeRebootstrapStartAuthorization($home, $journal);

        $authorized = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $authorized['code'], $authorized['output']);
        self::assertFileExists(
            $marker,
            'The signed rollback authorization must restart the restored old generation while the transaction remains durable.',
        );

        self::assertTrue(\unlink($transaction));
        self::assertTrue(\unlink(
            $trust . DIRECTORY_SEPARATOR . 'rebootstrap-start.authorization',
        ));
        $receiptDirectory = $trust . DIRECTORY_SEPARATOR
            . 'rebootstrap.receipts';
        self::assertTrue(\mkdir($receiptDirectory, 0700));
        $journal['phase'] = 'ROLLED_BACK';
        $journal['retained_backup_state'] = 'RETAINED';
        $journal['signature'] = '';
        $journal = $this->signRebootstrapJournal($journal, $home);
        $receipt = $receiptDirectory . DIRECTORY_SEPARATOR . $journal['nonce']
            . '.json';
        self::assertNotFalse(\file_put_contents(
            $receipt,
            GatewayClient::canonicalJson($journal) . "\n",
        ));
        self::assertTrue(\chmod($receipt, 0600));
        self::assertTrue(\unlink($marker));

        $restarted = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $restarted['code'], $restarted['output']);
        self::assertFileExists(
            $marker,
            'A terminal rollback receipt must coexist with a restarted restored slot.',
        );
    }

    public function testRebootstrapStartAuthorizationRejectsTamperingAndAcceptsTheSignedDigestBridge(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $journal = $this->writeRebootstrapJournal($home, 'START_AUTHORIZED');
        $authorization = $home . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'rebootstrap-start.authorization';
        $contents = (string)\file_get_contents($authorization);
        self::assertMatchesRegularExpression('/signature=[a-f0-9]{64}\n\z/D', $contents);
        $tampered = \preg_replace_callback(
            '/signature=([a-f0-9])/',
            static fn (array $match): string => 'signature='
                . ($match[1] === '0' ? '1' : '0'),
            $contents,
            1,
        );
        self::assertIsString($tampered);
        self::assertNotSame($contents, $tampered);
        self::assertNotFalse(\file_put_contents($authorization, $tampered));

        $blocked = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $blocked['code'], $blocked['output']);
        self::assertFileDoesNotExist($marker);

        $journalDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson($journal) . "\n",
        );
        $wrongPrimary = $this->differentHexValue($journalDigest);
        $wrongSecondary = ($journalDigest[0] === '2' ? '3' : '2')
            . \substr($journalDigest, 1);
        $this->writeRebootstrapStartAuthorization(
            $home,
            $journal,
            $wrongPrimary,
            $wrongSecondary,
        );
        $blockedDigest = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(0, $blockedDigest['code'], $blockedDigest['output']);
        self::assertFileDoesNotExist($marker);

        $this->writeRebootstrapStartAuthorization(
            $home,
            $journal,
            $wrongPrimary,
            $journalDigest,
        );
        $bridged = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $bridged['code'], $bridged['output']);
        self::assertFileExists(
            $marker,
            'A correctly signed transition bridge may authorize the exact secondary journal after-image.',
        );
    }

    public function testRebootstrapStartAuthorizationRejectsEveryResignedIdentityMismatch(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $journal = $this->writeRebootstrapJournal($home, 'START_AUTHORIZED');
        $mismatches = [
            'host identity' => [
                'host_id' => $this->differentHexValue(
                    (string)$journal['host_id'],
                ),
            ],
            'active slot' => ['active_slot' => 'B'],
            'runtime generation' => [
                'runtime_generation' => $this->differentHexValue(
                    (string)$journal['runtime_generation'],
                ),
            ],
            'stable launcher' => [
                'stable_launcher_sha256' => $this->differentHexValue(
                    (string)$journal['candidate_launcher_sha256'],
                ),
            ],
        ];

        foreach ($mismatches as $label => $overrides) {
            $this->writeRebootstrapStartAuthorization(
                $home,
                $journal,
                descriptorOverrides: $overrides,
            );
            $blocked = $this->runCommand([
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ]);
            self::assertSame(0, $blocked['code'], $label . ': ' . $blocked['output']);
            self::assertFileDoesNotExist($marker, $label);
        }
    }

    public function testOrphanedRebootstrapStartAuthorizationDoesNotBecomeAMaintenanceFence(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome();
        $this->writeRebootstrapJournal($home, 'START_AUTHORIZED');
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        self::assertTrue(\unlink(
            $trust . DIRECTORY_SEPARATOR . 'rebootstrap.transaction',
        ));
        self::assertFileExists(
            $trust . DIRECTORY_SEPARATOR . 'rebootstrap-start.authorization',
        );

        foreach ([
            'rebootstrap.transaction',
            'rebootstrap.transaction.wls-backup-0123456789abcdef',
            'rebootstrap.transaction.tmp-0123456789abcdef01234567',
            'rebootstrap.transaction.wls-backup-not-hex',
            'rebootstrap.transaction.TMP-malformed',
            'ReBootstrap.Transaction.WLS-BACKUP-0123456789ABCDEF',
            'REBOOTSTRAP.TRANSACTION',
        ] as $leaf) {
            $artifact = $trust . DIRECTORY_SEPARATOR . $leaf;
            self::assertNotFalse(\file_put_contents($artifact, "recovery\n"));
            self::assertTrue(\chmod($artifact, 0600));
            $blocked = $this->runCommand([
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ]);
            self::assertSame(0, $blocked['code'], $leaf . ': ' . $blocked['output']);
            self::assertFileDoesNotExist($marker, $leaf);
            self::assertFileExists($artifact, $leaf . ' must remain operator-owned');
            self::assertTrue(\unlink($artifact), $leaf);
        }

        $started = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $started['code'], $started['output']);
        self::assertFileExists(
            $marker,
            'An orphan authorization marker has no authority after its transaction is gone.',
        );
    }

    public function testThirdCandidateCrashWithinObservationWindowRollsBackWholeSlot(): void
    {
        [$home, $run, , $activeMarker] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR . 'candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
        );
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
        );
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $started = $this->runCommand([
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ]);
            self::assertSame(1, $started['code'], $started['output']);
            self::assertFileExists(
                $candidateMarker,
                $started['output'] . "\nactive=" . (string)@\file_get_contents(
                    $trust . DIRECTORY_SEPARATOR . 'active-slot',
                ) . "\nstate=" . (string)@\file_get_contents(
                    $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
                ) . "\nintent=" . (string)@\file_get_contents(
                    $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
                ),
            );
            self::assertTrue(\unlink($candidateMarker));
            self::assertFileDoesNotExist($activeMarker);
        }

        $rolledBack = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $rolledBack['code'], $rolledBack['output']);
        self::assertStringContainsString(
            'rollback awaits old-slot health proof',
            $rolledBack['output'],
        );
        self::assertFileExists($activeMarker);
        self::assertFileExists($candidateMarker);
        self::assertTrue(\unlink($candidateMarker));
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
        );
        self::assertFileExists($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
        self::assertStringContainsString(
            "phase=ROLLBACK_PENDING\n",
            (string)\file_get_contents($trust . DIRECTORY_SEPARATOR . 'upgrade-state'),
        );
        self::assertSame(
            "B\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'previous-slot'),
        );

        // Model a power loss after active-slot=A became durable but before the
        // inverse previous-slot=B write. The terminal rollback must not become
        // eligible until the launcher repairs and rereads that exact pointer.
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        $recovered = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $recovered['code'], $recovered['output']);
        self::assertSame(
            "B\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'previous-slot'),
        );
        self::assertFileExists($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
        self::assertStringContainsString(
            "phase=ROLLBACK_PENDING\n",
            (string)\file_get_contents($trust . DIRECTORY_SEPARATOR . 'upgrade-state'),
        );

        // A later launcher may inherit the crash image with active already
        // pointing at from.  If that old slot no longer proves contract-v2,
        // it must not repair previous-slot or start the old Broker.
        $this->invalidateRollbackTargetContract($home, 'A');
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        $blockedRecovery = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $blockedRecovery['code'], $blockedRecovery['output']);
        self::assertStringContainsString(
            'rollback target lacks the exact WLS 2.0 durable-state contract',
            $blockedRecovery['output'],
        );
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
        );
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'previous-slot'),
        );
        self::assertStringContainsString(
            "phase=ROLLBACK_PENDING\n",
            (string)\file_get_contents($trust . DIRECTORY_SEPARATOR . 'upgrade-state'),
        );
    }

    public function testCandidateIntentFromAnotherBootCanOnlyRollBack(): void
    {
        [$home, $run, , $activeMarker] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR
            . 'cross-boot-candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
            true,
        );
        $currentBoot = GatewayHostBootIdentity::current();
        $foreignBoot = ($currentBoot[0] === 'a' ? 'b' : 'a')
            . \substr($currentBoot, 1);
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
            $foreignBoot,
        );
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        $rolledBack = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $rolledBack['code'], $rolledBack['output']);
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
        );
        self::assertFileExists($activeMarker);
        self::assertFileDoesNotExist(
            $candidateMarker,
            $rolledBack['output'] . "\nstate=" . (string)@\file_get_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
            ),
        );
        $state = (string)\file_get_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
        );
        self::assertStringStartsWith("WLS-UPGRADE-STATE/3\n", $state);
        self::assertStringContainsString("phase=ROLLBACK_PENDING\n", $state);
        self::assertStringContainsString('boot_id=' . $currentBoot . "\n", $state);
    }

    public function testAutomaticRollbackNeverPublishesAnIncompatibleOldSlot(): void
    {
        [$home, $run, , $activeMarker] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR
            . 'incompatible-rollback-candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
            true,
        );
        $currentBoot = GatewayHostBootIdentity::current();
        $foreignBoot = ($currentBoot[0] === 'a' ? 'b' : 'a')
            . \substr($currentBoot, 1);
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
            $foreignBoot,
        );
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));
        $this->invalidateRollbackTargetContract($home, 'A');

        $blocked = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $blocked['code'], $blocked['output']);
        self::assertStringContainsString(
            'rollback target lacks the exact WLS 2.0 durable-state contract',
            $blocked['output'],
        );
        self::assertSame(
            "B\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
        );
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'previous-slot'),
        );
        self::assertFileDoesNotExist(
            $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
        );
        self::assertFileDoesNotExist($activeMarker);
        self::assertFileDoesNotExist($candidateMarker);
    }

    public function testExpiredSameBootMonotonicIntentCanOnlyRollBack(): void
    {
        $monotonicNow = \intdiv(\hrtime(true), 1_000_000);
        if ($monotonicNow <= 900_001) {
            self::markTestSkipped(
                'Host uptime is too short to construct an already-expired signed monotonic window.',
            );
        }
        [$home, $run, , $activeMarker] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR
            . 'expired-candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
            true,
        );
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
            GatewayHostBootIdentity::current(),
            $monotonicNow - 900_001,
        );
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        $rolledBack = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $rolledBack['code'], $rolledBack['output']);
        self::assertSame(
            "A\n",
            \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
        );
        self::assertFileExists($activeMarker);
        self::assertFileDoesNotExist(
            $candidateMarker,
            $rolledBack['output'] . "\nstate=" . (string)@\file_get_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
            ),
        );
        self::assertStringContainsString(
            "phase=ROLLBACK_PENDING\n",
            (string)\file_get_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
            ),
        );
    }

    public function testExpiredActivationDeadlineNeverStartsCandidate(): void
    {
        $monotonicNow = \intdiv(\hrtime(true), 1_000_000);
        if ($monotonicNow <= 300_001) {
            self::markTestSkipped(
                'Host uptime is too short to construct an expired activation window.',
            );
        }
        [$home, $run, , $activeMarker] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR
            . 'activation-expired-candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
            true,
        );
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
            GatewayHostBootIdentity::current(),
            $monotonicNow - 300_001,
        );
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        $rolledBack = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $rolledBack['code'], $rolledBack['output']);
        self::assertStringContainsString('activation-deadline', $rolledBack['output']);
        self::assertSame("A\n", \file_get_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
        ));
        self::assertFileExists($activeMarker);
        self::assertFileDoesNotExist($candidateMarker);
    }

    public function testExpiredObservationWithoutHealthRollsBackCandidate(): void
    {
        $monotonicNow = \intdiv(\hrtime(true), 1_000_000);
        if ($monotonicNow <= 400_000) {
            self::markTestSkipped(
                'Host uptime is too short to construct an expired observation window.',
            );
        }
        [$home, $run, , $activeMarker] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR
            . 'observation-expired-candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
            true,
        );
        $prepared = $monotonicNow - 400_000;
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
            GatewayHostBootIdentity::current(),
            $prepared,
        );
        $intent = $payload . 'signature=' . \hash_hmac(
            'sha256',
            $payload,
            $secret,
        ) . "\n";
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $intent,
        ));
        \sodium_memzero($secret);
        self::assertSame(1, \preg_match(
            '/nonce=([a-f0-9]{32})\n/',
            $intent,
            $nonce,
        ));
        $started = $prepared + 1_000;
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade-observing',
            "WLS-UPGRADE-OBSERVING/2\n"
                . 'intent_sha256=' . \hash('sha256', $intent) . "\n"
                . 'intent_nonce=' . $nonce[1] . "\n"
                . "from=A\nto=B\n"
                . 'runtime_generation=' . $candidateRuntimeGeneration . "\n"
                . 'boot_id=' . GatewayHostBootIdentity::current() . "\n"
                . 'started_monotonic_ms=' . $started . "\n"
                . 'deadline_monotonic_ms=' . ($started + 300_000) . "\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        $rolledBack = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);

        self::assertSame(1, $rolledBack['code'], $rolledBack['output']);
        self::assertStringContainsString('observation-deadline', $rolledBack['output']);
        self::assertSame("A\n", \file_get_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
        ));
        self::assertFileExists($activeMarker);
        self::assertFileDoesNotExist($candidateMarker);
    }

    public function testPosixAndWindowsLaunchersShareTheDurableUpgradeContract(): void
    {
        $native = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native';
        $posix = (string)\file_get_contents(
            $native . DIRECTORY_SEPARATOR . 'posix'
                . DIRECTORY_SEPARATOR . 'wls_gateway_launcher.c',
        );
        $windows = (string)\file_get_contents(
            $native . DIRECTORY_SEPARATOR . 'windows'
                . DIRECTORY_SEPARATOR . 'wls_gateway_launcher.c',
        );

        foreach ([$posix, $windows] as $source) {
            self::assertStringContainsString('WLS-UPGRADE/2', $source);
            self::assertStringContainsString('WLS-UPGRADE-STATE/3', $source);
            self::assertStringContainsString('WLS-UPGRADE-ROLLBACK/3', $source);
            self::assertStringContainsString('host_boot_id=', $source);
            self::assertStringContainsString(
                'rollback_deadline_monotonic_ms=',
                $source,
            );
            self::assertStringContainsString('WLS-UPGRADE-ROLLED-BACK/3', $source);
            self::assertStringContainsString(
                'from=%c\\nto=%c\\nruntime_generation=%s\\nat=%lld',
                $source,
            );
            self::assertStringContainsString('package-install.lock', $source);
            self::assertStringContainsString('WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS', $source);
            self::assertStringContainsString(
                'wls_delete_optional_durable(rollback_path)',
                $source,
            );
            self::assertStringContainsString('verified_previous != upgrade.to', $source);
            self::assertStringContainsString('WLS_UPGRADE_ACTIVATION_SECONDS', $source);
            self::assertStringContainsString('WLS_UPGRADE_TOTAL_SECONDS', $source);
            self::assertStringContainsString(
                'WLS_UPGRADE_OBSERVATION_MILLISECONDS',
                $source,
            );
            self::assertStringContainsString('WLS_ROLLBACK_HEALTH_MILLISECONDS', $source);
            self::assertStringContainsString('WLS_SLOT_RETENTION_MILLISECONDS', $source);
        }
        self::assertStringContainsString('flock(fd, LOCK_EX | LOCK_NB)', $posix);
        self::assertStringContainsString('mach_absolute_time()', $posix);
        self::assertStringContainsString('LockFileEx(', $windows);
        self::assertStringContainsString('UnlockFileEx(', $windows);
        self::assertStringContainsString('MOVEFILE_WRITE_THROUGH', $windows);
        self::assertStringContainsString('QueryPerformanceCounter(', $windows);
        self::assertStringContainsString(
            'wls_protocol_monotonic_milliseconds(&monotonic_now)',
            $windows,
        );
    }

    public function testPosixAndWindowsLaunchersAuthenticateTheStartAuthorizedRebootstrapPhase(): void
    {
        $native = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native';
        $posix = (string)\file_get_contents(
            $native . DIRECTORY_SEPARATOR . 'posix'
                . DIRECTORY_SEPARATOR . 'wls_gateway_launcher.c',
        );
        $windows = (string)\file_get_contents(
            $native . DIRECTORY_SEPARATOR . 'windows'
                . DIRECTORY_SEPARATOR . 'wls_gateway_launcher.c',
        );
        foreach ([
            'POSIX' => $posix,
            'Windows' => $windows,
        ] as $platform => $source) {
            self::assertIsInt(\strpos($source, 'START_AUTHORIZED'), $platform);
            self::assertIsInt(
                \strpos($source, 'ROLLBACK_START_AUTHORIZED'),
                $platform,
            );
            self::assertIsInt(
                \strpos($source, 'rebootstrap.transaction'),
                $platform,
            );
            self::assertIsInt(
                \strpos($source, 'rebootstrap-start.authorization'),
                $platform,
            );
            self::assertIsInt(\strpos($source, 'admin.token'), $platform);
            self::assertIsInt(
                \strpos(
                    $source,
                    'WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES 16384U',
                ),
                $platform,
            );
            self::assertIsInt(
                \strpos($source, 'wls_rebootstrap_recovery_artifacts_absent'),
                $platform,
            );
            self::assertIsInt(
                \strpos(
                    $source,
                    'wls_rebootstrap_reserved_recovery_name_self_test',
                ),
                $platform,
            );
            self::assertSame(
                1,
                \preg_match(
                    '/\+\+visited\s*>\s*'
                        . 'WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES/',
                    $source,
                ),
                $platform,
            );
            self::assertIsInt(\strpos($source, 'wls-backup'), $platform);
            self::assertIsInt(\strpos($source, 'tmp'), $platform);
            self::assertIsInt(
                \strpos($source, 'crypto_auth_hmacsha256'),
                $platform,
            );
            self::assertSame(
                1,
                \preg_match(
                    '/wls_recovery_maintenance_pending\([\s\S]*?'
                        . 'WLS-REBOOTSTRAP-START\/1[\s\S]*?'
                        . 'crypto_auth_hmacsha256\(/',
                    $source,
                ),
                $platform,
            );
        }
        self::assertSame(
            1,
            \preg_match(
                '/static int wls_recovery_read_controller_trust_file\('
                    . '[\s\S]*?\(before\.st_mode & 0777\) == 0600'
                    . '[\s\S]*?expected_owner == 0'
                    . '[\s\S]*?\(before\.st_mode & 0777\) == 0440'
                    . '[\s\S]*?before\.st_gid == trust_opened\.st_gid/',
                $posix,
            ),
            'POSIX may read 0440 controller trust leaves only for the root-owned production ACL; standalone fixtures remain 0600.',
        );
        self::assertSame(
            1,
            \preg_match(
                '/static int wls_recovery_read_controller_trust_file\('
                    . '[\s\S]*?return wls_recovery_read_secure_with_acl\('
                    . '\s*path,\s*0,\s*1,/',
                $windows,
            ),
            'Windows controller trust leaves must select the service-readable ACL verifier.',
        );
        self::assertSame(
            1,
            \preg_match(
                '/if \(service_readable\)[\s\S]*?'
                    . 'wls_launcher_gateway_service_sid\(&service_sid\)'
                    . '[\s\S]*?wls_launcher_slot_acl_valid\('
                    . '\s*file,\s*0,\s*service_sid/',
                $windows,
            ),
            'Windows service-readable trust leaves must be bound to the exact gateway service SID ACL.',
        );
        self::assertStringContainsString('fdopendir(trust_fd)', $posix);
        self::assertStringContainsString('O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW', $posix);
        self::assertStringContainsString('FileIdBothDirectoryRestartInfo', $windows);
        self::assertStringContainsString('FILE_FLAG_OPEN_REPARSE_POINT', $windows);
    }

    public function testCleanCandidateRestartsDoNotConsumeCrashBudget(): void
    {
        [$home, $run] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR . 'clean-candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
            true,
        );
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
        );
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        for ($restart = 1; $restart <= 3; $restart++) {
            $process = \proc_open(
                [
                    $this->launcher,
                    '--service',
                    '--home=' . $home,
                    '--run=' . $run,
                    '--profile=default',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            try {
                $deadline = \hrtime(true) + 5_000_000_000;
                while (!\is_file($candidateMarker) && \hrtime(true) < $deadline) {
                    \usleep(50_000);
                }
                self::assertFileExists($candidateMarker);
                self::assertSame(
                    "B\n",
                    \file_get_contents($trust . DIRECTORY_SEPARATOR . 'active-slot'),
                );
                self::assertFileDoesNotExist(
                    $trust . DIRECTORY_SEPARATOR . 'upgrade-attempts',
                );
            } finally {
                self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
                @\unlink($candidateMarker);
            }
        }

        self::assertFileExists($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
        self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade-rolled-back');
    }

    public function testHealthyCandidateCommitsWhileBrokerRemainsRunning(): void
    {
        [$home, $run] = $this->createSignedHome();
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $hostId = \bin2hex(\random_bytes(16));
        $secret = \random_bytes(32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret),
        ));
        $candidateMarker = $this->root . DIRECTORY_SEPARATOR . 'persistent-candidate-started';
        $candidateRuntimeGeneration = $this->createSignedCandidateSlot(
            $home,
            $candidateMarker,
            true,
        );
        $payload = $this->upgradeIntentPayload(
            $hostId,
            'A',
            'B',
            $candidateRuntimeGeneration,
        );
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            $payload . 'signature=' . \hash_hmac('sha256', $payload, $secret) . "\n",
        ));
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'previous-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "B\n",
        ));

        $process = \proc_open(
            [
                $this->launcher,
                '--service',
                '--home=' . $home,
                '--run=' . $run,
                '--profile=default',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            $deadline = \hrtime(true) + 5_000_000_000;
            while (!\is_file($candidateMarker) && \hrtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileExists(
                $candidateMarker,
                "active=" . (string)@\file_get_contents(
                    $trust . DIRECTORY_SEPARATOR . 'active-slot',
                ) . "\nstate=" . (string)@\file_get_contents(
                    $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
                ),
            );
            $stateFile = $trust . DIRECTORY_SEPARATOR . 'upgrade-state';
            $stateDeadline = \hrtime(true) + 5_000_000_000;
            while (!\is_file($stateFile) && \hrtime(true) < $stateDeadline) {
                \usleep(50_000);
            }
            $state = (string)\file_get_contents($stateFile);
            self::assertSame(1, \preg_match(
                '/\AWLS-UPGRADE-STATE\\/3\\n'
                    . 'intent_sha256=([a-f0-9]{64})\\n'
                    . 'intent_nonce=([a-f0-9]{32})\\n'
                    . 'from=A\\nto=B\\n'
                    . 'runtime_generation=([a-f0-9]{64})\\n'
                    . 'boot_id=([a-f0-9]{64})\\n/s',
                $state,
                $stateMatch,
            ));
            self::assertSame(GatewayHostBootIdentity::current(), $stateMatch[4]);
            $observationStarted = 1;
            $observationDeadline = 300001;
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-observing',
                "WLS-UPGRADE-OBSERVING/2\n"
                    . 'intent_sha256=' . $stateMatch[1] . "\n"
                    . 'intent_nonce=' . $stateMatch[2] . "\n"
                    . "from=A\nto=B\n"
                    . 'runtime_generation=' . $stateMatch[3] . "\n"
                    . 'boot_id=' . $stateMatch[4] . "\n"
                    . 'started_monotonic_ms=' . $observationStarted . "\n"
                    . 'deadline_monotonic_ms=' . $observationDeadline . "\n",
            ));
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-healthy',
                "WLS-UPGRADE-HEALTHY/2\n"
                    . 'intent_sha256=' . $stateMatch[1] . "\n"
                    . 'intent_nonce=' . $stateMatch[2] . "\n"
                    . "from=A\nto=B\n"
                    . 'runtime_generation=' . $stateMatch[3] . "\n"
                    . 'boot_id=' . $stateMatch[4] . "\n"
                    . 'observation_deadline_monotonic_ms=' . $observationDeadline . "\n"
                    . 'healthy_monotonic_ms=' . $observationDeadline . "\n",
            ));

            $retention = $trust . DIRECTORY_SEPARATOR . 'slot-retention';
            $deadline = \hrtime(true) + 5_000_000_000;
            while ((! \is_file($retention)
                    || \is_file($trust . DIRECTORY_SEPARATOR . 'upgrade.intent'))
                && \hrtime(true) < $deadline
            ) {
                \usleep(50_000);
            }
            self::assertFileExists($retention);
            self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade.intent');
            self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade-healthy');
            self::assertFileDoesNotExist($trust . DIRECTORY_SEPARATOR . 'upgrade-attempts');
            self::assertSame("B\n", \file_get_contents(
                $trust . DIRECTORY_SEPARATOR . 'active-slot',
            ));
            self::assertSame(1, \preg_match(
                '/\AWLS-SLOT-RETENTION\\/3\\n'
                    . 'intent_sha256=([a-f0-9]{64})\\n'
                    . 'intent_nonce=([a-f0-9]{32})\\n'
                    . 'slot=A\\n'
                    . 'boot_id=([a-f0-9]{64})\\n'
                    . 'retained_at=([0-9]+)\\n'
                    . 'retain_until=([0-9]+)\\n'
                    . 'retained_since_monotonic_ms=([0-9]+)\\n'
                    . 'retain_until_monotonic_ms=([0-9]+)\\n\z/D',
                (string)\file_get_contents($retention),
                $matches,
            ));
            self::assertSame($stateMatch[1], $matches[1]);
            self::assertSame($stateMatch[2], $matches[2]);
            self::assertSame($stateMatch[4], $matches[3]);
            self::assertSame(86_400, (int)$matches[5] - (int)$matches[4]);
            self::assertSame(86_400_000, (int)$matches[7] - (int)$matches[6]);
            self::assertGreaterThan(\time() + 86_000, (int)$matches[5]);
            self::assertTrue((bool)(\proc_get_status($process)['running'] ?? false));
        } finally {
            self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
        }
    }

    public function testHupRebuildsBrokerUnderTheSameStableLauncherPid(): void
    {
        [$home, $run, , $marker] = $this->createSignedHome(true);
        $process = \proc_open(
            [$this->launcher, '--service', '--home=' . $home, '--run=' . $run, '--profile=default'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            for ($attempt = 0; $attempt < 50 && !\is_file($marker); $attempt++) {
                \usleep(100000);
            }
            self::assertFileExists($marker);
            $status = \proc_get_status($process);
            $launcherPid = (int)($status['pid'] ?? 0);
            self::assertGreaterThan(0, $launcherPid);
            self::assertTrue(\unlink($marker));
            self::assertTrue(\posix_kill($launcherPid, SIGHUP));
            for ($attempt = 0; $attempt < 50 && !\is_file($marker); $attempt++) {
                \usleep(100000);
            }
            self::assertFileExists($marker);
            $after = \proc_get_status($process);
            self::assertTrue((bool)($after['running'] ?? false));
            self::assertSame($launcherPid, (int)($after['pid'] ?? 0));
        } finally {
            self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
        }
    }

    public function testForcedBrokerTerminationRequiresPlatformServiceTreeRestart(): void
    {
        $starts = $this->root . DIRECTORY_SEPARATOR . 'forced-broker-starts';
        $descendantPids = $this->root . DIRECTORY_SEPARATOR . 'forced-descendant-pids';
        $cleanup = $this->root . DIRECTORY_SEPARATOR . 'forced-cleanup';
        $broker = "#!/bin/sh\n"
            . "trap '' TERM INT HUP\n"
            . "printf 'started\\n' >> " . \escapeshellarg($starts) . "\n"
            . "(\n"
            . "  trap '' TERM INT HUP\n"
            . "  while [ ! -f " . \escapeshellarg($cleanup) . " ]; do sleep 1; done\n"
            . ") &\n"
            . "printf '%s\\n' \"\$!\" >> " . \escapeshellarg($descendantPids) . "\n"
            . "while [ ! -f " . \escapeshellarg($cleanup) . " ]; do sleep 1; done\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $process = \proc_open(
            [$this->launcher, '--service', '--home=' . $home, '--run=' . $run, '--profile=default'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $exitCode = -1;
        try {
            for ($attempt = 0; $attempt < 50 && !\is_file($descendantPids); $attempt++) {
                \usleep(100000);
            }
            self::assertFileExists($descendantPids);
            $status = \proc_get_status($process);
            $launcherPid = (int)($status['pid'] ?? 0);
            self::assertGreaterThan(0, $launcherPid);
            self::assertTrue(\posix_kill($launcherPid, SIGHUP));

            $deadline = \hrtime(true) + 8_000_000_000;
            do {
                $status = \proc_get_status($process);
                if (!(bool)($status['running'] ?? false)) {
                    $exitCode = (int)($status['exitcode'] ?? -1);
                    break;
                }
                \usleep(50_000);
            } while (\hrtime(true) < $deadline);

            self::assertFalse(
                (bool)($status['running'] ?? false),
                'A forced Broker kill must not be followed by an internal Broker reload.',
            );
            self::assertSame(79, $exitCode);
            self::assertSame(
                ['started'],
                \file($starts, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
                'The stable Launcher started an overlapping Broker generation.',
            );
            $pids = \array_map(
                'intval',
                \file($descendantPids, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            self::assertCount(1, $pids);
            self::assertTrue(
                \posix_kill($pids[0], 0),
                'The fixture must prove that platform-level whole-tree cleanup is still required.',
            );
        } finally {
            self::assertNotFalse(\touch($cleanup));
            $this->stopProcess($process, $pipes ?? [], 8.0);
            if (\is_file($descendantPids)) {
                foreach (\file($descendantPids, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $pid) {
                    $pid = (int)$pid;
                    $deadline = \hrtime(true) + 2_000_000_000;
                    while ($pid > 0 && @\posix_kill($pid, 0) && \hrtime(true) < $deadline) {
                        \usleep(25_000);
                    }
                    if ($pid > 0 && @\posix_kill($pid, 0)) {
                        @\posix_kill($pid, SIGKILL);
                    }
                }
            }
        }
    }

    public function testLinuxSystemdRestartsUnexpectedCleanBrokerExit(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux systemd semantics are required.');
        }
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_SYSTEMD_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set WLS_RUN_NATIVE_GATEWAY_SYSTEMD_INTEGRATION=1 for transient systemd validation.',
            );
        }

        $suffix = \bin2hex(\random_bytes(16));
        $unit = 'ai-test-wls2-clean-exit-' . $suffix;
        $systemRoot = '/var/tmp/weline-wls2-ci-' . $suffix;
        self::assertStringStartsWith('/var/tmp/weline-wls2-ci-', $systemRoot);
        $rootLauncher = $systemRoot . '/wls-gateway-launcher';
        $rootHome = $systemRoot . '/home';
        $rootRun = $systemRoot . '/run';
        $rootBroker = $rootHome . '/slots/A/bin/wls-gateway-broker';
        $marker = $systemRoot . '/systemd-clean-exit-starts';
        $broker = "#!/bin/sh\n"
            . "printf 'started\\n' >> " . \escapeshellarg($marker) . "\n"
            . "exit 0\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $rootCreated = false;
        $submissionAttempted = false;
        $unitOwned = false;
        $unloaded = false;
        $cleanup = ['code' => 1, 'output' => 'root fixture cleanup was not attempted'];
        try {
            $preflight = $this->runCommand([
                'sudo', '-n', 'systemctl', 'show', $unit,
                '--property=LoadState', '--value',
            ]);
            self::assertSame(0, $preflight['code'], $preflight['output']);
            self::assertSame(
                'not-found',
                \trim($preflight['output']),
                'The randomized systemd fixture unit already exists.',
            );
            $created = $this->runCommand([
                'sudo', '-n', '/bin/mkdir', '-m', '0755', $systemRoot,
            ]);
            self::assertSame(0, $created['code'], $created['output']);
            $rootCreated = true;
            foreach ([
                ['sudo', '-n', '/bin/cp', '-R', $home, $rootHome],
                ['sudo', '-n', '/bin/cp', '-R', $run, $rootRun],
                ['sudo', '-n', '/bin/cp', $this->launcher, $rootLauncher],
                ['sudo', '-n', '/bin/chown', '-R', 'root:root', $systemRoot],
                ['sudo', '-n', '/bin/chmod', '0755', $rootLauncher],
            ] as $command) {
                $prepared = $this->runCommand($command);
                self::assertSame(0, $prepared['code'], $prepared['output']);
            }
            $ownership = $this->runCommand([
                'sudo', '-n', '/usr/bin/stat', '-c', '%U:%G:%a', $rootLauncher, $rootBroker,
            ]);
            self::assertSame(0, $ownership['code'], $ownership['output']);
            self::assertSame(
                ['root:root:755', 'root:root:755'],
                \preg_split('/\R/', \trim($ownership['output'])),
            );

            $submissionAttempted = true;
            $started = $this->runCommand([
                'sudo',
                '-n',
                'systemd-run',
                '--unit=' . $unit,
                '--collect',
                '--property=Type=simple',
                '--property=Restart=on-failure',
                '--property=RestartSec=200ms',
                $rootLauncher,
                '--service',
                '--home=' . $rootHome,
                '--run=' . $rootRun,
                '--profile=default',
            ]);
            self::assertSame(0, $started['code'], $started['output']);
            $identity = $this->runCommand([
                'sudo', '-n', 'systemctl', 'show', $unit,
                '--property=ExecStart', '--value',
            ]);
            self::assertSame(0, $identity['code'], $identity['output']);
            self::assertStringContainsString($rootLauncher, $identity['output']);
            $unitOwned = true;

            $restartCount = 0;
            $deadline = \hrtime(true) + 8_000_000_000;
            while ($restartCount < 2 && \hrtime(true) < $deadline) {
                $lines = @\file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $restartCount = \is_array($lines) ? \count($lines) : 0;
                if ($restartCount < 2) {
                    \usleep(50_000);
                }
            }
            self::assertGreaterThanOrEqual(
                2,
                $restartCount,
                'systemd must start the clean-exiting Broker more than once.',
            );

            $status = $this->runCommand([
                'sudo',
                '-n',
                'systemctl',
                'show',
                $unit,
                '--property=Restart',
                '--property=NRestarts',
            ]);
            self::assertSame(0, $status['code'], $status['output']);
            self::assertStringContainsString('Restart=on-failure', $status['output']);
            self::assertSame(1, \preg_match('/^NRestarts=([0-9]+)$/m', $status['output'], $matches));
            self::assertGreaterThanOrEqual(1, (int)$matches[1]);
        } finally {
            if ($submissionAttempted) {
                if (!$unitOwned) {
                    $identity = $this->runCommand([
                        'sudo', '-n', 'systemctl', 'show', $unit,
                        '--property=LoadState', '--property=ExecStart',
                    ]);
                    $unloaded = $identity['code'] === 0
                        && \str_contains($identity['output'], 'LoadState=not-found');
                    $unitOwned = !$unloaded
                        && $identity['code'] === 0
                        && \str_contains($identity['output'], $rootLauncher);
                }
                if ($unitOwned) {
                    $this->runCommand([
                        'sudo', '-n', 'systemctl', 'stop', $unit,
                    ]);
                    $this->runCommand([
                        'sudo', '-n', 'systemctl', 'reset-failed', $unit,
                    ]);
                    $deadline = \hrtime(true) + 8_000_000_000;
                    do {
                        $loadState = $this->runCommand([
                            'sudo',
                            '-n',
                            'systemctl',
                            'show',
                            $unit,
                            '--property=LoadState',
                            '--value',
                        ]);
                        $unloaded = $loadState['code'] === 0
                            && \trim($loadState['output']) === 'not-found';
                        if (!$unloaded) {
                            \usleep(100_000);
                        }
                    } while (!$unloaded && \hrtime(true) < $deadline);
                } elseif (!$unloaded) {
                    $cleanup['output'] = 'systemd unit ownership became indeterminate; fixture retained';
                }
            }
            if ($rootCreated && (!$submissionAttempted || $unloaded)) {
                $cleanup = $this->runCommand([
                    'sudo', '-n', '/bin/rm', '-rf', $systemRoot,
                ]);
            }
        }
        self::assertTrue($unloaded, 'The transient systemd unit remained loaded.');
        self::assertSame(0, $cleanup['code'], $cleanup['output']);
        self::assertDirectoryDoesNotExist($systemRoot);
    }

    public function testMacOsLaunchdRestartsUnexpectedCleanBrokerExit(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('macOS launchd semantics are required.');
        }
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_LAUNCHD_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set WLS_RUN_NATIVE_GATEWAY_LAUNCHD_INTEGRATION=1 for transient launchd validation.',
            );
        }
        if (!\function_exists('posix_geteuid')) {
            self::markTestSkipped('The POSIX extension is required to select the launchd user domain.');
        }

        $label = 'com.weline.ai-test.wls2-clean-exit.' . \bin2hex(\random_bytes(16));
        $domain = 'gui/' . \posix_geteuid();
        $service = $domain . '/' . $label;
        $marker = $this->root . DIRECTORY_SEPARATOR . 'launchd-clean-exit-starts';
        self::assertNotFalse(\file_put_contents($marker, ''));
        $broker = "#!/bin/sh\n"
            . "printf 'started\\n' >> " . \escapeshellarg($marker) . "\n"
            . "exit 0\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $userLaunchdWrapper = $this->root . DIRECTORY_SEPARATOR
            . 'launchd-user-wrapper';
        self::assertNotFalse(\file_put_contents(
            $userLaunchdWrapper,
            "#!/bin/sh\n"
                . "child=\$1\n"
                . "shift\n"
                . "\"\$child\" \"\$@\"\n"
                . "status=\$?\n"
                . "exit \"\$status\"\n",
        ));
        self::assertTrue(\chmod($userLaunchdWrapper, 0500));
        $plist = $this->root . DIRECTORY_SEPARATOR . $label . '.plist';
        $this->writeLaunchdPlist(
            $plist,
            $label,
            $userLaunchdWrapper,
            $home,
            $run,
            $this->root . DIRECTORY_SEPARATOR . 'launchd.log',
            $this->launcher,
        );

        $lint = $this->runCommand(['/usr/bin/plutil', '-lint', $plist]);
        self::assertSame(0, $lint['code'], $lint['output']);
        $bootstrapAttempted = false;
        $bootstrapped = false;
        $serviceOwned = false;
        $unloaded = false;
        $bootout = ['code' => 1, 'output' => 'transient launchd service was not removed'];
        try {
            $preflight = $this->runCommand(['/bin/launchctl', 'print', $service]);
            self::assertNotSame(
                0,
                $preflight['code'],
                'The randomized launchd fixture service already exists: '
                    . $preflight['output'],
            );
            $bootstrapAttempted = true;
            $started = $this->runCommand([
                '/bin/launchctl',
                'bootstrap',
                $domain,
                $plist,
            ]);
            self::assertSame(0, $started['code'], $started['output']);
            $bootstrapped = true;
            $identity = $this->runCommand(['/bin/launchctl', 'print', $service]);
            self::assertSame(0, $identity['code'], $identity['output']);
            self::assertStringContainsString($this->launcher, $identity['output']);
            self::assertStringContainsString($home, $identity['output']);
            $serviceOwned = true;

            $restartCount = 0;
            $deadline = \hrtime(true) + 12_000_000_000;
            while ($restartCount < 2 && \hrtime(true) < $deadline) {
                $lines = @\file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $restartCount = \is_array($lines) ? \count($lines) : 0;
                if ($restartCount < 2) {
                    \usleep(50_000);
                }
            }
            self::assertGreaterThanOrEqual(
                2,
                $restartCount,
                'launchd must start the clean-exiting Broker more than once.',
            );

            $status = $this->runCommand(['/bin/launchctl', 'print', $service]);
            self::assertSame(0, $status['code'], $status['output']);
            self::assertStringContainsString('runs =', $status['output']);
            self::assertStringContainsString('last exit code = 1', $status['output']);
        } finally {
            if ($bootstrapAttempted) {
                if (!$serviceOwned) {
                    $identity = $this->runCommand(['/bin/launchctl', 'print', $service]);
                    $unloaded = $identity['code'] !== 0;
                    $serviceOwned = !$unloaded
                        && \str_contains($identity['output'], $this->launcher)
                        && \str_contains($identity['output'], $home);
                }
                if ($serviceOwned) {
                    $bootout = $this->runCommand(['/bin/launchctl', 'bootout', $service]);
                    $deadline = \hrtime(true) + 8_000_000_000;
                    do {
                        $active = $this->runCommand(['/bin/launchctl', 'print', $service]);
                        $unloaded = $active['code'] !== 0;
                        if (!$unloaded) {
                            \usleep(100_000);
                        }
                    } while (!$unloaded && \hrtime(true) < $deadline);
                    if (!$unloaded) {
                        $bootout = $this->runCommand([
                            '/bin/launchctl', 'bootout', $domain, $plist,
                        ]);
                        $active = $this->runCommand(['/bin/launchctl', 'print', $service]);
                        $unloaded = $active['code'] !== 0;
                    }
                }
            }
            $this->preserveRoot = !$unloaded;
        }
        self::assertTrue(
            $unloaded,
            'The transient launchd service remained loaded; its fixture root was retained. '
                . $bootout['output'],
        );
        if ($bootstrapped) {
            self::assertFalse($this->preserveRoot);
        }
        $active = $this->runCommand(['/bin/launchctl', 'print', $service]);
        self::assertNotSame(0, $active['code'], $active['output']);
    }

    public function testMacOsSystemLaunchDaemonRestartsUnexpectedCleanBrokerExit(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('macOS system launchd semantics are required.');
        }
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_SYSTEM_LAUNCHD_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set WLS_RUN_NATIVE_GATEWAY_SYSTEM_LAUNCHD_INTEGRATION=1 explicitly.',
            );
        }
        $sudo = $this->runCommand(['sudo', '-n', 'true']);
        if ($sudo['code'] !== 0) {
            $isCi = (string)\getenv('CI') === 'true'
                || (string)\getenv('GITHUB_ACTIONS') === 'true';
            if ($isCi) {
                self::fail($sudo['output']);
            }
            self::markTestSkipped(
                'Passwordless sudo is required for macOS system launchd validation: '
                    . \trim($sudo['output']),
            );
        }

        $suffix = \bin2hex(\random_bytes(16));
        $label = 'com.weline.ai-test.wls2-system-clean-exit.' . $suffix;
        $service = 'system/' . $label;
        $systemRoot = '/private/var/tmp/weline-wls2-ci-' . $suffix;
        self::assertStringStartsWith('/private/var/tmp/weline-wls2-ci-', $systemRoot);
        $marker = $systemRoot . '/system-launchd-clean-exit-starts';
        $broker = "#!/bin/sh\n"
            . "printf 'started\\n' >> " . \escapeshellarg($marker) . "\n"
            . "exit 0\n";
        [$home, $run] = $this->createSignedHome(false, $broker);
        $stagedPlist = $this->root . DIRECTORY_SEPARATOR . $label . '.plist';
        $rootLauncher = $systemRoot . '/wls-gateway-launcher';
        $rootHome = $systemRoot . '/home';
        $rootRun = $systemRoot . '/run';
        $rootBroker = $rootHome . '/slots/A/bin/wls-gateway-broker';
        $rootPlist = $systemRoot . '/' . $label . '.plist';
        $this->writeLaunchdPlist(
            $stagedPlist,
            $label,
            $rootLauncher,
            $rootHome,
            $rootRun,
            $systemRoot . '/launchd.log',
        );
        $lint = $this->runCommand(['/usr/bin/plutil', '-lint', $stagedPlist]);
        self::assertSame(0, $lint['code'], $lint['output']);

        $rootCreated = false;
        $bootstrapAttempted = false;
        $bootstrapped = false;
        $serviceOwned = false;
        $unloaded = false;
        $bootout = null;
        $cleanup = ['code' => 1, 'output' => 'root fixture cleanup was not attempted'];
        try {
            $preflight = $this->runCommand([
                'sudo', '-n', '/bin/launchctl', 'print', $service,
            ]);
            self::assertNotSame(
                0,
                $preflight['code'],
                'The randomized system LaunchDaemon already exists: '
                    . $preflight['output'],
            );
            $created = $this->runCommand([
                'sudo', '-n', '/bin/mkdir', '-m', '0755', $systemRoot,
            ]);
            self::assertSame(0, $created['code'], $created['output']);
            $rootCreated = true;
            foreach ([
                ['sudo', '-n', '/bin/cp', '-R', $home, $rootHome],
                ['sudo', '-n', '/bin/cp', '-R', $run, $rootRun],
                ['sudo', '-n', '/bin/cp', $this->launcher, $rootLauncher],
                ['sudo', '-n', '/usr/sbin/chown', '-R', 'root:wheel', $systemRoot],
                ['sudo', '-n', '/bin/chmod', '0755', $rootLauncher],
                [
                    'sudo',
                    '-n',
                    '/usr/bin/install',
                    '-o',
                    'root',
                    '-g',
                    'wheel',
                    '-m',
                    '0644',
                    $stagedPlist,
                    $rootPlist,
                ],
            ] as $command) {
                $prepared = $this->runCommand($command);
                self::assertSame(0, $prepared['code'], $prepared['output']);
            }
            $ownership = $this->runCommand([
                'sudo',
                '-n',
                '/usr/bin/stat',
                '-f',
                '%Su:%Sg:%Lp',
                $rootPlist,
                $rootLauncher,
                $rootBroker,
            ]);
            self::assertSame(0, $ownership['code'], $ownership['output']);
            self::assertSame(
                [
                    'root:wheel:644',
                    'root:wheel:755',
                    'root:wheel:755',
                ],
                \preg_split('/\R/', \trim($ownership['output'])),
            );

            $bootstrapAttempted = true;
            $started = $this->runCommand([
                'sudo',
                '-n',
                '/bin/launchctl',
                'bootstrap',
                'system',
                $rootPlist,
            ]);
            self::assertSame(0, $started['code'], $started['output']);
            $bootstrapped = true;
            $identity = $this->runCommand([
                'sudo', '-n', '/bin/launchctl', 'print', $service,
            ]);
            self::assertSame(0, $identity['code'], $identity['output']);
            self::assertStringContainsString($rootLauncher, $identity['output']);
            self::assertStringContainsString($rootHome, $identity['output']);
            $serviceOwned = true;
            $restartCount = 0;
            $deadline = \hrtime(true) + 12_000_000_000;
            while ($restartCount < 2 && \hrtime(true) < $deadline) {
                $lines = @\file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $restartCount = \is_array($lines) ? \count($lines) : 0;
                if ($restartCount < 2) {
                    \usleep(50_000);
                }
            }
            self::assertGreaterThanOrEqual(
                2,
                $restartCount,
                'The system LaunchDaemon must restart an unexpectedly clean Broker exit.',
            );
            $status = $this->runCommand([
                'sudo',
                '-n',
                '/bin/launchctl',
                'print',
                $service,
            ]);
            self::assertSame(0, $status['code'], $status['output']);
            self::assertStringContainsString('runs =', $status['output']);
            self::assertStringContainsString('last exit code = 1', $status['output']);
        } finally {
            if ($bootstrapAttempted) {
                if (!$serviceOwned) {
                    $identity = $this->runCommand([
                        'sudo', '-n', '/bin/launchctl', 'print', $service,
                    ]);
                    $unloaded = $identity['code'] !== 0;
                    $serviceOwned = !$unloaded
                        && \str_contains($identity['output'], $rootLauncher)
                        && \str_contains($identity['output'], $rootHome);
                }
                if ($serviceOwned) {
                    $bootout = $this->runCommand([
                        'sudo',
                        '-n',
                        '/bin/launchctl',
                        'bootout',
                        $service,
                    ]);
                    $deadline = \hrtime(true) + 8_000_000_000;
                    do {
                        $active = $this->runCommand([
                            'sudo', '-n', '/bin/launchctl', 'print', $service,
                        ]);
                        $unloaded = $active['code'] !== 0;
                        if (!$unloaded) {
                            \usleep(100_000);
                        }
                    } while (!$unloaded && \hrtime(true) < $deadline);
                } elseif (!$unloaded) {
                    $cleanup['output'] = 'LaunchDaemon ownership became indeterminate; fixture retained';
                }
            }
            if ($rootCreated && (!$bootstrapAttempted || $unloaded)) {
                $cleanup = $this->runCommand([
                    'sudo',
                    '-n',
                    '/bin/rm',
                    '-rf',
                    $systemRoot,
                ]);
            }
        }
        self::assertTrue(
            $unloaded,
            'The system LaunchDaemon remained loaded; its root fixture was retained. '
                . (string)($bootout['output'] ?? ''),
        );
        self::assertTrue(!$bootstrapped || $bootout !== null);
        self::assertSame(0, $cleanup['code'], $cleanup['output']);
        self::assertDirectoryDoesNotExist($systemRoot);
        $active = $this->runCommand([
            'sudo',
            '-n',
            '/bin/launchctl',
            'print',
            $service,
        ]);
        self::assertNotSame(0, $active['code'], $active['output']);
    }

    public function testLinuxLauncherReapsOrphanedGrandchildren(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux child-subreaper semantics are required.');
        }
        $orphanPidFile = $this->root . DIRECTORY_SEPARATOR . 'orphan-pid';
        $broker = "#!/bin/sh\n"
            . "printf '%s\\n' \"\$*\" > "
            . \escapeshellarg($this->root . DIRECTORY_SEPARATOR . 'broker-started')
            . "\n(\n"
            . "  sleep 1 &\n"
            . "  printf '%s\\n' \"\$!\" > " . \escapeshellarg($orphanPidFile) . "\n"
            . "  exit 0\n"
            . ") &\n"
            . "trap 'exit 0' TERM INT HUP\n"
            . "while :; do sleep 1; done\n";
        [$home, $run] = $this->createSignedHome(true, $broker);
        $process = \proc_open(
            [$this->launcher, '--service', '--home=' . $home, '--run=' . $run, '--profile=default'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            $deadline = \hrtime(true) + 5_000_000_000;
            while (!\is_file($orphanPidFile) && \hrtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileExists($orphanPidFile);
            $orphanPid = (int)\trim((string)\file_get_contents($orphanPidFile));
            self::assertGreaterThan(0, $orphanPid);

            $orphanProc = '/proc/' . $orphanPid;
            $deadline = \hrtime(true) + 5_000_000_000;
            while (\file_exists($orphanProc) && \hrtime(true) < $deadline) {
                \usleep(50_000);
            }
            self::assertFileDoesNotExist(
                $orphanProc,
                'The launcher must reap orphaned descendants instead of retaining zombies.',
            );
            self::assertTrue((bool)(\proc_get_status($process)['running'] ?? false));
        } finally {
            self::assertSame(0, $this->stopProcess($process, $pipes ?? []));
        }
    }

    private function createSignedCandidateSlot(
        string $home,
        string $marker,
        bool $persistent = false,
    ): string
    {
        $source = $home . DIRECTORY_SEPARATOR . 'slots/A';
        $slot = $home . DIRECTORY_SEPARATOR . 'slots/B';
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'app', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'share', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'release', 0700, true));
        foreach ([
            $slot,
            $slot . '/bin',
            $slot . '/app',
            $slot . '/share',
            $slot . '/release',
        ] as $directory) {
            self::assertTrue(\chmod($directory, 0755));
        }
        $broker = "#!/bin/sh\nprintf '%s\\n' \"\$*\" > "
            . \escapeshellarg($marker) . "\n";
        $broker .= $persistent
            ? "trap 'exit 0' TERM INT HUP\nwhile :; do sleep 1; done\n"
            : "exit 0\n";
        $files = [
            'bin/wls-gateway-broker' => [
                $broker,
                0755,
                0555,
            ],
            'bin/php' => [(string)\file_get_contents($source . '/bin/php'), 0755, 0555],
            'bin/nginx' => [(string)\file_get_contents($source . '/bin/nginx'), 0755, 0555],
            'bin/wls-gateway-launcher' => [
                (string)\file_get_contents($this->launcher),
                0755,
                0555,
            ],
            'app/controller.php' => [
                (string)\file_get_contents($source . '/app/controller.php'),
                0644,
                0444,
            ],
            'share/ca-bundle.pem' => [
                (string)\file_get_contents($source . '/share/ca-bundle.pem'),
                0644,
                0444,
            ],
        ];
        $releaseComponents = [];
        $installedComponents = [];
        foreach ($files as $relative => [$contents, $packageMode, $installedMode]) {
            $file = $slot . DIRECTORY_SEPARATOR . $relative;
            self::assertNotFalse(\file_put_contents($file, $contents));
            self::assertTrue(\chmod($file, $installedMode));
            $definition = [
                'sha256' => \hash_file('sha256', $file),
                'size' => \filesize($file),
            ];
            $releaseComponents[$relative] = $definition + ['mode' => $packageMode];
            $installedComponents[$relative] = $definition + ['mode' => $installedMode];
        }
        $manifest = \json_encode([
            'schema_version' => 2,
            'version' => '2.0.0-test-candidate',
            'implementation_level' => 'wls-2.0',
            'durable_state_contract' => self::DURABLE_STATE_CONTRACT,
            'capabilities' => self::ROLLBACK_TARGET_CAPABILITIES,
            'components' => $releaseComponents,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
        $releaseManifest = $slot . '/release/manifest.json';
        $releaseSignature = $slot . '/release/manifest.sig';
        self::assertNotFalse(\file_put_contents($releaseManifest, $manifest));
        self::assertTrue(\chmod($releaseManifest, 0444));
        self::assertNotFalse(\file_put_contents(
            $releaseSignature,
            \base64_encode(\sodium_crypto_sign_detached($manifest, $this->secretKey)) . PHP_EOL,
        ));
        self::assertTrue(\chmod($releaseSignature, 0444));
        $installedComponents += [
            'release/manifest.json' => $this->componentDefinition(
                $releaseManifest,
                0444,
            ),
            'release/manifest.sig' => $this->componentDefinition(
                $releaseSignature,
                0444,
            ),
        ];
        \ksort($installedComponents, SORT_STRING);
        $installed = $this->runtimeManifest('B', $installedComponents);
        $this->writeInstalledManifest(
            $slot . '/manifest.json',
            \json_encode(
                $installed,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
        return (string)$installed['runtime_generation'];
    }

    /** @return array{string,string,string,string} */
    private function createSignedHome(
        bool $persistent = false,
        ?string $brokerOverride = null,
    ): array
    {
        $home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $run = $this->root . DIRECTORY_SEPARATOR . 'run';
        $slot = $home . DIRECTORY_SEPARATOR . 'slots' . DIRECTORY_SEPARATOR . 'A';
        $release = $slot . DIRECTORY_SEPARATOR . 'release';
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'app', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'share', 0700, true));
        self::assertTrue(\mkdir($release, 0700, true));
        self::assertTrue(\mkdir($home . DIRECTORY_SEPARATOR . 'state', 0700, true));
        self::assertTrue(\mkdir($home . DIRECTORY_SEPARATOR . 'trust', 0700, true));
        self::assertTrue(\mkdir($run, 0700, true));
        self::assertTrue(\chmod($home, 0751));
        foreach ([
            $home . DIRECTORY_SEPARATOR . 'slots',
            $slot,
            $slot . DIRECTORY_SEPARATOR . 'bin',
            $slot . DIRECTORY_SEPARATOR . 'app',
            $slot . DIRECTORY_SEPARATOR . 'share',
            $release,
        ] as $directory) {
            self::assertTrue(\chmod($directory, 0755));
        }
        self::assertTrue(\chmod(
            $home . DIRECTORY_SEPARATOR . 'trust',
            0750,
        ));
        $marker = $this->root . DIRECTORY_SEPARATOR . 'broker-started';
        $broker = $brokerOverride
            ?? ("#!/bin/sh\nprintf '%s\\n' \"\$*\" > " . \escapeshellarg($marker) . "\n"
                . ($persistent
                    ? "trap 'exit 0' TERM INT HUP\nwhile :; do sleep 1; done\n"
                    : "exit 0\n"));
        $files = [
            'bin/wls-gateway-broker' => [
                $broker,
                0755,
                0555,
            ],
            'bin/php' => ["#!/bin/sh\nexit 0\n", 0755, 0555],
            'bin/nginx' => ["#!/bin/sh\nexit 0\n", 0755, 0555],
            'bin/wls-gateway-launcher' => [
                (string)\file_get_contents($this->launcher),
                0755,
                0555,
            ],
            'app/controller.php' => ["<?php\n", 0644, 0444],
            'share/ca-bundle.pem' => [
                "-----BEGIN CERTIFICATE-----\n"
                    . "V0xTLU5BVElWRS1JTlRFR1JBVElPTi1DQQ==\n"
                    . "-----END CERTIFICATE-----\n",
                0644,
                0444,
            ],
        ];
        $releaseComponents = [];
        $installedComponents = [];
        foreach ($files as $relative => [$contents, $packageMode, $installedMode]) {
            $file = $slot . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            self::assertNotFalse(\file_put_contents($file, $contents));
            self::assertTrue(\chmod($file, $installedMode));
            $definition = [
                'sha256' => \hash_file('sha256', $file),
                'size' => \filesize($file),
            ];
            $releaseComponents[$relative] = $definition + ['mode' => $packageMode];
            $installedComponents[$relative] = $definition + ['mode' => $installedMode];
        }
        $manifest = \json_encode([
            'schema_version' => 2,
            'version' => '2.0.0-test-signed',
            'implementation_level' => 'wls-2.0',
            'durable_state_contract' => self::DURABLE_STATE_CONTRACT,
            'capabilities' => self::ROLLBACK_TARGET_CAPABILITIES,
            'components' => $releaseComponents,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
        $releaseManifest = $release . DIRECTORY_SEPARATOR . 'manifest.json';
        $releaseSignature = $release . DIRECTORY_SEPARATOR . 'manifest.sig';
        self::assertNotFalse(\file_put_contents(
            $releaseManifest,
            $manifest,
        ));
        self::assertTrue(\chmod($releaseManifest, 0444));
        $signature = \sodium_crypto_sign_detached($manifest, $this->secretKey);
        self::assertNotFalse(\file_put_contents(
            $releaseSignature,
            \base64_encode($signature) . PHP_EOL,
        ));
        self::assertTrue(\chmod($releaseSignature, 0444));
        $installedComponents += [
            'release/manifest.json' => $this->componentDefinition(
                $releaseManifest,
                0444,
            ),
            'release/manifest.sig' => $this->componentDefinition(
                $releaseSignature,
                0444,
            ),
        ];
        \ksort($installedComponents, SORT_STRING);
        $installed = $this->runtimeManifest('A', $installedComponents);
        $this->writeInstalledManifest(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode(
                $installed,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
        self::assertNotFalse(\file_put_contents(
            $home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'active-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $home . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'ca-bundle.sha256',
            \hash_file(
                'sha256',
                $slot . DIRECTORY_SEPARATOR . 'share'
                    . DIRECTORY_SEPARATOR . 'ca-bundle.pem',
            ) . "\n",
        ));
        self::assertTrue(\chmod(
            $home . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'ca-bundle.sha256',
            0600,
        ));
        self::assertNotFalse(\file_put_contents(
            $home . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'stable-launcher.sha256',
            \hash_file('sha256', $this->launcher) . "\n",
        ));
        self::assertTrue(\chmod(
            $home . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'stable-launcher.sha256',
            0600,
        ));
        return [
            $home,
            $run,
            $slot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wls-gateway-broker',
            $marker,
        ];
    }

    /** @return array{sha256:string,size:int,mode:int} */
    private function componentDefinition(string $file, int $mode): array
    {
        $size = \filesize($file);
        $digest = \hash_file('sha256', $file);
        self::assertIsInt($size);
        self::assertIsString($digest);
        return ['sha256' => $digest, 'size' => $size, 'mode' => $mode];
    }

    /** @param array<string,array{sha256:string,size:int,mode:int}> $components */
    private function runtimeManifest(string $slot, array $components): array
    {
        $manifest = [
            'schema_version' => 2,
            'role' => 'host_gateway',
            'package_version' => '2.0.0-native-integration',
            'protocol_min' => 2,
            'protocol_max' => 2,
            'implementation_level' => 'wls-2.0',
            'capabilities' => self::ROLLBACK_TARGET_CAPABILITIES,
            'durable_state_contract' => self::DURABLE_STATE_CONTRACT,
            'host_id' => \substr(\hash('sha256', $this->root), 0, 32),
            'slot' => $slot,
            'listen_profile' => 'default',
            'test_mode' => true,
            'release_ready' => true,
            'components' => $components,
            'installed_at' => '2026-08-09T00:00:00+00:00',
        ];
        return $this->sealRuntimeGeneration($manifest);
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function sealRuntimeGeneration(array $manifest): array
    {
        unset($manifest['runtime_generation']);
        $manifest['runtime_generation'] = \hash(
            'sha256',
            \json_encode(
                $this->canonicalJsonValue($manifest),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        return $manifest;
    }

    private function invalidateRollbackTargetContract(string $home, string $slot): void
    {
        $file = $home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . $slot . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode(
            (string)\file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        unset(
            $manifest['runtime_generation'],
            $manifest['durable_state_contract'],
        );
        $manifest['runtime_generation'] = \hash(
            'sha256',
            \json_encode(
                $this->canonicalJsonValue($manifest),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        $this->writeInstalledManifest(
            $file,
            \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
    }

    private function writeInstalledManifest(
        string $file,
        string $contents,
        string $label = '',
    ): void {
        if (\file_exists($file)) {
            self::assertTrue(\chmod($file, 0644), $label);
        }
        self::assertNotFalse(\file_put_contents($file, $contents), $label);
        self::assertTrue(\chmod($file, 0444), $label);
    }

    private function assertSlotContractRejected(
        string $home,
        string $run,
        string $marker,
        string $label,
    ): void {
        @\unlink($marker);
        $blocked = $this->runCommand([
            $this->launcher,
            '--service',
            '--home=' . $home,
            '--run=' . $run,
            '--profile=default',
        ]);
        self::assertSame(1, $blocked['code'], $label . ': ' . $blocked['output']);
        self::assertStringContainsString(
            'active gateway slot lacks the exact WLS 2.0 durable-state contract',
            $blocked['output'],
            $label,
        );
        self::assertFileDoesNotExist($marker, $label);
    }

    private function canonicalJsonValue(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalJsonValue($child);
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function upgradeIntentPayload(
        string $hostId,
        string $from,
        string $to,
        string $runtimeGeneration,
        ?string $hostBootId = null,
        ?int $preparedMonotonic = null,
    ): string {
        $preparedAt = \time();
        $preparedMonotonic ??= \intdiv(\hrtime(true), 1_000_000);
        return "WLS-UPGRADE/2\n"
            . 'host_id=' . $hostId . "\n"
            . 'from=' . $from . "\n"
            . 'to=' . $to . "\n"
            . 'prepared_at=' . $preparedAt . "\n"
            . 'deadline=' . ($preparedAt + 300) . "\n"
            . 'runtime_generation=' . $runtimeGeneration . "\n"
            . 'host_boot_id=' . ($hostBootId ?? GatewayHostBootIdentity::current()) . "\n"
            . 'prepared_monotonic_ms=' . $preparedMonotonic . "\n"
            . 'activation_deadline_monotonic_ms='
                . ($preparedMonotonic + 300_000) . "\n"
            . 'rollback_deadline_monotonic_ms='
                . ($preparedMonotonic + 900_000) . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
    }

    /** @return array<string,mixed> */
    private function writeRebootstrapJournal(
        string $home,
        string $phase,
        bool $publishAdminStoppedIntent = false,
    ): array {
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $slot = $home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . 'A';
        $secret = \random_bytes(32);
        $hostId = \substr(\hash('sha256', 'host:' . $this->root), 0, 32);
        $epoch = \substr(\hash('sha256', 'epoch:' . $this->root), 0, 32);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($secret) . "\n",
        ));
        self::assertTrue(\chmod(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            0600,
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId . "\n",
        ));
        self::assertTrue(\chmod(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            0600,
        ));
        $intentBody = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . $hostId . "\n"
            . 'epoch=' . $epoch . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $intent = $intentBody . 'signature='
            . \hash_hmac('sha256', $intentBody, $secret) . "\n";
        if ($publishAdminStoppedIntent) {
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'admin-stopped.intent',
                $intent,
            ));
            self::assertTrue(\chmod(
                $trust . DIRECTORY_SEPARATOR . 'admin-stopped.intent',
                0600,
            ));
        }
        $runtimeManifest = \json_decode(
            (string)\file_get_contents(
                $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($runtimeManifest);
        $launcher = $slot . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls-gateway-launcher';
        $caBundle = $slot . DIRECTORY_SEPARATOR . 'share'
            . DIRECTORY_SEPARATOR . 'ca-bundle.pem';
        $releaseManifest = $slot . DIRECTORY_SEPARATOR . 'release'
            . DIRECTORY_SEPARATOR . 'manifest.json';
        $now = \time();
        $boot = GatewayHostBootIdentity::current();
        $launcherDigest = \hash_file('sha256', $launcher);
        $caDigest = \hash_file('sha256', $caBundle);
        $packageDigest = \hash_file('sha256', $releaseManifest);
        self::assertIsString($launcherDigest);
        self::assertIsString($caDigest);
        self::assertIsString($packageDigest);
        $journal = [
            'schema_version' => 3,
            'operation' => 'rebootstrap',
            'nonce' => \bin2hex(\random_bytes(16)),
            'host_id' => $hostId,
            'phase' => $phase,
            'package_digest' => $packageDigest,
            'package_version' => '2.0.0-native-rebootstrap-test',
            'profile' => 'default',
            'origin_boot_id' => $boot,
            'recovery_boot_id' => $boot,
            'created_at' => $now,
            'updated_at' => $now,
            'target_slot' => 'A',
            'runtime_generation' => (string)$runtimeManifest['runtime_generation'],
            'candidate_launcher_sha256' => $launcherDigest,
            'candidate_launcher_size' => (int)\filesize($launcher),
            'candidate_launcher_mode' => 0755,
            'candidate_ca_bundle_sha256' => $caDigest,
            'old_active_slot' => 'A',
            'old_previous_slot' => '',
            'old_launcher_sha256' => $launcherDigest,
            'old_launcher_size' => (int)\filesize($launcher),
            'old_launcher_mode' => 0555,
            'old_ca_bundle_sha256' => $caDigest,
            'old_slots' => [
                'A' => [
                    'slot' => 'A',
                    'runtime_generation' => (string)$runtimeManifest['runtime_generation'],
                    'package_digest' => $packageDigest,
                    'launcher_sha256' => $launcherDigest,
                ],
                'B' => null,
            ],
            'trust_rotation' => false,
            'derived_policy_sha256' => '',
            'old_derived_manifest_sha256' => '',
            'platform_snapshot' => [
                'kind' => 'test-session',
                'profile' => 'default',
                'definition_sha256' => \hash('sha256', 'definition:' . $this->root),
                'metadata_sha256' => \hash('sha256', 'metadata:' . $this->root),
            ],
            'admin_stopped_digest' => \hash('sha256', $intent),
            'admin_stopped_contents_b64' => \base64_encode($intent),
            'gateway_epoch' => $epoch,
            'old_gateway_epoch' => $epoch,
            'new_gateway_epoch' => '',
            'failure_reason' => $phase === 'ROLLING_BACK'
                ? 'native integration rollback'
                : '',
            'retained_backup_state' => 'NONE',
            'backup_collection_nonce' => '',
            'backup_collection_device' => '',
            'backup_collection_inode' => '',
            'retention_until' => 0,
            'retention_host_boot_id' => '',
            'retained_monotonic_ms' => 0,
            'retention_deadline_monotonic_ms' => 0,
            'signature' => '',
        ];
        $journal = $this->signRebootstrapJournal($journal, $home);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'rebootstrap.transaction',
            GatewayClient::canonicalJson($journal) . "\n",
        ));
        self::assertTrue(\chmod(
            $trust . DIRECTORY_SEPARATOR . 'rebootstrap.transaction',
            0600,
        ));
        if (\in_array($phase, [
            'START_AUTHORIZED',
            'OBSERVING',
            'COMMITTED',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
            'ROLLED_BACK',
        ], true)) {
            $this->writeRebootstrapStartAuthorization($home, $journal);
        }
        \sodium_memzero($secret);
        return $journal;
    }

    /**
     * @param array<string,mixed> $journal
     * @param array<string,string> $descriptorOverrides
     */
    private function writeRebootstrapStartAuthorization(
        string $home,
        array $journal,
        ?string $primaryDigest = null,
        ?string $secondaryDigest = null,
        array $descriptorOverrides = [],
    ): void {
        $phase = (string)$journal['phase'];
        $rollback = \in_array($phase, [
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
            'ROLLED_BACK',
        ], true);
        $slot = $rollback
            ? (string)$journal['old_active_slot']
            : (string)$journal['target_slot'];
        $runtimeGeneration = (string)$journal['runtime_generation'];
        $launcherDigest = (string)$journal['candidate_launcher_sha256'];
        if ($rollback) {
            $oldSlot = $journal['old_slots'][$slot] ?? null;
            self::assertIsArray($oldSlot);
            $runtimeGeneration = (string)$oldSlot['runtime_generation'];
            $launcherDigest = (string)$journal['old_launcher_sha256'];
        }
        $encoded = GatewayClient::canonicalJson($journal) . "\n";
        $primaryDigest ??= \hash('sha256', $encoded);
        $secondaryDigest ??= \str_repeat('0', 64);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $primaryDigest);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $secondaryDigest);
        $descriptor = [
            'host_id' => (string)$journal['host_id'],
            'nonce' => (string)$journal['nonce'],
            'purpose' => $rollback ? 'rollback' : 'forward',
            'active_slot' => $slot,
            'runtime_generation' => $runtimeGeneration,
            'stable_launcher_sha256' => $launcherDigest,
        ];
        foreach ($descriptorOverrides as $field => $value) {
            self::assertArrayHasKey($field, $descriptor);
            self::assertIsString($value);
            $descriptor[$field] = $value;
        }
        $unsigned = "WLS-REBOOTSTRAP-START/1\n"
            . 'host_id=' . $descriptor['host_id'] . "\n"
            . 'nonce=' . $descriptor['nonce'] . "\n"
            . 'purpose=' . $descriptor['purpose'] . "\n"
            . 'journal_sha256_primary=' . $primaryDigest . "\n"
            . 'journal_sha256_secondary=' . $secondaryDigest . "\n"
            . 'active_slot=' . $descriptor['active_slot'] . "\n"
            . 'runtime_generation=' . $descriptor['runtime_generation'] . "\n"
            . 'stable_launcher_sha256='
                . $descriptor['stable_launcher_sha256'] . "\n";
        $token = \trim((string)\file_get_contents(
            $home . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'admin.token',
        ));
        $key = \hex2bin($token);
        self::assertIsString($key);
        $authorization = $unsigned . 'signature='
            . \hash_hmac('sha256', $unsigned, $key) . "\n";
        \sodium_memzero($key);
        $file = $home . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'rebootstrap-start.authorization';
        self::assertNotFalse(\file_put_contents($file, $authorization));
        self::assertTrue(\chmod($file, 0600));
    }

    private function differentHexValue(string $value): string
    {
        self::assertMatchesRegularExpression('/\A[a-f0-9]+\z/D', $value);
        return ($value[0] === '0' ? '1' : '0') . \substr($value, 1);
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function signRebootstrapJournal(array $journal, string $home): array
    {
        $token = \trim((string)\file_get_contents(
            $home . DIRECTORY_SEPARATOR . 'trust'
                . DIRECTORY_SEPARATOR . 'admin.token',
        ));
        $key = \hex2bin($token);
        self::assertIsString($key);
        unset($journal['signature']);
        $journal['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($journal),
            $key,
        );
        \sodium_memzero($key);
        return $journal;
    }

    private function writeLaunchdPlist(
        string $plist,
        string $label,
        string $launcher,
        string $home,
        string $run,
        string $log,
        ?string $childProgram = null,
    ): void
    {
        $xml = static fn (string $value): string => \htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_XML1,
            'UTF-8',
        );
        self::assertNotFalse(\file_put_contents(
            $plist,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" "
                . "\"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n"
                . "<plist version=\"1.0\"><dict>\n"
                . "<key>Label</key><string>" . $xml($label) . "</string>\n"
                . "<key>ProgramArguments</key><array>\n"
                . "<string>" . $xml($launcher) . "</string>\n"
                . ($childProgram === null
                    ? ''
                    : "<string>" . $xml($childProgram) . "</string>\n")
                . "<string>--service</string>\n"
                . "<string>--home=" . $xml($home) . "</string>\n"
                . "<string>--run=" . $xml($run) . "</string>\n"
                . "<string>--profile=default</string>\n"
                . "</array>\n"
                . "<key>RunAtLoad</key><true/>\n"
                . "<key>KeepAlive</key><dict>"
                . "<key>SuccessfulExit</key><false/>"
                . "</dict>\n"
                . "<key>ThrottleInterval</key><integer>1</integer>\n"
                . "<key>ProcessType</key><string>Background</string>\n"
                . "<key>StandardOutPath</key><string>" . $xml($log) . "</string>\n"
                . "<key>StandardErrorPath</key><string>" . $xml($log) . "</string>\n"
                . "</dict></plist>\n",
        ));
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(array $command, float $timeoutSeconds = 60.0): array
    {
        $process = \proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return ['code' => 127, 'output' => 'Unable to start: ' . \implode(' ', $command)];
        }
        foreach ($pipes as $pipe) {
            \stream_set_blocking($pipe, false);
        }
        $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
        $output = '';
        $exitCode = -1;
        for (;;) {
            $status = \proc_get_status($process);
            foreach ($pipes as $pipe) {
                $chunk = \stream_get_contents($pipe);
                if (\is_string($chunk)) {
                    $output .= $chunk;
                }
            }
            if (!(bool)($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (\hrtime(true) >= $deadline) {
                $this->stopProcess($process, $pipes);
                return [
                    'code' => 124,
                    'output' => \trim(
                        $output . "\nCommand timed out: " . \implode(' ', $command),
                    ),
                ];
            }
            \usleep(25_000);
        }
        foreach ($pipes as $pipe) {
            $chunk = \stream_get_contents($pipe);
            if (\is_string($chunk)) {
                $output .= $chunk;
            }
            @\fclose($pipe);
        }
        $closed = \proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closed;
        }
        return ['code' => $exitCode, 'output' => \trim($output)];
    }

    /**
     * @param resource $process
     * @param array<int,resource> $pipes
     */
    private function stopProcess($process, array $pipes, float $timeoutSeconds = 5.0): int
    {
        $status = \proc_get_status($process);
        $exitCode = !(bool)($status['running'] ?? false)
            ? (int)($status['exitcode'] ?? -1)
            : -1;
        if ((bool)($status['running'] ?? false)) {
            @\proc_terminate($process, SIGTERM);
        }
        $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
        while ((bool)($status['running'] ?? false) && \hrtime(true) < $deadline) {
            $status = \proc_get_status($process);
            foreach ($pipes as $pipe) {
                \is_resource($pipe) && \stream_get_contents($pipe);
            }
            if (!(bool)($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            \usleep(25_000);
        }
        if ((bool)($status['running'] ?? false)) {
            @\proc_terminate($process, SIGKILL);
            $killDeadline = \hrtime(true) + 2_000_000_000;
            do {
                $status = \proc_get_status($process);
                if (!(bool)($status['running'] ?? false)) {
                    $exitCode = (int)($status['exitcode'] ?? -1);
                    break;
                }
                \usleep(25_000);
            } while (\hrtime(true) < $killDeadline);
        }
        foreach ($pipes as $pipe) {
            \is_resource($pipe) && @\fclose($pipe);
        }
        if ((bool)($status['running'] ?? false)) {
            return 124;
        }
        $closed = \proc_close($process);
        return $exitCode >= 0 ? $exitCode : $closed;
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
