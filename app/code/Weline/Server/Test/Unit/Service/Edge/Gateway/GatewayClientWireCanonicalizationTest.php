<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayClientWireCanonicalizationTest extends TestCase
{
    public function testPreservesSignedWireNumberLexemesAcrossPhpRuntimes(): void
    {
        $secret = \str_repeat('a', 64);
        $requestId = \str_repeat('b', 32);
        $canonical = '{"epoch":"' . \str_repeat('c', 32)
            . '","ok":true,"payload":{"clock":{"drift":1e+0,"stable":0.0},'
            . '"numeric_string":"1e+0"},"protocol":"' . GatewayPaths::PROTOCOL
            . '","request_id":"' . $requestId . '"}';
        $signature = \hash_hmac('sha256', $canonical, $secret);
        $wire = '{"protocol":"' . GatewayPaths::PROTOCOL
            . '","request_id":"' . $requestId
            . '","ok":true,"epoch":"' . \str_repeat('c', 32)
            . '","payload":{"clock":{"drift":1e+0,"stable":0.0},'
            . '"numeric_string":"1e+0"},"signature":"' . $signature . '"}' . "\n";

        $method = new \ReflectionMethod(GatewayClient::class, 'canonicalResponseFromWire');
        $actual = $method->invoke(null, $wire, $signature);

        self::assertSame($canonical, $actual);
        self::assertSame($signature, \hash_hmac('sha256', $actual, $secret));
        self::assertStringContainsString('"numeric_string":"1e+0"', $actual);
    }

    public function testRejectsAResponseWhoseParsedSignatureDoesNotMatch(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'canonicalResponseFromWire');

        $this->expectException(\RuntimeException::class);
        $method->invoke(
            null,
            '{"protocol":"wls-edge/2","signature":"' . \str_repeat('a', 64) . '"}',
            \str_repeat('b', 64),
        );
    }

    public function testAuthenticatedLegacyResponseIsSanitizedAfterVerification(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'sanitizeAuthenticatedResponse');
        $marker = 'must-not-cross-client-boundary';
        $response = [
            'protocol' => GatewayPaths::PROTOCOL,
            'ok' => true,
            'payload' => [
                'route' => [
                    'edge_capability_secret' => $marker,
                    'edge_capability_digest' => \str_repeat('c', 64),
                ],
            ],
            'signature' => \str_repeat('d', 64),
        ];

        $sanitized = $method->invoke(null, $response, 'project', [
            'operation' => 'own-status',
            'payload' => [],
        ]);

        self::assertArrayNotHasKey('edge_capability_secret', $sanitized['payload']['route']);
        self::assertSame(\str_repeat('c', 64), $sanitized['payload']['route']['edge_capability_digest']);
        self::assertSame(\str_repeat('d', 64), $sanitized['signature']);
    }

    public function testOnlyValidatedEnrollmentCredentialSurvivesSanitization(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'sanitizeAuthenticatedResponse');
        $projectUuid = '11111111-2222-4333-8444-555555555555';
        $hostId = \str_repeat('a', 32);
        $credential = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \str_repeat('b', 32),
            'credential_generation' => 1,
            'secret' => \str_repeat('c', 64),
            'issued_at' => '2026-07-31T00:00:00+00:00',
            'unexpected_secret' => 'drop-me',
        ];
        $request = [
            'operation' => 'enroll',
            'host_id' => $hostId,
            'payload' => ['project_uuid' => $projectUuid],
        ];

        $sanitized = $method->invoke(null, [
            'ok' => true,
            'payload' => ['credential' => $credential],
        ], 'admin', $request);

        self::assertSame(\str_repeat('c', 64), $sanitized['payload']['credential']['secret']);
        self::assertArrayNotHasKey('unexpected_secret', $sanitized['payload']['credential']);

        $credential['credential_id'] = 'invalid';
        $rejected = $method->invoke(null, [
            'ok' => true,
            'payload' => ['credential' => $credential],
        ], 'admin', $request);
        self::assertArrayNotHasKey('credential', $rejected['payload']);
    }

    public function testEnrollmentReceiptMustProveTheExactDurableCommit(): void
    {
        $projectUuid = '11111111-2222-4333-8444-555555555555';
        $hostId = \str_repeat('a', 32);
        $secret = \str_repeat('c', 64);
        $credentialId = \str_repeat('b', 32);
        $enrollment = GatewayHostManager::enrollmentRequestEnvelope([
            'project_uuid' => $projectUuid,
            'project_root' => '/srv/weline/project',
            'certificate_roots' => ['project_ssl' => '/srv/weline/project/app/etc/ssl'],
            'allowed_domains' => ['shop.example.test'],
            'capabilities' => ['acme_http_01' => true],
        ]);
        $credential = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'secret' => $secret,
            'issued_at' => '2026-08-03T00:00:00+00:00',
        ];
        $receipt = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'gateway_epoch' => \str_repeat('d', 32),
            'tx_id' => \str_repeat('e', 32),
            'project_uuid' => $projectUuid,
            'security_generation' => 7,
            'credential_generation' => 3,
            'credential_id' => $credentialId,
            'domains_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($enrollment['allowed_domains']),
            ),
            'capabilities_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($enrollment['capabilities']),
            ),
            'request_digest' => $enrollment['request_digest'],
            'idempotency_key' => $enrollment['idempotency_key'],
            'state' => 'COMMITTED',
            'issued_at' => '2026-08-03T00:00:01+00:00',
        ];
        $receipt['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($receipt),
            $secret,
        );

        self::assertSame(
            $receipt,
            GatewayHostManager::validateCredentialReceipt(
                $credential,
                $receipt,
                $enrollment,
            ),
        );

        $receipt['security_generation'] = 8;
        $this->expectException(\RuntimeException::class);
        GatewayHostManager::validateCredentialReceipt(
            $credential,
            $receipt,
            $enrollment,
        );
    }

    public function testPageableReadCannotAuthenticateAsEmptyWhenPageIsMissing(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'collectPaginatedResponse');
        $client = new GatewayClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mandatory fenced route page');
        $method->invoke($client, 'admin', 'routes', [], [
            'ok' => true,
            'payload' => [
                'receipt' => [
                    'schema' => 1,
                    'code' => 'response_too_large',
                    'committed' => true,
                ],
            ],
        ]);
    }

    public function testNumericInstanceIdsRemainDistinctInWireClosure(): void
    {
        $projectUuid = '11111111-2222-4333-8444-555555555555';
        $rows = [];
        foreach (['0', '1'] as $offset => $instanceId) {
            $identity = [
                'schema' => 'wls-backend-listener-identity/2',
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceId,
                'generation' => 1,
                'master_pid' => 100 + $offset,
                'master_epoch' => 1,
                'launch_id' => \str_repeat((string)($offset + 1), 32),
                'listener_lease_id' => \str_repeat((string)($offset + 3), 32),
                'edge_capability_digest' => \str_repeat((string)($offset + 5), 64),
                'session_capability' => 'isolated',
            ];
            $identity['public_digest'] = \hash(
                'sha256',
                GatewayClient::canonicalJson($identity),
            );
            $rows[] = [
                'instance_id' => $instanceId,
                'backends' => [[
                    'host' => '127.0.0.1',
                    'port' => 21000 + $offset,
                    'weight' => 1,
                ]],
                'backend_identity' => $identity,
            ];
        }
        $method = new \ReflectionMethod(GatewayClient::class, 'assertWireBackendInstances');
        $indexed = $method->invoke(new GatewayClient(), $rows, $projectUuid);

        self::assertSame(['instance:0', 'instance:1'], \array_keys($indexed));
        self::assertSame('0', $indexed['instance:0']['instance_id']);
        self::assertSame('1', $indexed['instance:1']['instance_id']);
    }
}
