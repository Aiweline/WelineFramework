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
            'host_boot_id', 'issued_monotonic', 'lease_sequence',
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
            '$heartbeat = $this->heartbeat($instanceName);',
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
        $receipt = [
            'project_uuid' => $projectUuid,
            'gateway_epoch' => $epoch,
            'project_generation' => 5,
            'request_digest' => $requestDigest,
            'idempotency_key' => $idempotencyKey,
            'active_config_generation' => 11,
            'active_config_digest' => $activeDigest,
            'host_boot_id' => \str_repeat('9', 64),
            'instance_id' => $instanceId,
            'instance_generation' => 7,
            'instance_digest' => $instanceDigest,
            'master_epoch' => 9,
            'launch_id' => $launchId,
            'route_generations' => [$routeId => 3],
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
    }
}
