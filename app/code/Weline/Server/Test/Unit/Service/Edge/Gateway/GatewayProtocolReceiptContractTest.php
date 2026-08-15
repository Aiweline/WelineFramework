<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayProjectIdentityRotator;

final class GatewayProtocolReceiptContractTest extends TestCase
{
    public function testLeaseReceiptValidationBindsCurrentHostBootBeforeUsingMonotonicTime(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(GatewayHostManager::class))->getFileName(),
        );

        self::assertStringContainsString(
            '$currentHostBootId = GatewayHostBootIdentity::current();',
            $source,
        );
        self::assertStringContainsString(
            '!\\hash_equals($currentHostBootId, $hostBootId)',
            $source,
        );
        self::assertSame(2, \substr_count(
            $source,
            "\$registration['host_boot_id'] = self::currentAuthenticatedHostBootId(\$status);",
        ));
        self::assertSame(3, \substr_count(
            $source,
            "'host_boot_id' => (string)\$registration['host_boot_id']",
        ));
        self::assertStringContainsString(
            '!\\hash_equals($current, $observed)',
            $source,
        );
    }

    public function testEnrollmentWireDigestExcludesOnlyDerivedEnvelopeFields(): void
    {
        $facts = ['project_uuid' => 'p', 'project_root' => '/project'];
        $digest = GatewayHostManager::enrollmentRequestDigest($facts);
        self::assertSame(
            $digest,
            GatewayHostManager::enrollmentRequestDigest($facts + [
                'request_digest' => \str_repeat('a', 64),
                'idempotency_key' => \str_repeat('b', 40),
            ]),
        );
        self::assertContains(
            'authenticated_desired_digest',
            GatewayHostManager::ENROLLMENT_RECEIPT_FIELDS,
        );
        self::assertSame([
            'schema_version', 'protocol', 'host_id', 'project_uuid',
            'gateway_epoch', 'project_generation', 'instance_id',
            'instance_generation', 'instance_digest', 'master_epoch',
            'launch_id', 'request_digest', 'idempotency_key',
            'active_config_generation', 'active_config_digest',
            'host_boot_id', 'issued_monotonic', 'lifecycle_state',
            'drain_operation_id', 'drain_started_monotonic', 'lease_sequence',
            'lease_ttl_seconds', 'route_generations', 'routes_digest',
            'issued_at', 'signature',
        ], GatewayHostManager::LEASE_RECEIPT_FIELDS);
    }

    public function testDrainOperationIdentityBindsLaunchAndCannotExtendWithSeconds(): void
    {
        $receipt = [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174011',
            'instance_generation' => 7,
            'master_epoch' => 9,
            'launch_id' => \str_repeat('a', 32),
        ];
        $facts = [
            'operation' => 'drain',
            'project_uuid' => $receipt['project_uuid'],
            'instance_id' => 'drain-idempotency',
            'instance_generation' => 7,
            'master_epoch' => 9,
            'launch_id' => \str_repeat('a', 32),
        ];
        $operationId = GatewayHostManager::drainOperationId(
            $receipt,
            'drain-idempotency',
        );
        self::assertSame(
            \hash('sha256', GatewayClient::canonicalJson($facts)),
            $operationId,
        );
        self::assertSame(
            $operationId,
            GatewayHostManager::drainOperationId($receipt, 'drain-idempotency'),
        );
        self::assertNotSame(
            $operationId,
            GatewayHostManager::drainOperationId(
                [...$receipt, 'launch_id' => \str_repeat('b', 32)],
                'drain-idempotency',
            ),
        );
    }

    public function testDrainPersistsPublishedLifecycleReceiptAndRefreshesItWhileWaiting(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(GatewayHostManager::class))->getFileName(),
        );

        self::assertStringContainsString(
            '$operationResult = \\is_array($operation[\'result\'] ?? null)',
            $source,
        );
        self::assertStringContainsString(
            'self::leaseReceiptFromPublication($payload)',
            $source,
        );
        self::assertStringContainsString(
            '$heartbeat = $this->heartbeat(',
            $source,
        );
        self::assertStringContainsString(
            'DRAIN_LEASE_HEARTBEAT_FAILURE_SECONDS',
            $source,
        );
        self::assertStringContainsString(
            '(($operationResult[\'already_removed\'] ?? false) === true)',
            $source,
        );
    }

    public function testRotationPrepareDigestAndIdempotencyMatchProtocolFacts(): void
    {
        $rotation = [
            'rotation_id' => \str_repeat('a', 32),
            'old_project_uuid' => '123e4567-e89b-42d3-a456-426614174031',
            'new_project_uuid' => '123e4567-e89b-42d3-a456-426614174032',
            'project_root' => '/project',
        ];
        $method = new \ReflectionMethod(
            GatewayProjectIdentityRotator::class,
            'prepareRequest',
        );
        $request = $method->invoke(null, $rotation);
        $digest = \hash('sha256', GatewayClient::canonicalJson([
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'rotation_id' => $rotation['rotation_id'],
            'old_project_uuid' => $rotation['old_project_uuid'],
            'new_project_uuid' => $rotation['new_project_uuid'],
            'project_root' => $rotation['project_root'],
        ]));
        self::assertSame($digest, $request['request_digest']);
        self::assertSame(\substr(\hash(
            'sha256',
            $rotation['old_project_uuid'] . ':rotate:'
                . $rotation['new_project_uuid'] . ':' . $digest,
        ), 0, 40), $request['idempotency_key']);
        self::assertSame(
            GatewayProjectIdentityRotator::COMMIT_RECEIPT_FIELDS,
            [
                'schema_version', 'protocol', 'host_id', 'gateway_epoch',
                'rotation_id', 'old_project_uuid', 'new_project_uuid',
                'project_root', 'request_digest', 'idempotency_key',
                'security_generation', 'new_credential_id', 'state',
                'issued_at', 'signature',
            ],
        );
    }

    public function testAuthenticatedRotationPrepareKeepsOnlyPendingCredentialShape(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'sanitizeAuthenticatedResponse');
        $hostId = \str_repeat('a', 32);
        $newUuid = '123e4567-e89b-42d3-a456-426614174042';
        $sanitized = $method->invoke(null, [
            'ok' => true,
            'payload' => [
                'credential' => [
                    'schema_version' => 1,
                    'protocol' => GatewayPaths::PROTOCOL,
                    'host_id' => $hostId,
                    'project_uuid' => $newUuid,
                    'credential_id' => \str_repeat('b', 32),
                    'credential_generation' => 2,
                    'secret' => \str_repeat('c', 64),
                    'issued_at' => '2026-08-03T00:00:00+00:00',
                    'unexpected' => 'discarded',
                ],
            ],
        ], 'project', [
            'operation' => 'rotate-prepare',
            'host_id' => $hostId,
            'payload' => ['new_project_uuid' => $newUuid],
        ]);
        self::assertSame(\str_repeat('c', 64), $sanitized['payload']['credential']['secret']);
        self::assertArrayNotHasKey('unexpected', $sanitized['payload']['credential']);
    }

    public function testRotationLocalAfterImageMustProveCommittedCredentialSecret(): void
    {
        $credential = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => \str_repeat('a', 32),
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174062',
            'credential_id' => \str_repeat('b', 32),
            'credential_generation' => 2,
            'secret' => \str_repeat('c', 64),
            'issued_at' => '2026-08-03T00:00:00+00:00',
        ];
        $rotation = [
            'rotation_id' => \str_repeat('d', 32),
            'old_project_uuid' => '123e4567-e89b-42d3-a456-426614174061',
            'new_project_uuid' => $credential['project_uuid'],
            'project_root' => '/project',
            'request_digest' => \str_repeat('e', 64),
            'idempotency_key' => \str_repeat('f', 40),
            'new_credential_id' => $credential['credential_id'],
        ];
        $receipt = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $credential['host_id'],
            'gateway_epoch' => \str_repeat('1', 32),
            'rotation_id' => $rotation['rotation_id'],
            'old_project_uuid' => $rotation['old_project_uuid'],
            'new_project_uuid' => $rotation['new_project_uuid'],
            'project_root' => $rotation['project_root'],
            'request_digest' => $rotation['request_digest'],
            'idempotency_key' => $rotation['idempotency_key'],
            'security_generation' => 7,
            'new_credential_id' => $rotation['new_credential_id'],
            'state' => 'CONTROLLER_COMMITTED',
            'issued_at' => '2026-08-03T00:00:00+00:00',
        ];
        $receipt['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($receipt),
            $credential['secret'],
        );
        $rotation['commit_receipt'] = $receipt;

        GatewayProjectIdentityRotator::validateCommittedCredential(
            $rotation,
            $credential,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not prove the committed host rotation');
        GatewayProjectIdentityRotator::validateCommittedCredential(
            $rotation,
            [...$credential, 'secret' => \str_repeat('2', 64)],
        );
    }

    public function testLeaseReceiptMustMatchExactAuthenticatedPublicationFence(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'assertLeaseReceiptMatchesAuthenticatedStatus',
        );
        $projectUuid = '123e4567-e89b-42d3-a456-426614174051';
        $instanceId = 'receipt-test';
        $epoch = \str_repeat('a', 32);
        $requestDigest = \str_repeat('b', 64);
        $idempotencyKey = \str_repeat('c', 40);
        $instanceDigest = \str_repeat('d', 64);
        $launchId = \str_repeat('e', 32);
        $activeDigest = \str_repeat('f', 64);
        $routeId = \str_repeat('1', 32);
        $routeGenerations = [$routeId => 3];
        $receipt = [
            'schema_version' => GatewayHostManager::LEASE_RECEIPT_SCHEMA_VERSION,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => \str_repeat('8', 32),
            'project_uuid' => $projectUuid,
            'gateway_epoch' => $epoch,
            'project_generation' => 5,
            'request_digest' => $requestDigest,
            'idempotency_key' => $idempotencyKey,
            'active_config_generation' => 11,
            'active_config_digest' => $activeDigest,
            'host_boot_id' => \str_repeat('9', 64),
            'issued_monotonic' => 100.0,
            'lifecycle_state' => 'ACTIVE',
            'drain_operation_id' => '',
            'drain_started_monotonic' => 0.0,
            'lease_sequence' => 1,
            'lease_ttl_seconds' => 45,
            'instance_id' => $instanceId,
            'instance_generation' => 7,
            'instance_digest' => $instanceDigest,
            'master_epoch' => 9,
            'launch_id' => $launchId,
            'route_generations' => $routeGenerations,
            'routes_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($routeGenerations),
            ),
            'issued_at' => '2026-08-06T00:00:00+00:00',
            'signature' => \str_repeat('7', 64),
        ];
        $status = [
            'ok' => true,
            'control_plane_ready' => true,
            'release_ready' => true,
            'broker_ready' => true,
            'supervisor_ready' => true,
            'protocol' => GatewayPaths::PROTOCOL,
            'implementation_level' => GatewayPaths::IMPLEMENTATION_LEVEL,
            'security_profile' => GatewayPaths::SECURITY_PROFILE,
            'protocol_min' => 2,
            'protocol_max' => 2,
            'public_http' => 80,
            'public_https' => 443,
            'epoch' => $epoch,
            'publication_exact' => true,
            'project_uuid' => $projectUuid,
            'project_generation' => 5,
            'request_digest' => $requestDigest,
            'idempotency_key' => $idempotencyKey,
            'active_config_generation' => 11,
            'active_config_digest' => $activeDigest,
            'host_boot_id' => \str_repeat('9', 64),
            'instances' => [[
                'instance_id' => $instanceId,
                'status' => 'ACTIVE',
                'generation' => 7,
                'digest' => $instanceDigest,
                'master_epoch' => 9,
                'launch_id' => $launchId,
            ]],
            'active_routes' => [[
                'project_uuid' => $projectUuid,
                'route_id' => $routeId,
                'status' => 'PENDING_CERTIFICATE',
                'route_generation' => 3,
            ]],
        ];

        $method->invoke(null, $receipt, $status);
        self::addToAssertionCount(1);

        foreach ([
            ['project_generation' => 6],
            ['request_digest' => \str_repeat('2', 64)],
            ['idempotency_key' => \str_repeat('3', 40)],
            ['active_config_generation' => 12],
            ['active_config_digest' => \str_repeat('4', 64)],
            ['instances' => [[...$status['instances'][0], 'digest' => \str_repeat('5', 64)]]],
            ['instances' => [[...$status['instances'][0], 'master_epoch' => 10]]],
            ['instances' => [[...$status['instances'][0], 'launch_id' => \str_repeat('6', 32)]]],
        ] as $override) {
            try {
                $method->invoke(null, $receipt, [...$status, ...$override]);
                self::fail('A stale receipt publication fence must be rejected.');
            } catch (\Throwable $throwable) {
                self::assertInstanceOf(\RuntimeException::class, $throwable);
            }
        }
    }

    public function testConcurrentReceiptCompletionCannotOverwriteNewerPublication(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'assertLeaseReceiptMayReplace',
        );
        $routeId = \str_repeat('1', 32);
        $existing = [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174061',
            'gateway_epoch' => \str_repeat('a', 32),
            'project_generation' => 5,
            'instance_id' => 'receipt-race',
            'instance_generation' => 7,
            'instance_digest' => \str_repeat('b', 64),
            'master_epoch' => 9,
            'launch_id' => \str_repeat('c', 32),
            'request_digest' => \str_repeat('d', 64),
            'idempotency_key' => \str_repeat('e', 40),
            'active_config_generation' => 12,
            'active_config_digest' => \str_repeat('f', 64),
            'host_boot_id' => \str_repeat('9', 64),
            'issued_monotonic' => 100.0,
            'lifecycle_state' => 'ACTIVE',
            'drain_operation_id' => '',
            'drain_started_monotonic' => 0.0,
            'lease_sequence' => 4,
            'lease_ttl_seconds' => 45,
            'route_generations' => [$routeId => 3],
            'routes_digest' => \hash('sha256', GatewayClient::canonicalJson([
                $routeId => 3,
            ])),
            'issued_at' => '2026-08-03T10:00:10+00:00',
        ];

        $newer = [...$existing,
            'active_config_generation' => 13,
            'active_config_digest' => \str_repeat('2', 64),
            'project_generation' => 6,
            'request_digest' => \str_repeat('3', 64),
            'idempotency_key' => \str_repeat('4', 40),
            'route_generations' => [$routeId => 4],
            'routes_digest' => \hash('sha256', GatewayClient::canonicalJson([
                $routeId => 4,
            ])),
            'issued_at' => '2026-08-03T10:00:20+00:00',
            'issued_monotonic' => 110.0,
            'lease_sequence' => 5,
        ];
        $method->invoke(null, $existing, $newer);
        self::addToAssertionCount(1);

        $drainOperationId = \str_repeat('7', 64);
        $draining = [...$newer,
            'lifecycle_state' => 'DRAINING',
            'drain_operation_id' => $drainOperationId,
            'drain_started_monotonic' => 105.0,
        ];
        $method->invoke(null, $existing, $draining);
        self::addToAssertionCount(1);

        foreach ([
            [...$existing,
                'active_config_generation' => 11,
                'issued_at' => '2026-08-03T10:00:20+00:00',
                'issued_monotonic' => 110.0,
                'lease_sequence' => 5,
            ],
            [...$existing,
                'active_config_digest' => \str_repeat('5', 64),
                'issued_at' => '2026-08-03T10:00:20+00:00',
                'issued_monotonic' => 110.0,
                'lease_sequence' => 5,
            ],
            [...$existing,
                'route_generations' => [$routeId => 4],
                'routes_digest' => \hash('sha256', GatewayClient::canonicalJson([
                    $routeId => 4,
                ])),
                'issued_at' => '2026-08-03T10:00:20+00:00',
                'issued_monotonic' => 110.0,
                'lease_sequence' => 5,
            ],
            [...$newer,
                'issued_monotonic' => 90.0,
            ],
            [...$newer,
                'issued_monotonic' => 110.0,
                'lease_ttl_seconds' => 10,
            ],
        ] as $staleCompletion) {
            $rejected = false;
            try {
                $method->invoke(null, $existing, $staleCompletion);
            } catch (\RuntimeException $throwable) {
                $rejected = true;
                self::assertStringStartsWith(
                    'REGISTER_REPLAY_REQUIRED:',
                    $throwable->getMessage(),
                );
            }
            self::assertTrue($rejected, 'An inverse receipt completion must fail closed.');
        }

        foreach ([
            [...$draining,
                'lifecycle_state' => 'ACTIVE',
                'drain_operation_id' => '',
                'drain_started_monotonic' => 0.0,
                'lease_sequence' => 6,
                'issued_monotonic' => 120.0,
            ],
            [...$draining,
                'drain_operation_id' => \str_repeat('8', 64),
                'lease_sequence' => 6,
                'issued_monotonic' => 120.0,
            ],
        ] as $inverseLifecycle) {
            try {
                $method->invoke(null, $draining, $inverseLifecycle);
                self::fail('A committed DRAINING receipt must never regress or change identity.');
            } catch (\RuntimeException $throwable) {
                self::assertStringStartsWith(
                    'REGISTER_REPLAY_REQUIRED:',
                    $throwable->getMessage(),
                );
            }
        }

        $newEpochDrain = [...$draining,
            'gateway_epoch' => \str_repeat('b', 32),
            'active_config_generation' => 1,
            'lease_sequence' => 1,
            'issued_monotonic' => 120.0,
        ];
        $method->invoke(null, $draining, $newEpochDrain);
        self::addToAssertionCount(1);
        try {
            $method->invoke(null, $newEpochDrain, [...$newer,
                'gateway_epoch' => \str_repeat('a', 32),
                'lease_sequence' => 99,
                'issued_monotonic' => 115.0,
            ]);
            self::fail('A delayed old-epoch heartbeat replaced the new epoch receipt.');
        } catch (\RuntimeException $throwable) {
            self::assertStringStartsWith(
                'REGISTER_REPLAY_REQUIRED:',
                $throwable->getMessage(),
            );
        }
        try {
            $method->invoke(null, $newEpochDrain, [...$newer,
                'gateway_epoch' => \str_repeat('a', 32),
                'lease_sequence' => 100,
                'issued_monotonic' => 120.0,
            ]);
            self::fail('Equal monotonic timestamps cannot order distinct gateway epochs.');
        } catch (\RuntimeException $throwable) {
            self::assertStringStartsWith(
                'REGISTER_REPLAY_REQUIRED:',
                $throwable->getMessage(),
            );
        }
    }

    public function testDurableLeaseReceiptCommitIsNotReclassifiedByExpiredBusinessDeadline(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(GatewayHostManager::class))->getFileName(),
        );
        $start = \strpos($source, 'private function persistLeaseReceipt(');
        $end = \strpos($source, 'private static function assertLeaseReceiptMayReplace(', $start ?: 0);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = \substr($source, $start, $end - $start);

        self::assertStringContainsString(
            '$updated = ServerInstanceManager::updateJsonFileAtomically(',
            $method,
        );
        self::assertStringContainsString('if (!$updated) {', $method);
        self::assertStringNotContainsString(
            '$this->remainingOperationDeadline($deadlineMonotonic);',
            $method,
            'A successful atomic publication is the durable commit result; a post-commit deadline gate creates a false failure.',
        );
    }

    public function testExpiredRegistrationDeadlineIsNotReusedForFailureCompensation(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(GatewayHostManager::class))->getFileName(),
        );
        $start = \strpos($source, 'private function submitRegistration(');
        $end = \strpos($source, 'public static function controlPlaneAcceptsRegistration(', $start ?: 0);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = \substr($source, $start, $end - $start);
        $catch = \strpos($method, '} catch (\\Throwable $throwable) {');
        self::assertIsInt($catch);
        $compensation = \substr($method, $catch);

        self::assertStringContainsString(
            'FAILURE_COMPENSATION_DEADLINE_SECONDS',
            $compensation,
        );
        self::assertStringNotContainsString(
            '$deadlineMonotonic',
            $compensation,
            'Failure bookkeeping needs its own bounded deadline after the host mutation budget has expired.',
        );
    }

    public function testPosixSnapshotReceiptV2BindsImmutableEntityAndRootOnlyMac(): void
    {
        $source = self::posixNativeBrokerSource();
        $canonicalStart = \strpos(
            $source,
            'static int wls_snapshot_receipt_canonical(',
        );
        $canonicalEnd = \strpos(
            $source,
            'static int wls_snapshot_receipt_mac(',
            $canonicalStart ?: 0,
        );
        self::assertIsInt($canonicalStart);
        self::assertIsInt($canonicalEnd);
        $canonical = \substr(
            $source,
            $canonicalStart,
            $canonicalEnd - $canonicalStart,
        );
        self::assertStringContainsString('WLS-SNAPSHOT-RECEIPT/2', $canonical);
        self::assertStringNotContainsString('receipt_digest=', $canonical);
        self::assertStringNotContainsString('receipt_mac=', $canonical);

        $ordered = [
            'snapshot_digest=', 'project_uuid=', 'transaction_id=',
            'intent_digest=', 'security_generation=', 'certificate_generation=',
            'source_binding_digest=', 'cert_source_digest=', 'key_source_digest=',
            'chain_source_digest=', 'manifest_semantic_digest=',
            'manifest_file_digest=', 'fullchain_digest=', 'private_key_digest=',
            'leaf_fingerprint=', 'san_names_digest=', 'not_before=', 'not_after=',
            'key_match=', 'platform=', 'gateway_epoch=', 'host_boot_id=',
            'broker_binary_digest=', 'data_plane_identity_kind=',
            'data_plane_identity_value=', 'controller_identity_kind=',
            'controller_identity_value=', 'owner_identity_kind=',
            'owner_identity_value=', 'acl_profile=', 'directory_acl_digest=',
            'fullchain_acl_digest=', 'private_key_acl_digest=',
            'manifest_acl_digest=', 'acl_protected=', 'reparse_free=',
            'single_link_files=', 'snapshot_file_ids_digest=',
            'broker_runtime_generation=',
        ];
        $previous = -1;
        foreach ($ordered as $field) {
            $position = \strpos($canonical, $field);
            self::assertIsInt($position, $field);
            self::assertGreaterThan($previous, $position, $field);
            $previous = $position;
        }

        $macEnd = \strpos(
            $source,
            'static int wls_snapshot_receipt_publish(',
            $canonicalEnd,
        );
        self::assertIsInt($macEnd);
        $mac = \substr($source, $canonicalEnd, $macEnd - $canonicalEnd);
        self::assertStringContainsString('wls_snapshot_receipt_key(home, token)', $mac);
        self::assertStringContainsString('snapshot-receipt.key', $source);
        self::assertStringContainsString(
            'wls_snapshot_receipt_key_link_recover(',
            $source,
        );
        self::assertStringContainsString(
            'wls_snapshot_receipt_key_candidate_namespace_empty(',
            $source,
        );
        self::assertStringContainsString('key_status->st_nlink != 2', $source);
        self::assertStringContainsString(
            'Simulate a process dying after linkat(final)',
            $source,
        );
        self::assertStringNotContainsString('admin.token', $mac);
        self::assertStringContainsString('"snapshots-v2"', $source);
        self::assertStringContainsString('"snapshot-candidates-v2"', $source);
        self::assertStringContainsString('WLS-SNAPSHOT-CANDIDATE-REMOVE/1', $source);
        self::assertStringContainsString(
            'wls_snapshot_internal_candidates_recover(',
            $source,
        );
        self::assertStringContainsString(
            '".candidate-%s-%ld-2222222222222222"',
            $source,
        );
        self::assertStringContainsString(
            'wls_snapshot_internal_candidate_final_safe(',
            $source,
        );
        self::assertStringContainsString(
            'kill(owner_pid, 0) == 0 || errno != ESRCH',
            $source,
        );
        self::assertStringContainsString('(private_key_before.st_mode & 07777) != 0640', $source);
        self::assertStringContainsString('snapshot_file_ids_digest', $source);

        $runtimeStart = \strpos($source, 'static int wls_prepare_data_plane_runtime(');
        $runtimeEnd = \strpos(
            $source,
            'static int wls_nginx_probe_context_load(',
            $runtimeStart ?: 0,
        );
        self::assertIsInt($runtimeStart);
        self::assertIsInt($runtimeEnd);
        $runtimePreparation = \substr(
            $source,
            $runtimeStart,
            $runtimeEnd - $runtimeStart,
        );
        self::assertStringNotContainsString('{"snapshots", 0710}', $runtimePreparation);
    }

    public function testPosixNginxTestIsPublicationBoundAndRestoresTemporaryAcl(): void
    {
        $source = self::posixNativeBrokerSource();
        $start = \strpos($source, 'static int wls_nginx_test_action_v2(');
        $end = \strpos(
            $source,
            'static int wls_nginx_lifecycle_action_v2(',
            $start ?: 0,
        );
        self::assertIsInt($start);
        self::assertIsInt($end);
        $action = \substr($source, $start, $end - $start);

        self::assertStringContainsString('WLS-NGINX-TEST/1', $action);
        self::assertStringContainsString('wls_nginx_test_context_load(', $action);
        self::assertStringContainsString('wls_prepare_nginx_test_candidate(', $action);
        self::assertStringContainsString('wls_restore_nginx_test_candidate(', $action);
        self::assertStringContainsString('wls_nginx_test_context_binding_same(', $action);
        self::assertStringContainsString(
            "test_spawn_status = wls_nginx_spawn_wait(\n"
                . "            &before.action,\n"
                . "            \"TEST\",\n"
                . '            pid_source_config,',
            $action,
        );
        self::assertStringContainsString('candidate_granted = 0;', $action);
        self::assertStringContainsString('NGINX_TEST_FAILED', $action);
        self::assertStringContainsString('WLS-NGINX-TEST-RESTORE-FAILED/1', $source);
        self::assertStringContainsString('state/publication-current.json', $source);
        self::assertStringContainsString('wls_json_checksummed_envelope(', $source);
        self::assertStringContainsString('WLS-NGINX-LKG-TEST-INTENT/1', $source);
        self::assertStringContainsString('wls_lkg_certificate_closure_valid(', $source);
        self::assertStringContainsString(
            'context->lkg_source_config_digest, &config_status',
            $source,
        );
        self::assertStringNotContainsString(
            'strcmp(candidate_digest, source_config_digest) != 0',
            $source,
        );
        self::assertStringContainsString('strcmp(channel, "admin") != 0', $action);
        self::assertStringContainsString('count == 13U', $source);
        self::assertStringContainsString('arguments[14] = "--nginx-config";', $source);

        $restoreArmed = \strpos($action, 'candidate_granted = 1;');
        $prepare = \strpos($action, 'wls_prepare_nginx_test_candidate(', $restoreArmed ?: 0);
        $cleanupRestore = \strpos(
            $action,
            'if (candidate_granted) {',
            $prepare ?: 0,
        );
        $failureRecord = \strpos(
            $action,
            'wls_nginx_test_restore_failure_record(',
            $cleanupRestore ?: 0,
        );
        self::assertIsInt($restoreArmed);
        self::assertIsInt($prepare);
        self::assertIsInt($cleanupRestore);
        self::assertIsInt($failureRecord);
        self::assertGreaterThan($restoreArmed, $prepare);
        self::assertGreaterThan($prepare, $cleanupRestore);
        self::assertGreaterThan($cleanupRestore, $failureRecord);

        $receiptFields = [
            'gateway_epoch=%s', 'host_boot_id=%s', 'active_slot=%s',
            'runtime_generation=%s', 'binary_digest=%s',
            'broker_binary_digest=%s', 'config_digest=%s',
            'test_config_path_digest=%s', 'target_config_path_digest=%s',
            'publication_generation=%lu', 'candidate_transaction_id=%s',
            'candidate_phase=%s',
        ];
        $previous = -1;
        foreach ($receiptFields as $field) {
            $position = \strpos($action, $field);
            self::assertIsInt($position, $field);
            self::assertGreaterThan($previous, $position, $field);
            $previous = $position;
        }
    }

    public function testPosixReloadTestsSameFenceBeforeSignallingMaster(): void
    {
        $source = self::posixNativeBrokerSource();
        $start = \strpos($source, 'static int wls_nginx_lifecycle_action_v2(');
        $end = \strpos(
            $source,
            'static int wls_owned_nginx_alive(',
            $start ?: 0,
        );
        self::assertIsInt($start);
        self::assertIsInt($end);
        $lifecycle = \substr($source, $start, $end - $start);
        $testCall = "test_spawn_status = wls_nginx_spawn_wait(\n"
            . "            &before,\n"
            . "            \"TEST\",\n"
            . '            test_pid_source_config,';
        $test = \strpos(
            $lifecycle,
            $testCall,
        );
        $same = \strpos(
            $lifecycle,
            'wls_nginx_action_context_same(&before, &after)',
            $test ?: 0,
        );
        $reload = \strpos(
            $lifecycle,
            'reload ? "RELOAD" : "START"',
            $same ?: 0,
        );
        self::assertIsInt($test);
        self::assertIsInt($same);
        self::assertIsInt($reload);
        self::assertGreaterThan($test, $same);
        self::assertGreaterThan($same, $reload);
    }

    private static function posixNativeBrokerSource(): string
    {
        $path = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native' . DIRECTORY_SEPARATOR . 'posix'
            . DIRECTORY_SEPARATOR . 'wls_gateway_broker.c';
        self::assertFileExists($path);
        $source = \file_get_contents($path);
        self::assertIsString($source);
        return $source;
    }
}
