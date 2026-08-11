<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayCertificateSnapshotMigrationTest extends TestCase
{
    private object $controller;
    private \ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\class_exists('WlsEdgeGatewayController', false)) {
            if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
                \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
            }
            require \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php';
        }
        $this->reflection = new \ReflectionClass('WlsEdgeGatewayController');
        $this->controller = $this->reflection->newInstanceWithoutConstructor();
    }

    public function testLegacyAndIncompleteReceiptsRequireExactSchemaTwoReseal(): void
    {
        $migration = $this->invoke(
            'certificateSnapshotReceiptMigration',
            [$this->legacyCertificate(), true],
        );
        self::assertTrue($migration['reseal_required']);
        self::assertTrue($migration['re_register_required']);
        self::assertSame(1, $migration['snapshot_receipt_schema_current']);
        self::assertSame(2, $migration['snapshot_receipt_schema_target']);

        $incomplete = $this->portableCertificate();
        unset($incomplete['snapshot_seal_mac']);
        $migration = $this->invoke(
            'certificateSnapshotReceiptMigration',
            [$incomplete, true],
        );
        self::assertTrue($migration['reseal_required']);
        self::assertSame(0, $migration['snapshot_receipt_schema_current']);

        $migration = $this->invoke(
            'certificateSnapshotReceiptMigration',
            [$this->portableCertificate(), true],
        );
        self::assertFalse($migration['reseal_required']);
        self::assertFalse($migration['re_register_required']);
        self::assertSame(2, $migration['snapshot_receipt_schema_current']);
    }

    public function testSameBusinessGenerationMayResealOnlyExactSourceReferences(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174000';
        $routeId = \str_repeat('a', 64);
        $legacy = $this->legacyCertificate();
        $this->setProperty('state', [
            'routes' => [
                $routeId => [
                    'route_id' => $routeId,
                    'project_uuid' => $projectUuid,
                    'status' => 'ACTIVE',
                    'certificate' => $legacy,
                ],
            ],
        ]);
        $candidate = [[
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'certificate' => [
                'state' => 'active',
                'generation' => $legacy['generation'],
                'source_digest' => $legacy['source_digest'],
                'cert' => $legacy['source_refs']['cert'],
                'key' => $legacy['source_refs']['key'],
                'chain' => $legacy['source_refs']['chain'],
            ],
        ]];
        self::assertTrue($this->invoke(
            'registrationSnapshotResealRequired',
            [$projectUuid, $candidate],
        ));

        $changedDigest = $candidate;
        $changedDigest[0]['certificate']['source_digest'] = \str_repeat('f', 64);
        self::assertFalse($this->invoke(
            'registrationSnapshotResealRequired',
            [$projectUuid, $changedDigest],
        ));

        $changedReference = $candidate;
        $changedReference[0]['certificate']['cert']['relative_path']
            = 'rotated/fullchain.pem';
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exact retained source references');
        $this->invoke(
            'registrationSnapshotResealRequired',
            [$projectUuid, $changedReference],
        );
    }

    public function testNewPortableClosureWinsOnceAndThenRemainsStable(): void
    {
        $legacy = $this->legacyCertificate();
        $portable = $this->portableCertificate();
        $merged = $this->invoke(
            'mergeSameBusinessCertificateSnapshot',
            [$legacy, $portable],
        );
        self::assertSame(
            $portable['snapshot_seal_receipt_sha256'],
            $merged['snapshot_seal_receipt_sha256'],
        );
        self::assertSame(3, $merged['snapshot_manifest_schema']);

        $replacement = $portable;
        $replacement['snapshot_seal_receipt_sha256'] = \str_repeat('9', 64);
        $stable = $this->invoke(
            'mergeSameBusinessCertificateSnapshot',
            [$portable, $replacement],
        );
        self::assertSame(
            $portable['snapshot_seal_receipt_sha256'],
            $stable['snapshot_seal_receipt_sha256'],
        );
    }

    public function testProjectMigrationStatusIsReadOnlyAndAggregatesWorstSchema(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174000';
        $state = [
            'routes' => [
                'legacy' => [
                    'project_uuid' => $projectUuid,
                    'status' => 'ACTIVE',
                    'certificate' => $this->legacyCertificate(),
                ],
                'portable' => [
                    'project_uuid' => $projectUuid,
                    'status' => 'ACTIVE',
                    'certificate' => $this->portableCertificate(),
                ],
            ],
        ];
        $this->setProperty('state', $state);
        $status = $this->invoke(
            'snapshotReceiptMigrationStatus',
            [$projectUuid, true],
        );
        self::assertTrue($status['reseal_required']);
        self::assertTrue($status['re_register_required']);
        self::assertSame(1, $status['snapshot_receipt_schema_current']);
        self::assertSame(2, $status['snapshot_receipt_schema_target']);
        self::assertSame($state, $this->getProperty('state'));
    }

    public function testAttestationCacheCoversMoreThanFiveSecondsWithoutActionPeer(): void
    {
        $facts = $this->receiptFacts();
        $certificate = $this->receiptCertificate($facts);
        $now = \hrtime(true) / 1_000_000_000;
        $facts['attested_monotonic'] = $now - 6.0;
        $this->setProperty('sealedCertificateSnapshotFacts', [
            $facts['receipt_digest'] => $facts,
        ]);

        $cached = $this->invoke(
            'brokerAttestCertificateSnapshot',
            [$certificate],
        );
        self::assertIsArray($cached);
        self::assertSame($facts['receipt_digest'], $cached['receipt_digest']);

        $facts['attested_monotonic'] = $now - 16.0;
        $this->setProperty('sealedCertificateSnapshotFacts', [
            $facts['receipt_digest'] => $facts,
        ]);
        self::assertNull($this->invoke(
            'brokerAttestCertificateSnapshot',
            [$certificate],
        ));
    }

    public function testBootstrapPendingFenceRequiresFreshSuccessfulReconcile(): void
    {
        $this->setProperty('publication', [
            'phase' => 'PENDING_PUBLICATION',
        ]);
        $this->setProperty('state', [
            'ready' => true,
            'health_state' => 'HEALTHY',
            'recovery' => ['stage' => 'NONE'],
        ]);
        self::assertTrue($this->invoke('bootstrapPublicationPending'));
        self::assertFalse($this->invoke('bootstrapRecoveryReconciled'));

        $this->setProperty('publication', null);
        $this->setProperty('state', [
            'ready' => true,
            'health_state' => 'CONTROL_DEGRADED',
            'recovery' => ['stage' => 'ROUTE_PROBE_DEFERRED'],
        ]);
        self::assertFalse($this->invoke('bootstrapRecoveryReconciled'));

        $this->setProperty('state', [
            'ready' => false,
            'health_state' => 'CONTROL_DEGRADED',
            'recovery' => ['stage' => 'ADOPTED'],
        ]);
        self::assertFalse($this->invoke('bootstrapRecoveryReconciled'));

        $this->setProperty('state', [
            'ready' => true,
            'health_state' => 'HEALTHY',
            'recovery' => ['stage' => 'NONE'],
        ]);
        self::assertTrue($this->invoke('bootstrapRecoveryReconciled'));

        $source = self::methodSource('bootstrapRecovery');
        $publication = \strpos($source, '$this->processPendingPublication();');
        $freshStage = \strpos($source, '$postPublicationStage =');
        $clear = \strpos(
            $source,
            '$this->startupDataPlaneRecoveryPending = false;',
        );
        self::assertIsInt($publication);
        self::assertIsInt($freshStage);
        self::assertIsInt($clear);
        self::assertLessThan($freshStage, $publication);
        self::assertLessThan($clear, $freshStage);
        self::assertStringContainsString(
            '$this->forceSnapshotAttestationRefresh = true;',
            $source,
        );
        self::assertStringContainsString(
            '$this->forceSnapshotAttestationRefresh = false;',
            $source,
        );
    }

    /** @return array<string,mixed> */
    private function legacyCertificate(): array
    {
        return [
            'state' => 'active',
            'valid' => true,
            'pending' => false,
            'source_digest' => \str_repeat('1', 64),
            'snapshot_digest' => \str_repeat('1', 64),
            'snapshot_manifest_schema' => 2,
            'snapshot_seal_schema' => 1,
            'generation' => 7,
            'source_refs' => [
                'cert' => [
                    'root_alias' => 'project_ssl',
                    'relative_path' => 'fullchain.pem',
                ],
                'key' => [
                    'root_alias' => 'project_ssl',
                    'relative_path' => 'privkey.pem',
                ],
                'chain' => null,
            ],
            'cert_path' => '/legacy/fullchain.pem',
            'key_path' => '/legacy/privkey.pem',
            'san_names' => ['example.test'],
            'not_before' => 1_700_000_000,
            'not_after' => 1_900_000_000,
        ];
    }

    /** @return array<string,mixed> */
    private function portableCertificate(): array
    {
        $facts = $this->receiptFacts();
        return $this->receiptCertificate($facts) + [
            'state' => 'active',
            'valid' => true,
            'pending' => false,
            'source_digest' => $facts['snapshot_digest'],
            'snapshot_manifest_schema' => 3,
            'snapshot_seal_schema' => 2,
            'source_refs' => $this->legacyCertificate()['source_refs'],
            'cert_path' => '/sealed/fullchain.pem',
            'key_path' => '/sealed/privkey.pem',
        ];
    }

    /** @return array<string,mixed> */
    private function receiptFacts(): array
    {
        $hash = static fn (string $label): string => \hash('sha256', $label);
        return [
            'receipt_digest' => $hash('receipt'),
            'receipt_mac' => $hash('mac'),
            'snapshot_digest' => \str_repeat('1', 64),
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'transaction_id' => \str_repeat('2', 32),
            'intent_digest' => $hash('intent'),
            'security_generation' => 5,
            'certificate_generation' => 7,
            'source_binding_digest' => $hash('source-binding'),
            'cert_source_digest' => $hash('cert-source'),
            'key_source_digest' => $hash('key-source'),
            'chain_source_digest' => $hash('chain-source'),
            'manifest_semantic_digest' => $hash('manifest-semantic'),
            'manifest_file_digest' => $hash('manifest-file'),
            'fullchain_digest' => $hash('fullchain'),
            'private_key_digest' => $hash('private-key'),
            'leaf_fingerprint' => $hash('leaf'),
            'san_names_digest' => \hash('sha256', '["example.test"]'),
            'not_before' => 1_700_000_000,
            'not_after' => 1_900_000_000,
            'key_match' => true,
            'platform' => 'posix',
            'gateway_epoch' => \str_repeat('3', 32),
            'host_boot_id' => $hash('boot'),
            'broker_binary_digest' => $hash('broker'),
            'data_plane_identity_kind' => 'uid_gid',
            'data_plane_identity_value' => 'uid=501;gid=502',
            'controller_identity_kind' => 'uid_gid',
            'controller_identity_value' => 'uid=503;gid=504',
            'owner_identity_kind' => 'uid',
            'owner_identity_value' => 'uid=0',
            'acl_profile' => 'posix-sealed-v1',
            'directory_acl_digest' => $hash('directory-acl'),
            'fullchain_acl_digest' => $hash('fullchain-acl'),
            'private_key_acl_digest' => $hash('private-key-acl'),
            'manifest_acl_digest' => $hash('manifest-acl'),
            'acl_protected' => true,
            'reparse_free' => true,
            'single_link_files' => true,
            'snapshot_file_ids_digest' => $hash('file-ids'),
            'broker_runtime_generation' => $hash('runtime'),
        ];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function receiptCertificate(array $facts): array
    {
        return [
            'snapshot_seal_receipt_sha256' => $facts['receipt_digest'],
            'snapshot_seal_mac' => $facts['receipt_mac'],
            'snapshot_digest' => $facts['snapshot_digest'],
            'snapshot_project_uuid' => $facts['project_uuid'],
            'snapshot_authorization_transaction_id' => $facts['transaction_id'],
            'snapshot_authorization_intent_digest' => $facts['intent_digest'],
            'snapshot_security_generation' => $facts['security_generation'],
            'generation' => $facts['certificate_generation'],
            'snapshot_source_binding_sha256' => $facts['source_binding_digest'],
            'snapshot_cert_source_sha256' => $facts['cert_source_digest'],
            'snapshot_key_source_sha256' => $facts['key_source_digest'],
            'snapshot_chain_source_sha256' => $facts['chain_source_digest'],
            'snapshot_manifest_sha256' => $facts['manifest_semantic_digest'],
            'snapshot_manifest_file_sha256' => $facts['manifest_file_digest'],
            'snapshot_fullchain_sha256' => $facts['fullchain_digest'],
            'snapshot_private_key_sha256' => $facts['private_key_digest'],
            'leaf_fingerprint_sha256' => $facts['leaf_fingerprint'],
            'san_names' => ['example.test'],
            'snapshot_san_names_sha256' => $facts['san_names_digest'],
            'not_before' => $facts['not_before'],
            'not_after' => $facts['not_after'],
            'snapshot_seal_platform' => $facts['platform'],
            'snapshot_gateway_epoch' => $facts['gateway_epoch'],
            'snapshot_host_boot_id' => $facts['host_boot_id'],
            'snapshot_broker_binary_sha256' => $facts['broker_binary_digest'],
            'snapshot_data_plane_identity_kind' => $facts['data_plane_identity_kind'],
            'snapshot_data_plane_identity_value' => $facts['data_plane_identity_value'],
            'snapshot_controller_identity_kind' => $facts['controller_identity_kind'],
            'snapshot_controller_identity_value' => $facts['controller_identity_value'],
            'snapshot_owner_identity_kind' => $facts['owner_identity_kind'],
            'snapshot_owner_identity_value' => $facts['owner_identity_value'],
            'snapshot_acl_profile' => $facts['acl_profile'],
            'snapshot_directory_acl_sha256' => $facts['directory_acl_digest'],
            'snapshot_fullchain_acl_sha256' => $facts['fullchain_acl_digest'],
            'snapshot_private_key_acl_sha256' => $facts['private_key_acl_digest'],
            'snapshot_manifest_acl_sha256' => $facts['manifest_acl_digest'],
            'snapshot_acl_protected' => true,
            'snapshot_reparse_free' => true,
            'snapshot_single_link_files' => true,
            'snapshot_file_ids_sha256' => $facts['snapshot_file_ids_digest'],
            'snapshot_broker_generation' => $facts['broker_runtime_generation'],
        ];
    }

    private function setProperty(string $name, mixed $value): void
    {
        $this->reflection->getProperty($name)->setValue($this->controller, $value);
    }

    private function getProperty(string $name): mixed
    {
        return $this->reflection->getProperty($name)->getValue($this->controller);
    }

    /** @param list<mixed> $arguments */
    private function invoke(string $method, array $arguments = []): mixed
    {
        return $this->reflection->getMethod($method)->invokeArgs(
            $this->controller,
            $arguments,
        );
    }

    private static function methodSource(string $method): string
    {
        $reflection = new \ReflectionMethod('WlsEdgeGatewayController', $method);
        $lines = \file((string)$reflection->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
