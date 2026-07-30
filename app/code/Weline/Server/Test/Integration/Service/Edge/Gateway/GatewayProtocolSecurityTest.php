<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;

final class GatewayProtocolSecurityTest extends TestCase
{
    private string $root = '';
    private string $home = '';
    private string $hostId = '';
    private string $adminSecret = '';
    private string $fencing = '';
    private \ReflectionMethod $serveClient;
    private \WlsEdgeGatewayController $controller;

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('stream_socket_pair')) {
            self::markTestSkipped('The protocol security harness currently requires POSIX socket pairs.');
        }
        $temporaryRoot = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-protocol-'
            . \bin2hex(\random_bytes(8));
        $this->home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $state = $this->home . DIRECTORY_SEPARATOR . 'state';
        $trust = $this->home . DIRECTORY_SEPARATOR . 'trust';
        $slot = $this->home . DIRECTORY_SEPARATOR . 'slots' . DIRECTORY_SEPARATOR . 'A';
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0750, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        $this->hostId = \bin2hex(\random_bytes(16));
        $this->adminSecret = \bin2hex(\random_bytes(32));
        $this->fencing = \bin2hex(\random_bytes(32));
        self::assertNotFalse(\file_put_contents($trust . DIRECTORY_SEPARATOR . 'host-id', $this->hostId));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            $this->adminSecret,
        ));
        self::assertNotFalse(\file_put_contents($trust . DIRECTORY_SEPARATOR . 'active-slot', "A\n"));
        self::assertNotFalse(\file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode([
                'slot' => 'A',
                'test_mode' => true,
                'release_ready' => false,
                'implementation_level' => 'wls-2.0',
                'security_profile' => 'native-broker-v1',
                'runtime_generation' => 'test-runtime-a',
                'components' => [],
            ], JSON_THROW_ON_ERROR),
        ));
        $nginx = $slot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'nginx';
        self::assertNotFalse(\file_put_contents(
            $nginx,
            "#!/bin/sh\nprintf '%s\\n' 'nginx version: wls-test' >&2\nexit 0\n",
        ));
        self::assertTrue(\chmod($nginx, 0700));

        $run = $this->home . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'run';
        self::assertTrue(\mkdir($run, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $run . DIRECTORY_SEPARATOR . 'fencing-token',
            $this->fencing,
        ));
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require_once \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
        $this->controller = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $run . DIRECTORY_SEPARATOR . 'controller.sock',
        );
        $this->serveClient = new \ReflectionMethod($this->controller, 'serveClient');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testControllerPreservesPreProvisionedTrustDirectoryAcl(): void
    {
        self::assertSame(
            0750,
            (int)\fileperms($this->home . DIRECTORY_SEPARATOR . 'trust') & 0777,
        );
    }

    public function testEnrollmentIssuesHostBoundCredentialAndProjectCannotUseAdminOperation(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174000';
        $before = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($before['ok']);
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['shop.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);
        $this->assertResponseSignature($enrollment, $this->adminSecret);
        $credential = $enrollment['payload']['credential'];
        self::assertSame($this->hostId, $credential['host_id']);
        self::assertSame($projectUuid, $credential['project_uuid']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $credential['credential_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $credential['secret']);
        self::assertSame(
            (int)$before['payload']['control_generation'] + 1,
            (int)$enrollment['payload']['security_generation'],
            'Enrollment must return the durable security generation acknowledged to the administrator.',
        );
        $after = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($after['ok']);
        self::assertSame(
            (int)$enrollment['payload']['security_generation'],
            (int)$after['payload']['control_generation'],
        );
        self::assertSame(
            (int)$before['payload']['generation'],
            (int)$after['payload']['generation'],
            'Enrollment changes host authorization, not the active Nginx configuration.',
        );

        $forbidden = $this->request(
            'project',
            'stop',
            ['project_uuid' => $projectUuid, 'force' => true],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($forbidden['ok']);
        self::assertSame('unauthorized', $forbidden['error']['code']);
        self::assertStringContainsString(
            'cannot perform administrator operations',
            $forbidden['error']['message'],
        );
        $this->assertResponseSignature($forbidden, (string)$credential['secret']);
    }

    public function testAdminStoppedIntentUsesNativeLauncherCompatibleBinaryHmacKey(): void
    {
        (new \ReflectionMethod($this->controller, 'writeAdminStoppedIntent'))
            ->invoke($this->controller);
        $file = $this->home . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
        self::assertFileExists($file);
        $contents = (string)\file_get_contents($file);
        self::assertSame(1, \preg_match(
            '/\A(WLS-ADMIN-STOPPED\\/1\\n'
                . 'host_id=[a-f0-9]{32}\\n'
                . 'epoch=[a-f0-9]{32}\\n'
                . 'at=[0-9]+\\n'
                . 'nonce=[a-f0-9]{32}\\n)'
                . 'signature=([a-f0-9]{64})\\n\z/D',
            $contents,
            $matches,
        ));
        $key = \hex2bin($this->adminSecret);
        self::assertIsString($key);
        self::assertSame(
            \hash_hmac('sha256', (string)$matches[1], $key),
            (string)$matches[2],
        );
        \sodium_memzero($key);
    }

    public function testUnenrolledPeerTamperReplayAndCrossOwnerAreRejected(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174001';
        $unenrolled = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            \bin2hex(\random_bytes(16)),
            \bin2hex(\random_bytes(32)),
        );
        self::assertFalse($unenrolled['ok']);

        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['api.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        $credential = $enrollment['payload']['credential'];
        $nonce = \bin2hex(\random_bytes(16));
        $valid = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            nonce: $nonce,
        );
        self::assertTrue($valid['ok']);
        $this->assertResponseSignature($valid, (string)$credential['secret']);

        $replayed = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            nonce: $nonce,
        );
        self::assertFalse($replayed['ok']);
        self::assertStringContainsString('replayed', $replayed['error']['message']);

        $tampered = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            tamperDigest: true,
        );
        self::assertFalse($tampered['ok']);
        self::assertStringContainsString('digest', \strtolower($tampered['error']['message']));
        $this->assertResponseSignature($tampered, (string)$credential['secret']);

        $wrongOwner = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            peerUid: (int)\posix_geteuid() + 1000,
        );
        self::assertFalse($wrongOwner['ok']);
        self::assertStringContainsString('peer identity', $wrongOwner['error']['message']);
    }

    public function testRequestMonotonicTimestampAndNonceRetentionResistWallClockRollback(): void
    {
        $stale = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
            monotonicTimestamp: (\hrtime(true) / 1_000_000_000) - 61.0,
        );
        self::assertFalse($stale['ok']);
        self::assertStringContainsString(
            'monotonic timestamp',
            \strtolower((string)$stale['error']['message']),
        );

        $hostBootId = (string)(new \ReflectionProperty(
            $this->controller,
            'hostBootId',
        ))->getValue($this->controller);
        $now = \hrtime(true) / 1_000_000_000;
        $legacyNonce = \str_repeat('1', 32);
        $freshNonce = \str_repeat('2', 32);
        $expiredNonce = \str_repeat('3', 32);
        $previousBootNonce = \str_repeat('4', 32);
        $normalize = new \ReflectionMethod($this->controller, 'normalizePersistedNonces');
        $normalized = $normalize->invoke($this->controller, [
            $legacyNonce => \time() - 3600,
            $freshNonce => [
                'seen_at' => \time(),
                'seen_monotonic' => $now - 30.0,
                'boot_id' => $hostBootId,
            ],
            $expiredNonce => [
                'seen_at' => \time(),
                'seen_monotonic' => $now - 121.0,
                'boot_id' => $hostBootId,
            ],
            $previousBootNonce => [
                'seen_at' => \time() - 3600,
                'seen_monotonic' => 1.0,
                'boot_id' => \str_repeat('f', 64),
            ],
            'not-a-valid-nonce' => \time(),
            \str_repeat('5', 32) => [
                'seen_at' => 'invalid',
                'seen_monotonic' => $now,
                'boot_id' => $hostBootId,
            ],
        ]);
        self::assertSame('legacy', $normalized[$legacyNonce]['boot_id']);
        self::assertSame(0.0, $normalized[$legacyNonce]['seen_monotonic']);
        self::assertArrayNotHasKey('not-a-valid-nonce', $normalized);
        self::assertArrayNotHasKey(\str_repeat('5', 32), $normalized);

        $nonces = new \ReflectionProperty($this->controller, 'nonces');
        $nonces->setValue($this->controller, $normalized);
        (new \ReflectionMethod($this->controller, 'pruneNonces'))
            ->invoke($this->controller);
        $retained = $nonces->getValue($this->controller);
        self::assertArrayHasKey($legacyNonce, $retained);
        self::assertArrayHasKey($freshNonce, $retained);
        self::assertArrayNotHasKey($expiredNonce, $retained);
        self::assertArrayHasKey($previousBootNonce, $retained);
    }

    public function testClockUntrustedStateAllowsOnlyExactAdministratorAcknowledgement(): void
    {
        $clockWallAnchor = new \ReflectionProperty($this->controller, 'clockWallAnchor');
        $clockWallAnchor->setValue($this->controller, \time() - 10);

        $blocked = $this->request(
            'admin',
            'enroll',
            [],
            'admin',
            $this->adminSecret,
        );
        self::assertFalse($blocked['ok']);
        self::assertStringContainsString(
            'wall clock is untrusted',
            \strtolower((string)$blocked['error']['message']),
        );

        $combinedRepair = $this->request(
            'admin',
            'repair',
            [
                'accept_clock' => true,
                'accept_security_reset' => true,
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertFalse($combinedRepair['ok']);
        self::assertStringContainsString(
            'wall clock is untrusted',
            \strtolower((string)$combinedRepair['error']['message']),
        );

        $accepted = $this->request(
            'admin',
            'repair',
            ['accept_clock' => true],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($accepted['ok'], \json_encode($accepted));
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayNotHasKey('clock_untrusted_since', $state['security']);
    }

    public function testClockTrustRecoversAfterAStableMonotonicWindow(): void
    {
        $clockWallAnchor = new \ReflectionProperty($this->controller, 'clockWallAnchor');
        $clockWallAnchor->setValue($this->controller, \time() - 10);
        $observe = new \ReflectionMethod($this->controller, 'observeWallClock');

        self::assertFalse($observe->invoke($this->controller));
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayHasKey('clock_untrusted_since', $state['security']);
        $status = (new \ReflectionMethod($this->controller, 'status'))
            ->invoke($this->controller);
        self::assertFalse($status['clock']['trusted']);
        self::assertGreaterThan(0, $status['clock']['recovery_remaining_seconds']);
        self::assertTrue($status['clock']['state_persist_pending']);

        $stableSince = new \ReflectionProperty(
            $this->controller,
            'clockStableSinceMonotonic',
        );
        $stableSince->setValue(
            $this->controller,
            (\hrtime(true) / 1_000_000_000) - 31.0,
        );

        self::assertTrue($observe->invoke($this->controller));
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayNotHasKey('clock_untrusted_since', $state['security']);
        self::assertSame('RECOVERING', $state['health_state']);
        self::assertSame('CLOCK_STABLE', $state['recovery']['stage']);
    }

    public function testMaintenanceRecoversAndPersistsClockTrustWithoutControlTraffic(): void
    {
        $clockWallAnchor = new \ReflectionProperty($this->controller, 'clockWallAnchor');
        $clockWallAnchor->setValue($this->controller, \time() - 10);
        $observe = new \ReflectionMethod($this->controller, 'observeWallClock');
        self::assertFalse($observe->invoke($this->controller));

        (new \ReflectionProperty($this->controller, 'clockStableSinceMonotonic'))
            ->setValue(
                $this->controller,
                (\hrtime(true) / 1_000_000_000) - 31.0,
            );
        (new \ReflectionProperty($this->controller, 'lastHealthAt'))
            ->setValue($this->controller, PHP_FLOAT_MAX);
        (new \ReflectionProperty($this->controller, 'lastBackendProbeAt'))
            ->setValue($this->controller, PHP_FLOAT_MAX);

        (new \ReflectionMethod($this->controller, 'maintenance'))
            ->invoke($this->controller);

        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayNotHasKey('clock_untrusted_since', $state['security']);
        self::assertNotSame('', $state['security']['clock_retrusted_at']);
        self::assertFalse(
            (new \ReflectionProperty($this->controller, 'clockStatePersistPending'))
                ->getValue($this->controller),
        );
        $persisted = \json_decode((string)\file_get_contents(
            $this->home . DIRECTORY_SEPARATOR . 'state'
                . DIRECTORY_SEPARATOR . 'gateway-state.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey(
            'clock_untrusted_since',
            $persisted['payload']['security'],
        );
        $status = (new \ReflectionMethod($this->controller, 'status'))
            ->invoke($this->controller);
        self::assertTrue($status['clock']['trusted']);
        self::assertSame(0, $status['clock']['recovery_remaining_seconds']);
    }

    public function testSecurityLedgerSurvivesDerivedStateCorruptionAndFailsClosedWhenCorrupt(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174010';
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['ledger.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);
        $stateDir = $this->home . DIRECTORY_SEPARATOR . 'state';
        $ledger = $stateDir . DIRECTORY_SEPARATOR . 'security-ledger.json';
        self::assertFileExists($ledger);
        self::assertSame(0600, \fileperms($ledger) & 0777);

        self::assertNotFalse(\file_put_contents(
            $stateDir . DIRECTORY_SEPARATOR . 'gateway-state.json',
            '{"corrupt":true}',
        ));
        $recovered = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/recovered.sock',
        );
        $stateProperty = new \ReflectionProperty($recovered, 'state');
        $recoveredState = $stateProperty->getValue($recovered);
        self::assertArrayHasKey($projectUuid, $recoveredState['enrollments']);
        self::assertTrue($recoveredState['security_ledger_valid']);
        self::assertTrue($recoveredState['isolation_mode']);

        $stateRecoveryRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/recovered-again.sock',
        );
        $stateAfterSecondRestart = (new \ReflectionProperty(
            $stateRecoveryRestart,
            'state',
        ))->getValue($stateRecoveryRestart);
        self::assertTrue($stateAfterSecondRestart['isolation_mode']);
        self::assertSame('STATE_REBUILD', $stateAfterSecondRestart['health_state']);
        self::assertArrayHasKey($projectUuid, $stateAfterSecondRestart['enrollments']);

        self::assertNotFalse(\file_put_contents($ledger, '{"tampered":true}'));
        $isolated = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/isolated.sock',
        );
        $isolatedState = (new \ReflectionProperty($isolated, 'state'))->getValue($isolated);
        self::assertFalse($isolatedState['security_ledger_valid']);
        self::assertSame([], $isolatedState['enrollments']);
        self::assertSame('SECURITY_LEDGER_UNTRUSTED', $isolatedState['health_state']);
        self::assertFileExists($ledger . '.untrusted');

        $secondRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/isolated-again.sock',
        );
        $secondState = (new \ReflectionProperty($secondRestart, 'state'))->getValue($secondRestart);
        self::assertFalse($secondState['security_ledger_valid']);
        self::assertSame([], $secondState['enrollments']);
    }

    public function testSecurityTombstoneCannotReviveLegacyOrPriorGenerationRoute(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174011';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 12,
        ];
        $state['security']['tombstones']['project:' . $projectUuid] = [
            'kind' => 'project_revoke',
            'project_uuid' => $projectUuid,
            'generation' => 11,
        ];
        $stateProperty->setValue($this->controller, $state);
        $allowed = new \ReflectionMethod($this->controller, 'routeAllowedBySecurity');

        self::assertFalse($allowed->invoke($this->controller, [
            'project_uuid' => $projectUuid,
        ]));
        self::assertFalse($allowed->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'enrollment_security_generation' => 10,
        ]));
        self::assertTrue($allowed->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'enrollment_security_generation' => 12,
        ]));
    }

    public function testDomainTransferFencesSourceAndPublishesExactlyOneDesiredOwner(): void
    {
        $from = '123e4567-e89b-42d3-a456-426614174040';
        $to = '123e4567-e89b-42d3-a456-426614174041';
        $domain = 'transfer.example.test';
        $sourceRouteId = \str_repeat('4', 32);
        $targetRouteId = \str_repeat('5', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 40;
        $state['enrollments'][$from] = [
            'project_uuid' => $from,
            'security_generation' => 31,
            'allowed_domains' => [$domain],
        ];
        $state['enrollments'][$to] = [
            'project_uuid' => $to,
            'security_generation' => 32,
            'allowed_domains' => [$domain],
        ];
        $state['projects'][$from] = [
            'project_uuid' => $from,
            'route_ids' => [$sourceRouteId],
        ];
        $state['routes'][$sourceRouteId] = [
            'route_id' => $sourceRouteId,
            'project_uuid' => $from,
            'enrollment_security_generation' => 31,
            'route_generation' => 7,
            'domain' => $domain,
            'status' => 'ACTIVE',
            'certificate' => [
                'snapshot_digest' => \str_repeat('a', 64),
                'valid' => true,
            ],
        ];
        $state['acme_challenges']['source-challenge'] = [
            'project_uuid' => $from,
            'domain' => $domain,
        ];
        $stateProperty->setValue($this->controller, $state);

        $prepared = (new \ReflectionMethod($this->controller, 'prepareDomainTransfer'))
            ->invoke($this->controller, [
                'domain' => $domain,
                'from_project_uuid' => $from,
                'to_project_uuid' => $to,
                'confirm' => true,
            ]);
        $transferId = (string)$prepared['transfer_id'];
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $transferId);
        $state = $stateProperty->getValue($this->controller);
        $state['transfers'][$transferId]['status'] = 'STAGED';
        $state['transfers'][$transferId]['target_project'] = [
            'project_uuid' => $to,
            'project_root' => '/target',
            'generation' => 9,
            'digest' => \str_repeat('b', 64),
            'idempotency_key' => 'transfer-test',
        ];
        $state['transfers'][$transferId]['target_instance'] = [
            'instance_id' => 'target-instance',
            'generation' => 3,
        ];
        $stateProperty->setValue($this->controller, $state);
        $candidate = [
            'route_id' => $targetRouteId,
            'project_uuid' => $to,
            'enrollment_security_generation' => 32,
            'route_generation' => 1,
            'domain' => $domain,
            'status' => 'ACTIVE',
            'certificate' => [
                'snapshot_digest' => \str_repeat('c', 64),
                'valid' => true,
            ],
        ];
        (new \ReflectionMethod($this->controller, 'applyCommittedDomainTransfer'))
            ->invoke(
                $this->controller,
                $transferId,
                $state['transfers'][$transferId],
                $candidate,
                41,
            );

        $committed = $stateProperty->getValue($this->controller);
        self::assertSame('REMOVED', $committed['routes'][$sourceRouteId]['status']);
        self::assertSame('ACTIVE', $committed['routes'][$targetRouteId]['status']);
        self::assertSame(41, $committed['routes'][$targetRouteId]['domain_security_generation']);
        self::assertSame(
            $to,
            $committed['security']['tombstones']['domain:' . $domain]['to_project_uuid'],
        );
        self::assertSame([], $committed['projects'][$from]['route_ids']);
        self::assertSame([$targetRouteId], $committed['projects'][$to]['route_ids']);
        self::assertArrayNotHasKey('source-challenge', $committed['acme_challenges']);
        self::assertSame('COMMITTED', $committed['transfers'][$transferId]['status']);

        $allowed = new \ReflectionMethod($this->controller, 'routeAllowedBySecurity');
        self::assertFalse($allowed->invoke($this->controller, $state['routes'][$sourceRouteId]));
        self::assertTrue($allowed->invoke($this->controller, $committed['routes'][$targetRouteId]));
        try {
            (new \ReflectionMethod($this->controller, 'assertNoDomainConflicts'))
                ->invoke($this->controller, $from, [[
                    'project_uuid' => $from,
                    'domain' => $domain,
                ]]);
            self::fail('Transferred domain was accepted for its previous owner.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('fenced', $exception->getMessage());
        }
    }

    public function testRenderedRoutePinsBackendCapabilityAndSanitizesProxyHeaders(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174012';
        $routeId = \str_repeat('a', 32);
        $secret = \str_repeat('b', 64);
        (new \ReflectionMethod($this->controller, 'ensureNeutralCertificate'))
            ->invoke($this->controller);
        $neutral = [
            'cert' => $this->home . DIRECTORY_SEPARATOR . 'state/neutral-cert.pem',
            'key' => $this->home . DIRECTORY_SEPARATOR . 'state/neutral-key.pem',
        ];
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 5,
        ];
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'enrollment_security_generation' => 5,
            'instance_id' => 'gateway-test',
            'domain' => 'secure.example.test',
            'status' => 'ACTIVE',
            'backends' => [['host' => '127.0.0.1', 'port' => 29001, 'weight' => 1]],
            'backend_identity' => [
                'instance_id' => 'gateway-test',
                'generation' => 7,
                'edge_capability_secret' => $secret,
            ],
            'certificate' => [
                'valid' => true,
                'generation' => 3,
                'cert_path' => $neutral['cert'],
                'key_path' => $neutral['key'],
            ],
        ];
        $secondRouteId = \str_repeat('c', 32);
        $state['routes'][$secondRouteId] = $state['routes'][$routeId];
        $state['routes'][$secondRouteId]['route_id'] = $secondRouteId;
        $state['routes'][$secondRouteId]['domain'] = 'secure-alias.example.test';
        $stateProperty->setValue($this->controller, $state);
        $config = (new \ReflectionMethod($this->controller, 'renderNginxConfig'))
            ->invoke($this->controller, false);

        self::assertStringContainsString('ssl_session_cache off;', $config);
        self::assertStringContainsString('ssl_session_tickets off;', $config);
        self::assertStringNotContainsString('ssl_session_ticket_key ', $config);
        self::assertStringContainsString('ssl_early_data off;', $config);
        self::assertStringContainsString('location = /_wls/health {', $config);
        self::assertStringContainsString('if ($args != "") { return 404; }', $config);
        self::assertSame(
            1,
            \preg_match(
                '/proxy_pass http:\\/\\/(wls_backend_[a-f0-9]{32})\\/_wls\\/health\\?;/',
                $config,
                $upstreamMatch,
            ),
        );
        self::assertSame(1, \substr_count($config, '  upstream wls_backend_'));
        self::assertSame(2, \substr_count(
            $config,
            'proxy_pass http://' . $upstreamMatch[1] . '/_wls/health?;',
        ));
        self::assertStringContainsString('    keepalive 32;', $config);
        self::assertStringContainsString('    keepalive_timeout 10s;', $config);
        self::assertStringContainsString('    keepalive_requests 10000;', $config);
        self::assertStringNotContainsString('location = /_wls/health { return 404; }', $config);
        self::assertStringContainsString('location = /__wls_gateway_sentinel', $config);
        self::assertStringContainsString('proxy_set_header X-WLS-Edge-Token "' . $secret . '";', $config);
        self::assertStringContainsString('proxy_set_header X-Forwarded-For $remote_addr;', $config);
        self::assertStringContainsString('proxy_set_header Forwarded "";', $config);
        self::assertStringContainsString(
            'map $http_upgrade $connection_upgrade { default upgrade; "" ""; }',
            $config,
        );
        self::assertStringContainsString('access_log off;', $config);
        self::assertStringNotContainsString('/access.log', $config);
        self::assertStringNotContainsString(
            'map $http_upgrade $connection_upgrade { default upgrade; "" close; }',
            $config,
        );
        self::assertStringContainsString('proxy_set_header Connection $connection_upgrade;', $config);
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertStringNotContainsString('worker_rlimit_nofile', $config);
        } else {
            self::assertStringContainsString('worker_rlimit_nofile 65536;', $config);
        }
        self::assertStringNotContainsString('$proxy_add_x_forwarded_for', $config);
    }

    public function testBackendEndpointRequiresLaunchFenceAndEdgeCapability(): void
    {
        $project = $this->createProject();
        $project = (string)\realpath($project);
        $runtime = $project . DIRECTORY_SEPARATOR . 'var/server';
        self::assertTrue(\mkdir($runtime, 0700, true));
        $endpoint = $runtime . DIRECTORY_SEPARATOR . 'gateway-test.json';
        $launchId = \str_repeat('c', 32);
        self::assertNotFalse(\file_put_contents($endpoint, \json_encode([
            'instance_name' => 'gateway-test',
            'main_port' => 29001,
            'master_pid' => \getmypid(),
            'master_epoch' => 9,
            'gateway' => ['launch_id' => $launchId],
        ], JSON_THROW_ON_ERROR)));
        $identity = [
            'instance_id' => 'gateway-test',
            'launch_id' => $launchId,
            'master_epoch' => 9,
            'endpoint_file' => $endpoint,
        ];
        $validator = new \ReflectionMethod($this->controller, 'validateBackendEndpointIdentity');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('edge capability');
        $validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => 29001, 'weight' => 1]],
        );
    }

    public function testBackendEndpointRejectsForgedSharedSessionEvidence(): void
    {
        $project = (string)\realpath($this->createProject());
        $runtime = $project . DIRECTORY_SEPARATOR . 'var/server';
        self::assertTrue(\mkdir($runtime, 0700, true));
        $endpoint = $runtime . DIRECTORY_SEPARATOR . 'gateway-capability.json';
        $projectUuid = '123e4567-e89b-42d3-a456-426614174097';
        $launchId = \str_repeat('e', 32);
        $secret = \str_repeat('b', 64);
        $evidence = [
            'schema' => 'wls-session-capability/1',
            'storage' => 'wls',
            'runtime_source' => 'project_shared_state',
            'runtime_registered' => true,
            'runtime_shared_service' => true,
            'host' => '127.0.0.1',
            'port' => 20970,
            'token_scope_digest' => \hash('sha256', 'session_server.20970.token'),
            'probe' => 'healthy',
            'reason' => 'authenticated_session_runtime',
        ];
        self::assertNotFalse(\file_put_contents($endpoint, \json_encode([
            'instance_name' => 'gateway-capability',
            'main_port' => 29012,
            'master_pid' => \getmypid(),
            'master_epoch' => 14,
            'shared_state' => [
                'session' => [
                    'role' => 'session_server',
                    'host' => '127.0.0.1',
                    'port' => 20970,
                    'token_file_name' => 'session_server.20970.token',
                    'shared_service' => true,
                    'registered' => true,
                ],
            ],
            'gateway' => [
                'project_uuid' => $projectUuid,
                'instance_generation' => 8,
                'launch_id' => $launchId,
            ],
        ], JSON_THROW_ON_ERROR)));
        $identity = [
            'project_uuid' => $projectUuid,
            'instance_id' => 'gateway-capability',
            'generation' => 8,
            'launch_id' => $launchId,
            'master_epoch' => 14,
            'endpoint_file' => $endpoint,
            'edge_capability_secret' => $secret,
            'edge_capability_digest' => \hash('sha256', $secret),
            'session_capability' => 'shared_session',
            'session_capability_evidence' => $evidence,
            'session_capability_evidence_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            ),
        ];

        $validator = new \ReflectionMethod(
            $this->controller,
            'validateBackendEndpointIdentity',
        );
        self::assertNull($validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => 29012, 'weight' => 1]],
        ));

        $identity['session_capability_evidence']['token_scope_digest'] = \str_repeat('f', 64);
        $identity['session_capability_evidence_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($identity['session_capability_evidence']),
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('session capability evidence');
        $validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => 29012, 'weight' => 1]],
        );
    }

    public function testBackendEndpointAcceptsOnlyActiveFencedGatewayJoinPort(): void
    {
        $project = (string)\realpath($this->createProject());
        $runtime = $project . DIRECTORY_SEPARATOR . 'var/server';
        self::assertTrue(\mkdir($runtime, 0700, true));
        $endpoint = $runtime . DIRECTORY_SEPARATOR . 'gateway-join.json';
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $launchId = \str_repeat('d', 32);
        $secret = \str_repeat('a', 64);
        $joinPort = 24579;
        $payload = [
            'instance_name' => 'gateway-join',
            'main_port' => 29011,
            'master_pid' => \getmypid(),
            'master_epoch' => 13,
            'gateway' => [
                'project_uuid' => $projectUuid,
                'instance_generation' => 7,
                'launch_id' => $launchId,
                'join_backend' => [
                    'state' => 'ACTIVE',
                    'port' => $joinPort,
                    'project_uuid' => $projectUuid,
                    'instance_generation' => 7,
                    'master_pid' => \getmypid(),
                    'master_epoch' => 13,
                    'worker_pid' => \getmypid(),
                    'edge_capability_digest' => \hash('sha256', $secret),
                    'workers' => [
                        [
                            'instance_id' => 1,
                            'pid' => \getmypid(),
                            'launch_id' => 'join-worker-one',
                            'state' => 'READY',
                        ],
                    ],
                    'ready_count' => 1,
                    'desired_count' => 1,
                ],
            ],
        ];
        self::assertNotFalse(\file_put_contents(
            $endpoint,
            \json_encode($payload, JSON_THROW_ON_ERROR),
        ));
        $identity = [
            'project_uuid' => $projectUuid,
            'instance_id' => 'gateway-join',
            'generation' => 7,
            'launch_id' => $launchId,
            'master_epoch' => 13,
            'endpoint_file' => $endpoint,
            'edge_capability_secret' => $secret,
            'edge_capability_digest' => \hash('sha256', $secret),
        ];
        $validator = new \ReflectionMethod($this->controller, 'validateBackendEndpointIdentity');
        self::assertNull($validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => $joinPort, 'weight' => 1]],
        ));

        $payload['gateway']['join_backend']['state'] = 'DRAINING';
        self::assertNotFalse(\file_put_contents(
            $endpoint,
            \json_encode($payload, JSON_THROW_ON_ERROR),
        ));
        try {
            $validator->invoke(
                $this->controller,
                $identity,
                $project,
                [['host' => '127.0.0.1', 'port' => $joinPort, 'weight' => 1]],
            );
            self::fail('Inactive gateway join backend port was accepted.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Backend port', $exception->getMessage());
        }
    }

    public function testAcmeHttp01UsesOnlyExactExpiringProjectLease(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174098';
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['acme.example.test'],
                'capabilities' => ['acme_http_01' => true],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);
        $credential = $enrollment['payload']['credential'];
        $token = 'TOKEN_123-abc';
        $keyAuthorization = $token . '.' . \str_repeat('A', 43);
        $pendingDigest = \hash(
            'sha256',
            'wls-pending-certificate' . "\0" . 'acme.example.test',
        );
        $snapshot = (new \ReflectionMethod($this->controller, 'snapshotCertificate'))
            ->invoke(
                $this->controller,
                $projectUuid,
                $project,
                'acme.example.test',
                [
                    'pending' => true,
                    'source_digest' => $pendingDigest,
                    'generation' => 0,
                ],
            );
        self::assertFalse($snapshot['valid']);
        self::assertTrue($snapshot['pending']);
        self::assertSame(0, $snapshot['generation']);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $routeId = \str_repeat('a', 32);
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'acme.example.test',
            'status' => 'PENDING_CERTIFICATE',
            'backends' => [],
            'certificate' => ['valid' => false],
        ];
        $stateProperty->setValue($this->controller, $state);
        $desiredChallenges = [[
            'domain' => 'acme.example.test',
            'token' => $token,
            'key_authorization' => $keyAuthorization,
            'expires_at' => \time() + 300,
        ]];
        $desiredChallengeDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson($desiredChallenges),
        );
        $firstSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 2,
                'desired_digest' => $desiredChallengeDigest,
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertTrue($firstSync['ok'], \json_encode($firstSync));

        $staleSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 1,
                'challenges' => [],
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($staleSync['ok']);
        self::assertStringContainsString('generation is stale', $staleSync['error']['message']);

        $ambiguousSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 2,
                'challenges' => [],
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($ambiguousSync['ok']);
        self::assertStringContainsString('generation is ambiguous', $ambiguousSync['error']['message']);

        $driftedState = $stateProperty->getValue($this->controller);
        $driftedState['acme_challenges'] = [];
        $stateProperty->setValue($this->controller, $driftedState);

        $idempotentSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 2,
                'desired_digest' => $desiredChallengeDigest,
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertTrue($idempotentSync['ok'], \json_encode($idempotentSync));
        $reconciledState = $stateProperty->getValue($this->controller);
        self::assertCount(
            1,
            $reconciledState['acme_challenges'] ?? [],
            'An idempotent replay must restore a drifted serving lease.',
        );

        $render = new \ReflectionMethod($this->controller, 'renderNginxConfig');
        $config = $render->invoke($this->controller, false);
        self::assertStringContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $config,
        );
        self::assertStringContainsString('return 200 "' . $keyAuthorization . '";', $config);
        self::assertStringNotContainsString('location ^~ /.well-known/acme-challenge/', $config);
        self::assertStringNotContainsString('proxy_pass http://wls_backend_', $config);

        (new \ReflectionProperty($this->controller, 'rateWindows'))
            ->setValue($this->controller, []);
        $beforeGenerationOnlyRefresh = $stateProperty->getValue($this->controller);
        $generationOnlyRefresh = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 3,
                'desired_digest' => $desiredChallengeDigest,
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertTrue($generationOnlyRefresh['ok'], \json_encode($generationOnlyRefresh));
        $afterGenerationOnlyRefresh = $stateProperty->getValue($this->controller);
        self::assertSame(
            $beforeGenerationOnlyRefresh['generation'],
            $afterGenerationOnlyRefresh['generation'],
            'An identical challenge set must not reload Nginx.',
        );
        $stateFile = (new \ReflectionMethod($this->controller, 'stateFile'))
            ->invoke($this->controller);
        $persistedEnvelope = \json_decode(
            (string)\file_get_contents($stateFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            3,
            $persistedEnvelope['payload']['acme_generations'][$projectUuid]['generation'] ?? 0,
            'Generation-only ACME fencing must survive Controller restart.',
        );

        $tamperedDigest = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 3,
                'desired_digest' => \str_repeat('f', 64),
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($tamperedDigest['ok']);
        self::assertStringContainsString(
            'desired digest does not match',
            $tamperedDigest['error']['message'],
        );

        (new \ReflectionProperty($this->controller, 'rateWindows'))
            ->setValue($this->controller, []);

        $outsideCapability = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 3,
                'challenges' => [[
                    'domain' => 'other.example.test',
                    'token' => $token,
                    'key_authorization' => $keyAuthorization,
                    'expires_at' => \time() + 300,
                ]],
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($outsideCapability['ok']);
        self::assertStringContainsString(
            'outside the project enrollment capability',
            $outsideCapability['error']['message'],
        );

        $state = $stateProperty->getValue($this->controller);
        foreach ((array)$state['acme_challenges'] as $leaseId => $lease) {
            $state['acme_challenges'][$leaseId]['expires_at'] = \time() - 1;
        }
        $stateProperty->setValue($this->controller, $state);
        self::assertStringNotContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );
    }

    public function testJournalHashChainRecoversTailAndQuarantinesMiddleTamper(): void
    {
        $journal = new \ReflectionMethod($this->controller, 'journal');
        $journal->invoke($this->controller, 'first', ['value' => 1]);
        $journal->invoke($this->controller, 'second', ['value' => 2]);
        $file = $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl';
        $lines = \file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        $first = \json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $second = \json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $first['sequence']);
        self::assertSame(\str_repeat('0', 64), $first['previous_sha256']);
        self::assertSame(2, $second['sequence']);
        self::assertSame($first['sha256'], $second['previous_sha256']);

        self::assertNotFalse(\file_put_contents($file, '{"truncated":', FILE_APPEND));
        $tailRecovered = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/tail.sock',
        );
        self::assertTrue(
            (new \ReflectionProperty($tailRecovered, 'journalTrusted'))->getValue($tailRecovered),
        );
        self::assertSame(
            2,
            (new \ReflectionProperty($tailRecovered, 'journalSequence'))->getValue($tailRecovered),
        );

        $lines = \file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $tampered = \json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $tampered['event'] = 'tampered';
        $lines[0] = \json_encode($tampered, JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents($file, \implode("\n", $lines) . "\n"));
        $isolated = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/tamper.sock',
        );
        $isolatedState = (new \ReflectionProperty($isolated, 'state'))->getValue($isolated);
        self::assertFalse(
            (new \ReflectionProperty($isolated, 'journalTrusted'))->getValue($isolated),
        );
        self::assertTrue($isolatedState['isolation_mode']);
        self::assertSame('JOURNAL_UNTRUSTED', $isolatedState['health_state']);
        self::assertNotEmpty(\glob($file . '.corrupt-*') ?: []);
    }

    public function testLkgBundleProtectsCertificateClosureAndRollbackGeneration(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $digest = \str_repeat('a', 64);
        $unusedDigest = \str_repeat('b', 64);
        $routeId = \str_repeat('c', 32);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174019';
        $state['generation'] = 19;
        $state['active_config_generation'] = 19;
        $state['pending_lkg_generation'] = 19;
        $state['pending_lkg_since'] = \time() - 20;
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 17,
        ];
        $state['routes'] = [
            $routeId => [
                'route_id' => $routeId,
                'project_uuid' => $projectUuid,
                'enrollment_security_generation' => 17,
                'domain' => 'lkg.example.test',
                'status' => 'STALE',
                'backends' => [],
                'certificate' => [
                    'valid' => true,
                    'snapshot_digest' => $digest,
                    'generation' => 3,
                ],
                'force_https' => true,
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $configDirectory = $this->home . DIRECTORY_SEPARATOR . 'runtime/conf';
        self::assertTrue(\is_dir($configDirectory) || \mkdir($configDirectory, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $configDirectory . DIRECTORY_SEPARATOR . 'nginx.conf',
            "events {}\nhttp {}\n",
        ));
        foreach ([$digest, $unusedDigest] as $snapshotDigest) {
            $snapshot = $this->home . DIRECTORY_SEPARATOR . 'snapshots/'
                . $snapshotDigest;
            self::assertTrue(\mkdir($snapshot, 0700, true));
            self::assertNotFalse(\file_put_contents(
                $snapshot . DIRECTORY_SEPARATOR . 'source-cert.pem',
                "snapshot\n",
            ));
            self::assertTrue(\touch($snapshot, \time() - 8 * 86400));
        }

        (new \ReflectionMethod($this->controller, 'promoteLkg'))->invoke($this->controller);
        $promoted = $stateProperty->getValue($this->controller);
        self::assertCount(1, $promoted['lkg']);
        $entry = $promoted['lkg'][0];
        self::assertSame([$digest], $entry['certificate_digests']);
        self::assertFileExists($entry['manifest_file']);
        self::assertFileExists($entry['file']);
        self::assertFileExists($entry['route_file']);

        $promoted['routes'] = [];
        $promoted['generation'] = 25;
        $promoted['active_config_generation'] = 25;
        $stateProperty->setValue($this->controller, $promoted);
        (new \ReflectionMethod($this->controller, 'collectSnapshots'))->invoke($this->controller);
        self::assertDirectoryExists(
            $this->home . DIRECTORY_SEPARATOR . 'snapshots/' . $digest,
        );
        self::assertDirectoryDoesNotExist(
            $this->home . DIRECTORY_SEPARATOR . 'snapshots/' . $unusedDigest,
        );

        self::assertTrue(
            (new \ReflectionMethod($this->controller, 'rollbackToLkg'))
                ->invoke($this->controller),
        );
        $rolledBack = $stateProperty->getValue($this->controller);
        self::assertSame(19, $rolledBack['active_config_generation']);
        self::assertSame(19, $rolledBack['last_lkg_rollback_generation']);

        self::assertNotFalse(\file_put_contents($entry['route_file'], '{"tampered":true}'));
        self::assertNull(
            (new \ReflectionMethod($this->controller, 'loadLkgBundle'))
                ->invoke($this->controller, $entry),
        );
    }

    public function testDiskPressureRejectsMutationAndConfirmedRepairRecreatesReserve(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $freeBytes = \getenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $reserveFailure = \getenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE');
        $reserve = $this->home . DIRECTORY_SEPARATOR . 'state/recovery.reserve';
        $marker = $this->home . DIRECTORY_SEPARATOR . 'state/disk-pressure.marker';
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=1');
            self::assertFileExists($reserve);

            try {
                (new \ReflectionMethod(
                    $this->controller,
                    'assertPersistentMutationAllowed',
                ))->invoke($this->controller, 'test-mutation');
                self::fail('A durable mutation was accepted below the disk threshold.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'storage reserve is below',
                    $exception->getMessage(),
                );
            }

            $state = (new \ReflectionProperty($this->controller, 'state'))
                ->getValue($this->controller);
            self::assertSame('DISK_PRESSURE', $state['health_state']);
            self::assertSame('DISK_PRESSURE', $state['recovery']['stage']);
            self::assertFileExists($marker);
            self::assertFileDoesNotExist($reserve);

            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=33554432');
            (new \ReflectionMethod($this->controller, 'repair'))->invoke(
                $this->controller,
                ['accept_storage_recovery' => true],
            );

            self::assertFileDoesNotExist($marker);
            self::assertFileExists($reserve);
            self::assertSame(65536, (int)\filesize($reserve));
            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'journalTrusted'))
                    ->getValue($this->controller),
            );
            (new \ReflectionMethod(
                $this->controller,
                'assertPersistentMutationAllowed',
            ))->invoke($this->controller, 'post-repair-mutation');

            $stateProperty = new \ReflectionProperty($this->controller, 'state');
            $durableState = $stateProperty->getValue($this->controller);
            $stateFile = (new \ReflectionMethod($this->controller, 'stateFile'))
                ->invoke($this->controller);
            $durableHash = \hash_file('sha256', $stateFile);
            $mutated = $durableState;
            $mutated['generation'] = (int)$mutated['generation'] + 1;
            $stateProperty->setValue($this->controller, $mutated);
            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=temporary_write_or_fsync_failed');
            try {
                (new \ReflectionMethod($this->controller, 'persistState'))
                    ->invoke($this->controller);
                self::fail('Injected atomic persistence failure was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'temporary gateway file',
                    $exception->getMessage(),
                );
            } finally {
                \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
            }

            self::assertSame($durableHash, \hash_file('sha256', $stateFile));
            self::assertSame([], \glob($stateFile . '.tmp-*') ?: []);
            self::assertFileExists($marker);
            self::assertFileDoesNotExist($reserve);
            $storage = (new \ReflectionMethod($this->controller, 'storageStatus'))
                ->invoke($this->controller);
            self::assertTrue($storage['pressure_marker']);
            self::assertFalse($storage['mutation_ready']);

            $durableStateContents = \file_get_contents($stateFile);
            self::assertIsString($durableStateContents);
            $securityLedgerFile = (new \ReflectionMethod(
                $this->controller,
                'securityLedgerFile',
            ))->invoke($this->controller);
            $securityLedgerContents = \file_get_contents($securityLedgerFile);
            self::assertIsString($securityLedgerContents);
            self::assertNotFalse(\file_put_contents($stateFile, '{"corrupt"'));
            self::assertNotFalse(\file_put_contents($securityLedgerFile, '{"corrupt"'));
            $pressureStateHash = \hash_file('sha256', $stateFile);
            $pressureLedgerHash = \hash_file('sha256', $securityLedgerFile);
            $journalFile = (new \ReflectionMethod($this->controller, 'journalFile'))
                ->invoke($this->controller);
            self::assertNotFalse(\file_put_contents(
                $journalFile,
                '{"partial"',
                FILE_APPEND,
            ));
            $pressureJournalHash = \hash_file('sha256', $journalFile);
            $staleShadowFile = $this->home . DIRECTORY_SEPARATOR
                . 'runtime/shadow/' . \str_repeat('a', 32) . '-0/evidence';
            self::assertTrue(\mkdir(\dirname($staleShadowFile), 0700, true));
            self::assertNotFalse(\file_put_contents($staleShadowFile, 'preserve'));
            $restarted = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime'
                    . DIRECTORY_SEPARATOR . 'run' . DIRECTORY_SEPARATOR . 'controller.sock',
            );
            self::assertFileDoesNotExist(
                $reserve,
                'A disk-pressure restart must not reallocate the released reserve.',
            );
            $restartedState = (new \ReflectionProperty($restarted, 'state'))
                ->getValue($restarted);
            self::assertSame('DISK_PRESSURE', $restartedState['health_state']);
            self::assertTrue($restartedState['isolation_mode']);
            self::assertSame(
                $pressureStateHash,
                \hash_file('sha256', $stateFile),
                'A disk-pressure restart must not quarantine state on disk.',
            );
            self::assertSame(
                $pressureLedgerHash,
                \hash_file('sha256', $securityLedgerFile),
                'A disk-pressure restart must not quarantine the security ledger on disk.',
            );
            self::assertSame(
                $pressureJournalHash,
                \hash_file('sha256', $journalFile),
                'A disk-pressure restart must not repair the journal on disk.',
            );
            self::assertFalse(
                (new \ReflectionProperty($restarted, 'journalTrusted'))
                    ->getValue($restarted),
            );
            self::assertFileExists(
                $staleShadowFile,
                'A disk-pressure restart must not mutate stale runtime artifacts.',
            );
            self::assertNotFalse(\file_put_contents($stateFile, $durableStateContents));
            self::assertNotFalse(\file_put_contents(
                $securityLedgerFile,
                $securityLedgerContents,
            ));

            try {
                (new \ReflectionMethod(
                    $this->controller,
                    'assertPersistentMutationAllowed',
                ))->invoke($this->controller, 'latched-pressure-mutation');
                self::fail('A marker-latched disk-pressure mutation was accepted.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'storage reserve is below',
                    $exception->getMessage(),
                );
            }

            (new \ReflectionProperty($this->controller, 'lastHealthAt'))
                ->setValue($this->controller, 0.0);
            (new \ReflectionProperty($this->controller, 'lastBackendProbeAt'))
                ->setValue($this->controller, 0.0);
            (new \ReflectionMethod($this->controller, 'maintenance'))
                ->invoke($this->controller);
            self::assertSame(
                $durableHash,
                \hash_file('sha256', $stateFile),
                'Disk-pressure maintenance must not enter a persistent write loop.',
            );
            $pressureState = $stateProperty->getValue($this->controller);
            self::assertSame('DISK_PRESSURE', $pressureState['health_state']);
            self::assertNull(
                (new \ReflectionProperty($this->controller, 'publication'))
                    ->getValue($this->controller),
            );

            $stateProperty->setValue($this->controller, $durableState);
            (new \ReflectionMethod($this->controller, 'repair'))->invoke(
                $this->controller,
                ['accept_storage_recovery' => true],
            );
            self::assertFileDoesNotExist($marker);
            self::assertFileExists($reserve);

            self::assertTrue(\unlink($securityLedgerFile));
            self::assertTrue(\unlink($reserve));
            \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE=after_write');
            $reserveFailedRestart = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime'
                    . DIRECTORY_SEPARATOR . 'run' . DIRECTORY_SEPARATOR . 'controller.sock',
            );
            \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE');
            self::assertFileExists(
                $marker,
                'A startup reserve allocation failure must latch disk pressure.',
            );
            self::assertFileDoesNotExist(
                $reserve,
                'A partial or untrusted recovery reserve must be released.',
            );
            $reserveFailedState = (new \ReflectionProperty($reserveFailedRestart, 'state'))
                ->getValue($reserveFailedRestart);
            self::assertSame('DISK_PRESSURE', $reserveFailedState['health_state']);

            (new \ReflectionMethod($reserveFailedRestart, 'repair'))->invoke(
                $reserveFailedRestart,
                ['accept_storage_recovery' => true],
            );
            self::assertFileDoesNotExist($marker);
            self::assertFileExists($reserve);
            self::assertFileExists(
                $securityLedgerFile,
                'Confirmed storage repair must finish a deferred security-ledger bootstrap.',
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $freeBytes === false
                ? \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES')
                : \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=' . $freeBytes);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $reserveFailure === false
                ? \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE=' . $reserveFailure);
        }
    }

    public function testRecoveryCircuitUsesMonotonicWindowAndReleasesOneRetry(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $recordFailure = new \ReflectionMethod($this->controller, 'recordFailure');
        $circuitAction = new \ReflectionMethod(
            $this->controller,
            'recoveryCircuitAction',
        );

        for ($failure = 1; $failure <= 10; ++$failure) {
            $recordFailure->invoke($this->controller, 'failure-' . $failure);
        }
        $state = $stateProperty->getValue($this->controller);
        self::assertCount(10, $state['failure_events']);
        self::assertSame($hostBootId, $state['recovery']['circuit_boot_id']);
        self::assertGreaterThan(
            \hrtime(true) / 1_000_000_000,
            $state['recovery']['circuit_open_until_monotonic'],
        );
        foreach ($state['failure_events'] as $event) {
            self::assertSame($hostBootId, $event['boot_id']);
            self::assertGreaterThan(0.0, $event['at_monotonic']);
        }

        $until = (float)$state['recovery']['circuit_open_until_monotonic'];
        self::assertSame(
            'OPEN',
            $circuitAction->invoke($this->controller, $until - 0.01),
        );
        self::assertSame(
            $until,
            $stateProperty->getValue(
                $this->controller,
            )['recovery']['circuit_open_until_monotonic'],
        );
        (new \ReflectionMethod($this->controller, 'recoverDataPlane'))
            ->invoke($this->controller);
        $whileOpen = $stateProperty->getValue($this->controller);
        self::assertCount(10, $whileOpen['failure_events']);
        self::assertSame('CIRCUIT_OPEN', $whileOpen['recovery']['stage']);
        self::assertSame(
            $until,
            $whileOpen['recovery']['circuit_open_until_monotonic'],
        );
        self::assertSame(
            'RETRY',
            $circuitAction->invoke($this->controller, $until + 0.01),
        );
        $released = $stateProperty->getValue($this->controller);
        self::assertSame(0.0, $released['recovery']['circuit_open_until_monotonic']);
        self::assertSame(0, $released['recovery']['next_retry_at']);
        self::assertSame('CLOSED', $circuitAction->invoke($this->controller, $until + 0.02));

        $released['failure_events'] = [[
            'at' => \time(),
            'reason' => 'legacy-wall-clock-only',
        ]];
        $released['recovery']['consecutive_failures'] = 0;
        $released['recovery']['backoff_attempt'] = 0;
        $stateProperty->setValue($this->controller, $released);
        $recordFailure->invoke($this->controller, 'new-monotonic-failure');
        $filtered = $stateProperty->getValue($this->controller);
        self::assertCount(1, $filtered['failure_events']);
        self::assertSame(
            'new-monotonic-failure',
            $filtered['failure_events'][0]['reason'],
        );
    }

    public function testMultipleInstancesRequireCapabilityBeforeDistributionAndFailOver(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174020';
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'capabilities' => ['stateless' => false, 'shared_session' => false],
        ];
        $route = [
            'project_uuid' => $projectUuid,
            'domain' => 'multi.example.test',
            'certificate' => [
                'valid' => true,
                'snapshot_digest' => \str_repeat('a', 64),
                'generation' => 1,
            ],
            'instances' => [
                'zeta' => $this->instanceLease('zeta', 29002, 'shared_session'),
                'alpha' => $this->instanceLease('alpha', 29001, 'shared_session'),
            ],
            'backends' => [],
            'status' => 'PENDING_BACKEND',
            'last_heartbeat' => \time(),
            'stale_since' => null,
        ];
        $stateProperty->setValue($this->controller, $state);
        $selector = new \ReflectionMethod($this->controller, 'selectRouteBackends');
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('alpha', $route['preferred_instance_id']);
        self::assertSame('alpha', $route['instance_id']);
        self::assertSame([29001], \array_column($route['backends'], 'port'));
        self::assertSame('ACTIVE', $route['status']);

        $route['instances']['alpha']['last_heartbeat'] = 1;
        $route['instances']['zeta']['last_heartbeat'] = \PHP_INT_MAX;
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('alpha', $route['instance_id']);
        self::assertSame([29001], \array_column($route['backends'], 'port'));

        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid]['capabilities']['shared_session'] = true;
        $stateProperty->setValue($this->controller, $state);
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame([29001, 29002], \array_column($route['backends'], 'port'));
        self::assertSame('shared_session', $route['distribution_mode']);
        self::assertSame(
            ['alpha', 'zeta'],
            \array_keys($route['backend_instances']),
        );
        self::assertSame(
            $route['instances']['alpha']['backend_identity'],
            $route['backend_identity'],
        );

        $mismatchedEvidence = $route['instances']['zeta']['backend_identity'][
            'session_capability_evidence'
        ];
        $mismatchedEvidence['port'] = 31999;
        $route['instances']['zeta']['backend_identity'][
            'session_capability_evidence'
        ] = $mismatchedEvidence;
        $route['instances']['zeta']['backend_identity'][
            'session_capability_evidence_digest'
        ] = \hash('sha256', GatewayClient::canonicalJson($mismatchedEvidence));
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame(
            'single',
            $route['distribution_mode'],
            'Instances using different shared Session runtimes must never share traffic.',
        );
        self::assertSame([29001], \array_column($route['backends'], 'port'));

        $route['instances']['alpha']['backend_healthy'] = false;
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('zeta', $route['instance_id']);
        self::assertSame([29002], \array_column($route['backends'], 'port'));
        self::assertSame('single', $route['distribution_mode']);

        $route['instances']['zeta']['backend_healthy'] = false;
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('', $route['instance_id']);
        self::assertSame([], $route['backends']);
        self::assertSame([], $route['backend_instances']);
        self::assertSame('PENDING_BACKEND', $route['status']);
        self::assertNull($route['stale_since']);
    }

    public function testActiveBackendProbeKeepsAuthenticatedTransportAndFailsClosedOnIdentityMismatch(): void
    {
        $applyResult = new \ReflectionMethod(
            $this->controller,
            'applyBackendProbeResult',
        );
        $instance = ['backend_healthy' => true];

        self::assertTrue($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(1, $instance['backend_probe_failures']);

        self::assertTrue($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(2, $instance['backend_probe_failures']);

        self::assertFalse($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(3, $instance['backend_probe_failures']);

        self::assertFalse($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(4, $instance['backend_probe_failures']);

        self::assertFalse($applyResult->invokeArgs(
            $this->controller,
            [&$instance, true, ''],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(0, $instance['backend_probe_failures']);
        self::assertSame('', $instance['last_backend_probe_failure_kind']);

        self::assertTrue($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'identity'],
        ));
        self::assertFalse($instance['backend_healthy']);
        self::assertSame('identity', $instance['last_backend_probe_failure_kind']);

        $neverAuthenticated = ['backend_healthy' => false];
        self::assertTrue($applyResult->invokeArgs(
            $this->controller,
            [&$neverAuthenticated, false, 'transport'],
        ));
        self::assertFalse($neverAuthenticated['backend_healthy']);
    }

    public function testCertificateReferencesRequireEnrolledAliasAndRejectTraversal(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174021';
        $certificateRoot = $project . DIRECTORY_SEPARATOR . 'app/etc/ssl';
        $certificate = $certificateRoot . DIRECTORY_SEPARATOR . 'route.pem';
        self::assertNotFalse(\file_put_contents($certificate, "certificate\n"));
        self::assertTrue(\chmod($certificate, 0600));

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'certificate_roots' => ['project_ssl' => $certificateRoot],
        ];
        $stateProperty->setValue($this->controller, $state);
        $resolver = new \ReflectionMethod($this->controller, 'resolveCertificateReference');

        self::assertSame(\realpath($certificate), $resolver->invoke($this->controller, $projectUuid, [
            'root_alias' => 'project_ssl',
            'relative_path' => 'route.pem',
        ]));

        foreach ([
            ['root_alias' => 'unknown', 'relative_path' => 'route.pem'],
            ['root_alias' => 'project_ssl', 'relative_path' => '../route.pem'],
            ['root_alias' => 'project_ssl', 'relative_path' => '/etc/passwd'],
        ] as $invalid) {
            try {
                $resolver->invoke($this->controller, $projectUuid, $invalid);
                self::fail('Invalid certificate reference was accepted.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCertificateWildcardMatchesExactlyOneLabelAndNeverTheApex(): void
    {
        $covers = new \ReflectionMethod($this->controller, 'certificateCoversDomain');
        $wildcard = [
            'extensions' => ['subjectAltName' => 'DNS:*.example.test'],
            'subject' => ['CN' => '*.example.test'],
        ];
        self::assertTrue($covers->invoke($this->controller, $wildcard, 'shop.example.test'));
        self::assertFalse($covers->invoke($this->controller, $wildcard, 'example.test'));
        self::assertFalse($covers->invoke($this->controller, $wildcard, 'a.shop.example.test'));

        $apex = [
            'extensions' => ['subjectAltName' => 'DNS:example.test'],
            'subject' => ['CN' => 'example.test'],
        ];
        self::assertFalse($covers->invoke($this->controller, $apex, '*.example.test'));
        self::assertFalse($covers->invoke($this->controller, [
            'extensions' => [],
            'subject' => ['CN' => 'legacy.example.test'],
        ], 'legacy.example.test'));
    }

    public function testCertificateSnapshotIsContentAddressedAndDamagedBundleIsNeverOverwritten(): void
    {
        $project = $this->createProject();
        $domain = 'snapshot.example.test';
        $projectUuid = '123e4567-e89b-42d3-a456-426614174022';
        $source = $this->createCertificate($project, $domain, 'snapshot');
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $source['key']) . ':',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 1,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $certificate = [
            'cert' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'snapshot/fullchain.pem',
            ],
            'key' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'snapshot/privkey.pem',
            ],
            'source_digest' => $sourceDigest,
            'generation' => 1,
        ];
        $snapshotMethod = new \ReflectionMethod(
            $this->controller,
            'snapshotCertificate',
        );
        $mismatch = $this->createCertificate($project, $domain, 'mismatch');
        $invalid = $certificate;
        $invalid['key']['relative_path'] = 'mismatch/privkey.pem';
        $invalid['source_digest'] = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $mismatch['key']) . ':',
        );
        try {
            $snapshotMethod->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $domain,
                $invalid,
            );
            self::fail('A mismatched private key was accepted.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'private key does not match',
                $exception->getMessage(),
            );
        }
        self::assertCount(
            0,
            \glob($this->home . DIRECTORY_SEPARATOR . 'snapshots/*') ?: [],
        );

        $first = $snapshotMethod->invoke(
            $this->controller,
            $projectUuid,
            $project,
            $domain,
            $certificate,
        );
        self::assertTrue($first['valid']);
        self::assertSame($sourceDigest, $first['snapshot_digest']);
        self::assertFileExists($first['cert_path']);
        self::assertFileExists($first['key_path']);
        self::assertFileExists(
            \dirname((string)$first['key_path']) . DIRECTORY_SEPARATOR . 'manifest.json',
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($first['key_path']) & 0777);
        }

        $idempotent = $snapshotMethod->invoke(
            $this->controller,
            $projectUuid,
            $project,
            $domain,
            $certificate,
        );
        self::assertSame($first['cert_path'], $idempotent['cert_path']);
        self::assertSame($first['key_path'], $idempotent['key_path']);

        self::assertNotFalse(\file_put_contents(
            $first['key_path'],
            "\ntampered\n",
            FILE_APPEND,
        ));
        try {
            $snapshotMethod->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $domain,
                $certificate,
            );
            self::fail('A damaged immutable snapshot was silently overwritten.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'damaged; refusing to overwrite',
                $exception->getMessage(),
            );
        }
        self::assertStringEndsWith(
            "tampered\n",
            (string)\file_get_contents($first['key_path']),
        );
        self::assertCount(
            1,
            \glob($this->home . DIRECTORY_SEPARATOR . 'snapshots/*') ?: [],
        );
    }

    public function testAbortedRoutingMutationRestoresDesiredStateAndClearsJournal(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 41;
        $state['active_config_generation'] = 39;
        $state['pending_lkg_generation'] = 38;
        $state['pending_lkg_since'] = 1234;
        $state['isolation_mode'] = false;
        $state['projects'] = ['project-before' => ['generation' => 3]];
        $state['instances'] = ['project-before' => ['instance-before' => ['generation' => 7]]];
        $state['routes'] = ['route-before' => ['status' => 'ACTIVE']];
        $state['acme_challenges'] = ['challenge-before' => ['expires_at' => 12345]];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, false);

        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'test-abort');
        $mutated = $stateProperty->getValue($this->controller);
        $mutated['generation'] = 42;
        $mutated['active_config_generation'] = 42;
        $mutated['pending_lkg_generation'] = 42;
        $mutated['pending_lkg_since'] = 5678;
        $mutated['isolation_mode'] = true;
        $mutated['projects'] = ['project-after' => ['generation' => 4]];
        $mutated['instances'] = [];
        $mutated['routes'] = ['route-after' => ['status' => 'PENDING_BACKEND']];
        $mutated['acme_challenges'] = ['challenge-after' => ['expires_at' => 67890]];
        $stateProperty->setValue($this->controller, $mutated);
        $dirtyProperty->setValue($this->controller, true);

        (new \ReflectionMethod($this->controller, 'abortRoutingMutation'))
            ->invoke($this->controller, 'injected failure');

        $restored = $stateProperty->getValue($this->controller);
        self::assertSame(41, $restored['generation']);
        self::assertSame(39, $restored['active_config_generation']);
        self::assertSame(38, $restored['pending_lkg_generation']);
        self::assertSame(1234, $restored['pending_lkg_since']);
        self::assertFalse($restored['isolation_mode']);
        self::assertSame(['project-before' => ['generation' => 3]], $restored['projects']);
        self::assertSame(
            ['project-before' => ['instance-before' => ['generation' => 7]]],
            $restored['instances'],
        );
        self::assertSame(['route-before' => ['status' => 'ACTIVE']], $restored['routes']);
        self::assertSame(
            ['challenge-before' => ['expires_at' => 12345]],
            $restored['acme_challenges'],
        );
        self::assertFalse($dirtyProperty->getValue($this->controller));
        self::assertNull($publicationProperty->getValue($this->controller));
        $publicationFile = (new \ReflectionMethod($this->controller, 'publicationFile'))
            ->invoke($this->controller);
        self::assertFileDoesNotExist($publicationFile);
    }

    public function testIrrevocableSecurityMutationNeverRestoresRevokedRoute(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174030';
        $routeId = \str_repeat('d', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $state = $stateProperty->getValue($this->controller);
        $state['ready'] = true;
        $state['projects'][$projectUuid] = ['generation' => 1];
        $state['instances'][$projectUuid] = ['instance' => ['generation' => 1]];
        $state['enrollments'][$projectUuid] = ['security_generation' => 1];
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'status' => 'ACTIVE',
        ];
        $stateProperty->setValue($this->controller, $state);

        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'test-revoke');
        $mutated = $stateProperty->getValue($this->controller);
        unset($mutated['projects'][$projectUuid]);
        unset($mutated['instances'][$projectUuid]);
        unset($mutated['enrollments'][$projectUuid]);
        $mutated['routes'][$routeId]['status'] = 'REMOVED';
        $mutated['security']['tombstones']['project:' . $projectUuid] = [
            'project_uuid' => $projectUuid,
            'generation' => 2,
        ];
        $stateProperty->setValue($this->controller, $mutated);
        (new \ReflectionMethod($this->controller, 'markPublicationIrrevocableSecurity'))
            ->invoke($this->controller);
        (new \ReflectionMethod($this->controller, 'abortRoutingMutation'))
            ->invoke($this->controller, 'injected publication failure');

        $failedClosed = $stateProperty->getValue($this->controller);
        self::assertArrayNotHasKey($projectUuid, $failedClosed['projects']);
        self::assertArrayNotHasKey($projectUuid, $failedClosed['instances']);
        self::assertArrayNotHasKey($projectUuid, $failedClosed['enrollments']);
        self::assertSame('REMOVED', $failedClosed['routes'][$routeId]['status']);
        self::assertArrayHasKey(
            'project:' . $projectUuid,
            $failedClosed['security']['tombstones'],
        );
        self::assertFalse($failedClosed['ready']);
        self::assertTrue($failedClosed['isolation_mode']);
        self::assertSame('SECURITY_MUTATION_FAILED_CLOSED', $failedClosed['health_state']);
        self::assertTrue($dirtyProperty->getValue($this->controller));
    }

    public function testRemovedRouteCannotBeReactivatedByBackendSelectionOrLeaseSweep(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174031';
        $routeId = \str_repeat('f', 32);
        $instanceId = 'revoked-instance';
        $lease = $this->instanceLease($instanceId, 29120, 'stateless');
        $removedAt = \time() - 60;
        $route = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'revoked.example.test',
            'status' => 'REMOVED',
            'removed_at' => $removedAt,
            'certificate' => [
                'valid' => true,
                'snapshot_digest' => \str_repeat('e', 64),
                'generation' => 7,
            ],
            'instances' => [$instanceId => $lease],
            'backends' => [],
            'backend_instances' => [],
        ];

        $selector = new \ReflectionMethod($this->controller, 'selectRouteBackends');
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('REMOVED', $route['status']);
        self::assertSame($removedAt, $route['removed_at']);
        self::assertSame([], $route['instances']);
        self::assertSame([], $route['backends']);
        self::assertSame([], $route['backend_instances']);
        self::assertSame('', $route['preferred_instance_id']);
        self::assertSame('', $route['instance_id']);
        self::assertSame([], $route['backend_identity']);

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = $route;
        $state['security']['tombstones']['project:' . $projectUuid] = [
            'kind' => 'project_revoke',
            'project_uuid' => $projectUuid,
            'generation' => 7,
        ];
        $stateProperty->setValue($this->controller, $state);

        (new \ReflectionMethod($this->controller, 'expireLeases'))
            ->invoke($this->controller);

        $swept = $stateProperty->getValue($this->controller);
        self::assertSame('REMOVED', $swept['routes'][$routeId]['status']);
        self::assertSame($removedAt, $swept['routes'][$routeId]['removed_at']);
        self::assertSame([], $swept['routes'][$routeId]['instances']);
    }

    public function testQueuedPublicationCoalescesAndFailedRequestKeepsEarlierAcceptedState(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174032';
        $otherProjectUuid = '123e4567-e89b-42d3-a456-426614174033';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $deferProperty = new \ReflectionProperty($this->controller, 'deferPublication');
        $requestOperation = new \ReflectionProperty($this->controller, 'requestOperation');
        $requestPrincipal = new \ReflectionProperty($this->controller, 'requestPrincipal');
        $lastOperation = new \ReflectionProperty($this->controller, 'lastQueuedOperationId');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $begin = new \ReflectionMethod($this->controller, 'beginRoutingMutation');
        $publish = new \ReflectionMethod($this->controller, 'publishIfDirty');
        $abort = new \ReflectionMethod($this->controller, 'abortRoutingMutation');
        $operationStatus = new \ReflectionMethod($this->controller, 'operationStatus');

        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 51;
        $state['operations'] = [];
        $state['routes'] = [];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, true);
        $deferProperty->setValue($this->controller, true);
        $requestOperation->setValue($this->controller, 'register');
        $requestPrincipal->setValue($this->controller, $projectUuid);

        $begin->invoke($this->controller, 'register:' . $projectUuid . ':instance-a');
        $state = $stateProperty->getValue($this->controller);
        $state['routes']['accepted-route'] = ['project_uuid' => $projectUuid];
        $stateProperty->setValue($this->controller, $state);
        self::assertTrue($publish->invoke($this->controller));

        $operationId = $lastOperation->getValue($this->controller);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $operationId);
        $publication = $publicationProperty->getValue($this->controller);
        self::assertSame('PENDING_PUBLICATION', $publication['phase']);
        self::assertSame([$operationId], $publication['operation_ids']);
        self::assertSame(
            'PENDING_PUBLICATION',
            $operationStatus->invoke(
                $this->controller,
                $operationId,
                $projectUuid,
                false,
            )['state'],
        );

        $requestOperation->setValue($this->controller, 'register');
        $requestPrincipal->setValue($this->controller, $otherProjectUuid);
        $begin->invoke($this->controller, 'register:' . $otherProjectUuid . ':instance-b');
        $state = $stateProperty->getValue($this->controller);
        $state['routes']['rejected-route'] = ['project_uuid' => $otherProjectUuid];
        $stateProperty->setValue($this->controller, $state);
        $abort->invoke($this->controller, 'second request rejected');

        $restored = $stateProperty->getValue($this->controller);
        self::assertArrayHasKey('accepted-route', $restored['routes']);
        self::assertArrayNotHasKey('rejected-route', $restored['routes']);
        self::assertSame(
            'PENDING_PUBLICATION',
            $publicationProperty->getValue($this->controller)['phase'],
        );
        self::assertSame(
            'PENDING_PUBLICATION',
            $restored['operations'][$operationId]['state'],
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('belongs to another project');
        $operationStatus->invoke(
            $this->controller,
            $operationId,
            $otherProjectUuid,
            false,
        );
    }

    public function testActivePublicationKeepsReadsAvailableAndSerializesRoutingMutations(): void
    {
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'publication-control-availability');
        $publication = $publicationProperty->getValue($this->controller);
        $publication['phase'] = 'PREPARED';
        $publicationProperty->setValue($this->controller, $publication);

        $dispatch = new \ReflectionMethod($this->controller, 'dispatch');
        $status = $dispatch->invoke($this->controller, 'status', []);
        self::assertArrayHasKey('ready', $status);
        self::assertSame('PREPARED', $status['publication']['phase']);

        try {
            $dispatch->invoke($this->controller, 'register', []);
            self::fail('A second routing mutation entered an active publication.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'Gateway publication is active; retry_after=1.',
                $exception->getMessage(),
            );
        }
    }

    public function testStalledPublicationProbeStillServicesNewControlRequest(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The cooperative publication probe test requires pcntl.');
        }
        $controlSocket = $this->root . DIRECTORY_SEPARATOR . 'publication-control.sock';
        $server = \stream_socket_server(
            'unix://' . $controlSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        \stream_set_blocking($server, false);
        $controlServer = new \ReflectionProperty($this->controller, 'controlServer');
        $controlServer->setValue($this->controller, $server);

        $request = [
            'protocol' => 'wls-edge/2',
            'channel' => 'admin',
            'host_id' => $this->hostId,
            'credential_id' => 'admin',
            'operation' => 'status',
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'monotonic_timestamp' => \hrtime(true) / 1_000_000_000,
            'nonce' => \bin2hex(\random_bytes(16)),
            'payload' => [],
        ];
        $request['request_digest'] = \hash('sha256', GatewayClient::canonicalJson([
            'operation' => 'status',
            'payload' => [],
        ]));
        $request['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($request),
            $this->adminSecret,
        );
        $encoded = \json_encode(
            $request,
            JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $broker = \json_encode([
            'broker_schema' => 1,
            'channel' => 'admin',
            'uid' => (int)\posix_geteuid(),
            'gid' => (int)\posix_getegid(),
            'pid' => \getmypid(),
            'fencing_token' => $this->fencing,
            'payload_length' => \strlen($encoded),
        ], JSON_THROW_ON_ERROR) . "\n";
        $responseFile = $this->root . DIRECTORY_SEPARATOR . 'publication-control-response.json';
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            @\fclose($server);
            \usleep(30000);
            $started = \microtime(true);
            $client = @\stream_socket_client('unix://' . $controlSocket, $childErrno, $childError, 1);
            if (!\is_resource($client)) {
                @\file_put_contents($responseFile, \json_encode([
                    'error' => $childError !== '' ? $childError : (string)$childErrno,
                ]));
                exit(70);
            }
            \stream_set_timeout($client, 1);
            @\fwrite($client, $broker . $encoded);
            $response = @\fgets($client, 4 * 1024 * 1024);
            @\fclose($client);
            @\file_put_contents($responseFile, \json_encode([
                'elapsed_ms' => (\microtime(true) - $started) * 1000,
                'response' => \is_string($response) ? \json_decode($response, true) : null,
            ]));
            exit(\is_string($response) ? 0 : 71);
        }

        (new \ReflectionMethod($this->controller, 'publicationProbePause'))
            ->invoke($this->controller);
        \pcntl_waitpid($pid, $status);
        @\fclose($server);
        $controlServer->setValue($this->controller, null);
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
        $result = \json_decode((string)\file_get_contents($responseFile), true);
        self::assertIsArray($result);
        self::assertLessThan(350.0, (float)($result['elapsed_ms'] ?? 9999));
        self::assertTrue((bool)($result['response']['ok'] ?? false));
        self::assertArrayHasKey('ready', (array)($result['response']['payload'] ?? []));
    }

    public function testIdenticalRegistrationRetryReturnsPendingOperationWithoutMutation(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174034';
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['retry.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);

        $operationId = \str_repeat('c', 32);
        $projectDigest = \str_repeat('1', 64);
        $instanceDigest = \str_repeat('2', 64);
        $instanceId = 'retry-instance';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $lastOperation = new \ReflectionProperty($this->controller, 'lastQueuedOperationId');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 44;
        $state['projects'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'generation' => 2,
            'digest' => $projectDigest,
            'idempotency_key' => $projectUuid . ':' . $instanceId . ':2',
            'route_ids' => [],
        ];
        $state['instances'][$projectUuid][$instanceId] = [
            'instance_id' => $instanceId,
            'generation' => 4,
            'digest' => $instanceDigest,
            'master_epoch' => 4,
            'launch_id' => \str_repeat('3', 32),
            'status' => 'ACTIVE',
        ];
        $state['operations'][$operationId] = [
            'operation_id' => $operationId,
            'operation' => 'register',
            'principal' => $projectUuid,
            'project_uuid' => $projectUuid,
            'state' => 'ACTIVATING',
            'desired_generation' => 44,
            'active_generation' => 0,
            'created_unix' => \time(),
            'completed_unix' => 0,
            'error' => '',
        ];
        $stateProperty->setValue($this->controller, $state);

        $result = (new \ReflectionMethod($this->controller, 'register'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'instance_id' => $instanceId,
                'project_generation' => 2,
                'request_digest' => $projectDigest,
                'idempotency_key' => $projectUuid . ':' . $instanceId . ':2',
                'instance_generation' => 4,
                'instance_digest' => $instanceDigest,
                'master_epoch' => 4,
                'launch_id' => \str_repeat('3', 32),
                'gateway_epoch' => (string)$state['epoch'],
                'routes' => [],
                '_broker_peer' => [
                    'channel' => 'project',
                    'uid' => (int)\posix_geteuid(),
                    'gid' => (int)\posix_getegid(),
                    'pid' => \getmypid(),
                ],
            ],
            false,
        );

        $unchanged = $stateProperty->getValue($this->controller);
        self::assertTrue($result['idempotent']);
        self::assertSame($operationId, $lastOperation->getValue($this->controller));
        self::assertSame(44, $unchanged['generation']);
        self::assertSame(4, $unchanged['instances'][$projectUuid][$instanceId]['generation']);
        self::assertSame('ACTIVATING', $unchanged['operations'][$operationId]['state']);
    }

    public function testSameProjectGenerationMayRefreshSameLiveInstanceCapabilityDigest(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174037';
        $instanceId = 'capability-refresh-instance';
        $launchId = \str_repeat('3', 32);
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['capability.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['projects'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'generation' => 2,
            'digest' => \str_repeat('1', 64),
            'idempotency_key' => $projectUuid . ':' . $instanceId . ':2',
            'route_ids' => [],
        ];
        $state['instances'][$projectUuid][$instanceId] = [
            'instance_id' => $instanceId,
            'generation' => 4,
            'digest' => \str_repeat('2', 64),
            'master_epoch' => 4,
            'launch_id' => $launchId,
            'status' => 'ACTIVE',
        ];
        $stateProperty->setValue($this->controller, $state);

        $register = new \ReflectionMethod($this->controller, 'register');
        try {
            $register->invoke(
                $this->controller,
                [
                    'project_uuid' => $projectUuid,
                    'project_root' => $project,
                    'instance_id' => $instanceId,
                    'project_generation' => 2,
                    'request_digest' => \str_repeat('1', 64),
                    'idempotency_key' => $projectUuid . ':' . $instanceId . ':2',
                    'instance_generation' => 4,
                    'instance_digest' => \str_repeat('5', 64),
                    'master_epoch' => 4,
                    'launch_id' => $launchId,
                    'gateway_epoch' => (string)$state['epoch'],
                    'routes' => [],
                    '_broker_peer' => [
                        'channel' => 'project',
                        'uid' => (int)\posix_geteuid(),
                        'gid' => (int)\posix_getegid(),
                        'pid' => \getmypid(),
                    ],
                ],
                false,
            );
            self::fail('The deliberately incomplete route set was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'Registration must contain 1..256 routes.',
                $exception->getMessage(),
                'The same fenced launch must pass the digest-refresh gate before route validation.',
            );
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Same instance generation has a different instance digest.');
        $register->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'instance_id' => $instanceId,
                'project_generation' => 4,
                'request_digest' => \str_repeat('6', 64),
                'idempotency_key' => $projectUuid . ':' . $instanceId . ':4',
                'instance_generation' => 4,
                'instance_digest' => \str_repeat('7', 64),
                'master_epoch' => 4,
                'launch_id' => \str_repeat('8', 32),
                'gateway_epoch' => (string)$state['epoch'],
                'routes' => [],
                '_broker_peer' => [
                    'channel' => 'project',
                    'uid' => (int)\posix_geteuid(),
                    'gid' => (int)\posix_getegid(),
                    'pid' => \getmypid(),
                ],
            ],
            false,
        );
    }

    public function testSameGenerationCapabilityRefreshCannotChangeBackendRouteState(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $instanceId = 'capability-only-refresh';
        $routeId = \str_repeat('9', 32);
        $launchId = \str_repeat('4', 32);
        $backends = [['host' => '127.0.0.1', 'port' => 29140, 'weight' => 1]];
        $identity = [
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceId,
            'generation' => 4,
            'endpoint_file' => '/tmp/capability-endpoint.json',
            'master_pid' => 4100,
            'master_epoch' => 4,
            'launch_id' => $launchId,
            'edge_capability_digest' => \str_repeat('a', 64),
            'session_capability' => 'isolated',
        ];
        $identity['digest'] = \hash('sha256', GatewayClient::canonicalJson($identity));
        $evidence = [
            'schema' => 'wls-stateless-capability/1',
            'runtime_source' => 'project_endpoint',
            'runtime_declared' => true,
            'instance_generation' => 4,
            'reason' => 'declared_stateless_runtime',
        ];
        $nextIdentity = $identity;
        $nextIdentity['session_capability'] = 'stateless';
        $nextIdentity['session_capability_evidence'] = $evidence;
        $nextIdentity['session_capability_evidence_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($evidence),
        );
        $nextIdentity['digest'] = \hash('sha256', GatewayClient::canonicalJson(
            \array_diff_key($nextIdentity, ['digest' => true]),
        ));
        $existingInstance = [
            'instance_id' => $instanceId,
            'generation' => 4,
            'digest' => \str_repeat('b', 64),
            'master_epoch' => 4,
            'launch_id' => $launchId,
            'backends' => $backends,
            'backend_identity' => $identity,
        ];
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['projects'][$projectUuid] = ['route_ids' => [$routeId]];
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'capability-only.example.test',
            'force_https' => true,
            'certificate' => [
                'source_digest' => \str_repeat('c', 64),
                'generation' => 3,
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $candidate = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'capability-only.example.test',
            'force_https' => true,
            'certificate' => [
                'source_digest' => \str_repeat('c', 64),
                'generation' => 3,
            ],
            'backends' => $backends,
            'backend_identity' => $nextIdentity,
        ];
        $guard = new \ReflectionMethod(
            $this->controller,
            'assertCapabilityOnlyInstanceRefresh',
        );
        $guard->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            $existingInstance,
            [$candidate],
        );

        $candidate['backends'][0]['port'] = 29141;
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Same instance generation may refresh capability evidence only.',
        );
        $guard->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            $existingInstance,
            [$candidate],
        );
    }

    public function testPreparedPublicationResumesAsPendingAfterControllerRestart(): void
    {
        $operationId = \str_repeat('a', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 71;
        $state['operations'][$operationId] = [
            'operation_id' => $operationId,
            'state' => 'PREPARING',
            'created_unix' => \time(),
            'completed_unix' => 0,
        ];
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'restart-test');
        $publication = $publicationProperty->getValue($this->controller);
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $candidate = $configDir . DIRECTORY_SEPARATOR . 'candidate-restart-test.conf';
        self::assertNotFalse(\file_put_contents($candidate, "events {}\nhttp {}\n"));
        $publication['phase'] = 'PREPARED';
        $publication['candidate_generation'] = 71;
        $publication['candidate_file'] = $candidate;
        $publication['candidate_digest'] = \hash_file('sha256', $candidate);
        $publication['operation_ids'] = [$operationId];
        $publicationProperty->setValue($this->controller, $publication);
        (new \ReflectionMethod($this->controller, 'persistState'))->invoke($this->controller);
        (new \ReflectionMethod($this->controller, 'persistPublication'))
            ->invoke($this->controller);

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/restarted.sock',
        );
        $restartedState = (new \ReflectionProperty($restarted, 'state'))->getValue($restarted);
        $restartedPublication = (new \ReflectionProperty(
            $restarted,
            'publication',
        ))->getValue($restarted);
        $publicationDueAt = (new \ReflectionProperty(
            $restarted,
            'publicationDueAt',
        ))->getValue($restarted);

        self::assertFileDoesNotExist($candidate);
        self::assertSame('PENDING_PUBLICATION', $restartedPublication['phase']);
        self::assertSame('', $restartedPublication['candidate_file']);
        self::assertSame('', $restartedPublication['candidate_digest']);
        self::assertSame('PENDING_PUBLICATION', $restartedState['operations'][$operationId]['state']);
        self::assertSame('PUBLICATION_RECOVERY', $restartedState['health_state']);
        self::assertGreaterThan(0.0, $publicationDueAt);
        self::assertTrue((new \ReflectionProperty($restarted, 'configDirty'))->getValue($restarted));
    }

    public function testCorruptPublicationFailsPendingOperationsAndPersistsIsolation(): void
    {
        $operationId = \str_repeat('b', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['operations'][$operationId] = [
            'operation_id' => $operationId,
            'state' => 'ACTIVATING',
            'created_unix' => \time(),
            'completed_unix' => 0,
        ];
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistState'))->invoke($this->controller);
        $publicationFile = (new \ReflectionMethod($this->controller, 'publicationFile'))
            ->invoke($this->controller);
        self::assertNotFalse(\file_put_contents($publicationFile, '{"corrupt":true}'));

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/corrupt-publication.sock',
        );
        $restartedState = (new \ReflectionProperty($restarted, 'state'))->getValue($restarted);
        self::assertTrue($restartedState['isolation_mode']);
        self::assertFalse($restartedState['ready']);
        self::assertSame('STATE_REBUILD', $restartedState['health_state']);
        self::assertSame('FAILED', $restartedState['operations'][$operationId]['state']);
        self::assertStringContainsString(
            'corrupt',
            $restartedState['operations'][$operationId]['error'],
        );
        self::assertFileDoesNotExist($publicationFile);
        self::assertNotSame([], \glob($publicationFile . '.corrupt.*') ?: []);

        $secondRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/corrupt-again.sock',
        );
        $secondState = (new \ReflectionProperty($secondRestart, 'state'))
            ->getValue($secondRestart);
        self::assertTrue($secondState['isolation_mode']);
        self::assertSame('STATE_REBUILD', $secondState['health_state']);
        self::assertSame('FAILED', $secondState['operations'][$operationId]['state']);
    }

    public function testShadowCandidateUsesOnlyIndependentLoopbackPortsAndRuntimeFiles(): void
    {
        $render = new \ReflectionMethod($this->controller, 'renderNginxConfig');
        $source = $render->invoke($this->controller, false);
        $root = $this->home . DIRECTORY_SEPARATOR . 'runtime/shadow/test';
        $pid = $root . DIRECTORY_SEPARATOR . 'nginx.pid';
        $shadow = (new \ReflectionMethod($this->controller, 'shadowConfig'))->invoke(
            $this->controller,
            $source,
            28180,
            28443,
            28643,
            $pid,
            $root,
        );

        self::assertStringContainsString('listen 127.0.0.1:28180', $shadow);
        self::assertStringContainsString('listen 127.0.0.1:28443 ssl', $shadow);
        self::assertStringContainsString('listen 127.0.0.1:28643;', $shadow);
        self::assertStringContainsString('pid "' . $pid . '";', $shadow);
        self::assertStringNotContainsString(
            'listen 0.0.0.0:' . (int)\getenv('WLS_GATEWAY_LISTEN_HTTP'),
            $shadow,
        );
        self::assertStringNotContainsString(
            'listen 0.0.0.0:' . (int)\getenv('WLS_GATEWAY_LISTEN_HTTPS'),
            $shadow,
        );
        self::assertStringNotContainsString('listen [::]:', $shadow);
        self::assertStringNotContainsString(' quic', $shadow);
    }

    public function testH3RuntimeQuarantineSurvivesRouteGenerationsUntilRuntimeChanges(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['h3_capable'] = true;
        $state['h3_capability_runtime_generation'] = 'test-runtime-a';
        $state['h3_quarantined_runtime_generation'] = 'test-runtime-a';
        $state['generation'] = (int)$state['generation'] + 1;
        $stateProperty->setValue($this->controller, $state);

        $render = new \ReflectionMethod($this->controller, 'renderNginxConfig');
        $render->invoke($this->controller, true);
        $quarantined = $stateProperty->getValue($this->controller);
        self::assertFalse($quarantined['h3_enabled']);

        $quarantined['generation'] = (int)$quarantined['generation'] + 1;
        $stateProperty->setValue($this->controller, $quarantined);
        $render->invoke($this->controller, true);
        self::assertFalse($stateProperty->getValue($this->controller)['h3_enabled']);

        $manifest = $this->home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . 'A' . DIRECTORY_SEPARATOR . 'manifest.json';
        $decoded = \json_decode((string)\file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $decoded['runtime_generation'] = 'test-runtime-b';
        self::assertNotFalse(\file_put_contents(
            $manifest,
            \json_encode($decoded, JSON_THROW_ON_ERROR),
        ));
        $nginx = $this->home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . 'A' . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'nginx';
        self::assertNotFalse(\file_put_contents(
            $nginx,
            "#!/bin/sh\n"
                . "printf '%s\\n' 'nginx version: wls-test' "
                . "'configure arguments: --with-http_v3_module' >&2\n"
                . "exit 0\n",
        ));
        self::assertTrue(\chmod($nginx, 0700));
        $render->invoke($this->controller, true);
        self::assertTrue($stateProperty->getValue($this->controller)['h3_enabled']);
    }

    public function testVerifiedPublicationRefreshesOnlySelectedActiveLease(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174035';
        $activeId = 'instance-published';
        $staleId = 'instance-stale';
        $active = $this->instanceLease($activeId, 29110, 'stateless');
        $active['last_heartbeat'] = \time() - 100;
        $active['last_heartbeat_monotonic'] -= 100.0;
        $stale = $this->instanceLease($staleId, 29111, 'stateless');
        $stale['status'] = 'STALE';
        $stale['last_heartbeat'] = \time() - 200;
        $stale['last_heartbeat_monotonic'] -= 200.0;

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 51,
        ];
        $state['instances'][$projectUuid] = [
            $activeId => $active,
            $staleId => $stale,
        ];
        $state['routes'][\str_repeat('6', 32)] = [
            'route_id' => \str_repeat('6', 32),
            'project_uuid' => $projectUuid,
            'domain' => 'published.example.test',
            'enrollment_security_generation' => 51,
            'status' => 'ACTIVE',
            'instance_id' => $activeId,
            'instances' => [$activeId => $active],
            'last_heartbeat' => $active['last_heartbeat'],
        ];
        $state['routes'][\str_repeat('7', 32)] = [
            'route_id' => \str_repeat('7', 32),
            'project_uuid' => $projectUuid,
            'domain' => 'stale.example.test',
            'enrollment_security_generation' => 51,
            'status' => 'STALE',
            'instance_id' => $staleId,
            'instances' => [$staleId => $stale],
            'last_heartbeat' => $stale['last_heartbeat'],
        ];
        $stateProperty->setValue($this->controller, $state);

        (new \ReflectionMethod($this->controller, 'refreshVerifiedPublishedLeases'))
            ->invoke($this->controller);
        $refreshed = $stateProperty->getValue($this->controller);
        self::assertGreaterThan(
            $active['last_heartbeat'],
            $refreshed['instances'][$projectUuid][$activeId]['last_heartbeat'],
        );
        self::assertGreaterThan(
            $active['last_heartbeat_monotonic'],
            $refreshed['routes'][\str_repeat('6', 32)]['instances'][$activeId]['last_heartbeat_monotonic'],
        );
        self::assertSame(
            $stale['last_heartbeat'],
            $refreshed['instances'][$projectUuid][$staleId]['last_heartbeat'],
        );
        self::assertSame(
            $stale['last_heartbeat_monotonic'],
            $refreshed['routes'][\str_repeat('7', 32)]['instances'][$staleId]['last_heartbeat_monotonic'],
        );
    }

    public function testHeartbeatCannotReactivateStaleOrDrainingRouting(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174034';
        $instanceId = 'instance-heartbeat';
        $routeId = \str_repeat('e', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29100, 'stateless');
        $lease['status'] = 'STALE';
        $lease['last_heartbeat'] = \time() - 100;
        $lease['drain_until'] = 12345;
        $lease['drain_until_monotonic'] = 12345.0;
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'instances' => [$instanceId => $lease],
            'status' => 'STALE',
        ];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, false);

        (new \ReflectionMethod($this->controller, 'touchInstanceLease'))->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            1,
            \str_repeat('c', 32),
            1,
        );

        $renewed = $stateProperty->getValue($this->controller);
        self::assertSame('STALE', $renewed['instances'][$projectUuid][$instanceId]['status']);
        self::assertSame(12345, $renewed['instances'][$projectUuid][$instanceId]['drain_until']);
        self::assertSame(
            'STALE',
            $renewed['routes'][$routeId]['instances'][$instanceId]['status'],
        );
        self::assertSame(
            12345,
            $renewed['routes'][$routeId]['instances'][$instanceId]['drain_until'],
        );
        self::assertFalse($dirtyProperty->getValue($this->controller));
        self::assertNull($publicationProperty->getValue($this->controller));
        self::assertGreaterThan(
            $lease['last_heartbeat'],
            $renewed['instances'][$projectUuid][$instanceId]['last_heartbeat'],
        );

        $renewed['projects'][$projectUuid] = ['generation' => 1];
        $stateProperty->setValue($this->controller, $renewed);
        $heartbeat = (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_generation' => 1,
                'instance_id' => $instanceId,
                'instance_generation' => 1,
                'master_epoch' => 1,
                'launch_id' => \str_repeat('c', 32),
            ],
        );
        self::assertTrue($heartbeat['accepted']);
        self::assertTrue($heartbeat['re_register_required']);
    }

    public function testHeartbeatRequestsReplayWhenInstanceDigestChangesWithoutTouchingLease(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174098';
        $instanceId = 'instance-capability-refresh';
        $routeId = \str_repeat('5', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29103, 'isolated');
        $lease['last_heartbeat'] = \time() - 20;
        $state['projects'][$projectUuid] = ['generation' => 1];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'instances' => [$instanceId => $lease],
            'status' => 'ACTIVE',
        ];
        $stateProperty->setValue($this->controller, $state);

        $heartbeat = (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_generation' => 1,
                'instance_id' => $instanceId,
                'instance_generation' => 1,
                'instance_digest' => \str_repeat('d', 64),
                'master_epoch' => 1,
                'launch_id' => \str_repeat('c', 32),
            ],
        );

        self::assertTrue($heartbeat['accepted']);
        self::assertTrue($heartbeat['re_register_required']);
        $unchanged = $stateProperty->getValue($this->controller);
        self::assertSame(
            $lease['last_heartbeat'],
            $unchanged['instances'][$projectUuid][$instanceId]['last_heartbeat'],
            'A changed digest must replay full registration before renewing the lease.',
        );
    }

    public function testIdempotentRegistrationFastPathRequiresFullyActiveRouting(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174036';
        $instanceId = 'instance-rejoin';
        $routeId = \str_repeat('6', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29102, 'stateless');
        $state['projects'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'route_ids' => [$routeId],
        ];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'status' => 'ACTIVE',
            'instances' => [
                $instanceId => $lease + ['backend_healthy' => true],
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $fullyActive = new \ReflectionMethod(
            $this->controller,
            'registrationLeaseFullyActive',
        );
        self::assertTrue($fullyActive->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
        ));

        $state['routes'][$routeId]['status'] = 'STALE';
        $state['routes'][$routeId]['instances'][$instanceId]['status'] = 'STALE';
        $state['instances'][$projectUuid][$instanceId]['status'] = 'STALE';
        $stateProperty->setValue($this->controller, $state);
        self::assertFalse($fullyActive->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
        ));

        $state['routes'][$routeId]['status'] = 'PENDING_BACKEND';
        $state['routes'][$routeId]['instances'][$instanceId]['status'] = 'ACTIVE';
        $state['routes'][$routeId]['instances'][$instanceId]['backend_healthy'] = false;
        $state['instances'][$projectUuid][$instanceId]['status'] = 'ACTIVE';
        $stateProperty->setValue($this->controller, $state);
        self::assertFalse($fullyActive->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
        ));
    }

    public function testHeartbeatPersistsFreshMasterDrainCountersWithoutPublishing(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174035';
        $instanceId = 'instance-draining';
        $routeId = \str_repeat('f', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29101, 'master-counters');
        $lease['status'] = 'DRAINING';
        $lease['drain_until'] = \time() + 300;
        $state['projects'][$projectUuid] = ['generation' => 1];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'instances' => [$instanceId => $lease],
            'status' => 'DRAINING',
        ];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, false);

        $heartbeat = (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_generation' => 1,
                'instance_id' => $instanceId,
                'instance_generation' => 1,
                'master_epoch' => 1,
                'launch_id' => \str_repeat('c', 32),
                'drain_counters' => [
                    'version' => 1,
                    'counters_known' => true,
                    'worker_count' => 2,
                    'reported_worker_count' => 2,
                    'active_requests' => 3,
                    'long_lived_connections' => 4,
                    'sse_connections' => 2,
                    'websocket_connections' => 1,
                ],
            ],
        );
        self::assertFalse($heartbeat['re_register_required']);

        $renewed = $stateProperty->getValue($this->controller);
        $stored = $renewed['instances'][$projectUuid][$instanceId]['drain_counters'];
        self::assertSame(3, $stored['active_requests']);
        self::assertSame(4, $stored['long_lived_connections']);
        self::assertSame(
            'DRAINING',
            $renewed['routes'][$routeId]['instances'][$instanceId]['status'],
        );
        self::assertFalse($dirtyProperty->getValue($this->controller));
        self::assertNull($publicationProperty->getValue($this->controller));

        $statuses = (new \ReflectionMethod($this->controller, 'projectInstanceStatuses'))
            ->invoke($this->controller, $projectUuid);
        self::assertCount(1, $statuses);
        self::assertTrue($statuses[0]['counters_known']);
        self::assertSame('master-heartbeat', $statuses[0]['counter_source']);
        self::assertSame(3, $statuses[0]['active_requests']);
        self::assertSame(4, $statuses[0]['long_lived_connections']);
        self::assertFalse($statuses[0]['drain_complete']);

        $renewed['instances'][$projectUuid][$instanceId]['drain_counters']['reported_monotonic']
            -= 26.0;
        $stateProperty->setValue($this->controller, $renewed);
        $stale = (new \ReflectionMethod($this->controller, 'projectInstanceStatuses'))
            ->invoke($this->controller, $projectUuid);
        self::assertFalse($stale[0]['counters_known']);
        self::assertSame(0, $stale[0]['active_requests']);
        self::assertSame(0, $stale[0]['long_lived_connections']);
        self::assertFalse($stale[0]['drain_complete']);
    }

    public function testUntrustedSecurityMarkerOverridesOlderValidLedger(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174031';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 1,
        ];
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistSecurityLedger'))
            ->invoke($this->controller);
        $ledger = $this->home . DIRECTORY_SEPARATOR . 'state/security-ledger.json';
        self::assertFileExists($ledger);
        self::assertNotFalse(\file_put_contents($ledger . '.untrusted', "injected failure\n"));

        $isolated = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/marker.sock',
        );
        $isolatedState = (new \ReflectionProperty($isolated, 'state'))->getValue($isolated);
        self::assertFalse($isolatedState['security_ledger_valid']);
        self::assertSame([], $isolatedState['enrollments']);
        self::assertTrue($isolatedState['isolation_mode']);
        self::assertSame('SECURITY_LEDGER_UNTRUSTED', $isolatedState['health_state']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(
        string $channel,
        string $operation,
        array $payload,
        string $credentialId,
        string $secret,
        ?string $nonce = null,
        bool $tamperDigest = false,
        ?int $peerUid = null,
        ?float $monotonicTimestamp = null,
    ): array {
        $request = [
            'protocol' => 'wls-edge/2',
            'channel' => $channel,
            'host_id' => $this->hostId,
            'credential_id' => $credentialId,
            'operation' => $operation,
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'monotonic_timestamp' => $monotonicTimestamp
                ?? \hrtime(true) / 1_000_000_000,
            'nonce' => $nonce ?? \bin2hex(\random_bytes(16)),
            'payload' => $payload,
        ];
        $request['request_digest'] = \hash('sha256', GatewayClient::canonicalJson([
            'operation' => $operation,
            'payload' => $payload,
        ]));
        if ($tamperDigest) {
            $request['request_digest'] = \str_repeat('0', 64);
        }
        $request['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($request),
            $secret,
        );
        $encoded = \json_encode(
            $request,
            JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $peerUid ??= (int)\posix_geteuid();
        $broker = \json_encode([
            'broker_schema' => 1,
            'channel' => $channel,
            'uid' => $peerUid,
            'gid' => (int)\posix_getegid(),
            'pid' => \getmypid(),
            'fencing_token' => $this->fencing,
            'payload_length' => \strlen($encoded),
        ], JSON_THROW_ON_ERROR) . "\n";
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($sockets);
        self::assertSame(\strlen($broker . $encoded), \fwrite($sockets[0], $broker . $encoded));
        $this->serveClient->invoke($this->controller, $sockets[1]);
        $line = \fgets($sockets[0], 4 * 1024 * 1024);
        \fclose($sockets[0]);
        self::assertIsString($line);
        return \json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $response */
    private function assertResponseSignature(array $response, string $secret): void
    {
        $signature = (string)($response['signature'] ?? '');
        unset($response['signature']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $signature);
        self::assertSame(
            \hash_hmac('sha256', GatewayClient::canonicalJson($response), $secret),
            $signature,
        );
    }

    private function createProject(): string
    {
        $project = $this->root . DIRECTORY_SEPARATOR . 'project-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($project . DIRECTORY_SEPARATOR . 'app/etc/ssl', 0700, true));
        return $project;
    }

    /**
     * @return array{cert:string,key:string}
     */
    private function createCertificate(
        string $project,
        string $domain,
        string $name,
    ): array {
        $directory = $project . DIRECTORY_SEPARATOR . 'app/etc/ssl/' . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $config = $directory . DIRECTORY_SEPARATOR . 'openssl.cnf';
        self::assertNotFalse(\file_put_contents($config, <<<CONF
[req]
distinguished_name = dn
prompt = no
req_extensions = server_ext
x509_extensions = server_ext

[dn]
CN = {$domain}

[server_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = {$domain}
CONF
        ));
        $arguments = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'req_extensions' => 'server_ext',
            'x509_extensions' => 'server_ext',
        ];
        $key = \openssl_pkey_new($arguments);
        self::assertNotFalse($key);
        $request = \openssl_csr_new(['commonName' => $domain], $key, $arguments);
        self::assertNotFalse($request);
        $certificate = \openssl_csr_sign($request, null, $key, 30, $arguments);
        self::assertNotFalse($certificate);
        self::assertTrue(\openssl_x509_export($certificate, $certificatePem));
        self::assertTrue(\openssl_pkey_export($key, $keyPem, null, $arguments));
        $certificatePath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertNotFalse(\file_put_contents($certificatePath, $certificatePem));
        self::assertNotFalse(\file_put_contents($keyPath, $keyPem));
        self::assertTrue(\chmod($certificatePath, 0600));
        self::assertTrue(\chmod($keyPath, 0600));
        return ['cert' => $certificatePath, 'key' => $keyPath];
    }

    /** @return array<string,mixed> */
    private function instanceLease(string $instanceId, int $port, string $capability): array
    {
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $identity = ['session_capability' => $capability];
        if ($capability === 'stateless') {
            $evidence = [
                'schema' => 'wls-stateless-capability/1',
                'runtime_source' => 'project_endpoint',
                'runtime_declared' => true,
                'instance_generation' => 1,
                'reason' => 'declared_stateless_runtime',
            ];
            $identity['session_capability_evidence'] = $evidence;
            $identity['session_capability_evidence_digest'] = \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            );
        } elseif ($capability === 'shared_session') {
            $evidence = [
                'schema' => 'wls-session-capability/1',
                'storage' => 'wls',
                'runtime_source' => 'project_shared_state',
                'runtime_registered' => true,
                'runtime_shared_service' => true,
                'host' => '127.0.0.1',
                'port' => 31998,
                'token_scope_digest' => \hash('sha256', 'session.token'),
                'probe' => 'healthy',
                'reason' => 'authenticated_session_runtime',
            ];
            $identity['session_capability_evidence'] = $evidence;
            $identity['session_capability_evidence_digest'] = \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            );
        }
        return [
            'instance_id' => $instanceId,
            'generation' => 1,
            'digest' => \str_repeat('b', 64),
            'master_epoch' => 1,
            'launch_id' => \str_repeat('c', 32),
            'backends' => [['host' => '127.0.0.1', 'port' => $port, 'weight' => 1]],
            'backend_identity' => $identity,
            'backend_healthy' => true,
            'status' => 'ACTIVE',
            'last_heartbeat' => \time(),
            'last_heartbeat_monotonic' => \hrtime(true) / 1_000_000_000,
            'lease_boot_id' => $hostBootId,
            'drain_until' => null,
            'drain_until_monotonic' => null,
        ];
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
