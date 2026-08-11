<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewaySnapshotReceiptV2Test extends TestCase
{
    private object $controller;
    private \ReflectionMethod $parse;
    private \ReflectionMethod $matches;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\class_exists('WlsEdgeGatewayController', false)) {
            if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
                \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
            }
            require \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php';
        }
        $reflection = new \ReflectionClass('WlsEdgeGatewayController');
        $this->controller = $reflection->newInstanceWithoutConstructor();
        $this->parse = $reflection->getMethod('parseSnapshotSealReceipt');
        $this->matches = $reflection->getMethod(
            'snapshotReceiptMatchesCertificate',
        );
    }

    public function testPortableReceiptParsesPosixAndWindowsIdentities(): void
    {
        $posix = $this->receipt();
        $facts = $this->parse->invoke($this->controller, $posix);

        self::assertSame('posix', $facts['platform']);
        self::assertSame(501, $facts['data_uid']);
        self::assertSame(502, $facts['data_gid']);
        self::assertSame(503, $facts['controller_uid']);
        self::assertSame(504, $facts['controller_gid']);
        self::assertTrue($facts['acl_protected']);
        self::assertTrue($facts['reparse_free']);
        self::assertTrue($facts['single_link_files']);

        $windows = $this->receipt([
            21 => 'windows',
            25 => 'restricting_sid',
            26 => 'S-1-5-21-111-222-333-1001',
            27 => 'service_sid',
            28 => 'S-1-5-80-111-222-333-444-555',
            29 => 'sid',
            30 => 'S-1-5-18',
            31 => 'windows-protected-v1',
        ]);
        $windowsFacts = $this->parse->invoke($this->controller, $windows);
        self::assertSame('windows', $windowsFacts['platform']);
        self::assertSame(0, $windowsFacts['data_uid']);
        self::assertSame(0, $windowsFacts['controller_uid']);
        self::assertSame(
            'S-1-5-21-111-222-333-1001',
            $windowsFacts['data_plane_identity_value'],
        );
    }

    public function testReceiptDigestAndPlatformProfileTamperingFailClosed(): void
    {
        $digestTamper = $this->receipt();
        $digestTamper[16] = \str_repeat('f', 64);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('receipt digest is invalid');
        $this->parse->invoke($this->controller, $digestTamper);
    }

    public function testPlatformCannotBorrowAnotherAclProfile(): void
    {
        $profileTamper = $this->receipt([31 => 'windows-protected-v1']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('POSIX snapshot identity is invalid');
        $this->parse->invoke($this->controller, $profileTamper);
    }

    public function testPosixControllerAndDataPlaneIdentitiesMustBeSeparated(): void
    {
        $sameUid = $this->receipt([28 => 'uid=501;gid=504']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identities are not separated');
        $this->parse->invoke($this->controller, $sameUid);
    }

    public function testWindowsControllerAndDataPlaneSidsMustBeSeparated(): void
    {
        $sameSid = 'S-1-5-21-111-222-333-1001';
        $receipt = $this->receipt([
            21 => 'windows',
            25 => 'restricting_sid',
            26 => $sameSid,
            27 => 'service_sid',
            28 => $sameSid,
            29 => 'sid',
            30 => 'S-1-5-18',
            31 => 'windows-protected-v1',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows snapshot identity is invalid');
        $this->parse->invoke($this->controller, $receipt);
    }

    public function testCertificateStateMustMatchEveryReceiptClosureField(): void
    {
        $facts = $this->parse->invoke($this->controller, $this->receipt());
        $certificate = $this->certificate($facts);

        self::assertTrue($this->matches->invoke(
            $this->controller,
            $facts,
            $certificate,
        ));

        foreach ([
            'snapshot_seal_receipt_sha256',
            'snapshot_seal_mac',
            'snapshot_digest',
            'snapshot_project_uuid',
            'snapshot_authorization_transaction_id',
            'snapshot_authorization_intent_digest',
            'snapshot_security_generation',
            'generation',
            'snapshot_source_binding_sha256',
            'snapshot_cert_source_sha256',
            'snapshot_key_source_sha256',
            'snapshot_chain_source_sha256',
            'snapshot_manifest_sha256',
            'snapshot_manifest_file_sha256',
            'snapshot_fullchain_sha256',
            'snapshot_private_key_sha256',
            'leaf_fingerprint_sha256',
            'snapshot_san_names_sha256',
            'not_before',
            'not_after',
            'snapshot_seal_platform',
            'snapshot_gateway_epoch',
            'snapshot_host_boot_id',
            'snapshot_private_key_acl_sha256',
            'snapshot_directory_acl_sha256',
            'snapshot_fullchain_acl_sha256',
            'snapshot_manifest_acl_sha256',
            'snapshot_acl_profile',
            'snapshot_acl_protected',
            'snapshot_reparse_free',
            'snapshot_single_link_files',
            'snapshot_file_ids_sha256',
            'snapshot_broker_binary_sha256',
            'snapshot_broker_generation',
            'snapshot_data_plane_identity_kind',
            'snapshot_data_plane_identity_value',
            'snapshot_controller_identity_kind',
            'snapshot_controller_identity_value',
            'snapshot_owner_identity_kind',
            'snapshot_owner_identity_value',
        ] as $field) {
            $tampered = $certificate;
            $tampered[$field] = \is_bool($tampered[$field])
                ? !$tampered[$field]
                : (\is_int($tampered[$field])
                    ? $tampered[$field] + 1
                    : 'tampered-' . $field);
            self::assertFalse(
                $this->matches->invoke($this->controller, $facts, $tampered),
                'Receipt replay unexpectedly survived tampering of ' . $field,
            );
        }

        $tamperedNames = $certificate;
        $tamperedNames['san_names'] = ['other.example.test'];
        self::assertFalse($this->matches->invoke(
            $this->controller,
            $facts,
            $tamperedNames,
        ));
    }

    public function testDurableReceiptKeepsSealTimeProvenanceAcrossBootAndSlot(): void
    {
        $facts = $this->parse->invoke($this->controller, $this->receipt());
        $certificate = $this->certificate($facts);
        $reflection = new \ReflectionObject($this->controller);
        $reflection->getProperty('state')->setValue($this->controller, [
            'epoch' => \str_repeat('0', 32),
        ]);
        $reflection->getProperty('hostBootId')->setValue(
            $this->controller,
            \str_repeat('1', 64),
        );

        self::assertNotSame(
            $certificate['snapshot_gateway_epoch'],
            \str_repeat('0', 32),
        );
        self::assertNotSame(
            $certificate['snapshot_host_boot_id'],
            \str_repeat('1', 64),
        );
        self::assertTrue($this->matches->invoke(
            $this->controller,
            $facts,
            $certificate,
        ));
    }

    public function testPortableSnapshotGcCannotInheritLegacyRetentionAge(): void
    {
        $reflection = new \ReflectionObject($this->controller);
        $legacy = self::methodSource($reflection, 'collectSnapshots');
        $portable = self::methodSource($reflection, 'collectBrokerSnapshots');
        $defaults = self::methodSource($reflection, 'defaultState');

        self::assertStringContainsString("'snapshot_gc_candidates'", $legacy);
        self::assertStringNotContainsString("'snapshot_gc_candidates_v2'", $legacy);
        self::assertStringContainsString(
            "'snapshot_gc_candidates_v2'",
            $portable,
        );
        self::assertStringNotContainsString("'snapshot_gc_candidates'", $portable);
        self::assertStringContainsString("'snapshot_gc_candidates' => []", $defaults);
        self::assertStringContainsString(
            "'snapshot_gc_candidates_v2' => []",
            $defaults,
        );
    }

    public function testPortableSnapshotGcRunsOnlyInBoundedBrokerBootstrapSweep(): void
    {
        $reflection = new \ReflectionObject($this->controller);
        $bootstrap = self::methodSource($reflection, 'bootstrapRecovery');
        $portable = self::methodSource($reflection, 'collectBrokerSnapshots');

        self::assertStringContainsString(
            '$this->collectBrokerSnapshots($snapshotCollectionNow, 1);',
            $bootstrap,
        );
        self::assertStringContainsString(
            '$snapshotCollectionNow - $this->lastBrokerSnapshotCollectionAt',
            $bootstrap,
        );
        self::assertStringContainsString('int $maxRemovals = 4', $portable);
        self::assertStringContainsString(
            'if ($removalCount >= $maxRemovals)',
            $portable,
        );
        self::assertStringContainsString('++$removalCount;', $portable);
    }

    private static function methodSource(
        \ReflectionClass $reflection,
        string $method,
    ): string {
        $target = $reflection->getMethod($method);
        $lines = \file((string)$target->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $target->getStartLine() - 1,
            $target->getEndLine() - $target->getStartLine() + 1,
        ));
    }

    /** @param array<int,string> $overrides @return list<string> */
    private function receipt(array $overrides = []): array
    {
        $sanDigest = \hash('sha256', '["example.test"]');
        $response = [
            '',
            \str_repeat('b', 64),
            \str_repeat('c', 64),
            '123e4567-e89b-42d3-a456-426614174000',
            \str_repeat('d', 32),
            \str_repeat('e', 64),
            '2',
            '3',
            \str_repeat('1', 64),
            \str_repeat('2', 64),
            \str_repeat('3', 64),
            \str_repeat('4', 64),
            \str_repeat('5', 64),
            \str_repeat('6', 64),
            \str_repeat('7', 64),
            \str_repeat('8', 64),
            \str_repeat('9', 64),
            $sanDigest,
            '1700000000',
            '1900000000',
            '1',
            'posix',
            \str_repeat('a', 32),
            \str_repeat('b', 64),
            \str_repeat('c', 64),
            'uid_gid',
            'uid=501;gid=502',
            'uid_gid',
            'uid=503;gid=504',
            'uid',
            'uid=0',
            'posix-sealed-v1',
            \str_repeat('a', 64),
            \str_repeat('b', 64),
            \str_repeat('c', 64),
            \str_repeat('d', 64),
            '1',
            '1',
            '1',
            \str_repeat('e', 64),
            \str_repeat('f', 64),
        ];
        foreach ($overrides as $index => $value) {
            $response[$index] = $value;
        }
        $response[0] = \hash('sha256', $this->canonicalReceipt($response));
        return \array_values($response);
    }

    /** @param list<string> $response */
    private function canonicalReceipt(array $response): string
    {
        $names = [
            'snapshot_digest',
            'project_uuid',
            'transaction_id',
            'intent_digest',
            'security_generation',
            'certificate_generation',
            'source_binding_digest',
            'cert_source_digest',
            'key_source_digest',
            'chain_source_digest',
            'manifest_semantic_digest',
            'manifest_file_digest',
            'fullchain_digest',
            'private_key_digest',
            'leaf_fingerprint',
            'san_names_digest',
            'not_before',
            'not_after',
            'key_match',
            'platform',
            'gateway_epoch',
            'host_boot_id',
            'broker_binary_digest',
            'data_plane_identity_kind',
            'data_plane_identity_value',
            'controller_identity_kind',
            'controller_identity_value',
            'owner_identity_kind',
            'owner_identity_value',
            'acl_profile',
            'directory_acl_digest',
            'fullchain_acl_digest',
            'private_key_acl_digest',
            'manifest_acl_digest',
            'acl_protected',
            'reparse_free',
            'single_link_files',
            'snapshot_file_ids_digest',
            'broker_runtime_generation',
        ];
        $canonical = "WLS-SNAPSHOT-RECEIPT/2\n";
        foreach ($names as $offset => $name) {
            $canonical .= $name . '=' . $response[$offset + 2] . "\n";
        }
        return $canonical;
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function certificate(array $facts): array
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
}
